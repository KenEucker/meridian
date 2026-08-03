<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Node\EventAuthorityException;
use Illuminate\Support\Facades\DB;

/**
 * Edits the organization values that govern staff lifecycle and operational
 * timing (M18.14; ORG-017, ORG-018, ORG-020, ORG-021; data/API 10.1).
 *
 * The columns predate this service — thresholds, calendar year start, and the
 * designation references have sat on `organizations` since the core model
 * milestone — but the only way to set them was a tinker session or God Mode,
 * and ORG-018 says configuration shall not be reachable only through God Mode.
 * This is the product path.
 *
 * The update is partial: a key absent from `$changes` is untouched, a key
 * present with null clears the value. That is what lets the surface save one
 * field without restating the rest, and what keeps the audit entry honest —
 * before and after cover the whole configuration, so the entry shows the
 * fields that moved against the ones that stood still.
 *
 * Referenced records are validated as belonging to the organization and not
 * archived. The calendar year start is refused February 29th: the pair has to
 * name a date that exists in every year it will be applied to.
 */
final class OrganizationConfigurationService
{
    /**
     * Common-year month lengths, for validating the calendar year start.
     *
     * @var array<int, int>
     */
    private const MONTH_LENGTHS = [
        1 => 31, 2 => 28, 3 => 31, 4 => 30, 5 => 31, 6 => 30,
        7 => 31, 8 => 31, 9 => 30, 10 => 31, 11 => 30, 12 => 31,
    ];

    /**
     * The configuration keys an update may carry.
     *
     * @var list<string>
     */
    private const KEYS = [
        'active_inactive_threshold_years',
        'prospective_inactive_threshold_years',
        'calendar_year_start_month',
        'calendar_year_start_day',
        'hours_correction_grace_period_days',
        'default_credit_policy_id',
        'organizers_department_id',
        'default_ic_department_id',
        'default_placement_department_id',
    ];

