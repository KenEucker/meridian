<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * The dashboard attention scale (UI contract 9.9; dashboard widget spec 5;
 * M18.28).
 *
 * How urgently the interface should draw attention to a condition, and nothing
 * else. It is deliberately a separate vocabulary from IMS priority, which
 * describes how serious an incident is, and from incident state, which describes
 * where an incident is in its workflow. The three are distinct in the contract
 * and stay distinct here: an `ic.active_incidents` widget carries an attention
 * level about the widget and reports incident priorities inside it, and neither
 * value is derived from the other.
 *
 * Attention is never communicated by color alone (widget spec 5). The value
 * travels as a word, so what a surface renders from it is a label first.
 */
enum DashboardAttention: string
{
    /** Useful information, no action required. */
    case Routine = 'routine';

    /** The user should review this soon. */
    case Attention = 'attention';

    /** An operational issue that needs action. */
    case Warning = 'warning';

    /** An urgent operational issue. */
    case Critical = 'critical';

    /** A security-sensitive or high-impact state. */
    case Restricted = 'restricted';

    /**
     * Where this level sorts in the mobile priority feed, most urgent first
     * (widget spec 8).
     *
     * `Restricted` sorts above `Critical` because it is the one level that is
     * about who may see a thing rather than how bad it is, and burying it under
     * a busy incident list is how a restricted state goes unnoticed.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Restricted => 0,
            self::Critical => 1,
            self::Warning => 2,
            self::Attention => 3,
            self::Routine => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Routine => 'Routine',
            self::Attention => 'Attention',
            self::Warning => 'Warning',
            self::Critical => 'Critical',
            self::Restricted => 'Restricted',
        };
    }
}
