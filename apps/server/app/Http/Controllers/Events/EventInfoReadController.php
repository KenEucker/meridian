<?php

declare(strict_types=1);

namespace App\Http\Controllers\Events;

use App\Domain\Documents\EventInfoSection;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\Documents\EventInfoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff-facing Event Info read transport (M11.20).
 *
 * UI implementation contract section 12.3 lists `event.info` as the staff-safe
 * event information surface. Until M11.20 it carried placeholder prose; it now
 * resolves the published policy/procedure documents the caller is permitted to
 * see, assembled by the rules in EventInfoService.
 */
final class EventInfoReadController extends Controller
{
    public function show(Request $request, Event $event, EventInfoService $eventInfo): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $eventInfo->canViewEventInfo($user, $event)) {
            return response()->json([
                'message' => 'Event information requires staff standing in this event organization.',
            ], 403);
        }

        return response()->json([
            'event' => [
                'id' => (string) $event->id,
                'organization_id' => (string) $event->organization_id,
                'name' => $event->name,
                'slug' => $event->slug,
                'timezone' => $event->timezone,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'status' => $event->status,
            ],
            'section_order' => EventInfoSection::keys(),
            'sections' => $eventInfo->sectionsFor($user, $event),
        ]);
    }
}
