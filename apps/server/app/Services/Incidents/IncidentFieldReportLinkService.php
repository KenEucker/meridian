<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentFieldReportLinkException;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\FieldReport;
use App\Models\Incident;
use App\Models\IncidentFieldReport;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\FieldReports\FieldReportIncidentNoteCopy;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Incident-to-Field-Report link/unlink command path (M11.8).
 */
final class IncidentFieldReportLinkService
{
    public function __construct(private readonly AuditService $audit) {}

    public function link(
        Incident $incident,
        FieldReport $fieldReport,
        User $actor,
        ?DateTimeInterface $linkedAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): IncidentFieldReport {
        return DB::transaction(function () use ($incident, $fieldReport, $actor, $linkedAt, $sourceContext): IncidentFieldReport {
            $lockedIncident = $this->lockIncident($incident);
            $lockedReport = $this->lockFieldReport($fieldReport);
            $lockedReport->loadMissing(['staff', 'submittedByUser']);
            $this->assertSameEvent($lockedIncident, $lockedReport);

            if ($this->activeLink($lockedIncident, $lockedReport) !== null) {
                throw IncidentFieldReportLinkException::invalid('Field Report is already linked to this incident.');
            }

            $now = CarbonImmutable::instance($linkedAt ?? now());
            $link = IncidentFieldReport::query()->create([
                'incident_id' => $lockedIncident->id,
                'field_report_id' => $lockedReport->id,
                'linked_by_user_id' => $actor->id,
                'linked_at' => $now,
            ]);

            $entry = IncidentTimelineEntry::query()->create([
                'incident_id' => $lockedIncident->id,
                'actor_user_id' => $actor->id,
                'entry_type' => IncidentTimelineEntry::TYPE_FIELD_REPORT_LINKED,
                'body' => FieldReportIncidentNoteCopy::fromReport($lockedReport),
                'new_value' => [
                    'incident_field_report_id' => $link->id,
                    'field_report_id' => $lockedReport->id,
                    'field_report_title' => $lockedReport->title,
                    'fra_number' => $lockedReport->fra_number,
                ],
                'created_at' => $now,
            ]);

            $lockedIncident->forceFill(['updated_at' => $now])->save();
            $event = Event::query()->findOrFail($lockedIncident->event_id);

            $this->audit->recordForEntity(
                entity: $link,
                action: 'incident.field_report_linked',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                after: $this->auditPayload($link->refresh(), $entry),
                sourceContext: $sourceContext,
            );

            return $link->refresh();
        });
    }

    public function unlink(
        Incident $incident,
        FieldReport $fieldReport,
        User $actor,
        ?DateTimeInterface $unlinkedAt = null,
        string $strickenReason = 'Field Report removed from incident.',
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): IncidentFieldReport {
        return DB::transaction(function () use ($incident, $fieldReport, $actor, $unlinkedAt, $strickenReason, $sourceContext): IncidentFieldReport {
            $lockedIncident = $this->lockIncident($incident);
            $lockedReport = $this->lockFieldReport($fieldReport);
            $this->assertSameEvent($lockedIncident, $lockedReport);

            $link = $this->activeLink($lockedIncident, $lockedReport);

            if ($link === null) {
                throw IncidentFieldReportLinkException::invalid('Field Report is not currently linked to this incident.');
            }

            $before = $this->auditPayload($link);
            $now = CarbonImmutable::instance($unlinkedAt ?? now());
            $link->forceFill([
                'unlinked_by_user_id' => $actor->id,
                'unlinked_at' => $now,
                'stricken_reason' => $strickenReason,
            ])->save();

            $copiedEntry = $this->copiedTimelineEntry($lockedIncident, $link);
            if ($copiedEntry !== null) {
                IncidentTimelineEntry::query()
                    ->whereKey($copiedEntry->id)
                    ->update([
                        'stricken_at' => $now,
                        'stricken_reason' => $strickenReason,
                    ]);
            }

            IncidentTimelineEntry::query()->create([
                'incident_id' => $lockedIncident->id,
                'actor_user_id' => $actor->id,
                'entry_type' => IncidentTimelineEntry::TYPE_FIELD_REPORT_UNLINKED,
                'body' => sprintf(
                    'Removed Field Report %s: %s.',
                    $lockedReport->fra_number ?: $lockedReport->temporary_local_number ?: $lockedReport->id,
                    $lockedReport->title,
                ),
                'previous_value' => [
                    'incident_field_report_id' => $link->id,
                    'field_report_id' => $lockedReport->id,
                    'field_report_title' => $lockedReport->title,
                    'fra_number' => $lockedReport->fra_number,
                ],
                'reason' => $strickenReason,
                'created_at' => $now,
            ]);

            $lockedIncident->forceFill(['updated_at' => $now])->save();
            $event = Event::query()->findOrFail($lockedIncident->event_id);

            $this->audit->recordForEntity(
                entity: $link,
                action: 'incident.field_report_unlinked',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                before: $before,
                after: $this->auditPayload($link->refresh(), $copiedEntry?->refresh()),
                sourceContext: $sourceContext,
            );

            return $link->refresh();
        });
    }

    private function lockIncident(Incident $incident): Incident
    {
        return Incident::query()
            ->whereKey($incident->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function lockFieldReport(FieldReport $fieldReport): FieldReport
    {
        return FieldReport::query()
            ->whereKey($fieldReport->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertSameEvent(Incident $incident, FieldReport $fieldReport): void
    {
        if ((string) $incident->event_id !== (string) $fieldReport->event_id) {
            throw IncidentFieldReportLinkException::invalid('Field Report must belong to the same event as the incident.');
        }
    }

    private function activeLink(Incident $incident, FieldReport $fieldReport): ?IncidentFieldReport
    {
        return IncidentFieldReport::query()
            ->where('incident_id', $incident->id)
            ->where('field_report_id', $fieldReport->id)
            ->whereNull('unlinked_at')
            ->lockForUpdate()
            ->first();
    }

    private function copiedTimelineEntry(Incident $incident, IncidentFieldReport $link): ?IncidentTimelineEntry
    {
        return IncidentTimelineEntry::query()
            ->where('incident_id', $incident->id)
            ->where('entry_type', IncidentTimelineEntry::TYPE_FIELD_REPORT_LINKED)
            ->where('new_value->incident_field_report_id', $link->id)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(IncidentFieldReport $link, ?IncidentTimelineEntry $entry = null): array
    {
        return [
            'incident_id' => $link->incident_id,
            'field_report_id' => $link->field_report_id,
            'linked_by_user_id' => $link->linked_by_user_id,
            'linked_at' => optional($link->linked_at)?->toIso8601String(),
            'unlinked_by_user_id' => $link->unlinked_by_user_id,
            'unlinked_at' => optional($link->unlinked_at)?->toIso8601String(),
            'stricken_reason' => $link->stricken_reason,
            'timeline_entry_id' => $entry?->id,
            'timeline_entry_stricken_at' => optional($entry?->stricken_at)?->toIso8601String(),
        ];
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
