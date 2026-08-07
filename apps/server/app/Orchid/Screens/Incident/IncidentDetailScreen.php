<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Incident;

use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Models\IncidentType;
use App\Models\User;
use App\Orchid\Layouts\Incident\IncidentDetailLayout;
use App\Services\Incidents\IncidentReadAccess;
use Illuminate\Http\Request;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

/**
 * One incident, in full (M18.34; UI contract 12.9; INC-001, INC-007, INC-014).
 *
 * The console permission opens the screen; {@see IncidentReadAccess} decides
 * whether this incident is on it, through the same `incidents.view` grant the
 * IMS detail read resolves. Enforcing it here rather than only in the list is
 * the point: a list filter that a typed URL walks around is not a rule.
 *
 * The timeline is the incident's whole history, stricken entries included and
 * marked as stricken rather than removed — the same thing the IMS read serves,
 * because INC-014 makes a strike an annotation on history rather than a
 * deletion of it.
 */
class IncidentDetailScreen extends Screen
{
    /**
     * @var Incident
     */
    public $incident;

    /**
     * @return array<string, mixed>
     */
    public function query(Request $request, IncidentReadAccess $access, Incident $incident): iterable
    {
        /** @var User $user */
        $user = $request->user();

        $event = $incident->event;

        abort_unless(
            $event !== null && $access->canViewIncidents($user, $event),
            403,
        );

        $incident->load([
            'event',
            'createdByUser',
            'incidentTypes',
            'incidentStaff.staff',
            'timelineEntries.actorUser',
            'fieldReportLinks.fieldReport',
        ]);

        return [
            'incident' => $incident,
            'event_display' => $incident->event?->name ?? __('None recorded'),
            'started_display' => $incident->started_at?->toDayDateTimeString() ?? __('None recorded'),
            'closed_display' => $incident->closed_at?->toDayDateTimeString() ?? __('Still open'),
            'created_by_display' => $incident->createdByUser?->name ?? __('None recorded'),
            'types_display' => $this->describeTypes($incident),
            'staff_display' => $this->describeStaff($incident),
            'timeline_display' => $this->describeTimeline($incident),
            'field_reports_display' => $this->describeFieldReports($incident),
        ];
    }

    public function name(): ?string
    {
        return 'Incident';
    }

    public function description(): ?string
    {
        return 'One IMS incident with its append-only history. Nothing here writes: a repair that leaves no timeline entry behind is a change nobody can account for later.';
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
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Back to Incidents'))
                ->icon('bs.arrow-left')
                ->route('platform.incidents'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            IncidentDetailLayout::class,
        ];
    }

    private function describeTypes(Incident $incident): string
    {
        if ($incident->incidentTypes->isEmpty()) {
            return __('None recorded');
        }

        return $incident->incidentTypes
            ->map(fn (IncidentType $type): string => (string) $type->name)
            ->implode(', ');
    }

    private function describeStaff(Incident $incident): string
    {
        if ($incident->incidentStaff->isEmpty()) {
            return __('None recorded');
        }

        return $incident->incidentStaff
            ->map(function (IncidentStaff $assignment): string {
                $name = $assignment->staff?->displayName() ?? __('Unknown');
                $label = $assignment->relationship_label;

                return $label === null || $label === ''
                    ? $name
                    : "{$name} ({$label})";
            })
            ->implode("\n");
    }

    private function describeTimeline(Incident $incident): string
    {
        if ($incident->timelineEntries->isEmpty()) {
            return __('None recorded');
        }

        return $incident->timelineEntries
            ->map(function (IncidentTimelineEntry $entry): string {
                $when = $entry->created_at?->toDayDateTimeString() ?? __('Undated');
                $who = $entry->actorUser?->name ?? __('A scheduled job');
                $stricken = $entry->stricken_at !== null
                    ? ' '.__('[stricken: :reason]', [
                        'reason' => $entry->stricken_reason ?? __('no reason recorded'),
                    ])
                    : '';

                return "[{$when}] {$who} — {$entry->entry_type}{$stricken}\n{$entry->body}";
            })
            ->implode("\n\n");
    }

    private function describeFieldReports(Incident $incident): string
    {
        if ($incident->fieldReportLinks->isEmpty()) {
            return __('None');
        }

        return $incident->fieldReportLinks
            ->map(function (IncidentFieldReport $link): string {
                $number = $link->fieldReport?->fra_number
                    ?? $link->fieldReport?->temporary_local_number
                    ?? (string) $link->field_report_id;

                return $link->unlinked_at !== null
                    ? $number.' '.__('(unlinked)')
                    : $number;
            })
            ->implode(', ');
    }
}
