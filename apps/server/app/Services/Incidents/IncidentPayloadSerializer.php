<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Attachment;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Services\NameReferences\NameReferenceSearchService;

/**
 * One incident as the read transport serializes it (M11.5, M16.20).
 *
 * Extracted from {@see \App\Http\Controllers\Incidents\IncidentReadController}
 * so the offline read set's IC section carries the same rows the list and
 * detail endpoints answer with. The client parses one shape
 * (`incidentReadModel.toIncident`), and a second serializer for the stored copy
 * would be a second answer to what an incident looks like — the drift ADR-0003
 * retired PowerSync to avoid, reintroduced one payload at a time.
 *
 * Serialization only. Nothing here decides who may read an incident: the
 * controller holds its IC gate and the offline contributor composes from the
 * caller's resolved IC grants, and both hand this class incidents they have
 * already decided to answer with.
 */
final class IncidentPayloadSerializer
{
    public function __construct(private readonly NameReferenceSearchService $nameReferences) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(Incident $incident): array
    {
        return [
            'id' => $incident->id,
            'event_id' => $incident->event_id,
            'incident_number' => $incident->incident_number,
            'status' => $incident->status,
            'priority_label' => $incident->priority_label,
            'started_at' => optional($incident->started_at)?->toIso8601String(),
            'title' => $incident->title,
            'location_name' => $incident->location_name,
            'location_address' => $incident->location_address,
            'location_details' => $incident->location_details,
            'camp_id' => $incident->camp_id,
            'map_location_id' => $incident->map_location_id,
            'incident_type_names' => $incident->incidentTypes->pluck('name')->values()->all(),
            'responders' => $incident->incidentStaff
                ->map(fn (IncidentStaff $staff): array => [
                    'staff_id' => $staff->staff_id,
                    'display_name' => $staff->staff?->preferred_name
                        ?? $staff->staff?->handle
                        ?? $staff->staff?->legal_name
                        ?? 'Unknown responder',
                    'relationship_label' => $staff->relationship_label,
                ])
                ->values()
                ->all(),
            'linked_incidents' => $this->linkedIncidentPayload($incident),
            'attached_field_reports' => $this->attachedFieldReportPayload($incident),
            'attachments' => $this->attachmentPayload($incident),
            'created_by_user_id' => $incident->created_by_user_id,
            'created_by_name' => $incident->createdByUser?->name,
            'created_at' => optional($incident->created_at)?->toIso8601String(),
            'updated_at' => optional($incident->updated_at)?->toIso8601String(),
            'closed_at' => optional($incident->closed_at)?->toIso8601String(),
            'name_reference_chips' => $this->nameReferences->incidentChips($incident),
            'timeline_entries' => $incident->relationLoaded('timelineEntries')
                ? $incident->timelineEntries->map(
                    fn (IncidentTimelineEntry $entry): array => $this->timelineEntryPayload($entry),
                )->values()->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function timelineEntryPayload(IncidentTimelineEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'incident_id' => $entry->incident_id,
            'actor_user_id' => $entry->actor_user_id,
            'actor_name' => $entry->actorUser?->name,
            'entry_type' => $entry->entry_type,
            'body' => $entry->body,
            'previous_value' => $entry->previous_value,
            'new_value' => $entry->new_value,
            'reason' => $entry->reason,
            'created_at' => optional($entry->created_at)?->toIso8601String(),
            'stricken_at' => optional($entry->stricken_at)?->toIso8601String(),
            'stricken_reason' => $entry->stricken_reason,
        ];
    }

    /**
     * @return list<array{id: string, filename: string, mime_type: string, byte_size: int, created_at: ?string}>
     */
    private function attachmentPayload(Incident $incident): array
    {
        return Attachment::query()
            ->where('attachable_type', Attachment::MORPH_INCIDENT)
            ->where('attachable_id', $incident->id)
            ->whereNull('stricken_at')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Attachment $attachment): array => [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'mime_type' => $attachment->mime_type,
                'byte_size' => $attachment->byte_size,
                'created_at' => optional($attachment->created_at)?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, incident_number: string, title: string, status: string}>
     */
    private function linkedIncidentPayload(Incident $incident): array
    {
        return IncidentLink::query()
            ->with(['sourceIncident', 'targetIncident'])
            ->whereNull('unlinked_at')
            ->where(function ($query) use ($incident): void {
                $query
                    ->where('source_incident_id', $incident->id)
                    ->orWhere('target_incident_id', $incident->id);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(function (IncidentLink $link) use ($incident): array {
                $linkedIncident = (string) $link->source_incident_id === (string) $incident->id
                    ? $link->targetIncident
                    : $link->sourceIncident;

                return [
                    'id' => $linkedIncident->id,
                    'incident_number' => $linkedIncident->incident_number,
                    'title' => $linkedIncident->title,
                    'status' => $linkedIncident->status,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array{id: string, field_report_id: string, incident_field_report_id: string, display_number: string, title: string, author_name: string, body: string, linked_at: ?string}>
     */
    private function attachedFieldReportPayload(Incident $incident): array
    {
        return IncidentFieldReport::query()
            ->with(['fieldReport.staff', 'fieldReport.submittedByUser'])
            ->where('incident_id', $incident->id)
            ->whereNull('unlinked_at')
            ->orderBy('linked_at')
            ->orderBy('id')
            ->get()
            ->map(function (IncidentFieldReport $link): array {
                $fieldReport = $link->fieldReport;

                return [
                    'id' => $fieldReport->id,
                    'field_report_id' => $fieldReport->id,
                    'incident_field_report_id' => $link->id,
                    'display_number' => $fieldReport->fra_number
                        ?? $fieldReport->temporary_local_number
                        ?? 'Field Report',
                    'title' => $fieldReport->title,
                    'author_name' => $fieldReport->staff?->preferred_name
                        ?? $fieldReport->staff?->legal_name
                        ?? $fieldReport->submittedByUser?->name
                        ?? 'Unknown author',
                    'body' => $fieldReport->body,
                    'linked_at' => optional($link->linked_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }
}
