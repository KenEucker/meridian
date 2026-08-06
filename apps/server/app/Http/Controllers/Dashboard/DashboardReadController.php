<?php

declare(strict_types=1);

namespace App\Http\Controllers\Dashboard;

use App\Domain\Dashboard\DashboardWidget;
use App\Domain\Dashboard\DashboardWidgetGroup;
use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Event;
use App\Services\Dashboard\DashboardAudienceResolver;
use App\Services\Dashboard\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The dashboard read (M18.28; UI contract 13.1 through 13.6).
 *
 * One read for every dashboard surface. `staff.dashboard`, `department.dashboard`,
 * `organizer.dashboard`, `ims.dashboard`, and `kiosk.home` are five presentations
 * of the same question — what needs this person's attention in this event — and
 * five endpoints would have been five places for the answers to disagree. A
 * surface asks for the groups it renders and gets the ones its reader holds.
 *
 * Three things are decided here and not on screen.
 *
 *  1. **Which groups open.** `DashboardAudience` resolves standing from the same
 *     services the surfaces behind the widgets use. A group the reader does not
 *     hold is absent from the response, not empty (CLIENT-005), so a dashboard
 *     cannot disclose the shape of a department somebody has no standing in.
 *  2. **What a widget says when it has nothing.** The quiet sentence comes from
 *     the contract through the catalogue, so two nodes cannot word the same
 *     reassurance differently and a client cannot invent one.
 *  3. **Nothing about ordering.** Widgets come back in contract order. The
 *     mobile priority feed of widget spec 8 orders by attention, and that is
 *     presentation — the same payload is a grid on a desk and a feed on a phone.
 *
 * The response is a read and only a read. It writes nothing, refuses no
 * operation, and records no audit event: everything on it is already readable by
 * the caller through the surface each widget points at.
 */
final class DashboardReadController extends Controller
{
    public function __construct(
        private readonly DashboardAudienceResolver $audiences,
        private readonly DashboardService $dashboards,
    ) {}

    public function show(Request $request, Event $event): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $department = $this->requestedDepartment($request, $event);

        if ($department instanceof JsonResponse) {
            return $department;
        }

        $audience = $this->audiences->resolve($request, $user, $event, $department);

        if (! $audience->hasAnyGroup()) {
            return response()->json([
                'message' => 'You do not have a dashboard for this event.',
            ], 403);
        }

        $now = Carbon::now();
        $widgets = $this->dashboards->compile($user, $event, $audience, $now);

        return response()->json([
            'context' => [
                'event_id' => (string) $event->getKey(),
                'event_label' => $event->name,
                'organization_id' => (string) $event->organization_id,
                'department_id' => $audience->department === null
                    ? null
                    : (string) $audience->department->getKey(),
                'department_label' => $audience->department?->name,
                'time_zone' => $event->timezone ?: config('app.timezone'),
                'as_of' => $now->toIso8601String(),
            ],
            /*
             * The groups this reader holds, named separately from the widgets so
             * a surface can say "you have no organizer dashboard" rather than
             * rendering an empty region and leaving the reader to guess whether
             * the event is quiet or they are not permitted.
             */
            'groups' => array_map(
                fn (DashboardWidgetGroup $group): array => [
                    'group' => $group->value,
                    'label' => $group->label(),
                    'contract_section' => $group->contractSection(),
                ],
                $audience->groups(),
            ),
            'widgets' => array_map(
                fn (DashboardWidget $widget): array => $widget->toArray(),
                $widgets,
            ),
            'inventory' => $this->dashboards->inventory(),
        ]);
    }

    /**
     * The department the department-scoped groups compile against.
     *
     * Named by the caller, and validated against the event's organization rather
     * than trusted: a department of another organization is not found, which is
     * the same answer the department operations reads give and for the same
     * reason. Omitting it is legitimate — an organizer's dashboard and an IC
     * dashboard are event-scoped — and a Kiosk's pinned department is resolved
     * later, from the workstation rather than from the request.
     */
    private function requestedDepartment(Request $request, Event $event): Department|JsonResponse|null
    {
        $departmentId = trim((string) $request->query('department_id', ''));

        if ($departmentId === '') {
            return null;
        }

        $department = Department::query()->find($departmentId);

        if ($department === null
            || (string) $department->organization_id !== (string) $event->organization_id) {
            return response()->json([
                'message' => 'Department not found for this event.',
            ], 404);
        }

        return $department;
    }
}
