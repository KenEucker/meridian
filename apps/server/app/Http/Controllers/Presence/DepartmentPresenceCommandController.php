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
 * Presence is connected-only. Data/API 7.2 closes the set of Alpha 1 offline
 * writes at Field Reports, check-in, check-out, and no-show, and going off-site
 * is refused on the strength of shifts and equipment the device cannot see the
 * whole of.
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
