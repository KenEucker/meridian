<?php

namespace App\Services\Node;

use App\Models\NodeOperation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * The wire shape one node hands another for a single node operation (technical
 * spec 10.1, 10.4; data/API 13.3).
 *
 * An envelope carries exactly what travels between nodes: the normalized
 * operation fields a signature covers, the `signature` and `hash` made over
 * them, and the optional `payload_json`. The delivery lifecycle columns
 * (`sent_at`, `received_at`, `applied_at`, `status`, `failure_reason`,
 * `retry_count`) are deliberately absent, because each node tracks an
 * operation's progress through its own send/receive/apply cycle on its own row.
 * A receiver that copied the sender's `status` would be recording the sender's
 * history rather than its own.
 *
 * Parsing is strict and refuses rather than coerces. The bytes a signature
 * covers are produced from these fields, so a value quietly reshaped on the way
 * in would fail verification for a reason nobody could read; refusing at the
 * boundary names the actual problem. {@see NodeOperationRejectedException}
 *
 * The transport that moves envelopes between nodes is the bidirectional sync
 * loop (M12.5); this class only defines what it moves.
 */
final class NodeOperationEnvelope
{
    /**
     * Optional normalized fields. Everything else in
     * {@see NodeOperation::NORMALIZED_ATTRIBUTES} is required.
     *
     * @var list<string>
     */
    private const NULLABLE_ATTRIBUTES = [
        'target_node_id',
        'actor_device_id',
        'event_id',
    ];

    /**
     * Normalized fields that must be UUIDs when present.
     *
     * @var list<string>
     */
    private const UUID_ATTRIBUTES = [
        'uuid',
        'origin_node_id',
        'target_node_id',
        'actor_user_id',
        'actor_device_id',
        'entity_id',
        'event_id',
    ];

    /**
     * @param  array<string, string|null>  $normalized  Keyed by {@see NodeOperation::NORMALIZED_ATTRIBUTES}.
     * @param  array<array-key, mixed>|null  $payload
     */
    private function __construct(
        public readonly array $normalized,
        public readonly string $signature,
        public readonly string $hash,
        public readonly ?array $payload,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws NodeOperationRejectedException
     */
    public static function fromArray(array $data): self
    {
        $normalized = [];

        foreach (NodeOperation::NORMALIZED_ATTRIBUTES as $attribute) {
            $normalized[$attribute] = self::normalizedValue($data, $attribute);
        }

        return new self(
            normalized: $normalized,
            signature: self::requiredString($data, 'signature'),
            hash: self::requiredString($data, 'hash'),
            payload: self::payload($data),
        );
    }

    /**
     * The envelope for an operation this node holds, so a sender transmits the
     * same fields a receiver parses.
     */
    public static function fromOperation(NodeOperation $operation): self
    {
        return new self(
            normalized: $operation->normalizedAttributes(),
            signature: (string) $operation->signature,
            hash: (string) $operation->hash,
            payload: $operation->payload_json,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...$this->normalized,
            'signature' => $this->signature,
            'hash' => $this->hash,
            'payload_json' => $this->payload,
        ];
    }

    public function uuid(): string
    {
        return (string) $this->normalized['uuid'];
    }

    public function originNodeId(): string
    {
        return (string) $this->normalized['origin_node_id'];
    }

    public function targetNodeId(): ?string
    {
        return $this->normalized['target_node_id'];
    }

    public function actorUserId(): string
    {
        return (string) $this->normalized['actor_user_id'];
    }

    public function actorDeviceId(): ?string
    {
        return $this->normalized['actor_device_id'];
    }

    /**
     * An unsaved operation carrying this envelope's content and none of the
     * receiver's delivery lifecycle state. Signature verification runs against
     * this model before anything is stored, so the model has to exist before
     * the decision to store it is made.
     */
    public function toOperation(): NodeOperation
    {
        $operation = new NodeOperation;

        $operation->forceFill([
            ...$this->normalized,
            'signature' => $this->signature,
            'hash' => $this->hash,
            'payload_json' => $this->payload,
        ]);

        return $operation;
    }

    /**
     * Whether a stored operation holds the same content this envelope carries.
     *
     * `hash` covers the normalized fields, so it settles those. `payload_json`
     * is outside the signed payload (data/API 13.3) and is compared directly.
     */
    public function matchesStored(NodeOperation $stored): bool
    {
        return hash_equals((string) $stored->hash, $this->hash)
            && hash_equals((string) $stored->signature, $this->signature)
            && $stored->payload_json === $this->payload;
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws NodeOperationRejectedException
     */
    private static function normalizedValue(array $data, string $attribute): ?string
    {
        $value = $data[$attribute] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            if (! in_array($attribute, self::NULLABLE_ATTRIBUTES, true)) {
                throw NodeOperationRejectedException::missingField($attribute);
            }

            return null;
        }

        if (! is_string($value)) {
            throw NodeOperationRejectedException::invalidField($attribute);
        }

        $value = trim($value);

        if (in_array($attribute, self::UUID_ATTRIBUTES, true) && ! Str::isUuid($value)) {
            throw NodeOperationRejectedException::invalidField($attribute);
        }

        if ($attribute === 'created_at') {
            return self::createdAt($value);
        }

        return $value;
    }

    /**
     * `created_at` is the origin node's creation time and is covered by the
     * signature as ISO-8601 UTC, so it is re-rendered in exactly that form
     * rather than kept as whatever string arrived.
     *
     * @throws NodeOperationRejectedException
     */
    private static function createdAt(string $value): string
    {
        try {
            return CarbonImmutable::parse($value)->utc()->toIso8601String();
        } catch (Throwable) {
            throw NodeOperationRejectedException::invalidField('created_at');
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws NodeOperationRejectedException
     */
    private static function requiredString(array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw NodeOperationRejectedException::missingField($field);
        }

        return trim($value);
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>|null
     *
     * @throws NodeOperationRejectedException
     */
    private static function payload(array $data): ?array
    {
        $payload = $data['payload_json'] ?? null;

        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw NodeOperationRejectedException::invalidField('payload_json');
        }

        return $payload;
    }
}