    public function __construct(
        private readonly OrganizationConfigurationGovernance $governance,
        private readonly AuditService $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $changes  present keys are applied, absent keys are untouched
     *
     * @throws OrganizationConfigurationException
     * @throws EventAuthorityException
     */
    public function update(
        Organization $organization,
        array $changes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Organization {
        $this->governance->assertEditable((string) $organization->getKey());

        $changes = array_intersect_key($changes, array_flip(self::KEYS));

        return DB::transaction(function () use ($organization, $changes, $actor, $sourceContext): Organization {
            $organization = Organization::query()
                ->whereKey($organization->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $values = $this->validated($organization, $changes);

            $before = $this->snapshot($organization);

            $organization->forceFill($values)->save();
            $organization = $organization->refresh();

            $after = $this->snapshot($organization);

            if ($before !== $after) {
                $this->audit->recordForEntity(
                    entity: $organization,
                    action: 'organization.configuration_updated',
                    actorUser: $actor,
                    organizationId: (string) $organization->getKey(),
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $organization;
        });
    }

    /**
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     *
     * @throws OrganizationConfigurationException
     */
    private function validated(Organization $organization, array $changes): array
    {
        $values = [];

        foreach (['active_inactive_threshold_years', 'prospective_inactive_threshold_years'] as $key) {
            if (array_key_exists($key, $changes)) {
                $values[$key] = $this->positiveYearsOrNull($changes[$key], $key);
            }
        }

        if (array_key_exists('hours_correction_grace_period_days', $changes)) {
            $values['hours_correction_grace_period_days'] = $this->gracePeriodDays(
                $changes['hours_correction_grace_period_days'],
            );
        }

        $this->calendarYearStart($organization, $changes, $values);

        if (array_key_exists('default_credit_policy_id', $changes)) {
            $values['default_credit_policy_id'] = $this->organizationCreditPolicyId(
                $organization,
                $changes['default_credit_policy_id'],
            );
        }

        foreach ([
            'organizers_department_id' => 'the Organizers Department designation',
            'default_ic_department_id' => 'the default Incident Command department designation',
            'default_placement_department_id' => 'the default Placement department designation',
        ] as $key => $label) {
            if (array_key_exists($key, $changes)) {
                $values[$key] = $this->organizationDepartmentId($organization, $changes[$key], $label);
            }
        }

        return $values;
    }

    private function positiveYearsOrNull(mixed $value, string $key): ?int
    {
        if ($value === null) {
            return null;
        }

        $years = (int) $value;

        if ($years < 1 || $years > 100) {
            throw OrganizationConfigurationException::invalid(sprintf(
                'The %s threshold must be between 1 and 100 years, or cleared to disable it.',
                $key === 'active_inactive_threshold_years' ? 'Active inactivity' : 'Prospective inactivity',
            ));
        }

        return $years;
    }

    private function gracePeriodDays(mixed $value): int
    {
        if ($value === null) {
            throw OrganizationConfigurationException::invalid(
                'The hours correction grace period cannot be cleared: an organization always has one, and 14 days is the default.',
            );
        }

        $days = (int) $value;

        if ($days < 0 || $days > 365) {
            throw OrganizationConfigurationException::invalid(
                'The hours correction grace period must be between 0 and 365 days after event end.',
            );
        }

        return $days;
    }

    /**
     * Month and day travel together: whichever of the pair an update carries,
     * the resulting pair must be both set naming a real recurring date, or
     * both null.
     *
     * @param  array<string, mixed>  $changes
     * @param  array<string, mixed>  $values
     */
    private function calendarYearStart(Organization $organization, array $changes, array &$values): void
    {
        if (! array_key_exists('calendar_year_start_month', $changes)
            && ! array_key_exists('calendar_year_start_day', $changes)) {
            return;
        }

        $month = array_key_exists('calendar_year_start_month', $changes)
            ? $changes['calendar_year_start_month']
            : $organization->calendar_year_start_month;
        $day = array_key_exists('calendar_year_start_day', $changes)
            ? $changes['calendar_year_start_day']
            : $organization->calendar_year_start_day;

        if ($month === null && $day === null) {
            $values['calendar_year_start_month'] = null;
            $values['calendar_year_start_day'] = null;

            return;
        }

        if ($month === null || $day === null) {
            throw OrganizationConfigurationException::invalid(
                'The calendar year start needs both a month and a day, or neither.',
            );
        }

        $month = (int) $month;
        $day = (int) $day;

        if ($month < 1 || $month > 12) {
            throw OrganizationConfigurationException::invalid(
                'The calendar year start month must be between 1 and 12.',
            );
        }

        // Common-year month lengths, so February 29th — a date that does not
        // exist in most of the years the setting will be applied to — is
        // refused rather than stored.
        if ($day < 1 || $day > self::MONTH_LENGTHS[$month]) {
            throw OrganizationConfigurationException::invalid(sprintf(
                'The calendar year start day must name a date that exists every year; month %d has %d days.',
                $month,
                self::MONTH_LENGTHS[$month],
            ));
        }

        $values['calendar_year_start_month'] = $month;
        $values['calendar_year_start_day'] = $day;
    }

    private function organizationCreditPolicyId(Organization $organization, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $policy = CreditPolicy::query()
            ->whereKey((string) $value)
            ->where('organization_id', (string) $organization->getKey())
            ->first();

        if ($policy === null) {
            throw OrganizationConfigurationException::invalid(
                'The default credit policy must be one of this organization\'s credit policies.',
            );
        }

        if ($policy->archived_at !== null) {
            throw OrganizationConfigurationException::invalid(
                'An archived credit policy cannot be the organization default.',
            );
        }

        return (string) $policy->getKey();
    }

    private function organizationDepartmentId(Organization $organization, mixed $value, string $label): ?string
    {
        if ($value === null) {
            return null;
        }

        $department = Department::query()
            ->whereKey((string) $value)
            ->where('organization_id', (string) $organization->getKey())
            ->first();

        if ($department === null) {
            throw OrganizationConfigurationException::invalid(sprintf(
                'The department for %s must belong to this organization.',
                $label,
            ));
        }

        if ($department->archived_at !== null) {
            throw OrganizationConfigurationException::invalid(sprintf(
                'An archived department cannot carry %s.',
                $label,
            ));
        }

        return (string) $department->getKey();
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Organization $organization): array
    {
        return [
            'id' => (string) $organization->getKey(),
            'active_inactive_threshold_years' => $organization->active_inactive_threshold_years,
            'prospective_inactive_threshold_years' => $organization->prospective_inactive_threshold_years,
            'calendar_year_start_month' => $organization->calendar_year_start_month,
            'calendar_year_start_day' => $organization->calendar_year_start_day,
            'hours_correction_grace_period_days' => $organization->hours_correction_grace_period_days,
            'default_credit_policy_id' => $organization->default_credit_policy_id !== null
                ? (string) $organization->default_credit_policy_id
                : null,
            'organizers_department_id' => $organization->organizers_department_id !== null
                ? (string) $organization->organizers_department_id
                : null,
            'default_ic_department_id' => $organization->default_ic_department_id !== null
                ? (string) $organization->default_ic_department_id
                : null,
            'default_placement_department_id' => $organization->default_placement_department_id !== null
                ? (string) $organization->default_placement_department_id
                : null,
        ];
    }
}
