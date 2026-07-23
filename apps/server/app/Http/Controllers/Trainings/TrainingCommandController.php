<?php

namespace App\Http\Controllers\Trainings;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\Staff;
use App\Models\Training;
use App\Models\User;
use App\Services\Shift\ShiftAssignmentException;
use App\Services\Shift\ShiftAssignmentService;
use App\Services\Shift\ShiftRemovalException;
use App\Services\Shift\ShiftRemovalService;
use App\Services\Shift\ShiftSignupException;
use App\Services\Shift\ShiftSignupService;
use App\Services\Training\TrainingAdminException;
use App\Services\Training\TrainingAdminService;
use App\Services\Training\TrainingPrerequisiteException;
use App\Services\Training\TrainingProductAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

final class TrainingCommandController extends Controller
{
    public function create(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            ...$this->trainingRules(),
        ]);

        $department = Department::query()->findOrFail((string) $validated['department_id']);

        if (! $access->canManageTrainings($user, $department)) {
            return $this->forbiddenManage();
        }

        try {
            $training = $trainings->create($department, $validated, $user);
        } catch (TrainingAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($training, $user, $access), 201);
    }

    public function update(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        [$user, $training, $department] = $this->resolveManagedTraining($request, $access);

        if ($department === null) {
            return $this->forbiddenManage();
        }

        $validated = $request->validate($this->trainingRules());

        try {
            $training = $trainings->update($training, $validated, $user);
        } catch (TrainingAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($training, $user, $access));
    }

    public function archive(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        [$user, $training, $department] = $this->resolveManagedTraining($request, $access);

        if ($department === null) {
            return $this->forbiddenManage();
        }

        $training = $trainings->archive($training, $user);

        return response()->json($this->payload($training, $user, $access));
    }

    public function restore(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        [$user, $training, $department] = $this->resolveManagedTraining($request, $access);

        if ($department === null) {
            return $this->forbiddenManage();
        }

        $training = $trainings->restore($training, $user);

        return response()->json($this->payload($training, $user, $access));
    }

    public function addPrerequisite(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        [$user, $training, $department] = $this->resolveManagedTraining($request, $access);

        if ($department === null) {
            return $this->forbiddenManage();
        }

        $validated = $request->validate([
            'prerequisite_training_id' => ['required', 'uuid', Rule::exists(Training::class, 'id')],
        ]);

        $prerequisite = Training::query()->findOrFail((string) $validated['prerequisite_training_id']);

        try {
            $trainings->addPrerequisite($training, $prerequisite, $user);
        } catch (TrainingPrerequisiteException|TrainingAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($training->refresh(), $user, $access));
    }

    public function removePrerequisite(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        [$user, $training, $department] = $this->resolveManagedTraining($request, $access);

        if ($department === null) {
            return $this->forbiddenManage();
        }

        $validated = $request->validate([
            'prerequisite_training_id' => ['required', 'uuid', Rule::exists(Training::class, 'id')],
        ]);

        $prerequisite = Training::query()->findOrFail((string) $validated['prerequisite_training_id']);

        try {
            $trainings->removePrerequisite($training, $prerequisite, $user);
        } catch (TrainingAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($training->refresh(), $user, $access));
    }

    public function signUp(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        return $this->signupCommand($request, $trainings, $access, cancel: false);
    }

    public function cancelSignup(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        return $this->signupCommand($request, $trainings, $access, cancel: true);
    }

    public function recordCompletion(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'training_id' => ['required', 'uuid', Rule::exists(Training::class, 'id')],
            'staff_id' => ['required', 'uuid', Rule::exists(Staff::class, 'id')],
            'completed_at' => ['sometimes', 'nullable', 'date'],
        ]);

        $training = Training::query()->findOrFail((string) $validated['training_id']);

        if (! $access->canRecordCompletions($user, $training)) {
            return response()->json([
                'message' => 'You do not have permission to record completions for this training.',
            ], 403);
        }

        $staff = Staff::query()->findOrFail((string) $validated['staff_id']);
        $completedAt = isset($validated['completed_at']) && $validated['completed_at'] !== null
            ? Carbon::parse((string) $validated['completed_at'])
            : null;

        try {
            $completion = $trainings->recordCompletion($training, $staff, $completedAt, $user);
        } catch (TrainingAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'training_id' => (string) $training->id,
            'staff_id' => (string) $staff->id,
            'completed_at' => $completion->completed_at?->toIso8601String(),
            'expires_at' => $completion->expires_at?->toIso8601String(),
        ], 201);
    }

    public function importCompletions(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'training_id' => ['required', 'uuid', Rule::exists(Training::class, 'id')],
            'csv' => ['required', 'string'],
        ]);

        $training = Training::query()->findOrFail((string) $validated['training_id']);
        $training->loadMissing('department');

        if ($training->department === null
            || ! $access->canManageTrainings($user, $training->department)) {
            return $this->forbiddenManage();
        }

        try {
            $result = $trainings->importCompletions($training, (string) $validated['csv'], $user);
        } catch (TrainingAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($result);
    }

    private function signupCommand(
        Request $request,
        TrainingAdminService $trainings,
        TrainingProductAccess $access,
        bool $cancel,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'training_id' => ['required', 'uuid', Rule::exists(Training::class, 'id')],
            'staff_id' => ['sometimes', 'nullable', 'uuid', Rule::exists(Staff::class, 'id')],
        ]);

        $training = Training::query()
            ->with(['department', 'linkedShift'])
            ->findOrFail((string) $validated['training_id']);

        if ($training->department === null) {
            return response()->json(['message' => 'This training is not managed through a department.'], 422);
        }

        if ($training->isOnline()) {
            return response()->json([
                'message' => 'Online trainings do not require signup. Visit the training page instead.',
            ], 422);
        }

        $staffId = $validated['staff_id'] ?? null;
        $selfSignup = $staffId === null;

        if ($staffId !== null) {
            // Managers and trainers maintain the roster for other staff.
            if (! $access->canRecordCompletions($user, $training)) {
                return response()->json([
                    'message' => 'You do not have permission to manage the roster for this training.',
                ], 403);
            }

            $staff = Staff::query()->findOrFail((string) $staffId);
        } else {
            $staff = $access->signupProfile($user, $training->department, $training);

            if ($staff === null) {
                return response()->json([
                    'message' => 'You must have active membership in this department to sign up.',
                ], 403);
            }
        }

        // Event-bound in-person trainings materialize a linked shift, so
        // signup flows through the normal shift signup machinery.
        if ($training->linkedShift !== null && $training->linkedShift->cancelled_at === null) {
            return $this->linkedShiftSignup($training, $staff, $user, $selfSignup, $cancel);
        }

        try {
            $signup = $cancel
                ? $trainings->cancelSignup($training, $staff, $user)
                : $trainings->signUp($training, $staff, $user);
        } catch (TrainingAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'training_id' => (string) $training->id,
            'staff_id' => (string) $staff->id,
            'signed_up_at' => $signup->signed_up_at?->toIso8601String(),
            'cancelled_at' => $signup->cancelled_at?->toIso8601String(),
            'active_signup_count' => $training->refresh()->activeSignupCount(),
        ], $cancel ? 200 : 201);
    }

    private function linkedShiftSignup(
        Training $training,
        Staff $staff,
        User $user,
        bool $selfSignup,
        bool $cancel,
    ): JsonResponse {
        $shift = $training->linkedShift;

        try {
            if ($cancel) {
                $assignment = ShiftAssignment::query()
                    ->active()
                    ->where('shift_id', $shift->id)
                    ->where('staff_id', $staff->id)
                    ->first();

                if ($assignment === null) {
                    return response()->json(['message' => 'This staff member is not signed up.'], 422);
                }

                $assignment = $selfSignup
                    ? app(ShiftRemovalService::class)->withdrawFromShift($assignment, $staff, $user)
                    : app(ShiftRemovalService::class)->removeStaffFromShift($assignment, $user);
            } else {
                $outcome = $selfSignup
                    ? app(ShiftSignupService::class)->signUp($shift, $staff, $user)
                    : app(ShiftAssignmentService::class)->assignStaffToShift($shift, $staff, $user);
                $assignment = $outcome->assignment;
            }
        } catch (ShiftSignupException|ShiftAssignmentException|ShiftRemovalException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'training_id' => (string) $training->id,
            'staff_id' => (string) $staff->id,
            'shift_id' => (string) $shift->id,
            'signed_up_at' => $assignment->created_at?->toIso8601String(),
            'cancelled_at' => $assignment->removed_at?->toIso8601String(),
            'active_signup_count' => ShiftAssignment::query()
                ->active()
                ->where('shift_id', $shift->id)
                ->count(),
        ], $cancel ? 200 : 201);
    }

    /**
     * Resolve the acting user, requested training, and its department when the
     * user may manage that department's trainings; department is null when the
     * user lacks manage authority.
     *
     * @return array{0: User, 1: Training, 2: Department|null}
     */
    private function resolveManagedTraining(Request $request, TrainingProductAccess $access): array
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'training_id' => ['required', 'uuid', Rule::exists(Training::class, 'id')],
        ]);

        $training = Training::query()->with('department')->findOrFail((string) $validated['training_id']);
        $department = $training->department;

        if ($department === null || ! $access->canManageTrainings($user, $department)) {
            return [$user, $training, null];
        }

        return [$user, $training, $department];
    }

    private function forbiddenManage(): JsonResponse
    {
        return response()->json([
            'message' => 'You do not have permission to manage trainings for this department.',
        ], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Training $training, User $user, TrainingProductAccess $access): array
    {
        $training->load(['prerequisiteTrainings', 'team', 'event', 'signups', 'completions', 'linkedShift']);

        return TrainingPayload::training(
            $training,
            $user,
            true,
            $access->canRecordCompletions($user, $training),
        );
    }

    /**
     * @return array<string, list<mixed>>
     */
    private function trainingRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'team_id' => ['sometimes', 'nullable', 'uuid'],
            'event_id' => ['sometimes', 'nullable', 'uuid'],
            'expires_after_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'delivery' => ['sometimes', 'string', Rule::in([Training::DELIVERY_IN_PERSON, Training::DELIVERY_ONLINE])],
            'online_url' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'scheduled_start_at' => ['sometimes', 'nullable', 'date'],
            'scheduled_end_at' => ['sometimes', 'nullable', 'date'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'capacity' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
            'time_commitment' => ['sometimes', 'nullable', 'string', 'max:255'],
            'after_training' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'provisions' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ];
    }
}
