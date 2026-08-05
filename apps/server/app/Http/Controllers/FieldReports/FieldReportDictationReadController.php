<?php

declare(strict_types=1);

namespace App\Http\Controllers\FieldReports;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Services\FieldReports\FieldReportOnBehalfAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff an operator may name when taking a Field Report (M18.24A; FR-015,
 * FR-017).
 *
 * GET /api/events/{event}/field-report-dictation
 *
 * The client's dictation picker read the Logistics Window until now, which
 * scoped it to a department the operator happened to be looking at rather than
 * to the authority they hold — an `ic_operator` working the event got whichever
 * department was on screen, and a Department Operator got one only because that
 * is the page they came from. Neither was FR-017's answer.
 *
 * This read is FR-017's answer: the staff this caller's taking authority already
 * reaches, and nothing else. A caller with no such authority gets a refusal
 * rather than an empty list — an empty picker is indistinguishable from an event
 * with nobody in it, and an operator staring at one has no way to tell that they
 * were never allowed to take reports in the first place.
 */
final class FieldReportDictationReadController extends Controller
{
    public function __invoke(
        Request $request,
        Event $event,
        FieldReportOnBehalfAccess $onBehalf,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $onBehalf->canTakeReports($user, $event)) {
            return response()->json([
                'message' => 'Taking a Field Report for another staff member requires Department Operator or Incident Command authority for this event.',
            ], 403);
        }

        return response()->json([
            'event_id' => (string) $event->id,
            'staff' => $onBehalf->selectableStaffOptions($user, $event),
        ]);
    }
}
