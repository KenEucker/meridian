<?php

declare(strict_types=1);

namespace App\Services\Secrets;

/**
 * The evaluated state of one {@see SecretRequirement} (technical spec 26.2).
 *
 * Three states, and the value behind them is in none of them: a status carries
 * the variable's name, what is wrong with it, and what to do about it. A node
 * that refused to boot has to be able to say why in a log an operator will read
 * over somebody's shoulder.
 */
final class SecretStatus
{
    /** Configured, and not one of the sample values. */
    public const OK = 'ok';

    /** Required and blank. */
    public const MISSING = 'missing';

    /** Still set to a sample or placeholder value. */
    public const DEFAULT_VALUE = 'default';

    public function __construct(
        public readonly SecretRequirement $requirement,
        public readonly string $state,
        public readonly ?string $reason = null,
    ) {}

    public static function ok(SecretRequirement $requirement): self
    {
        return new self($requirement, self::OK);
    }

    public static function missing(SecretRequirement $requirement): self
    {
        return new self(
            $requirement,
            self::MISSING,
            sprintf('%s is not set. %s', $requirement->name, $requirement->remedy),
        );
    }

    public static function default(SecretRequirement $requirement): self
    {
        return new self(
            $requirement,
            self::DEFAULT_VALUE,
            sprintf(
                '%s is still set to a sample or placeholder value. %s',
                $requirement->name,
                $requirement->remedy,
            ),
        );
    }

    public function failed(): bool
    {
        return $this->state !== self::OK;
    }

    /**
     * @return array{name: string, label: string, state: string, generatable: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return [
            'name' => $this->requirement->name,
            'label' => $this->requirement->label,
            'state' => $this->state,
            'generatable' => $this->requirement->generatable,
            'reason' => $this->reason,
        ];
    }
}
