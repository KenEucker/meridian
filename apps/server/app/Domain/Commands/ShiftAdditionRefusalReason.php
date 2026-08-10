<?php

namespace App\Domain\Commands;

/**
 * Why the node refused a Logistics unscheduled shift addition, and which of
 * those refusals an authority may override (M18.55; CLIENT-017, CLIENT-017A;
 * technical spec 11A.5; data/API 5.6).
 *
 * Until M18.55 a refusal was a sentence. That was enough while the only thing
 * anybody could do with one was read it and dismiss it. An override has to be
 * decided on rather than read, so the refusal needs a name a rule can be written
 * about — which is what this enum is. The sentence still travels with it and is
 * still what a person is shown; the code is what the allowlist below is keyed
 * on and what the audit entry records.
 *
 * **The allowlist is explicit and short.** Making every refusal negotiable would
 * turn the node's eligibility rules into suggestions, so the default here is
 * that a reason is *not* overridable and each exception is argued for. Three
 * are overridable and three are not, and the line between them is whether the
 * person standing at the desk is in a position to know better than the node:
 *
 *   - **`staff_not_on_site` is overridable.** Presence is the one fact on this
 *     list the node cannot observe and the operator can — they are looking at
 *     the person. M18.54 made the on-site mark an offline write precisely so
 *     this refusal would usually not arise, but it still can: the mark was made
 *     against another department, or was itself refused, or the person walked in
 *     through a gate nobody was at. The override records the operator's own
 *     account of what they saw, which is what the on-site mark is anyway.
 *   - **`not_eligible_team_member` is overridable.** Team eligibility is a
 *     scheduling convenience the department drew for itself, and the authority
 *     overriding it is the authority that drew it. Somebody from the next team
 *     over covering a gap at two in the morning is ordinary work, not a breach.
 *   - **`missing_required_training` is overridable.** A training requirement is
 *     the department's own operational rule, set by the same authority that
 *     holds this capability, and the common field case is a completion recorded
 *     on paper that has not reached the node. The override says a named lead
 *     took responsibility for that judgement, which is exactly the fact a review
 *     afterwards needs.
 *
 * And the three that are not, at any authority:
 *
 *   - **`do_not_staff`** — the plan is explicit and so is the reasoning. It is
 *     an organization's exclusion decision about a person, made deliberately and
 *     recorded at organization scope, and a Logistics desk does not overturn one
 *     at two in the morning. An organization that changed its mind changes the
 *     status.
 *   - **`missing_required_waiver`** — a waiver is executed by the person it
 *     binds. Nobody can sign one on their behalf, so an override would not
 *     produce a waived requirement; it would produce a shift worked with no
 *     waiver on record and no way to obtain one retroactively. This is the
 *     strongest exclusion after `do_not_staff`.
 *   - **`no_department_membership`** — the boundary the overriding authority's
 *     own scope is drawn from. A department lead overriding it would be adding
 *     somebody they hold no authority over, and unlike the three above it has an
 *     ordinary fix that takes seconds: add them to the department.
 *
 * `department_ineligible` is not overridable either, and is left off the
 * argument above because it is the same kind of fact as
 * `no_department_membership` — the membership exists and the organization has
 * marked it unusable — rather than a judgement about tonight.
 *
 * The remaining reasons are not eligibility at all and are deliberately absent
 * from the allowlist: `unauthorized` is the caller's own standing and is not
 * something the caller may waive for themselves, `already_assigned` is not a
 * refusal to work around because the work is already recorded, and
 * `cancelled_shift` and `shift_not_started` describe the shift rather than the
 * person.
 */
enum ShiftAdditionRefusalReason: string
{
    case Unauthorized = 'unauthorized';

    case CancelledShift = 'cancelled_shift';

    case ShiftNotStarted = 'shift_not_started';

    case DoNotStaff = 'do_not_staff';

    case NoDepartmentMembership = 'no_department_membership';

    case DepartmentIneligible = 'department_ineligible';

    case StaffNotOnSite = 'staff_not_on_site';

    case MissingRequiredTraining = 'missing_required_training';

    case MissingRequiredWaiver = 'missing_required_waiver';

    case NotEligibleTeamMember = 'not_eligible_team_member';

    case AlreadyAssigned = 'already_assigned';

    /**
     * Whether a caller holding `department.shift_additions.override` may
     * re-issue an addition this reason refused.
     *
     * Written as a match over every case rather than as membership of a list, so
     * a reason added later cannot default into being overridable by omission.
     */
    public function isOverridable(): bool
    {
        return match ($this) {
            self::StaffNotOnSite,
            self::NotEligibleTeamMember,
            self::MissingRequiredTraining => true,
            self::Unauthorized,
            self::CancelledShift,
            self::ShiftNotStarted,
            self::DoNotStaff,
            self::NoDepartmentMembership,
            self::DepartmentIneligible,
            self::MissingRequiredWaiver,
            self::AlreadyAssigned => false,
        };
    }

    /**
     * The overridable reasons, for the client that decides whether to offer the
     * control and for the tests that assert the list has not quietly grown.
     *
     * @return list<string>
     */
    public static function overridableCodes(): array
    {
        return array_values(array_map(
            fn (self $reason): string => $reason->value,
            array_filter(self::cases(), fn (self $reason): bool => $reason->isOverridable()),
        ));
    }

    public static function tryFromCode(?string $code): ?self
    {
        return $code === null ? null : self::tryFrom($code);
    }
}
