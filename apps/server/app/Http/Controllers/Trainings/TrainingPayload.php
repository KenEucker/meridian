<?php

namespace App\Http\Controllers\Trainings;

use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\ShiftTrainingRequirement;
use App\Models\Staff;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Models\TrainingSignup;
use App\Models\User;

/**
 * JSON payload shapes for the product training management surface (M11.16).
 */
final class TrainingPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function training(
        Training $training,
        User $viewer,
        bool $canManage,
        bool $canRecordCompletions,
    ): array {
        $training->loadMissing(['prerequisiteTrainings', 'team', 'event', 'linkedShift']);
        $viewerStaffIds = $viewer->staffProfiles()->pluck('staff.id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $linkedShift = $training->linkedShift;
        $usesShiftSignup = $linkedShift !== null && $linkedShift->cancelled_at === null;

        $activeShiftAssignments = $usesShiftSignup
            ? ShiftAssignment::query()->active()->where('shift_id', $linkedShift->id)->get()
            : collect();

        $viewerSignedUp = $usesShiftSignup
            ? $activeShiftAssignments->contains(
                fn (ShiftAssignment $assignment): bool => in_array((string) $assignment->staff_id, $viewerStaffIds, true),
            )
            : $training->signups
                ->contains(fn (TrainingSignup $signup): bool => ! $signup->isCancelled()
                    && in_array((string) $signup->staff_id, $viewerStaffIds, true));

        $viewerCompletion = $training->completions
            ->filter(fn (TrainingCompletion $completion): bool => ! $completion->isExpiredAt()
                && in_array((string) $completion->staff_id, $viewerStaffIds, true))
            ->sortByDesc('completed_at')
            ->first();

        $activeSignupCount = $usesShiftSignup
            ? $activeShiftAssignments->count()
            : $training->signups
                ->filter(fn (TrainingSignup $signup): bool => ! $signup->isCancelled())
                ->count();

        return [
            'id' => (string) $training->id,
            'organization_id' => (string) $training->organization_id,
            'department_id' => $training->department_id !== null ? (string) $training->department_id : null,
            'team_id' => $training->team_id !== null ? (string) $training->team_id : null,
            'team_name' => $training->team?->name,
            'event_id' => $training->event_id !== null ? (string) $training->event_id : null,
            'event_name' => $training->event?->name,
            'name' => $training->name,
            'description' => $training->description,
            'expires_after_days' => $training->expires_after_days,
            'delivery' => $training->delivery,
            'online_url' => $training->online_url,
            'requires_scheduled_attendance' => $training->requiresScheduledAttendance(),
            'scheduled_start_at' => $training->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $training->scheduled_end_at?->toIso8601String(),
            'location' => $training->location,
            'capacity' => $training->capacity,
            'time_commitment' => $training->time_commitment,
            'after_training' => $training->after_training,
            'provisions' => $training->provisions,
            'archived_at' => $training->archived_at?->toIso8601String(),
            'active_signup_count' => $activeSignupCount,
            'linked_shift' => $usesShiftSignup ? [
                'id' => (string) $linkedShift->id,
                'title' => $linkedShift->title,
                'starts_at' => $linkedShift->starts_at?->toIso8601String(),
                'ends_at' => $linkedShift->ends_at?->toIso8601String(),
                'capacity' => $linkedShift->capacity,
            ] : null,
            'unlocked_shifts' => self::unlockedShifts($training),
            'prerequisites' => $training->prerequisiteTrainings
                ->map(fn (Training $prerequisite): array => [
                    'id' => (string) $prerequisite->id,
                    'name' => $prerequisite->name,
                ])
                ->values()
                ->all(),
            'viewer' => [
                'can_manage' => $canManage,
                'can_record_completions' => $canRecordCompletions,
                'is_signed_up' => $viewerSignedUp,
                'completion' => $viewerCompletion !== null ? [
                    'completed_at' => $viewerCompletion->completed_at?->toIso8601String(),
                    'expires_at' => $viewerCompletion->expires_at?->toIso8601String(),
                ] : null,
            ],
        ];
    }

    /**
     * Roster and completion detail for authorized trainers/leads.
     *
     * @return array<string, mixed>
     */
    public static function trainingDetail(
        Training $training,
        User $viewer,
        bool $canManage,
        bool $canRecordCompletions,
    ): array {
        $payload = self::training($training, $viewer, $canManage, $canRecordCompletions);

        if (! $canRecordCompletions) {
            return $payload;
        }

        $linkedShift = $training->linkedShift;

        if ($linkedShift !== null && $linkedShift->cancelled_at === null) {
            $payload['roster'] = ShiftAssignment::query()
                ->active()
                ->where('shift_id', $linkedShift->id)
                ->with('staff')
                ->orderBy('created_at')
                ->get()
                ->map(fn (ShiftAssignment $assignment): array => [
                    'staff_id' => (string) $assignment->staff_id,
                    'display_name' => self::staffDisplayName($assignment->staff),
                    'email' => $assignment->staff?->email,
                    'signed_up_at' => $assignment->created_at?->toIso8601String(),
                    'completed' => $assignment->staff !== null && $training->isCompleteFor($assignment->staff),
                ])
                ->values()
                ->all();
        } else {
            $payload['roster'] = $training->signups
                ->filter(fn (TrainingSignup $signup): bool => ! $signup->isCancelled())
                ->sortBy(fn (TrainingSignup $signup) => $signup->signed_up_at)
                ->map(fn (TrainingSignup $signup): array => [
                    'staff_id' => (string) $signup->staff_id,
                    'display_name' => self::staffDisplayName($signup->staff),
                    'email' => $signup->staff?->email,
                    'signed_up_at' => $signup->signed_up_at?->toIso8601String(),
                    'completed' => $signup->staff !== null && $training->isCompleteFor($signup->staff),
                ])
                ->values()
                ->all();
        }

        $payload['completions'] = $training->completions
            ->sortByDesc('completed_at')
            ->map(fn (TrainingCompletion $completion): array => [
                'id' => (string) $completion->id,
                'staff_id' => (string) $completion->staff_id,
                'display_name' => self::staffDisplayName($completion->staff),
                'email' => $completion->staff?->email,
                'completed_at' => $completion->completed_at?->toIso8601String(),
                'expires_at' => $completion->expires_at?->toIso8601String(),
                'expired' => $completion->isExpiredAt(),
                'recorded_by' => $completion->recordedBy?->name,
            ])
            ->values()
            ->all();

        return $payload;
    }

    /**
     * Shifts that require this training for signup (excluding the training's
     * own linked shift), so the training page can say what completing it
     * unlocks.
     *
     * @return list<array<string, mixed>>
     */
    private static function unlockedShifts(Training $training): array
    {
        return ShiftTrainingRequirement::query()
            ->where('training_id', $training->id)
            ->with('shift')
            ->get()
            ->map(fn (ShiftTrainingRequirement $requirement): ?Shift => $requirement->shift)
            ->filter(fn (?Shift $shift): bool => $shift !== null
                && $shift->cancelled_at === null
                && (string) $shift->id !== (string) $training->linked_shift_id)
            ->map(fn (Shift $shift): array => [
                'id' => (string) $shift->id,
                'title' => $shift->title,
                'starts_at' => $shift->starts_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private static function staffDisplayName(?Staff $staff): ?string
    {
        if ($staff === null) {
            return null;
        }

        return $staff->preferred_name !== null && $staff->preferred_name !== ''
            ? $staff->preferred_name
            : $staff->legal_name;
    }
}
