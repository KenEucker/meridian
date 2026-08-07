<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventHorizon;

use App\Domain\EventHorizon\EventHorizonItem;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EventHorizon\EventHorizonItemKind;
use App\Services\EventHorizon\EventHorizonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The Event Horizon read (M18.38; HORIZON-001 through HORIZON-008; data/API
 * 5.8A).
 *
 * One GET answers the whole surface: the registered kinds this caller can
 * read, their items in the server's own order (HORIZON-006, so two clients
 * render the same list and ordering is not a presentation decision), whether
 * the presentation window applies, and whether the caller has hidden it.
 *
 * Authorization is event access alone (HORIZON-002): the endpoint requires no
 * capability of its own, and each kind is evaluated under the caller's
 * existing authorization for the domain it reads — a kind whose records the
 * caller cannot read is omitted from the response rather than returned empty
 * or as inaccessible.
 *
 * A caller outside the presentation window receives the same shape with the
 * window reported as not applicable rather than a 404, so a client can
 * distinguish "not yet" from "no such event".
 *
 * The read is a read and only a read: it compiles on request, persists no
 * compiled result (technical spec 21D.3), refuses no operation, and records
 * no audit event (21D.10).
 */
final class EventHorizonReadController extends Controller
{
    public function __construct(
        private readonly EventHorizonService $horizon,
    ) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $viewer = $this->horizon->viewerFor($user, $event);

        if (! $viewer->isEventStaff()) {
            return response()->json([
                'message' => 'You are not staff of this event, so it has no readiness list for you.',
            ], 403);
        }

        $now = Carbon::now();
        $items = $this->horizon->compile($viewer, $event, $now);
        $hasOutstanding = $this->horizon->hasOutstanding($items);
        $kinds = $this->horizon->availableKinds($viewer, $event);

        return response()->json([
            'context' => [
                'event_id' => (string) $event->getKey(),
                'event_label' => $event->name,
                'organization_id' => (string) $event->organization_id,
                'time_zone' => $event->timezone ?: config('app.timezone'),
                'as_of' => $now->toIso8601String(),
            ],
            'window' => $this->horizon->windowFor($event, $now)->toArray(),
            /*
             * Presence is the window plus the kinds (HORIZON-017): where no
             * kind is available to this viewer the surface is not presented at
             * all, rather than rendered as an empty list that reads as "you
             * are ready".
             */
            'presentable' => $kinds !== [],
            'hidden' => $this->horizon->isHidden($viewer, $event, $hasOutstanding),
            // The hide control is offered only at zero outstanding items
            // (HORIZON-013), and the command below enforces the same rule.
            'can_hide' => ! $hasOutstanding,
            'outstanding_count' => count(array_filter(
                $items,
                fn (EventHorizonItem $item): bool => $item->state->value === 'outstanding',
            )),
            'kinds' => array_map(
                fn (EventHorizonItemKind $kind): array => $kind->definition()->describe(),
                $kinds,
            ),
            'items' => array_map(
                fn (EventHorizonItem $item): array => $item->toArray(),
                $items,
            ),
        ]);
    }
}
