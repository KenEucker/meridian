<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\DepartmentMembership;
use App\Models\Event;
use App\Models\EventCredential;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Credential\CredentialEligibilityService;
use App\Services\Credential\CredentialRevocationService;
use App\Services\Credential\CredentialStatusReasons;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Generates and audits the event credential eligibility export (M13.1;
 * REPORT-001, REPORT-006 through REPORT-008, REPORT-010; CRED-001 through
 * CRED-014; technical spec 22.2).
 *
 * The export reports credential state as the domain already recorded it. It
 * never recalculates eligibility, so a file handed to a gate coordinator says
 * exactly what {@see CredentialEligibilityService} and
 * {@see CredentialRevocationService} last decided, including a revoked
 * credential that recalculation deliberately leaves alone.
 *
 * Sensitive fields are excluded by construction: no phone number, no emergency
 * contact, and no date of birth reaches the file, so an organizer export cannot
 * carry emergency contacts (REPORT-008, REPORT-010) and an age-related block is
 * conveyed by its reason rather than by exporting a birth date. Contact details
 * belong to {@see StaffContactExportService}, which is the one export
 * REPORT-009 lets carry them, and only for a department the caller leads.
 */
final class CredentialEligibilityExportService implements ReportingExportGenerator
{
    /**
     * Column order for the generated file. The header is part of the contract
     * the committed sample fixture pins, so a spreadsheet built against one
     * export keeps working against the next.
     *
     * @var list<string>
     */
    public const COLUMNS = [
        'event_name',
        'staff_legal_name',
        'staff_preferred_name',
        'staff_handle',
        'staff_email',
        'departments',
        'credential_status',
        'status_reason',
        'status_reason_label',
        'credential_shift_count',
        'credential_updated_at',
        'revoked_at',
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly CredentialEligibilityService $eligibility,
    ) {}

    public function export(
        Event $event,
        ReportingExportScope $scope,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): ReportingExport {
        $exportedAt = now()->utc();
        $departmentIds = $scope->departmentFilter();
        $credentials = $this->credentialsInScope($event, $scope);
        $shiftDetails = $this->credentialCountingShiftDetails($event, $credentials->pluck('staff_id')->all());

        $rows = $credentials
            ->map(function (EventCredential $credential) use ($event, $shiftDetails): array {
                $details = $shiftDetails[(string) $credential->staff_id] ?? ['count' => 0, 'departments' => []];

                return $this->row($event, $credential, $details['count'], $details['departments']);
            })
            ->values()
            ->all();

        $export = new ReportingExport(
            contents: ReportingExportFile::csv(self::COLUMNS, $rows),
            filename: ReportingExportFile::filename('credential-eligibility', $event, $scope, $exportedAt->format('Ymd-His')),
            rowCount: count($rows),
            exportedAt: $exportedAt,
        );

        // Exports are sensitive reads: who pulled which staff list, for which
        // event, and how wide the scope was (data/API section 8).
        $this->audit->recordForEntity(
            entity: $event,
            action: 'event_credential_eligibility.exported',
            actorUser: $actor,
            organizationId: (string) $event->organization_id,
            eventId: (string) $event->id,
            departmentId: count($departmentIds) === 1 ? $departmentIds[0] : null,
            after: [
                'format' => $export->format,
                'scope' => $scope->isEventWide() ? 'event' : 'department',
                'department_ids' => $departmentIds,
                'row_count' => $export->rowCount,
                'exported_at' => $export->exportedAt->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return $export;
    }

    /**
     * Credentials the caller may see, ordered so two exports of unchanged data
     * produce the same file.
     *
     * A department-scoped export follows active department membership rather
     * than current shift assignments, so a staff member whose shifts were all
     * removed still appears — Blocked with `no_signed_up_shifts` — which is
     * exactly the row a department lead needs to act on (CRED-010).
     *
     * @return Collection<int, EventCredential>
     */
    private function credentialsInScope(Event $event, ReportingExportScope $scope): Collection
    {
        $query = EventCredential::query()
            ->where('event_id', $event->id)
            ->with('staff');

        $departmentIds = $scope->departmentFilter();

        if ($departmentIds !== []) {
            $query->whereIn('staff_id', DepartmentMembership::query()
                ->active()
                ->whereIn('department_id', $departmentIds)
                ->select('staff_id'));
        }

        return $query
            ->get()
            ->sortBy(fn (EventCredential $credential): string => Str::lower(
                (string) $credential->staff?->legal_name,
            ).'|'.$credential->staff_id)
            ->values();
    }

    /**
     * Credential-counting shift totals and department names per staff member.
     *
     * Counting reuses {@see CredentialEligibilityService::countsTowardCredentialEligibility}
     * so the export agrees with the rule that produced the status: unscheduled
     * work added after a shift started is not counted (CRED-014).
     *
     * Department names come from the shift's snapshot first, so a historical
     * label stays readable after a department is renamed.
     *
     * @param  list<mixed>  $staffIds
     * @return array<string, array{count: int, departments: list<string>}>
     */
    private function credentialCountingShiftDetails(Event $event, array $staffIds): array
    {
        if ($staffIds === []) {
            return [];
        }

        $details = [];

        $assignments = ShiftAssignment::query()
            ->active()
            ->whereIn('staff_id', $staffIds)
            ->whereHas('shift', fn ($query) => $query->where('event_id', $event->id))
            ->with('shift.department')
            ->get()
            ->filter(fn (ShiftAssignment $assignment): bool => $this->eligibility
                ->countsTowardCredentialEligibility($assignment));

        foreach ($assignments as $assignment) {
            $staffId = (string) $assignment->staff_id;
            $details[$staffId] ??= ['count' => 0, 'departments' => []];
            $details[$staffId]['count']++;

            $departmentName = $assignment->shift?->department_name_snapshot
                ?? $assignment->shift?->department?->name;

            if ($departmentName !== null && ! in_array($departmentName, $details[$staffId]['departments'], true)) {
                $details[$staffId]['departments'][] = $departmentName;
            }
        }

        foreach (array_keys($details) as $staffId) {
            sort($details[$staffId]['departments']);
        }

        return $details;
    }

    /**
     * @param  list<string>  $departments
     * @return array<string, string>
     */
    private function row(Event $event, EventCredential $credential, int $shiftCount, array $departments): array
    {
        $staff = $credential->staff;

        return [
            'event_name' => (string) $event->name,
            'staff_legal_name' => (string) ($staff?->legal_name ?? ''),
            'staff_preferred_name' => (string) ($staff?->preferred_name ?? ''),
            'staff_handle' => (string) ($staff?->handle ?? ''),
            'staff_email' => (string) ($staff?->email ?? ''),
            'departments' => implode('; ', $departments),
            'credential_status' => (string) $credential->status,
            'status_reason' => (string) ($credential->status_reason ?? ''),
            'status_reason_label' => CredentialStatusReasons::label($credential->status_reason) ?? '',
            'credential_shift_count' => (string) $shiftCount,
            'credential_updated_at' => $credential->updated_at?->utc()->toIso8601String() ?? '',
            'revoked_at' => $credential->revoked_at?->utc()->toIso8601String() ?? '',
        ];
    }
}
