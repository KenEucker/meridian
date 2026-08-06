<?php

declare(strict_types=1);

namespace App\Domain\Audit;

/**
 * How much of what happens an organization writes down (data/API 14.1).
 *
 * Five levels, ordered, and the order is what matters: an action is written
 * when the organization's level is at or above the level the action is
 * catalogued at. That is the whole rule, and it is why the levels are integers
 * rather than a set of flags — an operator moving the control one notch should
 * be able to predict what they gain, and a set of flags cannot promise that.
 *
 * `Minimal` is not "nothing". The floor is what requirements 2.4 and data/API
 * section 8 require to be recorded, and no level, override, or configuration
 * reaches below it — see {@see AuditActionCatalog::REQUIRED}. An organization
 * that sets Minimal is saying "record what we are obliged to record and no
 * more", which is a legitimate answer for a small organization running one
 * event a year, and it is emphatically not the same as switching auditing off.
 */
enum AuditVerbosity: string
{
    /** The required floor and nothing else. */
    case Minimal = 'minimal';

    /** The floor, plus the decisions that change somebody's standing. */
    case Low = 'low';

    /** Everything an organizer would ask about after the fact. */
    case Standard = 'standard';

    /** Adds routine operational work — attendance, equipment, deployments. */
    case Detailed = 'detailed';

    /** Everything the application records, including sensitive reads. */
    case Complete = 'complete';

    /**
     * The documented default for an organization that has not chosen.
     *
     * Complete, which is exactly what Meridian recorded before this setting
     * existed. An earlier draft defaulted to Standard on the reasoning that
     * Complete is the noisiest setting and nobody should pay for it by
     * accident — which quietly stopped recording attendance, equipment,
     * deployments, and shift assignments for every organization that had never
     * heard of the control. A default that reduces the record is the wrong
     * default for an audit feature: the organization that most needs the
     * history is the one that never thought about the setting.
     *
     * So this is opt-in. Volume is a real problem and the levels are how an
     * organization addresses it, deliberately, having looked at what it is
     * currently storing on the God Mode screen that offers the choice.
     */
    public static function default(): self
    {
        return self::Complete;
    }

    public static function fromValue(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * The rank used to compare a level against a catalogued action.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Minimal => 0,
            self::Low => 1,
            self::Standard => 2,
            self::Detailed => 3,
            self::Complete => 4,
        };
    }

    public function includes(self $level): bool
    {
        return $this->rank() >= $level->rank();
    }

    public function label(): string
    {
        return match ($this) {
            self::Minimal => 'Minimal',
            self::Low => 'Low',
            self::Standard => 'Standard',
            self::Detailed => 'Detailed',
            self::Complete => 'Complete',
        };
    }

    /**
     * What an operator is choosing, in the terms they are choosing it in.
     */
    public function description(): string
    {
        return match ($this) {
            self::Minimal => 'Only what Meridian is required to record: permission and status changes, credential revocations, hour corrections, credit calculations, node and device trust, and sync resolution.',
            self::Low => 'The required record, plus the decisions that change what somebody may do — applications, team grants, designations, and staff administration.',
            self::Standard => 'The required record and every governance and administration change. What an organizer would ask about after the event.',
            self::Detailed => 'Everything above, plus routine operational work: attendance, equipment handling, deployments, and training completions.',
            self::Complete => 'Everything Meridian records, including who viewed an incident and who exported what. The most complete record and much the largest.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string, rank: int}>
     */
    public static function options(): array
    {
        return array_map(static fn (self $level): array => [
            'value' => $level->value,
            'label' => $level->label(),
            'description' => $level->description(),
            'rank' => $level->rank(),
        ], self::cases());
    }
}
