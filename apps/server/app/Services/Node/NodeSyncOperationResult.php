<?php

namespace App\Services\Node;

use Illuminate\Support\Str;

/**
 * What the far side of an exchange did with one operation it was handed
 * (technical spec 10.1).
 *
 * This is a delivery outcome, not an application outcome. It answers the only
 * question the sending node needs answered — does the peer hold this operation
 * now? — and deliberately does not report whether the peer managed to apply it.
 * A peer that stored an operation but could not apply it keeps its own `failed`
 * record and can retry locally (technical spec 10.3); the sender has nothing
 * left to do about it, and re-sending would be redelivery of an operation the
 * peer already holds.
 *
 * The same shape travels in both directions. It comes back in an exchange
 * response for operations that were pushed, and it goes out in the next
 * exchange request for operations that were pulled and refused, so a node
 * always learns which of its own operations the peer will never accept instead
 * of offering them forever.
 */
final class NodeSyncOperationResult
{
    /** Accepted and newly written to the peer's operation log. */
    public const OUTCOME_STORED = 'stored';

    /** Accepted, and the peer already held it. Redelivery is safe. */
    public const OUTCOME_DUPLICATE = 'duplicate';

    /** Refused on arrival and never stored. `reasonCode` says why. */
    public const OUTCOME_REFUSED = 'refused';

    /**
     * @var list<string>
     */
    public const OUTCOMES = [
        self::OUTCOME_STORED,
        self::OUTCOME_DUPLICATE,
        self::OUTCOME_REFUSED,
    ];

    public function __construct(
        public readonly string $uuid,
        public readonly string $outcome,
        /** A {@see NodeOperationRejectedException} reason code when refused. */
        public readonly ?string $reasonCode = null,
        public readonly ?string $detail = null,
    ) {}

    public static function stored(string $uuid): self
    {
        return new self($uuid, self::OUTCOME_STORED);
    }

    public static function duplicate(string $uuid): self
    {
        return new self($uuid, self::OUTCOME_DUPLICATE);
    }

    public static function refused(string $uuid, NodeOperationRejectedException $rejection): self
    {
        return new self($uuid, self::OUTCOME_REFUSED, $rejection->reason, $rejection->getMessage());
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws NodeSyncException
     */
    public static function fromArray(array $data): self
    {
        $uuid = $data['uuid'] ?? null;
        $outcome = $data['outcome'] ?? null;

        if (! is_string($uuid) || ! Str::isUuid(trim($uuid))) {
            throw NodeSyncException::invalidField('results.uuid');
        }

        if (! is_string($outcome) || ! in_array($outcome, self::OUTCOMES, true)) {
            throw NodeSyncException::invalidField('results.outcome');
        }

        return new self(
            uuid: trim($uuid),
            outcome: $outcome,
            reasonCode: is_string($data['reason_code'] ?? null) ? $data['reason_code'] : null,
            detail: is_string($data['detail'] ?? null) ? $data['detail'] : null,
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'outcome' => $this->outcome,
            'reason_code' => $this->reasonCode,
            'detail' => $this->detail,
        ];
    }

    public function wasAccepted(): bool
    {
        return $this->outcome !== self::OUTCOME_REFUSED;
    }

    /**
     * The text a sending node records on its own row when the peer refuses an
     * operation, so the refusal is readable without the exchange in hand.
     */
    public function failureReason(): string
    {
        return sprintf(
            'The peer node refused this operation (%s): %s',
            $this->reasonCode ?? 'unknown_reason',
            $this->detail ?? 'no detail was given.',
        );
    }
}
