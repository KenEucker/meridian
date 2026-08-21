<?php

declare(strict_types=1);

namespace App\Services\Offline;

use App\Domain\Modules\ModuleKey;
use App\Models\Event;
use App\Services\Documents\EventInfoService;
use App\Services\Offline\Concerns\ShapesOfflineRows;

/**
 * Event Info, assembled and rendered by the node, carried in the read set
 * (technical spec 9.3, 11.4A; POL-022; CLIENT-021).
 *
 * Section 9.3 exists because a volunteer at an event with no signal needs
 * their event's guidance — directions, arrival, packing — and Event Info is
 * that guidance. What kept it out of the set until 2026-08-20 was POL-022's
 * rule that the node renders documents with their fragments resolved: the set
 * carried document *source*, and a device must not run a second renderer. The
 * answer is not a second renderer; it is carrying the node's own render. Each
 * row here is `EventInfoService::sectionsFor`'s answer verbatim — the same
 * assembly, the same visibility rule, the same `rendered_html` the online
 * surface reads — composed at refresh time for the caller.
 *
 * One row per event in the caller's scope, gated by the service's own
 * staff-standing rule so the section reaches exactly the readers the endpoint
 * answers (11.4A: Event Info grants no visibility of its own).
 */
final class EventInfoSections implements OfflineReadSetContributor
{
    use ShapesOfflineRows;

    public function __construct(private readonly EventInfoService $eventInfo) {}

    /**
     * @return list<OfflineReadSetSection>
     */
    public function sectionsFor(OfflineReadSetScope $scope): array
    {
        if (! $scope->hasStaffProfile() || $scope->eventIds === []) {
            return [];
        }

        $rows = [];

        $events = Event::query()
            ->whereKey($scope->eventIds)
            ->orderBy('starts_at')
            ->orderBy('name')
            ->get();

        foreach ($events as $event) {
            if (! $this->eventInfo->canViewEventInfo($scope->user, $event)) {
                continue;
            }

            $rows[] = [
                'id' => (string) $event->getKey(),
                'event_id' => (string) $event->getKey(),
                'sections' => $this->eventInfo->sectionsFor($scope->user, $event),
            ];
        }

        if ($rows === []) {
            // No event passed the standing gate: no section rather than an
            // empty one, which would claim these events publish nothing.
            return [];
        }

        return [
            OfflineReadSetSection::owned('event_info', ModuleKey::Documents, $this->distinct($rows)),
        ];
    }

    /**
     * @return list<DeferredOfflineReadSetSection>
     */
    public function deferredFor(OfflineReadSetScope $scope): array
    {
        return [];
    }
}
