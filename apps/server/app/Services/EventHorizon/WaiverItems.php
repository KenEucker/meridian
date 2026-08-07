<?php

declare(strict_types=1);

namespace App\Services\EventHorizon;

use App\Domain\EventHorizon\EventHorizonCatalog;
use App\Domain\EventHorizon\EventHorizonItem;
use App\Domain\EventHorizon\EventHorizonItemKindDefinition;
use App\Domain\EventHorizon\EventHorizonItemState;
use App\Models\Event;
use App\Models\Waiver;
use App\Models\WaiverCompletion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Outstanding or expired waivers (M18.39; HORIZON-003; WAIVER-003 through
 * WAIVER-006, CRED-005).
 *
 * One item per active waiver in the member's scope: the event organization's
 * organization-wide waivers, those of departments the member actively belongs
 * to, and those of teams they are on — the same scope resolution the M18.18
 * waiver administration applies from the maintainer's side.
 *
 * An expired completion reads outstanding, not complete, and says so in its
 * own words: WAIVER-006 makes an expired required waiver a credential block
 * until renewed, and a list that read "complete" off a lapsed completion would
 * be reporting the one state the requirement exists to prevent. The item
 * distinguishes never-completed from expired because the member does different
 * things about them — read and complete versus renew.
 *
 * The action link opens the waiver administration surface, because completion
 * is *recorded* by an authorized maintainer (WAIVER-010) rather than
 * self-served; a member follows it under that surface's own authorization
 * (HORIZON-004), and the evaluation text tells them who actually resolves it.
 */
final class WaiverItems extends EventHorizonItemKind
{
    public function definition(): EventHorizonItemKindDefinition
    {
        return EventHorizonCatalog::definitions()[1];
    }

    /**
     * Waiver completions are the viewer's own records (WAIVER-003), so
     * standing is the gate, exactly as it is for acknowledgments.
     */
    public function availableTo(EventHorizonViewer $viewer, Event $event): bool
    {
        return $viewer->isEventStaff();
    }

    /**
     * @return list<EventHorizonItem>
     */
    public function compile(EventHorizonViewer $viewer, Event $event, Carbon $now): array
    {
        $departmentIds = $viewer->departmentIds();
        $teamIds = $viewer->teamIds;

        $waivers = Waiver::query()
            ->active()
            ->where('organization_id', $event->organization_id)
            ->where(function (Builder $query) use ($event, $departmentIds, $teamIds): void {
                $query
                    ->where(fn (Builder $scoped) => $scoped
                        ->where('scope_type', Waiver::SCOPE_ORGANIZATION)
                        ->where('scope_id', $event->organization_id))
                    ->orWhere(fn (Builder $scoped) => $scoped
                        ->where('scope_type', Waiver::SCOPE_DEPARTMENT)
                        ->whereIn('scope_id', $departmentIds))
                    ->orWhere(fn (Builder $scoped) => $scoped
                        ->where('scope_type', Waiver::SCOPE_TEAM)
                        ->whereIn('scope_id', $teamIds));
            })
            ->orderBy('name')
            ->get();

        if ($waivers->isEmpty()) {
            return [];
        }

        $completions = WaiverCompletion::query()
            ->whereIn('waiver_id', $waivers->pluck('id')->all())
            ->whereIn('staff_id', $viewer->staffIds)
            ->orderByDesc('completed_at')
            ->get()
            ->groupBy(fn (WaiverCompletion $completion): string => (string) $completion->waiver_id);

        $items = [];

        foreach ($waivers as $waiver) {
            $latest = $completions->get((string) $waiver->getKey())?->first();
            $current = $latest !== null && ! $latest->isExpiredAt($now);
            $expired = $latest !== null && $latest->isExpiredAt($now);

            $items[] = new EventHorizonItem(
                kind: $this->definition()->id,
                identity: 'waiver:'.(string) $waiver->getKey(),
                state: $current
                    ? EventHorizonItemState::Complete
                    : EventHorizonItemState::Outstanding,
                title: (string) $waiver->name,
                evaluation: match (true) {
                    $current => 'Your completion of this waiver is on record and current.',
                    $expired => sprintf(
                        'Your completion of this waiver expired %s. An expired required waiver blocks credential eligibility until renewed.',
                        $latest->expires_at?->toDateString() ?? 'earlier',
                    ),
                    default => 'This waiver is required in your scope and no completion is on record for you.',
                },
                completion: $current
                    ? 'Nothing — this is done.'
                    : ($expired
                        ? 'Complete the waiver again with an authorized maintainer so the renewal is recorded.'
                        : 'Complete the waiver with an authorized maintainer so your completion is recorded.'),
                dueAt: null,
                actionSurface: 'organizer.waivers',
                actionLabel: 'Open waiver administration',
            );
        }

        return $items;
    }
}
