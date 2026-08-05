<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Domain\Dashboard\DashboardAttention;
use App\Domain\Dashboard\DashboardWidget;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * UI contract 13.5, the Incident Command widgets (M18.28).
 *
 * This compiler runs only for a reader `IncidentReadAccess` has already
 * admitted, which is the same gate `GET /api/events/{event}/incidents` runs and
 * which resolves IC standing through the event's configured Incident Command
 * department. Organizer standing does not reach it and neither does department
 * lead standing; that is not enforced here, it is enforced by never calling this
 * compiler for a reader who does not hold IC authority.
 *
 * Three vocabularies meet in this group and the contract keeps them apart:
 * incident **state** is where an incident is in its workflow, IMS **priority**
 * is how serious it is, and dashboard **attention** is how hard the interface
 * should pull. So `ic.on_scene` and `ic.monitoring` are state widgets,
 * `ic.serious_incidents` is a priority widget, and the attention level each one
 * carries is a third thing derived from both — never a rename of either.
 *
 * Field Reports are gated a second time. FR-005 and FR-006 put event-wide Field
 * Report visibility on `field_reports.view_event`, which is granted separately
 * from `incidents.view`, so an IC reader without it reads the incident widgets
 * and not the Field Report one. The contract's "where permitted" on
 * `ims.field-reports` is that same rule.
 */
final class IncidentCommandDashboardWidgets extends DashboardWidgetCompiler
{
    /**
     * @return list<DashboardWidget>
     */
    public function compile(Event $event, DashboardAudience $audience, Carbon $now): array
    {
        $open = Incident::query()
            ->where('event_id', $event->id)
            ->whereNull('closed_at')
            ->where('status', '!=', Incident::STATUS_CLOSED)
            ->orderByDesc('started_at')
            ->get();

        $widgets = [
            $this->activeIncidents($open),
            $this->seriousIncidents($open),
            $this->byState(
                'ic.on_scene',
                $open->where('status', Incident::STATUS_ON_SCENE)->values(),
                'on scene',
            ),
            $this->byState(
                'ic.monitoring',
                $open->where('status', Incident::STATUS_MONITORING)->values(),
                'being monitored',
            ),
        ];

        if ($audience->canViewEventFieldReports) {
            $widgets[] = $this->unresolvedFieldReports($event);
        }

        return $widgets;
    }

    /**
     * @param  Collection<int, Incident>  $open
     */
    private function activeIncidents(Collection $open): DashboardWidget
    {
        if ($open->isEmpty()) {
            return $this->quiet('ic.active_incidents');
        }

        $critical = $open->where('priority_label', Incident::PRIORITY_CRITICAL)->count();

        return DashboardWidget::reporting(
            definition: $this->definition('ic.active_incidents'),
            attention: $critical > 0 ? DashboardAttention::Critical : DashboardAttention::Warning,
            summary: $this->plural($open->count(), 'incident').' open'
                .($critical > 0 ? ', '.$critical.' at Critical' : '').'.',
            items: $this->capped($this->rows($open)),
            metric: ['value' => $open->count(), 'label' => 'open incidents'],
        );
    }

    /**
     * Serious and Critical together (UI contract 13.5,
     * `ic.serious_incidents`).
     *
     * Critical is included because it is more serious than Serious, not less. A
     * widget titled "Serious Incidents" that omitted the worst ones would be the
     * single most dangerous thing on this dashboard.
     *
     * @param  Collection<int, Incident>  $open
     */
    private function seriousIncidents(Collection $open): DashboardWidget
    {
        $serious = $open->filter(fn (Incident $incident): bool => in_array(
            $incident->priority_label,
            [Incident::PRIORITY_SERIOUS, Incident::PRIORITY_CRITICAL],
            true,
        ))->values();

        if ($serious->isEmpty()) {
            return $this->quiet('ic.serious_incidents');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('ic.serious_incidents'),
            attention: DashboardAttention::Critical,
            summary: $this->plural($serious->count(), 'incident').' at Serious or Critical.',
            items: $this->capped($this->rows($serious)),
            metric: ['value' => $serious->count(), 'label' => 'serious or critical'],
        );
    }

    /**
     * One incident state, counted (UI contract 13.5, `ic.on_scene` and
     * `ic.monitoring`).
     *
     * On scene is a Warning and monitoring is Attention, because they are
     * different demands on the room: somebody is standing at one of them, and
     * the other is being watched.
     *
     * @param  Collection<int, Incident>  $incidents
     */
    private function byState(string $id, Collection $incidents, string $phrase): DashboardWidget
    {
        if ($incidents->isEmpty()) {
            return $this->quiet($id);
        }

        return DashboardWidget::reporting(
            definition: $this->definition($id),
            attention: $id === 'ic.on_scene' ? DashboardAttention::Warning : DashboardAttention::Attention,
            summary: $this->plural($incidents->count(), 'incident').' '.$phrase.'.',
            items: $this->capped($this->rows($incidents)),
            metric: ['value' => $incidents->count(), 'label' => $phrase],
        );
    }

    /**
     * Field Reports nobody has attached to an incident (UI contract 13.5,
     * `ic.unresolved_field_reports`).
     *
     * "Unresolved" means unlinked. Alpha 1 Field Reports carry no review state —
     * they are submitted and then appended to — so the only durable record of an
     * IC reader having dealt with one is the incident it was linked to
     * (FR-013). A report whose link was later struck is unresolved again, which
     * is the honest reading of an unlink.
     */
    private function unresolvedFieldReports(Event $event): DashboardWidget
    {
        $linked = IncidentFieldReport::query()
            ->whereNull('unlinked_at')
            ->pluck('field_report_id')
            ->map(fn ($id): string => (string) $id)
            ->unique()
            ->all();

        $unresolved = FieldReport::query()
            ->where('event_id', $event->id)
            ->when($linked !== [], fn ($query) => $query->whereNotIn('id', $linked))
            ->orderByDesc('server_received_at')
            ->get();

        if ($unresolved->isEmpty()) {
            return $this->quiet('ic.unresolved_field_reports');
        }

        return DashboardWidget::reporting(
            definition: $this->definition('ic.unresolved_field_reports'),
            attention: DashboardAttention::Attention,
            summary: $this->plural($unresolved->count(), 'Field Report').' not linked to an incident.',
            items: $this->capped($unresolved
                ->map(fn (FieldReport $report): array => $this->item(
                    label: (string) $report->title,
                    detail: $report->fra_number ?? $report->temporary_local_number,
                    status: 'Unlinked',
                ))
                ->values()
                ->all()),
            metric: ['value' => $unresolved->count(), 'label' => 'unlinked reports'],
        );
    }

    /**
     * @param  Collection<int, Incident>  $incidents
     * @return list<array<string, mixed>>
     */
    private function rows(Collection $incidents): array
    {
        return $incidents
            ->map(fn (Incident $incident): array => $this->item(
                label: (string) $incident->title,
                detail: $incident->incident_number,
                // The IMS priority, named as itself. A reader comparing this
                // against the widget's attention level is comparing two
                // different measurements, which is why both are on the card.
                status: $incident->priority_label,
            ))
            ->values()
            ->all();
    }
}
