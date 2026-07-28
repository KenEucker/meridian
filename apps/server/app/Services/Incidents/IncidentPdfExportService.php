<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentLink;
use App\Models\IncidentStaff;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\Branding\BrandingResolver;
use App\Services\Documents\PdfDocumentRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * Generates and audits single-incident PDF print exports (INC-015; M11.10).
 *
 * PDF generation reuses the dependency-free Alpha 1 renderer already used for
 * policy/procedure exports. Spreadsheet exports, offline generation, and
 * multi-incident packets remain outside this task (INC-016; M11.11 QA script).
 */
final class IncidentPdfExportService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly IncidentPrintAccess $printAccess,
        private readonly PdfDocumentRenderer $pdf,
        private readonly BrandingResolver $branding,
    ) {}

    public function export(
        Incident $incident,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): IncidentPdfExport {
        $incident->loadMissing([
            'event.organization',
            'createdByUser',
            'incidentTypes',
            'incidentStaff.staff',
            'timelineEntries.actorUser',
            'sourceIncidentLinks.targetIncident',
            'targetIncidentLinks.sourceIncident',
            'fieldReportLinks.fieldReport.staff',
            'fieldReportLinks.fieldReport.submittedByUser',
        ]);

        $event = $incident->event;
        if ($event === null) {
            throw new AuthorizationException('Incident event is unavailable.');
        }

        if (! $this->printAccess->canPrintIncident($actor, $event)) {
            throw new AuthorizationException(
                'Only Incident Command leads for this event may print incidents to PDF.',
            );
        }

        $exportedAt = now()->utc();

        // BRAND-002: a generated PDF carries the organization's identity, not
        // Meridian's. An unbranded organization resolves to "Meridian".
        $producedBy = $this->branding
            ->forOrganizationId($event->organization_id !== null ? (string) $event->organization_id : null)
            ->identityName();

        $lines = $this->pdfLines($incident, $actor, $exportedAt->toIso8601String(), $producedBy);
        $contents = $this->pdf->render($lines);
        $export = new IncidentPdfExport(
            contents: $contents,
            filename: $this->filename($incident),
            exportedAt: $exportedAt,
        );

        $this->audit->recordForEntity(
            entity: $incident,
            action: 'incident.exported',
            actorUser: $actor,
            organizationId: $event->organization_id,
            eventId: $event->id,
            departmentId: $this->effectiveIncidentCommandDepartmentId($event),
            after: [
                'format' => 'pdf',
                'incident_number' => $incident->incident_number,
                'exported_at' => $export->exportedAt->toIso8601String(),
            ],
            sourceContext: $sourceContext,
        );

        return $export;
    }

    /**
     * @return list<string>
     */
    private function pdfLines(
        Incident $incident,
        User $actor,
        string $exportTimestamp,
        string $producedBy,
    ): array {
        $lines = [
            $producedBy,
            '',
            'Incident PDF Export',
            'IMS number: '.$incident->incident_number,
            'Title: '.($incident->title !== '' ? $incident->title : 'Untitled incident'),
            'State: '.$this->statusLabel($incident->status),
            'Priority: '.($incident->priority_label ?? 'Priority not set'),
            'Incident types: '.$this->typeNames($incident),
            'Responders: '.$this->responderNames($incident),
            'Started at: '.$this->formatTimestamp($incident->started_at),
            'Location: '.($incident->location_name ?: 'Location not set'),
        ];

        if (filled($incident->location_address)) {
            $lines[] = 'Location address: '.$incident->location_address;
        }

        if (filled($incident->location_details)) {
            $lines[] = 'Location details: '.$incident->location_details;
        }

        $lines = [
            ...$lines,
            'Created by: '.($incident->createdByUser?->name ?? 'Creator unavailable'),
            'Created at: '.$this->formatTimestamp($incident->created_at),
            'Updated at: '.$this->formatTimestamp($incident->updated_at),
            'Exported at: '.$exportTimestamp,
            'Exported by: '.($actor->name ?: $actor->email),
            '',
            'Linked incidents:',
            ...$this->linkedIncidentLines($incident),
            '',
            'Attached Field Reports:',
            ...$this->attachedFieldReportLines($incident),
            '',
            'Attachments:',
            ...$this->attachmentLines($incident),
            '',
            'Timeline:',
            ...$this->timelineLines($incident),
        ];

        return $lines;
    }

    private function typeNames(Incident $incident): string
    {
        $names = $incident->incidentTypes->pluck('name')->filter()->values()->all();

        return $names === [] ? 'Types not set' : implode(', ', $names);
    }

    private function responderNames(Incident $incident): string
    {
        $names = $incident->incidentStaff
            ->map(function (IncidentStaff $staff): string {
                return $staff->staff?->preferred_name
                    ?? $staff->staff?->handle
                    ?? $staff->staff?->legal_name
                    ?? 'Unknown responder';
            })
            ->filter()
            ->values()
            ->all();

        return $names === [] ? 'Responders not set' : implode(', ', $names);
    }

    /**
     * @return list<string>
     */
    private function linkedIncidentLines(Incident $incident): array
    {
        $linked = collect()
            ->merge($incident->sourceIncidentLinks)
            ->merge($incident->targetIncidentLinks)
            ->filter(fn (IncidentLink $link): bool => $link->unlinked_at === null)
            ->map(function (IncidentLink $link) use ($incident): ?Incident {
                $related = (string) $link->source_incident_id === (string) $incident->id
                    ? $link->targetIncident
                    : $link->sourceIncident;

                return $related instanceof Incident ? $related : null;
            })
            ->filter()
            ->unique(fn (Incident $related): string => (string) $related->id)
            ->sortBy('incident_number')
            ->values();

        if ($linked->isEmpty()) {
            return ['- None'];
        }

        return $linked
            ->map(fn (Incident $related): string => sprintf(
                '- %s %s (%s)',
                $related->incident_number,
                $related->title !== '' ? $related->title : 'Untitled incident',
                $this->statusLabel($related->status),
            ))
            ->all();
    }

    /**
     * @return list<string>
     */
    private function attachedFieldReportLines(Incident $incident): array
    {
        $active = $incident->fieldReportLinks
            ->filter(fn (IncidentFieldReport $link): bool => $link->unlinked_at === null)
            ->sortBy('linked_at')
            ->values();

        if ($active->isEmpty()) {
            return ['- None'];
        }

        return $active
            ->map(function (IncidentFieldReport $link): string {
                $report = $link->fieldReport;
                $number = $report?->fra_number
                    ?? $report?->temporary_local_number
                    ?? 'Field Report';
                $title = $report?->title ?: 'Untitled Field Report';
                $author = $report?->staff?->preferred_name
                    ?? $report?->staff?->legal_name
                    ?? $report?->submittedByUser?->name
                    ?? 'Author unavailable';

                return "- {$number} {$title} (Author: {$author})";
            })
            ->all();
    }

    /**
     * @return list<string>
     */
    private function attachmentLines(Incident $incident): array
    {
        $attachments = Attachment::query()
            ->where('attachable_type', Attachment::MORPH_INCIDENT)
            ->where('attachable_id', $incident->id)
            ->whereNull('stricken_at')
            ->whereNull('deleted_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($attachments->isEmpty()) {
            return ['- None'];
        }

        return $attachments
            ->map(fn (Attachment $attachment): string => '- '.$attachment->filename)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function timelineLines(Incident $incident): array
    {
        $entries = $incident->timelineEntries;

        if ($entries->isEmpty()) {
            return ['- No timeline entries'];
        }

        $lines = [];

        foreach ($entries as $entry) {
            /** @var IncidentTimelineEntry $entry */
            $actor = $entry->actorUser?->name ?? 'Unknown actor';
            $lines[] = sprintf(
                '[%s] %s',
                $this->formatTimestamp($entry->created_at),
                $actor,
            );
            $lines[] = $this->timelineBody($entry);

            if ($entry->stricken_at !== null) {
                $lines[] = 'Stricken: '.($entry->stricken_reason ?: 'Removed from incident.');
            }

            $lines[] = '';
        }

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return $lines;
    }

    private function timelineBody(IncidentTimelineEntry $entry): string
    {
        if (filled($entry->body)) {
            return (string) $entry->body;
        }

        if ($entry->entry_type === IncidentTimelineEntry::TYPE_INCIDENT_OPENED) {
            return 'Incident opened.';
        }

        if ($entry->entry_type === IncidentTimelineEntry::TYPE_FIELD_UPDATED) {
            $newValue = is_array($entry->new_value) ? $entry->new_value : [];
            $parts = [];

            foreach ($newValue as $field => $value) {
                $parts[] = sprintf(
                    'Changed %s: %s',
                    $this->fieldLabel((string) $field),
                    $this->formatChangedValue((string) $field, is_string($value) || $value === null ? $value : (string) $value),
                );
            }

            return $parts === [] ? 'Incident field updated.' : implode('; ', $parts);
        }

        return Str::headline(str_replace('_', ' ', $entry->entry_type));
    }

    private function fieldLabel(string $field): string
    {
        return match ($field) {
            'title' => 'title',
            'status' => 'state',
            'priority_label', 'priorityLabel' => 'priority',
            'incident_type_names', 'incidentTypeNames' => 'incident types',
            'responders' => 'responders',
            'started_at', 'startedAt' => 'started',
            'location_name', 'locationName' => 'location name',
            'location_address', 'locationAddress' => 'location address',
            'location_details', 'locationDetails' => 'location details',
            default => $field,
        };
    }

    private function formatChangedValue(string $field, ?string $value): string
    {
        if ($value === null || $value === '') {
            return 'not set';
        }

        if (in_array($field, ['status'], true)) {
            return $this->statusLabel($value);
        }

        return $value;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            Incident::STATUS_OPEN => 'Open',
            Incident::STATUS_ON_SCENE => 'On Scene',
            Incident::STATUS_MONITORING => 'Monitoring',
            Incident::STATUS_ON_HOLD => 'On Hold',
            Incident::STATUS_CLOSED => 'Closed',
            default => Str::headline($status),
        };
    }

    private function formatTimestamp(mixed $value): string
    {
        if ($value === null) {
            return 'not set';
        }

        if (is_string($value)) {
            return $value;
        }

        if (method_exists($value, 'toIso8601String')) {
            return $value->toIso8601String();
        }

        return (string) $value;
    }

    private function filename(Incident $incident): string
    {
        $number = Str::slug($incident->incident_number) ?: 'incident';
        $title = Str::slug($incident->title) ?: 'untitled';

        return "incident-{$number}-{$title}.pdf";
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
