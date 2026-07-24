<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Staff;
use App\Models\Team;
use App\Models\TeamMembership;
use App\Services\Departments\DepartmentSelfAdminAccess;
use App\Services\Membership\TeamAssignmentException;
use App\Services\Membership\TeamMembershipService;
use App\Services\Teams\TeamAdminException;
use App\Services\Teams\TeamLeadDesignationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Product-path team lead designation and team staff assignment (M11.17).
 *
 * Lead designation is restricted to department administer authority; staff
 * assignment/removal is open to department administer authority and to
 * designated leads of the target team.
 */
final class TeamStaffCommandController extends Controller
{
    public function selectTeamLead(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamLeadDesignationService $leads,
    ): JsonResponse {
        [$staff, $team] = $this->validatedStaffAndTeam($request);

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canAdministerDepartment($user, $team->department)) {
            return response()->json([
                'message' => 'You do not have permission to designate leads for this department.',
            ], 403);
        }

        try {
            $membership = $leads->selectTeamLead($staff, $team, $user, AuditEvent::SOURCE_API);
        } catch (TeamAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->membershipPayload($membership), 201);
    }

    public function removeTeamLead(
        Request $request,
        DepartmentSelfAdminAccess $access,
        TeamLeadDesignationService $leads,
    ): JsonResponse {
        [$staff, $team] = $this->validatedStaffAndTeam($request);

        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canAdministerDepartment($user, $team->department)) {
            return response()->json([
                'message' => 'You do not have permission to designate leads for this department.',
            ], 403);
        }

        try {
            $membership = $leads->removeTeamLead($staff, $team, $user, AuditEvent::SOURCE_API);
        } catch (TeamAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->membershipPayload($membership));
    }

    public function assignStaffToTeam(
        Request $request,
        TeamMembershipService $memberships,
    ): JsonResponse {
        [$staff, $team] = $this->validatedStaffAndTeam($request);

        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $membership = $memberships->assignStaffToTeam($staff, $team, $user, AuditEvent::SOURCE_API);
        } catch (TeamAssignmentException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $this->assignmentStatus($exception),
            );
        }

        return response()->json($this->membershipPayload($membership), 201);
    }

    public function removeStaffFromTeam(
        Request $request,
        TeamMembershipService $memberships,
    ): JsonResponse {
        [$staff, $team] = $this->validatedStaffAndTeam($request);

        $user = $request->user();
        abort_unless($user !== null, 401);

        try {
            $membership = $memberships->removeStaffFromTeam($staff, $team, $user, AuditEvent::SOURCE_API);
        } catch (TeamAssignmentException $exception) {
            return response()->json(
                ['message' => $exception->getMessage()],
                $this->assignmentStatus($exception),
            );
        }

        return response()->json($this->membershipPayload($membership));
    }

    /**
     * @return array{0: Staff, 1: Team}
     */
    private function validatedStaffAndTeam(Request $request): array
    {
        $validated = $request->validate([
            'staff_id' => ['required', 'uuid', Rule::exists(Staff::class, 'id')],
            'team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
        ]);

        $staff = Staff::query()->findOrFail((string) $validated['staff_id']);
        $team = Team::query()
            ->with('department')
            ->findOrFail((string) $validated['team_id']);

        return [$staff, $team];
    }

    private function assignmentStatus(TeamAssignmentException $exception): int
    {
        return str_contains($exception->getMessage(), 'not authorized') ? 403 : 422;
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipPayload(TeamMembership $membership): array
    {
        return [
            'id' => (string) $membership->id,
            'team_id' => (string) $membership->team_id,
            'team_name' => $membership->team?->name,
            'staff_id' => (string) $membership->staff_id,
            'membership_role' => $membership->membership_role,
            'archived_at' => $membership->archived_at?->toIso8601String(),
        ];
    }
}
