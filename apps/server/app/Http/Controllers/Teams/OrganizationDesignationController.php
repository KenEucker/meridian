<?php

declare(strict_types=1);

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Team;
use App\Models\TeamDesignation;
use App\Services\Teams\OrganizationDesignationAccess;
use App\Services\Teams\TeamDesignationException;
use App\Services\Teams\TeamDesignationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Organization-level team designation read and commands (M18.12; TEAM-014,
 * TEAM-016).
 *
 * One designation exists at this level today: which team within the configured
 * Organizers Department carries Staff Coordinator authority. The read answers
 * with the current designation and the teams eligible to carry it, because the
 * eligibility rule — Organizers Department teams only — is the node's and the
 * configuration surface should offer only what would be accepted.
 */
final class OrganizationDesignationController extends Controller
{
    public function index(
        Request $request,
        Organization $organization,
        OrganizationDesignationAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canManageDesignations($user, $organization)) {
            return $this->refusal();
        }

        return response()->json($this->payload($organization));
    }

    public function designateStaffCoordinator(
        Request $request,
        OrganizationDesignationAccess $access,
        TeamDesignationService $designations,
    ): JsonResponse {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageDesignations($user, $organization)) {
            return $this->refusal();
        }

        $team = Team::query()->findOrFail((string) $validated['team_id']);

        try {
            $designations->designateStaffCoordinatorTeam(
                $organization,
                $team,
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (TeamDesignationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization));
    }

    public function removeStaffCoordinator(
        Request $request,
        OrganizationDesignationAccess $access,
        TeamDesignationService $designations,
    ): JsonResponse {
        $validated = $request->validate([
            'organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);

        $organization = Organization::query()->findOrFail((string) $validated['organization_id']);

        if (! $access->canManageDesignations($user, $organization)) {
            return $this->refusal();
        }

        try {
            $designations->removeStaffCoordinatorTeam($organization, $user, AuditEvent::SOURCE_API);
        } catch (TeamDesignationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($organization));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Organization $organization): array
    {
        $organizersDepartment = $organization->organizers_department_id !== null
            ? $organization->organizersDepartment()->first()
            : null;

        $designation = TeamDesignation::query()
            ->active()
            ->where('organization_id', $organization->id)
            ->whereNull('department_id')
            ->where('function_code', TeamDesignation::FUNCTION_STAFF_COORDINATOR)
            ->with('team')
            ->first();

        $eligibleTeams = $organizersDepartment === null
            ? collect()
            : Team::query()
                ->active()
                ->where('department_id', $organizersDepartment->id)
                ->orderBy('name')
                ->get();

        return [
            'organization_id' => (string) $organization->id,
            'organizers_department' => $organizersDepartment === null ? null : [
                'id' => (string) $organizersDepartment->id,
                'name' => $organizersDepartment->name,
            ],
            'staff_coordinator' => $designation === null ? null : [
                'function_code' => TeamDesignation::FUNCTION_STAFF_COORDINATOR,
                'function_label' => TeamDesignation::functionLabel(TeamDesignation::FUNCTION_STAFF_COORDINATOR),
                'team_id' => (string) $designation->team_id,
                'team_name' => $designation->team?->name,
            ],
            'eligible_teams' => $eligibleTeams
                ->map(fn (Team $team): array => [
                    'id' => (string) $team->id,
                    'name' => $team->name,
                ])
                ->values()
                ->all(),
        ];
    }

    private function refusal(): JsonResponse
    {
        return response()->json([
            'message' => 'Only organizers may maintain this organization\'s team designations.',
        ], 403);
    }
}
