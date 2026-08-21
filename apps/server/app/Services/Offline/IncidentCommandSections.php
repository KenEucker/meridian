<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Domain\Permissions\PermissionCatalog;
use App\Models\Incident;
use App\Services\Incidents\IncidentPayloadSerializer;
use App\Services\Offline\Concerns\ShapesOfflineRows;

/**
 * The Incident Command cache list of technical spec 9.3, composed for one
 * caller (INC-016 through INC-018; technical spec 19.2).
 *
 * The IC list was the one role-additive list ADR-0003 left out of the set,
 * behind "incidents should not be greedily synced" — and until 2026-08-20 the
 * only incident a device held was one its user had already opened while online
 * (the section 19.2 viewed-incident cache). That left an IC operator walking
 * into a no-signal field with exactly the incidents they had thought to open at
 * a desk, which for a fresh shift is none.
 *
 * The product decision is now a bounded preload rather than no preload: an
 * IC-scoped caller's set carries the event's **open incidents and its most
 * recently created entries**, capped, so the device holds the incidents a shift
 * is most likely to work before anyone has viewed anything. "Not greedily
 * synced" still holds as the bound — the whole history does not travel, closed
 * incidents travel only while they are recent, and the caps below are the
 * ceiling. The viewed-incident cache stays what it was: whatever the user
 * opened remains readable regardless of these bounds.
 *
 * Rows are serialized by {@see IncidentPayloadSerializer} — the same shape the
 * list and detail endpoints answer with — so the device parses one incident
 * shape wherever it got it. Timeline entries travel too: an incident without
 * its timeline is a headline, and the screen this feeds renders the timeline.
 *
 * Scoped by resolved IC grants (ic_lead, ic_operator, ic_viewer), which are
 * event-scoped roles (technical spec 16), so the section reaches exactly the
 * events the caller's IC standing reaches and nothing through department or
 * team membership alone.
 */
final class IncidentCommandSections implements OfflineReadSetContributor
{
    use ShapesOfflineRows;

    /**
     * Every incident still being worked travels; a closed one travels while it
     * is among the most recent. The caps are the "not greedily synced" bound:
     * an event running more than {@see self::OPEN_CAP} simultaneous open
     * incidents is past what a device-local list is for, and the newest are the
     * ones a responder is standing in.
     */
    public const OPEN_CAP = 100;

    public const RECENT_CAP = 30;

    public function __construct(private readonly IncidentPayloadSerializer $serializer) {}

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        $eventIds = $this->incidentCommandEventIds($scope);

        if ($eventIds === []) {
            return [];
        }

        $rows = [];

        foreach ($eventIds as $eventId) {
            $rows = [...$rows, ...$this->eventIncidents($eventId)];
        }

        return [
            OfflineReadSetSection::owned('ims_incidents', ModuleKey::IncidentManagement, $this->distinct($rows)),
        ];
    }

    /**
     * @return list<DeferredOfflineReadSetSection>
     */
    public function deferredFor(OfflineReadSetScope $scope): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    private function incidentCommandEventIds(OfflineReadSetScope $scope): array
    {
        $eventIds = [];

        $grants = $scope->grantsFor(
            PermissionCatalog::ROLE_IC_LEAD,
            PermissionCatalog::ROLE_IC_OPERATOR,
            PermissionCatalog::ROLE_IC_VIEWER,
        );

        foreach ($grants as $grant) {
            $eventIds[$grant->eventId] = $grant->eventId;
        }

        ksort($eventIds);

        return array_values($eventIds);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventIncidents(string $eventId): array
    {
        /** @var list<string> $openIds */
        $openIds = Incident::query()
            ->where('event_id', $eventId)
            ->where('status', '!=', 'closed')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::OPEN_CAP)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        /** @var list<string> $recentIds */
        $recentIds = Incident::query()
            ->where('event_id', $eventId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::RECENT_CAP)
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $incidents = Incident::query()
            ->with([
                'createdByUser',
                'incidentTypes',
                'incidentStaff.staff',
                'timelineEntries.actorUser',
            ])
            ->whereKey([...$openIds, ...$recentIds])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return $this->map(
            $incidents,
            fn (Incident $incident): array => $this->serializer->payload($incident),
        );
    }
}
