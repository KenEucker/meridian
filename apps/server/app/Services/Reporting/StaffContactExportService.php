<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventDepartmentAssignment;
use App\Models\StaffOrganizationStatus;
use App\Models\TeamMembership;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates and audits the department staff contact export (M13.3; REPORT-003,
 * REPORT-006 through REPORT-010; VOL-009, VOL-011, VOL-012; technical spec
 * 22.2).
 *
 * The export answers "who is in this department and how do I reach them": one
 * row per active department membership in the event's participating
 * departments, so a lead can print their own department's call list and an
 * organizer can pull the event's.
 *
 * This is the only Alpha 1 export permitted to carry emergency contacts, and
 * only for a department the caller actually leads. REPORT-009 and VOL-012 give
 * department leads and department administration emergency contact access for
 * their own staff; REPORT-010 and VOL-011 withhold it from organizers, who hold
 * no default access to emergency contacts at all. The two columns are therefore
 * added to the file only when the scope covers nothing but the caller's own
 * departments, rather than written blank for everyone else: a blank emergency
 * contact must keep meaning "none recorded" to the lead reading it, not "you
 * were not allowed to see it".
 *
 * Phone numbers are a different matter. REPORT-008 excludes them from shift
 * rosters specifically, and nothing withholds them from an organizer's staff
 * contact list, which would otherwise not be a contact list at all.
 *
 * Membership and organization statuses are reported as the domain recorded
 * them rather than filtered out, so a lead can see that someone on the list is
 * Inactive or Ineligible instead of wondering why they are missing from it.
 */
final class StaffContactExportService implements ReportingExportGenerator
{
    /**
     * Column order for the generated file. The header is part of the contract
     * the committed sample fixtures pin.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'event_name',
        'department',
        'department_code',
        'teams',
        'staff_legal_name',
        'staff_preferred_name',
        'staff_handle',
        'staff_email',
        'staff_phone',
        'department_membership_status',
        'organization_status',
    ];

    /**
     * Columns appended for a caller who leads every department in the export
     * (REPORT-009, VOL-012).
     *
     * @var list<string>
     */
    public const EMERGENCY_CONTACT_COLUMNS = [
        'emergency_contact_name',
        'emergency_contact_phone',
    ];

    public function __construct(private readonly AuditService $audit) {}

