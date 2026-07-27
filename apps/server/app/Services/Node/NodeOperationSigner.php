<?php

namespace App\Services\Node;

use App\Models\AuditEvent;
use App\Models\Device;
use App\Models\Node;
use App\Models\NodeOperation;
use App\Services\Audit\AuditService;

/**
 * Signs node operations and verifies operation signatures (technical spec
 * 10.4, 12.4).
 *
 * Technical spec 10.4 states three rules this service implements. Operation
 * signatures cover the normalized operation fields, not the whole row.
 * Operations are signed with the node private key. Device-originated
 * operations are signed by the originating device and then countersigned by
 * the accepting node.
 *
 * The signed message is the canonical payload: a format marker, a newline, and
 * the normalized-field projection {@see NodeOperation::normalizedAttributes()}
 * encoded as JSON in specification order. Fixing the field order and the
 * encoding is what lets two nodes that never share code paths agree on the
 * bytes. The format marker gives the payload domain separation from any other
 * Meridian signature and leaves room to version the encoding later. Deliberately
 * excluded from the signed message are `payload_json`, `signature`, `hash`, and
 * the delivery lifecycle columns, which change as an operation is sent,
 * received, applied, or retried and therefore cannot be covered by a signature
 * made at the origin.
 *
 * `hash` is the SHA-256 digest of that same canonical payload, so a receiver
 * recomputes it rather than trusting the value it was handed. Verification
 * checks the hash first and then the signature, and returns false for anything
 * it cannot check: a blank signature, a rewritten field, missing or unusable
 * key material, or a signature made by a different node. A remote peer controls
 * all of that input, so verification fails closed instead of raising.
 *
 * Signing happens before the operation row is inserted. Node operations are
 * append-only and `signature`/`hash` cannot be rewritten once recorded
 * (technical spec 10.4; data/API 13.3), so signing an already-recorded
 * operation is refused.
 *
 * This service decides only whether a signature is authentic. Whether an
 * authentic operation is then accepted, stored, and applied — including node
 * and device revocation, event authority, and conflicts — belongs to the
 * receive/store/apply path (M12.4) and the tasks after it.
 */
class NodeOperationSigner
{
    /**
     * Canonical payload format marker. Bump this only alongside a deliberate,
     * documented change to the signed encoding; every node must agree on it.
     */
    public const CANONICAL_FORMAT = 'meridian.node-operation.v1';

    public const HASH_ALGORITHM = 'sha256';

    /**
     * Normalized fields that must be present before an operation can be
     * signed. `origin_node_id` is filled in from the signing node, and
     * `created_at` is pinned at signing time when the caller has not set it.
     *
     * @var list<string>
     */
    private const REQUIRED_ATTRIBUTES = [
        'uuid',
        'actor_user_id',
        'operation_type',
        'entity_type',
        'entity_id',
    ];

    public function __construct(
        private readonly NodeSignatureAlgorithm $algorithm,
        private readonly NodeKeyProvider $keys,
        private readonly NodeSetupService $nodes,
        private readonly AuditService $audit,
    ) {}

