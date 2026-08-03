<?php

declare(strict_types=1);

namespace App\Services\Status;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\HoursWorked;
use App\Models\Organization;
use App\Models\StaffOrganizationStatus;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;

/**
 * Applies the organization staff lifecycle thresholds on a schedule (M18.15;
 * ORG-019; STAT-009, STAT-011).
 *
 * Two thresholds, both configured per organization in years (M18.14) and both
 * resolving to the same transition — Inactive, through the same
 * {@see StaffStatusService} path a person would use, with an audit entry per
 * transition so the trail reads the same whether a scheduler or an organizer
 * moved the status. An organization with no threshold configured evaluates
 * nothing: null means the organization has not adopted the rule, not that the
 * rule has a default.
 *
 * **Prospective** counts from when the status was last set. STAT-011 says
 * Prospective lasts for the configured number of years and then becomes
 * Inactive; the person has never worked, so there is no activity to count
 * from.
 *
 * **Active** counts from the staff member's last recorded activity: the later
 * of when the status was set and the end of their most recent recorded hours
 * in the organization. Counting from the status change alone would retire
 * somebody who worked last month because their status turned Active six years
 * ago, which is not what "inactivity threshold" means to anyone reading it.
 *
 * **STAT-009** is honored by skipping, not by erroring. A staff member with an
 * active department membership cannot go inactive at the organization level;
 * the evaluator leaves them alone and counts them, rather than tripping the
 * refusal {@see StaffStatusService::transitionOrganizationStatus} would throw.
 *
 * **Idempotent** because due-ness is derived from the record: a transition
 * moves the status to Inactive, an Inactive record matches neither query, and
 * a second run over the same data does nothing and audits nothing.
 */
final class LifecycleThresholdEvaluationService
{
    public function __construct(
        private readonly StaffStatusService $statuses,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return array{prospective_expired: int, active_expired: int, skipped_for_department_work: int}
     */
    public function evaluate(?Carbon $asOf = null): array
    {
        $asOf ??= Carbon::now();

        $result = [
            'prospective_expired' => 0,
            'active_expired' => 0,
            'skipped_for_department_work' => 0,
        ];

        $organizations = Organization::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNotNull('prospective_inactive_threshold_years')
                    ->orWhereNotNull('active_inactive_threshold_years');
            })
            ->get();

        foreach ($organizations as $organization) {
            $this->evaluateOrganization($organization, $asOf, $result);
        }

        return $result;
    }

    /**
     * @param  array{prospective_expired: int, active_expired: int, skipped_for_department_work: int}  $result
     */
    private function evaluateOrganization(Organization $organization, Carbon $asOf, array &$result): void
    {
        $prospectiveYears = $organization->prospective_inactive_threshold_years;
        $activeYears = $organization->active_inactive_threshold_years;

        $records = StaffOrganizationStatus::query()
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', array_filter([
                $prospectiveYears !== null ? StaffOrganizationStatus::STATUS_PROSPECTIVE : null,
                $activeYears !== null ? StaffOrganizationStatus::STATUS_ACTIVE : null,
            ]))
            ->with(['staff', 'organization'])
            ->get();

        foreach ($records as $record) {
            $due = match ($record->status) {
                StaffOrganizationStatus::STATUS_PROSPECTIVE => $this->prospectiveDue($record, (int) $prospectiveYears, $asOf),
                StaffOrganizationStatus::STATUS_ACTIVE => $this->activeDue($record, (int) $activeYears, $asOf),
                default => false,
            };

            if (! $due) {
                continue;
            }

            if ($this->hasActiveDepartmentWork($record)) {
                // STAT-009: working for any department prevents organization
                // inactivity, so this record is not due — it is protected.
                $result['skipped_for_department_work']++;

                continue;
            }

            $wasProspective = $record->status === StaffOrganizationStatus::STATUS_PROSPECTIVE;

            $this->transition($record, $wasProspective, $asOf);

            $result[$wasProspective ? 'prospective_expired' : 'active_expired']++;
        }
    }

    private function prospectiveDue(StaffOrganizationStatus $record, int $thresholdYears, Carbon $asOf): bool
    {
        $since = $record->status_changed_at ?? $record->created_at;

        return $since !== null
            && Carbon::instance($since)->addYears($thresholdYears)->lessThanOrEqualTo($asOf);
    }

    private function activeDue(StaffOrganizationStatus $record, int $thresholdYears, Carbon $asOf): bool
    {
        $since = $record->status_changed_at ?? $record->created_at;

        if ($since === null) {
            return false;
        }

        $lastActivity = Carbon::instance($since);
        $lastWorked = $this->lastRecordedHoursEnd($record);

        if ($lastWorked !== null && $lastWorked->greaterThan($lastActivity)) {
            $lastActivity = $lastWorked;
        }

        return $lastActivity->addYears($thresholdYears)->lessThanOrEqualTo($asOf);
    }

    /**
     * The end of this staff member's most recent recorded hours in the
     * organization, through the events the hours were worked at.
     */
    private function lastRecordedHoursEnd(StaffOrganizationStatus $record): ?Carbon
    {
        $endedAt = HoursWorked::query()
            ->where('staff_id', $record->staff_id)
            ->whereHas('event', function ($query) use ($record): void {
                $query->where('organization_id', $record->organization_id);
            })
            ->max('actual_ended_at');

        return $endedAt === null ? null : Carbon::parse($endedAt);
    }

    private function hasActiveDepartmentWork(StaffOrganizationStatus $record): bool
    {
        return DepartmentMembership::query()
            ->where('staff_id', $record->staff_id)
            ->where('status', DepartmentMembership::STATUS_ACTIVE)
            ->whereNull('archived_at')
            ->whereHas('department', function ($query) use ($record): void {
                $query->where('organization_id', $record->organization_id);
            })
            ->exists();
    }

    private function transition(StaffOrganizationStatus $record, bool $wasProspective, Carbon $asOf): void
    {
        $before = $this->snapshot($record);

        $record = $this->statuses->transitionOrganizationStatus(
            $record,
            StaffOrganizationStatus::STATUS_INACTIVE,
            $wasProspective
                ? 'Prospective status expired after the configured inactivity threshold.'
                : 'Active status lapsed after the configured inactivity threshold.',
            null,
            $asOf,
        );

        $this->audit->recordForEntity(
            entity: $record,
            action: 'staff_organization_status.changed',
            actorUser: null,
            organizationId: (string) $record->organization_id,
            before: $before,
            after: $this->snapshot($record),
            reason: $record->status_reason,
            sourceContext: AuditEvent::SOURCE_SYSTEM,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(StaffOrganizationStatus $record): array
    {
        return [
            'status' => $record->status,
            'status_reason' => $record->status_reason,
            'status_changed_at' => $record->status_changed_at?->toISOString(),
            'status_changed_by_user_id' => $record->status_changed_by_user_id,
        ];
    }
}
