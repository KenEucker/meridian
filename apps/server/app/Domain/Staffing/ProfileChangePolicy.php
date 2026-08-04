<?php

declare(strict_types=1);

namespace App\Domain\Staffing;

/**
 * How an organization decides handle and profile picture changes (VOL-027).
 *
 * Handles and pictures carry this setting separately, because they are
 * different kinds of fact about a person. A handle is spoken on a radio and
 * has to be unambiguous across the whole organization; a picture is how a desk
 * recognises somebody. An organization may reasonably want one locked down and
 * the other open.
 *
 * Every mode is a pair of answers to two questions, and the four modes are the
 * four combinations that are worth having:
 *
 * | Mode                    | Staff may propose a first value | Staff changes apply without review |
 * |-------------------------|---------------------------------|------------------------------------|
 * | `organizer_only`        | yes, reviewed                   | no                                 |
 * | `organizer_sets_first`  | no — an organizer sets it       | no                                 |
 * | `auto_approved`         | yes, applies now                | yes, up to the allowance           |
 * | `staff_sets_first`      | yes, applies now                | no                                 |
 *
 * `organizer_only` is the default, and is the behaviour every organization has
 * today. It is the strictest of the four: a staff member may still ask for
 * anything, and nothing they ask for takes effect until somebody says so.
 *
 * The allowance in the `auto_approved` row is VOL-028's configurable number and
 * applies to handles alone. A picture under `auto_approved` is not rationed:
 * the reason a handle is rationed is that other people memorise it and a person
 * who changes theirs weekly is a person nobody can call on the radio, and
 * nothing about a picture works that way.
 */
enum ProfileChangePolicy: string
{
    /** Every change is reviewed, including the first. The default. */
    case OrganizerOnly = 'organizer_only';

    /** An organizer sets the first value; staff changes after it are reviewed. */
    case OrganizerSetsFirst = 'organizer_sets_first';

    /** Staff changes apply immediately, up to the allowance for handles. */
    case AutoApproved = 'auto_approved';

    /** Staff set the first value themselves; every change after it is reviewed. */
    case StaffSetsFirst = 'staff_sets_first';

    public static function default(): self
    {
        return self::OrganizerOnly;
    }

    /**
     * Resolve a stored or submitted value, falling back to the default.
     *
     * A row written before this setting existed holds null, and a null is the
     * documented default rather than a missing answer.
     */
    public static function resolve(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::default();
    }

    /**
     * Whether a staff member may propose a value where their record holds none.
     *
     * False only for `organizer_sets_first`, which is the mode that exists to
     * say an organization issues the first handle or picture rather than
     * accepting one. The refusal is worth wording carefully wherever it
     * surfaces: the person is not being told no, they are being told to wait.
     */
    public function allowsStaffFirstValue(): bool
    {
        return $this !== self::OrganizerSetsFirst;
    }

    /**
     * Whether a first value a staff member proposes takes effect immediately.
     *
     * Under `organizer_only` it does not: that mode reviews everything, which
     * is what distinguishes it from `staff_sets_first`.
     */
    public function appliesFirstValueWithoutReview(): bool
    {
        return $this === self::AutoApproved || $this === self::StaffSetsFirst;
    }

    /**
     * Whether changing an existing value can apply without review at all.
     *
     * True only for `auto_approved`, and even there it is bounded for handles
     * by the organization's allowance (VOL-028). The other three modes review
     * every change to a value that already exists — which is the common case
     * the whole request path was built for.
     */
    public function allowsSelfServiceChanges(): bool
    {
        return $this === self::AutoApproved;
    }

    /** How this mode reads on a configuration surface. */
    public function label(): string
    {
        return match ($this) {
            self::OrganizerOnly => 'Approved by organizers',
            self::OrganizerSetsFirst => 'Organizer sets the first one, later changes reviewed',
            self::AutoApproved => 'Applied without review',
            self::StaffSetsFirst => 'Staff set the first one, later changes reviewed',
        };
    }

    /**
     * What this mode means for the people it governs, in the words a
     * configuration surface shows beside the choice.
     */
    public function description(string $noun): string
    {
        return match ($this) {
            self::OrganizerOnly => "A staff member may submit a {$noun} at any time and an organizer decides every one, including their first.",
            self::OrganizerSetsFirst => "An organizer sets a staff member's first {$noun}. After that the staff member may ask to change it, and an organizer decides.",
            self::AutoApproved => "A staff member sets their own {$noun} and it takes effect immediately.",
            self::StaffSetsFirst => "A staff member sets their own first {$noun} with no review. Every change after that is decided by an organizer.",
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