    public function export(
        Event $event,
        ReportingExportScope $scope,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): ReportingExport {
        $exportedAt = now()->utc();
        $departmentIds = $scope->departmentFilter();
        $withEmergencyContacts = $scope->coversOnlyOwnDepartments();
        $columns = $withEmergencyContacts
            ? [...self::COLUMNS, ...self::EMERGENCY_CONTACT_COLUMNS]
            : self::COLUMNS;

        $memberships = $this->membershipsInScope($event, $scope);
        $organizationStatuses = $this->organizationStatuses($event, $memberships);

        $rows = $memberships
            ->map(fn (DepartmentMembership $membership): array => $this->row(
                $event,
                $membership,
                $organizationStatuses[(string) $membership->staff_id] ?? '',
                $withEmergencyContacts,
            ))
            ->values()
            ->all();

        $export = new ReportingExport(
            contents: ReportingExportFile::csv($columns, $rows),
            filename: ReportingExportFile::filename('staff-contact', $event, $scope, $exportedAt->format('Ymd-His')),
            rowCount: count($rows),
            exportedAt: $exportedAt,
        );

        // Exports are sensitive reads: who pulled which contact list, for which
        // event, how wide the scope was, and whether emergency contacts went
        // with it (data/API section 8).
        $this->audit->recordForEntity(
            entity: $event,
            action: 'event_staff_contact.exported',
            actorUser: $actor,
            organizationId: (string) $event->organization_id,
            eventId: (string) $event->id,
            departmentId: count($departmentIds) === 1 ? $departmentIds[0] : null,
            after: [
                'format' => $export->format,
                'scope' => $scope->isEventWide() ? 'event' : 'department',
                'department_ids' => $departmentIds,
                'emergency_contacts_included' => $withEmergencyContacts,
                'row_count' => $export->rowCount,
                'exported_at' => $export->exportedAt->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return $export;
    }

    /**
     * Active department memberships the caller may see, ordered by department
     * and then by staff legal name so the file reads as a printed call list.
     * The final key is the membership id so two exports of unchanged data
     * produce the same file.
     *
     * Only departments actively assigned to the event are exported. Department
     * membership is an organization-level record, so without that intersection
     * an event export would list staff of departments that are not working the
     * event at all.
     *
     * @return Collection<int, DepartmentMembership>
     */
    private function membershipsInScope(Event $event, ReportingExportScope $scope): Collection
    {
        $departmentIds = EventDepartmentAssignment::query()
            ->active()
            ->where('event_id', $event->id)
            ->pluck('department_id')
            ->map(static fn ($departmentId): string => (string) $departmentId)
            ->all();

        $filter = $scope->departmentFilter();

        if ($filter !== []) {
            $departmentIds = array_values(array_intersect($departmentIds, $filter));
        }

        if ($departmentIds === []) {
            return collect();
        }

        return DepartmentMembership::query()
            ->active()
            ->whereIn('department_id', $departmentIds)
            ->with(['department', 'staff', 'teamMemberships.team'])
            ->get()
            ->sortBy(fn (DepartmentMembership $membership): string => implode('|', [
                Str::lower((string) $membership->department?->name),
                Str::lower((string) $membership->staff?->legal_name),
                (string) $membership->id,
            ]))
            ->values();
    }

    /**
     * Organization-level status per staff member for the event's organization,
     * so a Do Not Staff or Inactive person on a department list is visible as
     * such rather than silently indistinguishable from an active member.
     *
     * @param  Collection<int, DepartmentMembership>  $memberships
     * @return array<string, string>
     */
    private function organizationStatuses(Event $event, Collection $memberships): array
    {
        $staffIds = $memberships->pluck('staff_id')->unique()->all();

        if ($staffIds === []) {
            return [];
        }

        return StaffOrganizationStatus::query()
            ->where('organization_id', $event->organization_id)
            ->whereIn('staff_id', $staffIds)
            ->get(['staff_id', 'status'])
            ->mapWithKeys(static fn (StaffOrganizationStatus $status): array => [
                (string) $status->staff_id => (string) $status->status,
            ])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    private function row(
        Event $event,
        DepartmentMembership $membership,
        string $organizationStatus,
        bool $withEmergencyContacts,
    ): array {
        $staff = $membership->staff;

        $row = [
            'event_name' => (string) $event->name,
            'department' => (string) ($membership->department?->name ?? ''),
            'department_code' => (string) ($membership->department?->code ?? ''),
            'teams' => $this->teamNames($membership),
            'staff_legal_name' => (string) ($staff?->legal_name ?? ''),
            'staff_preferred_name' => (string) ($staff?->preferred_name ?? ''),
            'staff_handle' => (string) ($staff?->handle ?? ''),
            'staff_email' => (string) ($staff?->email ?? ''),
            'staff_phone' => (string) ($staff?->phone ?? ''),
            'department_membership_status' => (string) $membership->status,
            'organization_status' => $organizationStatus,
        ];

        if (! $withEmergencyContacts) {
            return $row;
        }

        return [
            ...$row,
            'emergency_contact_name' => (string) ($staff?->emergency_contact_name ?? ''),
            'emergency_contact_phone' => (string) ($staff?->emergency_contact_phone ?? ''),
        ];
    }

    /**
     * The staff member's current teams within this department, so a lead
     * calling down the list knows which team the person works on. Archived team
     * memberships are past assignments and are left out.
     */
    private function teamNames(DepartmentMembership $membership): string
    {
        $names = $membership->teamMemberships
            ->reject(static fn (TeamMembership $teamMembership): bool => $teamMembership->isArchived())
            ->map(static fn (TeamMembership $teamMembership): string => (string) $teamMembership->team?->name)
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return implode('; ', $names);
    }
}
