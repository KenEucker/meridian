<?php

namespace App\Services\Node;

/**
 * The signature material produced for one node operation (technical spec 10.4,
 * 12.4).
 *
 * `node_operations` stores one signature and one hash (data/API 13.3), and for
 * a device-originated operation that stored signature is the accepting node's
 * countersignature. The device signature has nowhere to live on the row, which
 * is why both specifications say both signatures are retained in audit data.
 * {@see toAuditMetadata()} is that record.
 */
class NodeOperationSignature
{
    public function __construct(
        public readonly string $hash,
        public readonly string $signature,
        public readonly string $algorithm,
        public readonly string $signingNodeId,
        public readonly ?string $deviceSignature = null,
        public readonly ?string $deviceAlgorithm = null,
        public readonly ?string $deviceId = null,
    ) {}

    /**
     * A countersignature is a node signature applied over an operation the
     * originating device already signed (technical spec 10.4, 12.4).
     */
    public function isCountersignature(): bool
    {
        return $this->deviceSignature !== null;
    }

    /**
     * Signature metadata for `audit_events.signature_metadata_json` (data/API
     * 14.1), retaining both signatures for device-originated operations.
     *
     * @return array<string, string|bool>
     */
    public function toAuditMetadata(): array
    {
        $metadata = [
            'canonical_format' => NodeOperationSigner::CANONICAL_FORMAT,
            'hash_algorithm' => NodeOperationSigner::HASH_ALGORITHM,
            'hash' => $this->hash,
            'node_signature' => $this->signature,
            'node_signature_algorithm' => $this->algorithm,
            'signing_node_id' => $this->signingNodeId,
            'countersigned' => $this->isCountersignature(),
        ];

        if ($this->deviceSignature !== null) {
            $metadata['device_signature'] = $this->deviceSignature;
            $metadata['device_signature_algorithm'] = (string) $this->deviceAlgorithm;
            $metadata['device_id'] = (string) $this->deviceId;
        }

        return $metadata;
    }
}