    /**
     * The exact bytes a signature covers.
     */
    public function canonicalPayload(NodeOperation $operation): string
    {
        $json = json_encode(
            $operation->normalizedAttributes(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        return self::CANONICAL_FORMAT."\n".$json;
    }

    public function hashFor(NodeOperation $operation): string
    {
        return hash(self::HASH_ALGORITHM, $this->canonicalPayload($operation));
    }

    /**
     * Sign an operation created on this node with the node private key.
     *
     * The operation must not be persisted yet: `signature` and `hash` are
     * append-only content. The signed model is left unsaved so the caller
     * inserts it, which is also the point at which the idempotency key becomes
     * durable.
     *
     * @throws NodeSigningException
     */
    public function sign(NodeOperation $operation, ?Node $signingNode = null): NodeOperationSignature
    {
        $node = $this->requireSigningNode($signingNode);

        $this->guardSignable($operation, $node);

        $privateKey = $this->keys->privateKeyFor($node);

        return $this->apply($operation, $node, $privateKey);
    }

    /**
     * Countersign a device-originated operation with the node private key
     * after verifying the originating device's signature (technical spec 10.4,
     * 12.4).
     *
     * The accepting node refuses to countersign an operation whose device
     * signature does not verify, so an unverifiable device operation never
     * gains node-signed standing.
     *
     * @throws NodeSigningException
     */
    public function countersign(
        NodeOperation $operation,
        string $deviceSignature,
        Device $device,
        ?Node $acceptingNode = null,
    ): NodeOperationSignature {
        $node = $this->requireSigningNode($acceptingNode);

        $this->guardSignable($operation, $node);

        if ((string) $operation->actor_device_id !== (string) $device->getKey()) {
            throw NodeSigningException::deviceMismatch();
        }

        if (! $this->verifyDeviceSignature($operation, $deviceSignature, $device)) {
            throw NodeSigningException::deviceSignatureRejected();
        }

        $privateKey = $this->keys->privateKeyFor($node);

        return $this->apply(
            operation: $operation,
            node: $node,
            privateKey: $privateKey,
            deviceSignature: $deviceSignature,
            device: $device,
        );
    }

    /**
     * Verify the node signature on an operation, failing closed.
     *
     * The signing node defaults to the operation's origin node, which is the
     * node that signed it in both cases: an operation created on a node is
     * signed there, and a device-originated operation is countersigned by the
     * node that accepted it and became its origin.
     */
    public function verify(NodeOperation $operation, ?Node $signingNode = null): bool
    {
        $node = $signingNode ?? $operation->originNode()->first();

        if (! $node instanceof Node) {
            return false;
        }

        $publicKey = $this->keys->publicKeyFor($node);

        if ($publicKey === null) {
            return false;
        }

        $payload = $this->canonicalPayload($operation);

        // The stored hash is recomputed rather than trusted, so a rewritten
        // normalized field fails here even before the signature is checked.
        if (! hash_equals(hash(self::HASH_ALGORITHM, $payload), (string) $operation->hash)) {
            return false;
        }

        return $this->algorithm->verify($payload, (string) $operation->signature, $publicKey);
    }

    /**
     * Verify the originating device's signature over the same canonical
     * payload (technical spec 12.4). Fails closed for a device with no
     * registered public key.
     */
    public function verifyDeviceSignature(
        NodeOperation $operation,
        string $deviceSignature,
        Device $device,
    ): bool {
        $publicKey = trim((string) $device->device_public_key);

        if ($publicKey === '') {
            return false;
        }

        return $this->algorithm->verify(
            $this->canonicalPayload($operation),
            $deviceSignature,
            $publicKey,
        );
    }

    /**
     * Retain the operation's signatures in audit data (technical spec 10.4,
     * 12.4, 23; data/API 13.3, 14.1).
     *
     * Called once the signed operation is recorded, because an audit event
     * names the entity it describes. For a countersigned operation this is the
     * only place the device signature is kept: the row holds the node
     * countersignature alone.
     *
     * @throws NodeSigningException
     */
    public function recordSignatures(
        NodeOperation $operation,
        NodeOperationSignature $signature,
        string $sourceContext = AuditEvent::SOURCE_SYNC,
    ): AuditEvent {
        if (! $operation->exists) {
            throw NodeSigningException::operationNotRecorded();
        }

        return $this->audit->recordForEntity(
            entity: $operation,
            action: $signature->isCountersignature()
                ? 'node_operation.countersigned'
                : 'node_operation.signed',
            actorUser: $operation->actorUser()->first(),
            actorDevice: $signature->isCountersignature() ? $operation->actorDevice()->first() : null,
            actorNode: $operation->originNode()->first(),
            eventId: $operation->event_id,
            sourceContext: $sourceContext,
            signatureMetadata: $signature->toAuditMetadata(),
        );
    }

    /**
     * @throws NodeSigningException
     */
    private function apply(
        NodeOperation $operation,
        Node $node,
        string $privateKey,
        ?string $deviceSignature = null,
        ?Device $device = null,
    ): NodeOperationSignature {
        $payload = $this->canonicalPayload($operation);
        $hash = hash(self::HASH_ALGORITHM, $payload);
        $signature = $this->algorithm->sign($payload, $privateKey);

        $operation->forceFill([
            'hash' => $hash,
            'signature' => $signature,
        ]);

        return new NodeOperationSignature(
            hash: $hash,
            signature: $signature,
            algorithm: $this->algorithm->algorithmFor($privateKey),
            signingNodeId: (string) $node->getKey(),
            deviceSignature: $deviceSignature,
            deviceAlgorithm: $device instanceof Device
                ? $this->algorithm->algorithmFor((string) $device->device_public_key)
                : null,
            deviceId: $device?->getKey(),
        );
    }

    /**
     * @throws NodeSigningException
     */
    private function guardSignable(NodeOperation $operation, Node $node): void
    {
        if ($operation->exists) {
            throw NodeSigningException::operationAlreadyRecorded();
        }

        foreach (self::REQUIRED_ATTRIBUTES as $attribute) {
            if (trim((string) $operation->getAttribute($attribute)) === '') {
                throw NodeSigningException::incompleteOperation($attribute);
            }
        }

        $originNodeId = (string) $operation->origin_node_id;

        if ($originNodeId !== '' && $originNodeId !== (string) $node->getKey()) {
            throw NodeSigningException::originNodeMismatch($node->node_name);
        }

        $operation->origin_node_id = $node->getKey();

        // `created_at` is the origin node's creation time and is covered by the
        // signature, so it is pinned before signing rather than filled in by
        // the insert (technical spec 10.4; data/API 13.3).
        if ($operation->created_at === null) {
            $operation->created_at = now();
        }
    }

    /**
     * @throws NodeSigningException
     */
    private function requireSigningNode(?Node $signingNode): Node
    {
        $node = $signingNode ?? $this->nodes->activeNode();

        if (! $node instanceof Node) {
            throw NodeSigningException::nodeNotConfigured();
        }

        return $node;
    }
}
