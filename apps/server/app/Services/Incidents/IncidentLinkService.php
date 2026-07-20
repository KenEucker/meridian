<?php

namespace App\Services\Incidents;

use App\Exceptions\IncidentLinkException;
use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\Incident;
use App\Models\IncidentLink;
use App\Models\IncidentTimelineEntry;
use App\Models\User;
use App\Services\Audit\AuditService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;

/**
 * Same-event incident link/unlink command path (M11.7B).
 */
final class IncidentLinkService
{
    public function __construct(private readonly AuditService $audit) {}

    public function link(
        Incident $incident,
        Incident $target,
        User $actor,
        ?DateTimeInterface $linkedAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): IncidentLink {
        return DB::transaction(function () use ($incident, $target, $actor, $linkedAt, $sourceContext): IncidentLink {
            [$lockedIncident, $lockedTarget] = $this->lockIncidentPair($incident, $target);
            $this->assertLinkable($lockedIncident, $lockedTarget);

            if ($this->activeLink($lockedIncident, $lockedTarget) !== null) {
                throw IncidentLinkException::invalid('Incidents are already linked.');
            }

            $now = CarbonImmutable::instance($linkedAt ?? now());
            $link = IncidentLink::query()->create([
                'source_incident_id' => $lockedIncident->id,
                'target_incident_id' => $lockedTarget->id,
                'link_type' => IncidentLink::TYPE_RELATED,
                'created_by_user_id' => $actor->id,
                'created_at' => $now,
            ]);

            $this->recordTimelinePair(
                $lockedIncident,
                $lockedTarget,
                $actor,
                IncidentTimelineEntry::TYPE_INCIDENT_LINKED,
                $now,
            );

            $event = Event::query()->findOrFail($lockedIncident->event_id);

            $this->audit->recordForEntity(
                entity: $link,
                action: 'incident.linked',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                after: $this->auditPayload($link),
                sourceContext: $sourceContext,
            );

            return $link->refresh();
        });
    }

    public function unlink(
        Incident $incident,
        Incident $target,
        User $actor,
        ?DateTimeInterface $unlinkedAt = null,
        string $sourceContext = AuditEvent::SOURCE_SYSTEM,
    ): IncidentLink {
        return DB::transaction(function () use ($incident, $target, $actor, $unlinkedAt, $sourceContext): IncidentLink {
            [$lockedIncident, $lockedTarget] = $this->lockIncidentPair($incident, $target);
            $this->assertLinkable($lockedIncident, $lockedTarget);

            $link = $this->activeLink($lockedIncident, $lockedTarget);

            if ($link === null) {
                throw IncidentLinkException::invalid('Incidents are not currently linked.');
            }

            $before = $this->auditPayload($link);
            $now = CarbonImmutable::instance($unlinkedAt ?? now());
            $link->forceFill([
                'unlinked_by_user_id' => $actor->id,
                'unlinked_at' => $now,
            ])->save();

            $this->recordTimelinePair(
                $lockedIncident,
                $lockedTarget,
                $actor,
                IncidentTimelineEntry::TYPE_INCIDENT_UNLINKED,
                $now,
            );

            $event = Event::query()->findOrFail($lockedIncident->event_id);

            $this->audit->recordForEntity(
                entity: $link,
                action: 'incident.unlinked',
                actorUser: $actor,
                organizationId: $event->organization_id,
                eventId: $event->id,
                departmentId: $this->effectiveIncidentCommandDepartmentId($event),
                before: $before,
                after: $this->auditPayload($link->refresh()),
                sourceContext: $sourceContext,
            );

            return $link->refresh();
        });
    }

    /**
     * @return array{0: Incident, 1: Incident}
     */
    private function lockIncidentPair(Incident $incident, Incident $target): array
    {
        $ids = collect([$incident->id, $target->id])->sort()->values()->all();
        $locked = Incident::query()
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        return [
            $locked->get($incident->id) ?? throw IncidentLinkException::invalid('Incident not found for this event.'),
            $locked->get($target->id) ?? throw IncidentLinkException::invalid('Linked incident not found for this event.'),
        ];
    }

    private function assertLinkable(Incident $incident, Incident $target): void
    {
        if ((string) $incident->id === (string) $target->id) {
            throw IncidentLinkException::invalid('An incident cannot be linked to itself.');
        }

        if ((string) $incident->event_id !== (string) $target->event_id) {
            throw IncidentLinkException::invalid('Linked incidents must belong to the same event.');
        }
    }

    private function activeLink(Incident $incident, Incident $target): ?IncidentLink
    {
        return IncidentLink::query()
            ->whereNull('unlinked_at')
            ->where(function ($query) use ($incident, $target): void {
                $query
                    ->where(function ($query) use ($incident, $target): void {
                        $query
                            ->where('source_incident_id', $incident->id)
                            ->where('target_incident_id', $target->id);
                    })
                    ->orWhere(function ($query) use ($incident, $target): void {
                        $query
                            ->where('source_incident_id', $target->id)
                            ->where('target_incident_id', $incident->id);
                    });
            })
            ->lockForUpdate()
            ->first();
    }

    private function recordTimelinePair(
        Incident $incident,
        Incident $target,
        User $actor,
        string $entryType,
        CarbonImmutable $createdAt,
    ): void {
        $this->recordTimeline($incident, $target, $actor, $entryType, $createdAt);
        $this->recordTimeline($target, $incident, $actor, $entryType, $createdAt);

        Incident::query()
            ->whereIn('id', [$incident->id, $target->id])
            ->update(['updated_at' => $createdAt]);
    }

    private function recordTimeline(
        Incident $incident,
        Incident $other,
        User $actor,
        string $entryType,
        CarbonImmutable $createdAt,
    ): void {
        $verb = $entryType === IncidentTimelineEntry::TYPE_INCIDENT_LINKED ? 'Linked' : 'Unlinked';

        IncidentTimelineEntry::query()->create([
            'incident_id' => $incident->id,
            'actor_user_id' => $actor->id,
            'entry_type' => $entryType,
            'body' => sprintf(
                '%s related incident %s: %s.',
                $verb,
                $other->incident_number,
                $other->title === '' ? 'Untitled incident' : $other->title,
            ),
            'previous_value' => $entryType === IncidentTimelineEntry::TYPE_INCIDENT_UNLINKED
                ? ['linked_incident_id' => $other->id]
                : null,
            'new_value' => $entryType === IncidentTimelineEntry::TYPE_INCIDENT_LINKED
                ? ['linked_incident_id' => $other->id]
                : null,
            'created_at' => $createdAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function auditPayload(IncidentLink $link): array
    {
        return [
            'source_incident_id' => $link->source_incident_id,
            'target_incident_id' => $link->target_incident_id,
            'link_type' => $link->link_type,
            'created_by_user_id' => $link->created_by_user_id,
            'created_at' => optional($link->created_at)?->toIso8601String(),
            'unlinked_by_user_id' => $link->unlinked_by_user_id,
            'unlinked_at' => optional($link->unlinked_at)?->toIso8601String(),
        ];
    }

    private function effectiveIncidentCommandDepartmentId(Event $event): ?string
    {
        $event->loadMissing('organization');

        return $event->ic_department_id ?? $event->organization?->default_ic_department_id;
    }
}
