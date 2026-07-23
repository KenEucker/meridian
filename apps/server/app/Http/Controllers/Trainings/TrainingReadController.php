<?php

namespace App\Http\Controllers\Trainings;

use App\Http\Controllers\Controller;
use App\Models\Department;
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

        $trainings = $query->get()
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
        abort_unless((string) $training->department_id === (string) $department->id, 404);

        if (! $access->canViewTrainings($user, $department)) {
            return response()->json([
                'message' => 'You do not have permission to view trainings for this department.',
            ], 403);
        }

        $canManage = $access->canManageTrainings($user, $department);

        if ($training->isArchived() && ! $canManage) {
            abort(404);
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
}
