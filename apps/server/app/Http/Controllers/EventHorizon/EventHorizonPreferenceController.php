<?php

declare(strict_types=1);

namespace App\Http\Controllers\EventHorizon;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\EventHorizon\EventHorizonDismissalRefusedException;
use App\Services\EventHorizon\EventHorizonService;
use App\Services\EventHorizon\EventHorizonViewer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The Event Horizon's two preference commands (M18.44; HORIZON-012 through
 * HORIZON-015; data/API 5.8A).
 *
 * `hide-event-horizon` is refused while any item is outstanding (HORIZON-013).
 * The refusal is server-side rather than a hidden control, so a client that
 * offers the action early does not succeed. Both commands write personal view
 * state, are not audited (technical spec 21D.10), and act only on the caller's
 * own preference — neither accepts a subject staff member, so there is nothing
 * here for anyone to do to anyone else.
 */
final class EventHorizonPreferenceController extends Controller
{
    public function __construct(
        private readonly EventHorizonService $horizon,
    ) {}

    public function hide(Request $request): JsonResponse
    {
        [$event, $viewer] = $this->resolve($request);

        try {
            $this->horizon->hide($viewer, $event);
        } catch (EventHorizonDismissalRefusedException $exception) {
            /*
             * 409, not 422: the request was well-formed and the preference is
             * simply not available while work is outstanding — the same shape
             * governance refusals take.
             */
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['hidden' => true]);
    }

    public function showSurface(Request $request): JsonResponse
    {
        [$event, $viewer] = $this->resolve($request);

        $this->horizon->show($viewer, $event);

        return response()->json(['hidden' => false]);
    }

    /**
     * @return array{0: Event, 1: EventHorizonViewer}
     */
    private function resolve(Request $request): array
    {
        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $event = Event::query()->findOrFail((string) $validated['event_id']);

        return [$event, $this->horizon->viewerFor($user, $event)];
    }
}
