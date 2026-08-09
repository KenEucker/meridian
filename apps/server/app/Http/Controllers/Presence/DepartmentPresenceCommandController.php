<?php

namespace App\Http\Controllers\Presence;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Event;
use App\Models\Staff;
use App\Services\Presence\DepartmentPresenceException;
use App\Services\Presence\DepartmentPresenceResult;
use App\Services\Presence\DepartmentPresenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Marking department staff on-site and off-site (M16.21; SLB-015 through
 * SLB-018; technical spec 20.3).
 *
 * `DepartmentPresenceService` has enforced these rules since M10.4 and nothing
 * could reach it: presence had no endpoint, so the Logistics Window's on-site
 * and off-site buttons moved a value in the browser and no record anywhere.
 * These two routes are the transport, and every rule stays where it was.
 *
 * The two halves are not the same command as far as offline goes, and M18.54
 * split them deliberately.
 *
 * **On-site is an Alpha 1 offline write** (technical spec 9.4, data/API 7.2).
 * It records what an operator saw — this person is standing here — and every
 * rule it is decided against is one the node holds when the command arrives:
 * the department belongs to the event's organization, the caller may manage
 * presence, the staff member is an active department member. It is idempotent
 * by construction: marking somebody on-site who is already on-site changes
 * nothing and reports so, which is what makes a replayed delivery safe without
 * a key of its own. `marked_at` is the device's moment, so a mark made at 02:10
 * and delivered at 06:00 is recorded at 02:10.
 *
 * **Off-site stays connected-only.** SLB-018 refuses it on the strength of
 * every open equipment checkout and every checked-in shift in the department,
 * which is state the device holds no whole copy of; a queued off-site mark
 * would be one an operator was told was captured and the node then refused
 * hours later.
 */
final class DepartmentPresenceCommandController extends Controller
{
    public function markOnSite(
        Request $request,
        DepartmentPresenceService $presence,
    ): JsonResponse {
        return $this->mark($request, $presence, onSite: true);
    }

    public function markOffSite(
        Request $request,
        DepartmentPresenceService $presence,
    ): JsonResponse {
        return $this->mark($request, $presence, onSite: false);
    }

    private function mark(
        Request $request,
        DepartmentPresenceService $presence,
        bool $onSite,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'event_id' => ['required', 'uuid', 'exists:events,id'],
            'department_id' => ['required', 'uuid', 'exists:departments,id'],
            'staff_id' => ['required', 'uuid', 'exists:staff,id'],
            'marked_at' => ['nullable', 'date'],
        ]);

        $event = Event::query()->findOrFail((string) $validated['event_id']);
        $department = Department::query()->findOrFail((string) $validated['department_id']);
        $staff = Staff::query()->findOrFail((string) $validated['staff_id']);
        $markedAt = isset($validated['marked_at'])
            ? Carbon::parse((string) $validated['marked_at'])
            : null;

        try {
            $result = $onSite
                ? $presence->markOnSite($event, $department, $staff, $user, $markedAt)
                : $presence->markOffSite($event, $department, $staff, $user, $markedAt);
        } catch (DepartmentPresenceException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($result), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(DepartmentPresenceResult $result): array
    {
        return [
            'presence_id' => (string) $result->presence->id,
            'event_id' => (string) $result->presence->event_id,
            'department_id' => (string) $result->presence->department_id,
            'staff_id' => (string) $result->presence->staff_id,
            'current_state' => $result->presence->current_state,
            'marked_on_site_at' => $result->presence->marked_on_site_at?->toIso8601String(),
            'marked_off_site_at' => $result->presence->marked_off_site_at?->toIso8601String(),
            'created_state_change' => $result->createdStateChange,
        ];
    }
}
