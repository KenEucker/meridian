<?php

namespace App\Http\Controllers\Trainings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\DepartmentMembership;
use App\Models\Team;
use App\Models\Training;
use App\Services\Training\TrainingProductAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrainingReadController extends Controller
{
    public function index(
        Request $request,
        Department $department,
        TrainingProductAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        if (! $access->canViewTrainings($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to view trainings for this department.',
            ], 403);
        }

        $canManage = $access->canManageTrainings($user, $department);

        $query = Training::query()
            ->where('department_id', $department->id)
            ->with(['prerequisiteTrainings', 'team', 'event', 'signups', 'completions', 'linkedShift'])
            ->orderBy('name');

        if (! $canManage) {
            $query->active();
        }

        $records = $query->get();

        /*
         * Whether this caller may record a completion for anything here. A team
         * lead is an authorized trainer for their own team's trainings without
         * managing the department's (TRAIN-005), so the roster below cannot be
         * gated on manage authority alone.
         */
        $canRecordAny = $records->contains(
            fn (Training $training): bool => $access->canRecordCompletions($user, $training),
        );

        $trainings = $records
            ->map(fn (Training $training): array => TrainingPayload::training(
                $training,
                $user,
                $canManage,
                $access->canRecordCompletions($user, $training),
            ))
            ->values()
            ->all();

        return response()->json([
            'department_id' => (string) $department->id,
            'organization_id' => (string) $department->organization_id,
            /*
             * The caller's authority over the surface as a whole, rather than
             * over one training. A department with no trainings yet still has to
             * decide whether the page offers to create one, and each training's
             * own `viewer` block cannot answer that.
             */
            'access' => [
                'can_manage' => $canManage,
                'can_record_completions' => $canRecordAny,
            ],
            /*
             * Option lists for the forms on this surface, on the terms the
             * caller holds them: team scope is a manager's field, and a roster
             * is visible only to authorized trainers and leads, so an ordinary
             * member's read carries neither.
             */
            'teams' => $canManage ? $this->teamOptions($department) : [],
            'department_staff' => $canManage || $canRecordAny
                ? $this->departmentStaffOptions($department)
                : [],
            'trainings' => $trainings,
        ]);
    }

    public function show(
        Request $request,
        Department $department,
        Training $training,
        TrainingProductAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        /*
         * A training belonging to another department is answered as a stated
         * refusal rather than a bare 404, so the surface can say what happened
         * instead of showing an empty form.
         */
        if ((string) $training->department_id !== (string) $department->id) {
            return response()->json([
                'message' => 'Training not found for this department.',
            ], 404);
        }

        if (! $access->canViewTrainings($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to view trainings for this department.',
            ], 403);
        }

        $canManage = $access->canManageTrainings($user, $department);

        if ($training->isArchived() && ! $canManage) {
            return response()->json([
                'message' => 'Training not found for this department.',
            ], 404);
        }

        $training->load([
            'prerequisiteTrainings',
            'team',
            'event',
            'linkedShift',
            'signups.staff',
            'completions.staff',
            'completions.recordedBy',
        ]);

        return response()->json(TrainingPayload::trainingDetail(
            $training,
            $user,
            $canManage,
            $access->canRecordCompletions($user, $training),
        ));
    }

    /**
     * The department's teams, for the team-scope field on a training.
     *
     * Archived teams are included and marked: a training already scoped to one
     * has to keep saying so.
     *
     * @return list<array<string, mixed>>
     */
    private function teamOptions(Department $department): array
    {
        return Team::query()
            ->where('department_id', $department->id)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(fn (Team $team): array => [
                'id' => (string) $team->id,
                'name' => $team->name,
                'is_default' => (bool) $team->is_default,
                'archived_at' => $team->archived_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Staff with active membership in the department, for the roster and
     * completion-recording fields.
     *
     * @return list<array<string, mixed>>
     */
    private function departmentStaffOptions(Department $department): array
    {
        return DepartmentMembership::query()
            ->active()
            ->where('department_id', $department->id)
            ->with('staff')
            ->get()
            ->map(fn (DepartmentMembership $membership): array => [
                'staff_id' => (string) $membership->staff_id,
                'display_name' => $membership->staff->preferred_name
                    ?: $membership->staff->legal_name,
                'email' => $membership->staff->email,
            ])
            ->sortBy('display_name')
            ->values()
            ->all();
    }
}
