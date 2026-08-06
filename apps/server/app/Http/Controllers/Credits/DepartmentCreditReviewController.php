<?php

declare(strict_types=1);

namespace App\Http\Controllers\Credits;

use App\Domain\Permissions\PermissionCatalog;
use App\Http\Controllers\Controller;
use App\Models\CreditLedgerEntry;
use App\Models\Department;
use App\Models\Event;
use App\Models\HoursWorked;
use App\Services\Attendance\HoursCorrectionWindow;
use App\Services\Reporting\CreditsEarnedExportService;
use App\Services\Reporting\ReportingExportAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * `department.credits` — credit review for one department at one event
 * (M18.30; UI contract 12.4; CREDIT-004, CREDIT-005; REPORT-005, REPORT-007).
 *
 * The credits earned export has existed since M13.6 and answers the same
 * question in a file. This is the reading somebody does before they download
 * one, and before they have to explain a number to the person who earned it:
 * what each staff member of this department was credited at this event, and the
 * arithmetic behind every line of it.
 *
 * **Authority is the export's, deliberately.** `reports.credits_earned.export`
 * resolves through {@see ReportingExportAccess} and the resolved scope is
 * narrowed to the department in the route, so a department lead reads their own
 * department (REPORT-007) and an organizer reads any department of their own
 * organization's event (REPORT-006). Reading the ledger and exporting it are
 * the same rows and the same disclosure, and inventing a second capability for
 * the screen would have meant two answers to one question — plus a way to grant
 * somebody the page without the file, or the file without the page, neither of
 * which any requirement asks for. It is not the authority to *change* anything:
 * calculation answers to `organization.credit_policies.manage` and stays with
 * organizers (ORG-010), which is why nothing here writes.
 *
 * **Every number comes from the frozen entry.** The rate, the policy name, and
 * its source are read from `calculation_basis` rather than from the policy row
 * the entry names, because a policy may be renamed or re-rated afterwards
 * (CREDIT-004) and this page has to keep showing what the work was actually
 * credited at. That is the same rule {@see CreditsEarnedExportService}
 * follows, for the same reason.
 *
 * **What is not yet credited is reported as such.** Hours the department has
 * worked that carry no ledger entry are counted, with the grace period close
 * beside them, so a lead reading a total that looks low is told why rather than
 * left to conclude the event under-credited them. Hours resolving to no policy
 * are not credited at all and would otherwise be invisible here.
 */
final class DepartmentCreditReviewController extends Controller
{
    public function __invoke(
        Request $request,
        Event $event,
        Department $department,
        ReportingExportAccess $access,
        HoursCorrectionWindow $correctionWindow,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if ((string) $department->organization_id !== (string) $event->organization_id) {
            return response()->json(['message' => 'Department not found for this event.'], 404);
        }

        $scope = $access->resolve(
            $user,
            $event,
            PermissionCatalog::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
        );

        if ($scope === null || ! $scope->includesDepartment((string) $department->getKey())) {
            return response()->json([
                'message' => 'You do not have permission to review credits for this department.',
            ], 403);
        }

        $entries = $this->entriesFor($event, $department);

        return response()->json([
            'context' => [
                'event_id' => (string) $event->getKey(),
                'event_label' => $event->name,
                'department_id' => (string) $department->getKey(),
                'department_label' => $department->name,
            ],
            'access' => [
                // Whether this reader came by their authority as an organizer
                // or through this department, which is what decides whether the
                // export they run next covers the event or only this department.
                'organization_wide' => $scope->organizationWide,
                'can_export' => true,
            ],
            'totals' => [
                'entry_count' => $entries->count(),
                'staff_count' => $entries->pluck('staff_id')->unique()->count(),
                'hours' => $this->total($entries, 'hours'),
                'credits' => $this->total($entries, 'credits'),
            ],
            'outstanding' => $this->outstanding($event, $department, $correctionWindow),
            'staff' => $this->staffTotals($entries),
            'entries' => $entries
                ->map(fn (CreditLedgerEntry $entry): array => $this->entry($entry))
                ->values()
                ->all(),
        ]);
    }

