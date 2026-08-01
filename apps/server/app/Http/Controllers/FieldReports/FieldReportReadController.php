<?php

declare(strict_types=1);

namespace App\Http\Controllers\FieldReports;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Services\FieldReports\FieldReportVisibilityAccess;
use App\Services\Incidents\IncidentReadAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Restricted event-wide Field Report read (FR-005, FR-006; M11.8, M16.20).
 *
 * GET /api/events/{event}/field-reports
 *
 * M11.8 asked for the IC Field Report list and the cross-links that manage
 * attachments from incident screens. The commands that link and unlink a report
 * landed with it; the read a picker chooses from did not, so until now the only
 * list of an event's Field Reports the client had was compiled into the browser.
 *
 * The gate is `field_reports.view_event` (technical spec 17.6), the same one
 * `FieldReportPolicy::view` applies to a single report, so this list discloses
 * nothing a per-report read would not. An author's own reports are not reached
 * through here: this is the IC review list, not the author's, and authorship
 * alone grants no event-wide read.
 *
 * `related_incidents` is gated separately on `incidents.view`. The two
 * permissions travel together in today's catalog, but a Field Report naming the
 * incidents it belongs to is an incident disclosure, and it should stop being
 * one the moment the mapping changes rather than the moment somebody notices.
 */
final class FieldReportReadController extends Controller
{
    public function index(
        Request $request,
        Event $event,
        FieldReportVisibilityAccess $visibility,
        IncidentReadAccess $incidentAccess,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $visibility->canViewEventFieldReports($user, $event)) {
            return response()->json([
                'message' => 'This page requires event-wide Field Report access for the event configured IC department.',
            ], 403);
        }

        $reports = FieldReport::query()
            ->with(['staff', 'submittedByUser'])
            ->forEvent($event)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        $relatedIncidents = $incidentAccess->canViewIncidents($user, $event)
            ? $this->relatedIncidents($reports->pluck('id')->all())
            : [];

        return response()->json([
            'event_id' => $event->id,
            'field_reports' => $reports
                ->map(fn (FieldReport $report): array => [
                    'id' => $report->id,
                    'event_id' => $report->event_id,
                    'display_number' => $report->fra_number
                        ?? $report->temporary_local_number
                        ?? 'Field Report',
                    'title' => $report->title,
                    'author_name' => $report->staff?->preferred_name
                        ?? $report->staff?->legal_name
                        ?? $report->submittedByUser?->name
                        ?? 'Unknown author',
                    'body' => $report->body,
                    // The moment the node accepted the report, falling back to
                    // the row's own timestamp for one that predates acceptance
                    // recording. This is what the picker orders candidates by
                    // (IMS spec 9).
                    'created_at' => optional($report->server_received_at ?? $report->created_at)?->toIso8601String(),
                    'related_incidents' => $relatedIncidents[(string) $report->id] ?? [],
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * The actively linked incidents for each of these reports, keyed by report.
     *
     * One query for the whole page rather than one per row: an event's Field
     * Report list is read at the top of every IC shift and the link table is
     * small enough to resolve in a single pass.
     *
     * @param  list<string>  $fieldReportIds
     * @return array<string, list<array{id: string, incident_number: string, title: string, status: string, priority_label: ?string}>>
     */
    private function relatedIncidents(array $fieldReportIds): array
    {
        if ($fieldReportIds === []) {
            return [];
        }

        $related = [];

        $links = IncidentFieldReport::query()
            ->with('incident')
            ->whereIn('field_report_id', $fieldReportIds)
            ->whereNull('unlinked_at')
            ->orderBy('linked_at')
            ->orderBy('id')
            ->get();

        foreach ($links as $link) {
            $incident = $link->incident;

            if (! $incident instanceof Incident) {
                continue;
            }

            $related[(string) $link->field_report_id][] = [
                'id' => $incident->id,
                'incident_number' => $incident->incident_number,
                'title' => $incident->title,
                'status' => $incident->status,
                'priority_label' => $incident->priority_label,
            ];
        }

        return $related;
    }
}
