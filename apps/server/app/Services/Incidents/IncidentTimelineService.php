<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentTimelineException;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use App\Services\NameReferences\NameReferenceIndexService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Append-only incident timeline write path (INC-007, INC-014).
 */
final class IncidentTimelineService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly NameReferenceIndexService $nameReferences,
    ) {}

    public function recordIncidentOpened(
        Incident $incident,
        User $actor,
        ?DateTimeInterface $createdAt = null,
    ): IncidentTimelineEntry {
        $createdAt = CarbonImmutable::instance($createdAt ?? now());

        return IncidentTimelineEntry::query()->create([
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => IncidentTimelineEntry::TYPE_INCIDENT_OPENED,
            'body' => "Incident {$incident->incident_number} opened.",
            'created_at' => $createdAt,
        ]);
    }

    public function appendNote(
        Incident $incident,
        User $actor,
        string $body,
        ?DateTimeInterface $createdAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): IncidentTimelineEntry {
        $body = $this->body($body);
        $createdAt = CarbonImmutable::instance($createdAt ?? now());

        return DB::transaction(function () use ($incident, $actor, $body, $createdAt, $sourceContext): IncidentTimelineEntry {
            /** @var Incident $lockedIncident */
            $lockedIncident = Incident::query()
                ->whereKey($incident->id)
                ->lockForUpdate()
                ->firstOrFail();

            $entry = IncidentTimelineEntry::query()->create([
                'incident_id' => $lockedIncident->id,
                'actor_user_id' => $actor->id,
                'entry_type' => IncidentTimelineEntry::TYPE_OPERATIONAL_NOTE,
                'body' => $body,
                'created_at' => $createdAt,
            ]);

            $this->nameReferences->synchronizeIncidentTimelineEntry($entry);

            $lockedIncident->forceFill(['updated_at' => $createdAt])->save();
            $event = Event::query()->findOrFail($lockedIncident->event_id);

            $this->audit->recordForEntity(
                entity: $entry,
                action: 'incident.note_appended',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                after: [
                    'incident_id' => $lockedIncident->id,
                    'entry_type' => $entry->entry_type,
                    'body' => $entry->body,
                ],
                sourceContext: $sourceContext,
            );

            return $entry->refresh();
        });
    }

    private function body(string $value): string
    {
        $body = trim($value);

        if ($body === '') {
            throw IncidentTimelineException::invalid('Incident note body is required.');
        }

        if (mb_strlen($body) > 10000) {
            throw IncidentTimelineException::invalid('Incident note body may not be greater than 10000 characters.');
        }

        return $body;
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
