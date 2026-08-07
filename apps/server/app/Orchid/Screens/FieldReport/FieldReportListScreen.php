<?php

declare(strict_types=1);

namespace App\Orchid\Screens\FieldReport;

use App\Models\FieldReport;
use App\Models\User;
use App\Orchid\Layouts\FieldReport\FieldReportListLayout;
use App\Orchid\Layouts\ScopeFiltersLayout;
use App\Services\FieldReports\FieldReportVisibilityAccess;
use Illuminate\Http\Request;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * Field Report repair visibility (M18.34; UI contract 12.9; technical spec
 * 22.2, 22.3; FR-004 through FR-007).
 *
 * Read-only, and unusually so for a God Mode screen: the console reaches this
 * table under the *product's* visibility rules rather than under console
 * standing. `platform.field-reports` decides whether the screen opens at all;
 * {@see FieldReportVisibilityAccess} decides which rows are in it, exactly as
 * it does for `staff.field-reports`.
 *
 * That is deliberately different from the audit trail beside it, and the
 * difference is what the two records are. An audit row says a report was
 * submitted, by whom, and when — history about a change, which repair work
 * needs and ORG-015 does not govern. A Field Report is somebody's own account
 * of something that happened to them, and FR-005 and FR-006 restrict who reads
 * it to its author, whoever took it down for them, and Incident Command for
 * that event. A console permission is not one of those three, and a screen that
 * ignored them would be a way around FR-005 rather than a way into a broken
 * record.
 *
 * Nothing here writes. Technical spec 22.3 is explicit that God Mode cannot
 * edit a finalized original body and provides no append or redaction workflow
 * in Alpha 1, and {@see FieldReport} throws on update and delete besides.
 */
class FieldReportListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(Request $request, FieldReportVisibilityAccess $visibility): iterable
    {
        /** @var User $user */
        $user = $request->user();

        $reports = FieldReport::query()
            ->with(['event', 'department', 'team', 'submittedByUser', 'staff.users']);

        return [
            'reports' => $visibility
                ->constrainToVisible($reports, $user)
                ->filters($this->scopeFilters()->filters())
                ->filters()
                ->defaultSort('created_at', 'desc')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Field Reports';
    }

    public function description(): ?string
    {
        return 'Break-glass visibility into submitted Field Reports. Reports are immutable and nothing here edits, appends to, or redacts one. Which reports appear follows the same rule the product does: the ones you authored or took down, plus every report of an event you hold Incident Command visibility for. Console access alone shows none.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.field-reports',
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            FieldReportListLayout::class,
        ];
    }

    /**
     * Organization / department / team narrowing shared by the query and the
     * rendered controls, so what is displayed and what is applied cannot drift.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(FieldReport::class);
    }
}
