<?php

namespace App\Http\Controllers\Shifts;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Shift;
use App\Models\Team;
use App\Models\User;
use App\Services\Shift\ShiftAdminAccess;
use App\Services\Shift\ShiftAdminException;
use App\Services\Shift\ShiftAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Product-path shift create/maintain commands for department and team leads
 * (M11.17; UI contract 12.4 `department.shift-create`, `department.shift-edit`).
 */
final class ShiftAdminCommandController extends Controller
{
    public function create(
        Request $request,
        ShiftAdminAccess $access,
        ShiftAdminService $shifts,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'event_id' => ['required', 'uuid'],
            'eligible_team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'capacity' => ['nullable', 'integer'],
            'signup_opens_at' => ['nullable', 'date'],
            'signup_closes_at' => ['nullable', 'date'],
            'schedule_lock_at' => ['nullable', 'date'],
            'schedule_lock_offset_minutes' => ['nullable', 'integer', 'min:1'],
            'credit_policy_id' => ['nullable', 'uuid'],
            'custom_credit_multiplier' => ['nullable', 'numeric'],
            'required_training_ids' => ['array'],
            'required_training_ids.*' => ['uuid'],
            'required_waiver_ids' => ['array'],
            'required_waiver_ids.*' => ['uuid'],
        ]);

        $department = Department::query()->findOrFail((string) $validated['department_id']);
        $team = Team::query()->findOrFail((string) $validated['eligible_team_id']);

        if (! $access->canManageShiftForTeam($user, $department, $team)) {
            return response()->json([
                'message' => 'You do not have permission to create shifts for this team.',
            ], 403);
        }

        try {
            $shift = $shifts->create(
                $department,
                $this->attributes($validated),
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (ShiftAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($shift), 201);
    }

    public function update(
        Request $request,
        ShiftAdminAccess $access,
        ShiftAdminService $shifts,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'shift_id' => ['required', 'uuid', Rule::exists(Shift::class, 'id')],
            'eligible_team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'capacity' => ['nullable', 'integer'],
            'signup_opens_at' => ['nullable', 'date'],
            'signup_closes_at' => ['nullable', 'date'],
            'schedule_lock_at' => ['nullable', 'date'],
            'schedule_lock_offset_minutes' => ['nullable', 'integer', 'min:1'],
            'credit_policy_id' => ['nullable', 'uuid'],
            'custom_credit_multiplier' => ['nullable', 'numeric'],
            'required_training_ids' => ['array'],
            'required_training_ids.*' => ['uuid'],
            'required_waiver_ids' => ['array'],
            'required_waiver_ids.*' => ['uuid'],
        ]);

        $shift = Shift::query()
            ->with(['department', 'eligibleTeam'])
            ->findOrFail((string) $validated['shift_id']);

        if (! $access->canManageShift($user, $shift)) {
            return response()->json([
                'message' => 'You do not have permission to manage this shift.',
            ], 403);
        }

        $targetTeam = Team::query()->findOrFail((string) $validated['eligible_team_id']);

        if (! $access->canManageShiftForTeam($user, $shift->department, $targetTeam)) {
            return response()->json([
                'message' => 'You do not have permission to move this shift to the selected team.',
            ], 403);
        }

        try {
            $shift = $shifts->update(
                $shift,
                $this->attributes($validated),
                $user,
                AuditEvent::SOURCE_API,
            );
        } catch (ShiftAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($shift));
    }

    public function cancel(
        Request $request,
        ShiftAdminAccess $access,
        ShiftAdminService $shifts,
    ): JsonResponse {
        return $this->transition($request, $access, fn (Shift $shift, $user) => $shifts->cancel($shift, $user, AuditEvent::SOURCE_API));
    }

    public function restore(
        Request $request,
        ShiftAdminAccess $access,
        ShiftAdminService $shifts,
    ): JsonResponse {
        return $this->transition($request, $access, fn (Shift $shift, $user) => $shifts->restore($shift, $user, AuditEvent::SOURCE_API));
    }

    /**
     * @param  callable(Shift, User): Shift  $operation
     */
    private function transition(
        Request $request,
        ShiftAdminAccess $access,
        callable $operation,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $validated = $request->validate([
            'shift_id' => ['required', 'uuid', Rule::exists(Shift::class, 'id')],
        ]);

        $shift = Shift::query()
            ->with(['department', 'eligibleTeam'])
            ->findOrFail((string) $validated['shift_id']);

        if (! $access->canManageShift($user, $shift)) {
            return response()->json([
                'message' => 'You do not have permission to manage this shift.',
            ], 403);
        }

        try {
            $shift = $operation($shift, $user);
        } catch (ShiftAdminException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json($this->payload($shift));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributes(array $validated): array
    {
        return [
            'event_id' => (string) ($validated['event_id'] ?? ''),
            'eligible_team_id' => (string) $validated['eligible_team_id'],
            'title' => (string) $validated['title'],
            'starts_at' => Carbon::parse((string) $validated['starts_at']),
            'ends_at' => Carbon::parse((string) $validated['ends_at']),
            'capacity' => $validated['capacity'] ?? null,
            'signup_opens_at' => isset($validated['signup_opens_at']) && $validated['signup_opens_at'] !== null
                ? Carbon::parse((string) $validated['signup_opens_at'])
                : null,
            'signup_closes_at' => isset($validated['signup_closes_at']) && $validated['signup_closes_at'] !== null
                ? Carbon::parse((string) $validated['signup_closes_at'])
                : null,
            'schedule_lock_at' => isset($validated['schedule_lock_at']) && $validated['schedule_lock_at'] !== null
                ? Carbon::parse((string) $validated['schedule_lock_at'])
                : null,
            'schedule_lock_offset_minutes' => isset($validated['schedule_lock_offset_minutes']) && $validated['schedule_lock_offset_minutes'] !== null
                ? (int) $validated['schedule_lock_offset_minutes']
                : null,
            'credit_policy_id' => isset($validated['credit_policy_id']) && $validated['credit_policy_id'] !== null
                ? (string) $validated['credit_policy_id']
                : null,
            'custom_credit_multiplier' => $validated['custom_credit_multiplier'] ?? null,
            'required_training_ids' => array_map(
                fn ($id): string => (string) $id,
                $validated['required_training_ids'] ?? [],
            ),
            'required_waiver_ids' => array_map(
                fn ($id): string => (string) $id,
                $validated['required_waiver_ids'] ?? [],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Shift $shift): array
    {
        return [
            'id' => (string) $shift->id,
            'event_id' => (string) $shift->event_id,
            'department_id' => (string) $shift->department_id,
            'eligible_team_id' => (string) $shift->eligible_team_id,
            'title' => $shift->title,
            'starts_at' => $shift->starts_at?->toIso8601String(),
            'ends_at' => $shift->ends_at?->toIso8601String(),
            'capacity' => $shift->capacity,
            'signup_opens_at' => $shift->signup_opens_at?->toIso8601String(),
            'signup_closes_at' => $shift->signup_closes_at?->toIso8601String(),
            'schedule_lock_at' => $shift->schedule_lock_at?->toIso8601String(),
            'schedule_lock_offset_minutes' => $shift->schedule_lock_offset_minutes,
            'schedule_lock_resolves_to' => $shift->resolvedScheduleLockAt()?->toIso8601String(),
            'credit_policy_id' => $shift->credit_policy_id !== null
                ? (string) $shift->credit_policy_id
                : null,
            'custom_credit_multiplier' => $shift->customCreditMultiplier(),
            'cancelled_at' => $shift->cancelled_at?->toIso8601String(),
            'required_training_ids' => $shift->trainingRequirements()
                ->pluck('training_id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all(),
            'required_waiver_ids' => $shift->waiverRequirements()
                ->pluck('waiver_id')
                ->map(fn ($id): string => (string) $id)
                ->values()
                ->all(),
            'created_at' => $shift->created_at?->toIso8601String(),
            'updated_at' => $shift->updated_at?->toIso8601String(),
        ];
    }
}
