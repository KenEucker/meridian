<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Incident;

use App\Models\Incident;
use App\Models\User;
use App\Orchid\Layouts\Incident\IncidentListLayout;
use App\Orchid\Layouts\ScopeFiltersLayout;
use App\Services\Incidents\IncidentReadAccess;
use Illuminate\Http\Request;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * Incident repair visibility (M18.34; UI contract 12.9; technical spec 22.2;
 * INC-001; ORG-015).
 *
 * Read-only, and gated twice for the same reason the Field Report screen is:
 * `platform.incidents` decides whether the screen opens, and
 * {@see IncidentReadAccess} decides which incidents are on it, through the same
 * `incidents.view` grant the IMS surfaces resolve. Console access is not
 * Incident Command standing, and an operator holding no such grant sees an
 * empty list rather than the node's incidents.
 *
 * The audit trail beside this screen deliberately draws the line elsewhere: it
 * carries every incident row, because a record of who reopened an incident is
 * history about a change and repair work needs it. This screen is the incident
 * itself — its narrative timeline, who was assigned, where it happened — which
 * is what ORG-015 and the IC gate are about.
 *
 * Repairing an incident, which technical spec 22.3 does allow God Mode to do,
 * is not offered here. This task is the visibility half; a console write path
 * would need the reason capture 22.3 requires of a dangerous action and the
 * timeline entry INC-007 requires of a change, and neither belongs in a screen
 * whose job is to let somebody read what happened.
 */
class IncidentListScreen extends Screen
{
    private ?ScopeFiltersLayout $scopeFilters = null;

    /**
     * @return array<string, mixed>
     */
    public function query(Request $request, IncidentReadAccess $access): iterable
    {
        /** @var User $user */
        $user = $request->user();

        $incidents = Incident::query()->with(['event', 'createdByUser']);

        return [
            'incidents' => $access
                ->constrainToVisible($incidents, $user)
                ->filters($this->scopeFilters()->filters())
                ->filters()
                ->defaultSort('started_at', 'desc')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Incidents';
    }

    public function description(): ?string
    {
        return 'Break-glass visibility into IMS incidents. Nothing here writes: an incident is repaired through the paths that record a timeline entry for the change. Which incidents appear follows the same rule the IMS surfaces do — the events you hold Incident Command visibility for. Console access alone shows none.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.incidents',
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            $this->scopeFilters(),
            IncidentListLayout::class,
        ];
    }

    /**
     * Organization narrowing shared by the query and the rendered control. An
     * incident declares no department or team scope, so those two controls are
     * absent rather than present and inert.
     */
    private function scopeFilters(): ScopeFiltersLayout
    {
        return $this->scopeFilters ??= ScopeFiltersLayout::for(Incident::class);
    }
}
