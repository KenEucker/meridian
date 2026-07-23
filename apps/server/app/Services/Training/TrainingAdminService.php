<?php

namespace App\Services\Training;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftTrainingRequirement;
use App\Models\Staff;
use App\Models\StaffOrganizationStatus;
use App\Models\Team;
use App\Models\Training;
use App\Models\TrainingCompletion;
use App\Models\TrainingPrerequisite;
use App\Models\TrainingSignup;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Product-path department training administration (M11.16).
 *
 * Wraps the existing training domain primitives ({@see TrainingService}) with
 * the department-scoped create/edit, prerequisite/expiration setup, scheduled
 * attendance signup/roster, manual completion recording, and spreadsheet
 * completion import behaviors required by TRAIN-001 through TRAIN-006.
 */
final class TrainingAdminService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly TrainingService $trainings,
    ) {}

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     team_id?: string|null,
     *     event_id?: string|null,
     *     expires_after_days?: int|null,
     *     scheduled_start_at?: string|null,
     *     scheduled_end_at?: string|null,
     *     location?: string|null,
     *     capacity?: int|null
     * }  $attributes
     */
    public function create(
        Department $department,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Training {
        $values = $this->trainingValues($department, $attributes);

        return DB::transaction(function () use ($department, $values, $actor, $sourceContext): Training {
            $training = Training::query()->create([
                'organization_id' => $department->organization_id,
                'department_id' => $department->id,
                ...$values,
            ]);

            $training = $this->syncLinkedShift($training);

            $this->audit->recordForEntity(
                entity: $training,
                action: 'training.created',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                departmentId: (string) $department->id,
                after: $this->trainingSnapshot($training),
                sourceContext: $sourceContext,
            );

            return $training;
        });
    }

    /**
     * @param  array{
     *     name: string,
     *     description?: string|null,
     *     team_id?: string|null,
     *     event_id?: string|null,
     *     expires_after_days?: int|null,
     *     scheduled_start_at?: string|null,
     *     scheduled_end_at?: string|null,
     *     location?: string|null,
     *     capacity?: int|null
     * }  $attributes
     */
    public function update(
        Training $training,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Training {
        $department = $this->departmentFor($training);
        $values = $this->trainingValues($department, $attributes);

        return DB::transaction(function () use ($training, $values, $actor, $sourceContext): Training {
            $before = $this->trainingSnapshot($training);
            $training->fill($values)->save();
            $training = $this->syncLinkedShift($training->refresh());

            $this->audit->recordForEntity(
                entity: $training,
                action: 'training.updated',
                actorUser: $actor,
                organizationId: (string) $training->organization_id,
                departmentId: (string) $training->department_id,
                before: $before,
                after: $this->trainingSnapshot($training),
                sourceContext: $sourceContext,
            );

            return $training;
        });
    }

    public function archive(
        Training $training,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Training {
        return $this->transitionArchiveState($training, now(), 'training.archived', $actor, $sourceContext);
    }

    public function restore(
        Training $training,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Training {
        return $this->transitionArchiveState($training, null, 'training.restored', $actor, $sourceContext);
    }

    /**
     * Record a prerequisite relationship (TRAIN-004).
     *
     * @throws TrainingPrerequisiteException
     */
    public function addPrerequisite(
        Training $training,
        Training $prerequisite,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TrainingPrerequisite {
        return DB::transaction(function () use ($training, $prerequisite, $actor, $sourceContext): TrainingPrerequisite {
            $record = $this->trainings->addPrerequisite($training, $prerequisite);
            $this->syncLinkedShiftPrerequisites($training);

            $this->audit->recordForEntity(
                entity: $record,
                action: 'training.prerequisite_added',
                actorUser: $actor,
                organizationId: (string) $training->organization_id,
                departmentId: $training->department_id !== null ? (string) $training->department_id : null,
                after: [
                    'training_id' => (string) $training->id,
                    'prerequisite_training_id' => (string) $prerequisite->id,
                ],
                sourceContext: $sourceContext,
            );

            return $record;
        });
    }

    public function removePrerequisite(
        Training $training,
        Training $prerequisite,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): void {
        DB::transaction(function () use ($training, $prerequisite, $actor, $sourceContext): void {
            $record = TrainingPrerequisite::query()
                ->where('training_id', $training->id)
                ->where('prerequisite_training_id', $prerequisite->id)
                ->lockForUpdate()
                ->first();

            if ($record === null) {
                throw new TrainingAdminException('That prerequisite is not set on this training.');
            }

            $record->delete();
            $this->syncLinkedShiftPrerequisites($training);

            $this->audit->recordForEntity(
                entity: $record,
                action: 'training.prerequisite_removed',
                actorUser: $actor,
                organizationId: (string) $training->organization_id,
                departmentId: $training->department_id !== null ? (string) $training->department_id : null,
                before: [
                    'training_id' => (string) $training->id,
                    'prerequisite_training_id' => (string) $prerequisite->id,
                ],
                sourceContext: $sourceContext,
            );
        });
    }

    /**
     * Sign a staff member up for a scheduled training (roster entry).
     */
    public function signUp(
        Training $training,
        Staff $staff,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TrainingSignup {
        if ($training->isArchived()) {
            throw new TrainingAdminException('Archived trainings do not take signups.');
        }

        if ($training->isOnline()) {
            throw new TrainingAdminException('Online trainings do not require signup. Visit the training page instead.');
        }

        if (! $training->requiresScheduledAttendance()) {
            throw new TrainingAdminException('This training has no scheduled session to sign up for.');
        }

        if ($training->linked_shift_id !== null) {
            throw new TrainingAdminException('This training takes signups through its linked shift.');
        }

        $this->assertStaffInTrainingDepartment($training, $staff);
        $this->assertPrerequisitesComplete($training, $staff);

        return DB::transaction(function () use ($training, $staff, $actor, $sourceContext): TrainingSignup {
            $existing = TrainingSignup::query()
                ->where('training_id', $training->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && ! $existing->isCancelled()) {
                throw new TrainingAdminException('This staff member is already signed up.');
            }

            if ($training->isFull()) {
                throw new TrainingAdminException('This training session is full.');
            }

            if ($existing !== null) {
                $existing->forceFill([
                    'signed_up_at' => now(),
                    'cancelled_at' => null,
                ])->save();
                $signup = $existing->refresh();
            } else {
                $signup = TrainingSignup::query()->create([
                    'training_id' => $training->id,
                    'staff_id' => $staff->id,
                    'signed_up_at' => now(),
                ]);
            }

            $this->audit->recordForEntity(
                entity: $signup,
                action: 'training.signed_up',
                actorUser: $actor,
                organizationId: (string) $training->organization_id,
                departmentId: $training->department_id !== null ? (string) $training->department_id : null,
                after: $this->signupSnapshot($signup),
                sourceContext: $sourceContext,
            );

            return $signup;
        });
    }

    public function cancelSignup(
        Training $training,
        Staff $staff,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TrainingSignup {
        return DB::transaction(function () use ($training, $staff, $actor, $sourceContext): TrainingSignup {
            $signup = TrainingSignup::query()
                ->active()
                ->where('training_id', $training->id)
                ->where('staff_id', $staff->id)
                ->lockForUpdate()
                ->first();

            if ($signup === null) {
                throw new TrainingAdminException('This staff member is not signed up.');
            }

            $before = $this->signupSnapshot($signup);
            $signup->forceFill(['cancelled_at' => now()])->save();
            $signup->refresh();

            $this->audit->recordForEntity(
                entity: $signup,
                action: 'training.signup_cancelled',
                actorUser: $actor,
                organizationId: (string) $training->organization_id,
                departmentId: $training->department_id !== null ? (string) $training->department_id : null,
                before: $before,
                after: $this->signupSnapshot($signup),
                sourceContext: $sourceContext,
            );

            return $signup;
        });
    }

    /**
     * Record a manual completion by an authorized trainer/lead (TRAIN-005).
     */
    public function recordCompletion(
        Training $training,
        Staff $staff,
        ?Carbon $completedAt,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): TrainingCompletion {
        if ($training->isArchived()) {
            throw new TrainingAdminException('Archived trainings cannot receive completions.');
        }

        $this->assertStaffInOrganization($training, $staff);

        return DB::transaction(function () use ($training, $staff, $completedAt, $actor, $sourceContext): TrainingCompletion {
            $completion = $this->trainings->recordCompletion(
                $training,
                $staff,
                $completedAt,
                $actor,
            );

            $this->audit->recordForEntity(
                entity: $completion,
                action: 'training.completion_recorded',
                actorUser: $actor,
                organizationId: (string) $training->organization_id,
                departmentId: $training->department_id !== null ? (string) $training->department_id : null,
                after: [
                    'training_id' => (string) $training->id,
                    'staff_id' => (string) $staff->id,
                    'completed_at' => $completion->completed_at?->toIso8601String(),
                    'expires_at' => $completion->expires_at?->toIso8601String(),
                ],
                sourceContext: $sourceContext,
            );

            return $completion;
        });
    }

    /**
     * Import completions from spreadsheet CSV text (TRAIN-006).
     *
     * Expected header columns: `email` (required) and `completed_at`
     * (optional; defaults to now). Unknown columns are ignored. Each row is
     * processed independently so one bad row does not abort the import.
     *
     * @return array{
     *     imported: int,
     *     skipped: int,
     *     rows: list<array{line: int, email: string, status: string, reason: string|null}>
     * }
     */
    public function importCompletions(
        Training $training,
        string $csv,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): array {
        if ($training->isArchived()) {
            throw new TrainingAdminException('Archived trainings cannot receive completions.');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === [] || trim($lines[0]) === '') {
            throw new TrainingAdminException('The CSV is empty.');
        }

        $header = array_map(
            static fn (string $column): string => Str::lower(trim($column)),
            str_getcsv(array_shift($lines)),
        );
        $emailIndex = array_search('email', $header, true);

        if ($emailIndex === false) {
            throw new TrainingAdminException('The CSV must include an "email" header column.');
        }

        $completedAtIndex = array_search('completed_at', $header, true);

        $results = [];
        $imported = 0;

        foreach ($lines as $offset => $line) {
            $lineNumber = $offset + 2;

            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line);
            $email = Str::lower(trim((string) ($columns[$emailIndex] ?? '')));

            if ($email === '') {
                $results[] = $this->importRow($lineNumber, $email, 'skipped', 'Missing email.');

                continue;
            }

            $staff = Staff::query()->where('email', $email)->first();

            if ($staff === null) {
                $results[] = $this->importRow($lineNumber, $email, 'skipped', 'No staff record with this email.');

                continue;
            }

            $completedAt = null;
            if ($completedAtIndex !== false && trim((string) ($columns[$completedAtIndex] ?? '')) !== '') {
                try {
                    $completedAt = Carbon::parse(trim((string) $columns[$completedAtIndex]));
                } catch (\Throwable) {
                    $results[] = $this->importRow($lineNumber, $email, 'skipped', 'Unreadable completed_at date.');

                    continue;
                }
            }

            try {
                $this->recordCompletion($training, $staff, $completedAt, $actor, $sourceContext);
            } catch (TrainingAdminException $exception) {
                $results[] = $this->importRow($lineNumber, $email, 'skipped', $exception->getMessage());

                continue;
            }

            $imported++;
            $results[] = $this->importRow($lineNumber, $email, 'imported', null);
        }

        $skipped = count($results) - $imported;

        $this->audit->recordForEntity(
            entity: $training,
            action: 'training.completions_imported',
            actorUser: $actor,
            organizationId: (string) $training->organization_id,
            departmentId: $training->department_id !== null ? (string) $training->department_id : null,
            after: [
                'imported' => $imported,
                'skipped' => $skipped,
            ],
            sourceContext: $sourceContext,
        );

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'rows' => $results,
        ];
    }

    /**
     * @return array{line: int, email: string, status: string, reason: string|null}
     */
    private function importRow(int $line, string $email, string $status, ?string $reason): array
    {
        return [
            'line' => $line,
            'email' => $email,
            'status' => $status,
            'reason' => $reason,
        ];
    }

    /**
     * Validate and normalize product-path training attributes.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function trainingValues(Department $department, array $attributes): array
    {
        $name = trim((string) $attributes['name']);
        if ($name === '') {
            throw new TrainingAdminException('Training name is required.');
        }

        $teamId = $attributes['team_id'] ?? null;
        if ($teamId !== null && $teamId !== '') {
            $team = Team::query()->find($teamId);
            if ($team === null || (string) $team->department_id !== (string) $department->id) {
                throw new TrainingAdminException('Team must belong to the department.');
            }
        } else {
            $teamId = null;
        }

        $eventId = $attributes['event_id'] ?? null;
        if ($eventId !== null && $eventId !== '') {
            $event = Event::query()->find($eventId);
            if ($event === null || (string) $event->organization_id !== (string) $department->organization_id) {
                throw new TrainingAdminException('Event must belong to the organization.');
            }
        } else {
            $eventId = null;
        }

        $delivery = (string) ($attributes['delivery'] ?? Training::DELIVERY_IN_PERSON);
        if (! in_array($delivery, [Training::DELIVERY_IN_PERSON, Training::DELIVERY_ONLINE], true)) {
            throw new TrainingAdminException('Delivery must be in-person or online.');
        }

        $onlineUrl = $this->nullableTrim($attributes['online_url'] ?? null);

        if ($delivery === Training::DELIVERY_ONLINE) {
            if ($onlineUrl === null) {
                throw new TrainingAdminException('Online trainings need a training URL.');
            }

            if (! str_starts_with($onlineUrl, 'https://') && ! str_starts_with($onlineUrl, 'http://')) {
                throw new TrainingAdminException('The training URL must start with http:// or https://.');
            }
        } elseif ($onlineUrl !== null) {
            throw new TrainingAdminException('Only online trainings carry a training URL.');
        }

        $scheduledStartAt = $this->nullableDate($attributes['scheduled_start_at'] ?? null, 'scheduled start');
        $scheduledEndAt = $this->nullableDate($attributes['scheduled_end_at'] ?? null, 'scheduled end');

        if ($scheduledStartAt === null && $scheduledEndAt !== null) {
            throw new TrainingAdminException('A scheduled end requires a scheduled start.');
        }

        if ($scheduledStartAt !== null && $scheduledEndAt !== null && $scheduledEndAt->lessThanOrEqualTo($scheduledStartAt)) {
            throw new TrainingAdminException('The scheduled end must be after the scheduled start.');
        }

        if ($delivery === Training::DELIVERY_IN_PERSON
            && $eventId !== null
            && $scheduledStartAt !== null
            && $scheduledEndAt === null) {
            throw new TrainingAdminException(
                'Event trainings with a scheduled session need a scheduled end to appear as a shift.',
            );
        }

        $capacity = $attributes['capacity'] ?? null;
        if ($capacity !== null && (int) $capacity < 1) {
            throw new TrainingAdminException('Capacity must be at least 1 when set.');
        }

        if ($capacity !== null && $delivery === Training::DELIVERY_ONLINE) {
            throw new TrainingAdminException('Online trainings do not take signups, so capacity does not apply.');
        }

        if ($capacity !== null && $scheduledStartAt === null) {
            throw new TrainingAdminException('Capacity applies only to scheduled trainings.');
        }

        $expiresAfterDays = $attributes['expires_after_days'] ?? null;
        if ($expiresAfterDays !== null && (int) $expiresAfterDays < 1) {
            throw new TrainingAdminException('Expiration must be at least 1 day when set.');
        }

        return [
            'name' => $name,
            'description' => $this->nullableTrim($attributes['description'] ?? null),
            'team_id' => $teamId,
            'event_id' => $eventId,
            'expires_after_days' => $expiresAfterDays !== null ? (int) $expiresAfterDays : null,
            'delivery' => $delivery,
            'online_url' => $onlineUrl,
            'scheduled_start_at' => $scheduledStartAt,
            'scheduled_end_at' => $scheduledEndAt,
            'location' => $this->nullableTrim($attributes['location'] ?? null),
            'capacity' => $capacity !== null ? (int) $capacity : null,
            'time_commitment' => $this->nullableTrim($attributes['time_commitment'] ?? null),
            'after_training' => $this->nullableTrim($attributes['after_training'] ?? null),
            'provisions' => $this->nullableTrim($attributes['provisions'] ?? null),
        ];
    }

    /**
     * Materialize, refresh, or cancel the linked shift for an in-person
     * training with an event-bound scheduled session, so staff sign up for it
     * like other shifts. The training's prerequisites become the shift's
     * training requirements (TRAIN-008 machinery).
     */
    private function syncLinkedShift(Training $training): Training
    {
        $training->loadMissing('department');

        $shouldMaterialize = ! $training->isOnline()
            && ! $training->isArchived()
            && $training->event_id !== null
            && $training->scheduled_start_at !== null
            && $training->scheduled_end_at !== null
            && $training->department !== null;

        $shift = $training->linked_shift_id !== null
            ? Shift::query()->find($training->linked_shift_id)
            : null;

        if (! $shouldMaterialize) {
            if ($shift !== null && $shift->cancelled_at === null) {
                $shift->forceFill(['cancelled_at' => now()])->save();
            }

            if ($training->linked_shift_id !== null) {
                $training->forceFill(['linked_shift_id' => null])->save();
            }

            return $training->refresh();
        }

        $team = $training->team_id !== null
            ? Team::query()->find($training->team_id)
            : $training->department->defaultTeam()->first();

        if ($team === null) {
            throw new TrainingAdminException(
                'The department needs a default team before this training can appear as a shift.',
            );
        }

        $attributes = [
            'event_id' => $training->event_id,
            'department_id' => $training->department_id,
            'eligible_team_id' => $team->id,
            'title' => 'Training: '.$training->name,
            'department_name_snapshot' => $training->department->name,
            'team_name_snapshot' => $team->name,
            'starts_at' => $training->scheduled_start_at,
            'ends_at' => $training->scheduled_end_at,
            'capacity' => $training->capacity,
            'cancelled_at' => null,
        ];

        if ($shift === null) {
            $shift = Shift::query()->create($attributes);
            $training->forceFill(['linked_shift_id' => $shift->id])->save();
        } else {
            $shift->forceFill($attributes)->save();
        }

        $this->syncShiftPrerequisites($training, $shift);

        return $training->refresh();
    }

    private function syncLinkedShiftPrerequisites(Training $training): void
    {
        if ($training->linked_shift_id === null) {
            return;
        }

        $shift = Shift::query()->find($training->linked_shift_id);

        if ($shift !== null) {
            $this->syncShiftPrerequisites($training, $shift);
        }
    }

    private function syncShiftPrerequisites(Training $training, Shift $shift): void
    {
        $prerequisiteIds = $training->prerequisites()
            ->pluck('prerequisite_training_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        $stale = ShiftTrainingRequirement::query()->where('shift_id', $shift->id);
        if ($prerequisiteIds !== []) {
            $stale->whereNotIn('training_id', $prerequisiteIds);
        }
        $stale->delete();

        foreach ($prerequisiteIds as $prerequisiteId) {
            ShiftTrainingRequirement::query()->firstOrCreate([
                'shift_id' => $shift->id,
                'training_id' => $prerequisiteId,
            ]);
        }
    }

    private function transitionArchiveState(
        Training $training,
        ?Carbon $archivedAt,
        string $action,
        User $actor,
        string $sourceContext,
    ): Training {
        return DB::transaction(function () use ($training, $archivedAt, $action, $actor, $sourceContext): Training {
            $before = $this->trainingSnapshot($training);
            $training->forceFill(['archived_at' => $archivedAt])->save();
            $training = $this->syncLinkedShift($training->refresh());

            $this->audit->recordForEntity(
                entity: $training,
                action: $action,
                actorUser: $actor,
                organizationId: (string) $training->organization_id,
                departmentId: $training->department_id !== null ? (string) $training->department_id : null,
                before: $before,
                after: $this->trainingSnapshot($training),
                sourceContext: $sourceContext,
            );

            return $training;
        });
    }

    private function departmentFor(Training $training): Department
    {
        $training->loadMissing('department');

        if ($training->department === null) {
            throw new TrainingAdminException('This training is not managed through a department.');
        }

        return $training->department;
    }

    private function assertStaffInTrainingDepartment(Training $training, Staff $staff): void
    {
        if ($training->department_id === null) {
            throw new TrainingAdminException('This training is not managed through a department.');
        }

        $inDepartment = $staff->departmentMemberships()
            ->active()
            ->where('department_id', $training->department_id)
            ->exists();

        if (! $inDepartment) {
            throw new TrainingAdminException('Staff must have active membership in the department to sign up.');
        }

        if ($training->team_id !== null) {
            $inTeam = $staff->teamMemberships()
                ->active()
                ->where('team_id', $training->team_id)
                ->exists();

            if (! $inTeam) {
                throw new TrainingAdminException('Staff must belong to the training team to sign up.');
            }
        }
    }

    private function assertStaffInOrganization(Training $training, Staff $staff): void
    {
        $inOrganization = StaffOrganizationStatus::query()
            ->where('organization_id', $training->organization_id)
            ->where('staff_id', $staff->id)
            ->exists();

        if (! $inOrganization) {
            throw new TrainingAdminException('Staff must belong to the organization.');
        }
    }

    private function assertPrerequisitesComplete(Training $training, Staff $staff): void
    {
        $missing = $training->prerequisiteTrainings()
            ->get()
            ->reject(fn (Training $prerequisite): bool => $prerequisite->isCompleteFor($staff))
            ->pluck('name')
            ->all();

        if ($missing !== []) {
            throw new TrainingAdminException(
                'Prerequisite training incomplete: '.implode(', ', $missing).'.',
            );
        }
    }

    private function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableDate(mixed $value, string $label): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            throw new TrainingAdminException("The {$label} date is unreadable.");
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function trainingSnapshot(Training $training): array
    {
        return [
            'id' => (string) $training->id,
            'name' => $training->name,
            'department_id' => $training->department_id !== null ? (string) $training->department_id : null,
            'team_id' => $training->team_id !== null ? (string) $training->team_id : null,
            'event_id' => $training->event_id !== null ? (string) $training->event_id : null,
            'expires_after_days' => $training->expires_after_days,
            'delivery' => $training->delivery,
            'online_url' => $training->online_url,
            'scheduled_start_at' => $training->scheduled_start_at?->toIso8601String(),
            'scheduled_end_at' => $training->scheduled_end_at?->toIso8601String(),
            'location' => $training->location,
            'capacity' => $training->capacity,
            'linked_shift_id' => $training->linked_shift_id !== null ? (string) $training->linked_shift_id : null,
            'archived_at' => $training->archived_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function signupSnapshot(TrainingSignup $signup): array
    {
        return [
            'training_id' => (string) $signup->training_id,
            'staff_id' => (string) $signup->staff_id,
            'signed_up_at' => $signup->signed_up_at?->toIso8601String(),
            'cancelled_at' => $signup->cancelled_at?->toIso8601String(),
        ];
    }
}