    /**
     * The department's frozen ledger for this event, earliest shift first.
     *
     * Scoped by `credit_ledger_entries.department_id`, which calculation copies
     * from the hours record it credited, so a renamed or re-snapshotted shift
     * cannot move credits between departments after the fact.
     *
     * @return Collection<int, CreditLedgerEntry>
     */
    private function entriesFor(Event $event, Department $department): Collection
    {
        return CreditLedgerEntry::query()
            ->where('event_id', $event->getKey())
            ->where('department_id', $department->getKey())
            ->with(['staff', 'shift.eligibleTeam'])
            ->get()
            ->sortBy(fn (CreditLedgerEntry $entry): string => implode('|', [
                $entry->shift?->starts_at?->utc()->toIso8601String() ?? '',
                Str::lower((string) $entry->shift?->title),
                Str::lower((string) $entry->staff?->legal_name),
                (string) $entry->getKey(),
            ]))
            ->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(CreditLedgerEntry $entry): array
    {
        $shift = $entry->shift;
        $staff = $entry->staff;

        return [
            'id' => (string) $entry->getKey(),
            'staff_id' => (string) $entry->staff_id,
            'staff_name' => (string) ($staff?->preferred_name ?: $staff?->legal_name ?? ''),
            'staff_handle' => $staff?->handle,
            'shift_title' => $shift?->title,
            'shift_starts_at' => $shift?->starts_at?->toIso8601String(),
            'shift_ends_at' => $shift?->ends_at?->toIso8601String(),
            'team' => $shift?->team_name_snapshot ?? $shift?->eligibleTeam?->name,
            'entry_type' => (string) $entry->entry_type,
            'status' => (string) $entry->status,
            'hours' => (string) $entry->hours,
            'credits' => (string) $entry->credits,
            // The basis, so a reader can multiply the first two and land on the
            // third without opening a policy record (CREDIT-005).
            'credit_policy_name' => $this->basis($entry, 'credit_policy_name'),
            'credit_multiplier' => $this->basis($entry, 'credit_multiplier'),
            'policy_source' => $this->basis($entry, 'policy_source'),
            'minutes_worked' => $this->basis($entry, 'minutes_worked'),
            'calculated_at' => $this->basis($entry, 'calculated_at'),
            'hours_corrected_at' => $this->basis($entry, 'hours_corrected_at'),
            'frozen_at' => $entry->frozen_at?->toIso8601String(),
        ];
    }

    /**
     * Per-staff totals, which is the reading a lead actually does: one line per
     * person, ordered by name, with the shift count behind each number.
     *
     * @param  Collection<int, CreditLedgerEntry>  $entries
     * @return list<array<string, mixed>>
     */
    private function staffTotals(Collection $entries): array
    {
        return $entries
            ->groupBy(fn (CreditLedgerEntry $entry): string => (string) $entry->staff_id)
            ->map(function (Collection $staffEntries, string $staffId): array {
                $staff = $staffEntries->first()?->staff;

                return [
                    'staff_id' => $staffId,
                    'staff_name' => (string) ($staff?->preferred_name ?: $staff?->legal_name ?? ''),
                    'staff_handle' => $staff?->handle,
                    'entry_count' => $staffEntries->count(),
                    'hours' => $this->total($staffEntries, 'hours'),
                    'credits' => $this->total($staffEntries, 'credits'),
                ];
            })
            ->sortBy(fn (array $row): string => Str::lower((string) $row['staff_name']))
            ->values()
            ->all();
    }

    /**
     * What this department has worked that the ledger does not yet answer for.
     *
     * Both counts are honest gaps rather than errors. Hours still open are
     * inside the correction grace period and cannot be credited yet
     * (CREDIT-001); hours frozen and uncredited are either waiting on a
     * calculation run or resolved to no policy at all, and either way the total
     * above them is not the department's final answer.
     *
     * @return array<string, mixed>
     */
    private function outstanding(
        Event $event,
        Department $department,
        HoursCorrectionWindow $correctionWindow,
    ): array {
        $creditedHoursIds = CreditLedgerEntry::query()
            ->calculated()
            ->where('event_id', $event->getKey())
            ->where('department_id', $department->getKey())
            ->pluck('hours_worked_id')
            ->all();

        // Scoped by the hours record's own `department_id`, which is the column
        // calculation copies onto the entry, so "worked here" and "credited
        // here" are answered from the same fact.
        $departmentHours = HoursWorked::query()
            ->where('event_id', $event->getKey())
            ->where('department_id', $department->getKey());

        $openHours = (clone $departmentHours)->whereNull('frozen_at')->count();

        $uncredited = (clone $departmentHours)
            ->whereNotNull('frozen_at')
            ->whereNotIn('id', $creditedHoursIds)
            ->count();

        $closesAt = $correctionWindow->closesAt($event);

        return [
            'open_hours_count' => $openHours,
            'uncredited_hours_count' => $uncredited,
            'grace_closes_at' => $closesAt?->toIso8601String(),
            'grace_closed' => $closesAt !== null && Carbon::now()->greaterThanOrEqualTo($closesAt),
        ];
    }

    /**
     * One value out of an entry's frozen calculation basis.
     *
     * A missing key reads as absent rather than as a substituted live value:
     * the basis is the record of what the entry was calculated from, and
     * filling a gap from the policy row would report a rate the work was not
     * credited at.
     */
    private function basis(CreditLedgerEntry $entry, string $key): ?string
    {
        $basis = $entry->calculation_basis;

        if (! is_array($basis)) {
            return null;
        }

        $value = $basis[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    /**
     * @param  Collection<int, CreditLedgerEntry>  $entries
     */
    private function total(Collection $entries, string $column): string
    {
        $total = round((float) $entries->sum(
            static fn (CreditLedgerEntry $entry): float => (float) $entry->{$column},
        ), 2);

        return number_format($total, 2, '.', '');
    }
}
