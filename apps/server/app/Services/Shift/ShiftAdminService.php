<?php

namespace App\Services\Shift;

use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftTrainingRequirement;
use App\Models\ShiftWaiverRequirement;
use App\Models\Team;
use App\Models\Training;
use App\Models\User;
use App\Models\Waiver;
use App\Services\Audit\AuditService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Product-path shift creation and maintenance for department and team leads
 * (M11.17; SHIFT-001 through SHIFT-010).
 *
 * Documented eligibility and time-window rules enforced here:
 * - shifts belong to an event and department, with exactly one eligible team
 *   from the same department (SHIFT-001, SHIFT-004);
 * - scheduled end must be after scheduled start (SHIFT-002);
 * - signup windows must open before they close (SHIFT-008);
 * - capacity cannot drop below current active assignments (SHIFT-007);
 * - once a shift has started, its scheduled times and eligible team are locked
 *   and the shift can no longer be cancelled, preserving worked history
 *   (TEAM-007; UI contract 12.4 `department.shift-edit` time restrictions);
 * - cancel/restore are soft state changes on `cancelled_at`;
 * - a shift may name one of its organization's credit policies as its own
 *   rate (SHIFT-010; M18.16), taking precedence over the organization default
 *   (CREDIT-002). An archived policy cannot be newly chosen, but a shift
 *   already naming one keeps it, because archiving withdraws a policy from
 *   future selection rather than repricing scheduled work.
 */
final class ShiftAdminService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param array{
     *     event_id: string,
     *     eligible_team_id: string,
     *     title: string,
     *     starts_at: Carbon,
     *     ends_at: Carbon,
     *     capacity?: int|null,
     *     signup_opens_at?: Carbon|null,
     *     signup_closes_at?: Carbon|null,
     *     schedule_lock_at?: Carbon|null,
     *     credit_policy_id?: string|null,
     *     required_training_ids?: list<string>,
     *     required_waiver_ids?: list<string>
     * } $attributes
     *
     * @throws ShiftAdminException
     */
    public function create(
        Department $department,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Shift {
        if ($department->isArchived()) {
            throw new ShiftAdminException('Archived departments cannot receive new shifts.');
        }

        $event = Event::query()->find((string) $attributes['event_id']);

        if ($event === null || (string) $event->organization_id !== (string) $department->organization_id) {
            throw new ShiftAdminException('Shifts must belong to an event in the department organization.');
        }

        $team = $this->eligibleTeam($department, (string) $attributes['eligible_team_id']);

        $title = trim((string) $attributes['title']);

        if ($title === '') {
            throw new ShiftAdminException('Shifts require a displayed title/function.');
        }

        $this->assertScheduleValid($attributes['starts_at'], $attributes['ends_at']);
        $this->assertSignupWindowValid(
            $attributes['signup_opens_at'] ?? null,
            $attributes['signup_closes_at'] ?? null,
        );
        $capacity = $this->normalizedCapacity($attributes['capacity'] ?? null);

        $trainings = $this->requiredTrainings($department, $attributes['required_training_ids'] ?? []);
        $waivers = $this->requiredWaivers($department, $attributes['required_waiver_ids'] ?? []);
        $creditPolicyId = $this->creditPolicyId($department, $attributes['credit_policy_id'] ?? null);

        return DB::transaction(function () use ($department, $event, $team, $title, $attributes, $capacity, $trainings, $waivers, $creditPolicyId, $actor, $sourceContext): Shift {
            $shift = Shift::query()->create([
                'event_id' => $event->id,
                'department_id' => $department->id,
                'eligible_team_id' => $team->id,
                'title' => $title,
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'capacity' => $capacity,
                'signup_opens_at' => $attributes['signup_opens_at'] ?? null,
                'signup_closes_at' => $attributes['signup_closes_at'] ?? null,
                'schedule_lock_at' => $attributes['schedule_lock_at'] ?? null,
                'credit_policy_id' => $creditPolicyId,
            ]);

            foreach ($trainings as $training) {
                ShiftTrainingRequirement::query()->create([
                    'shift_id' => $shift->id,
                    'training_id' => $training->id,
                ]);
            }

            foreach ($waivers as $waiver) {
                ShiftWaiverRequirement::query()->create([
                    'shift_id' => $shift->id,
                    'waiver_id' => $waiver->id,
                ]);
            }

            $shift->refresh();

            $this->audit->recordForEntity(
                entity: $shift,
                action: 'shift.created',
                actorUser: $actor,
                organizationId: (string) $department->organization_id,
                eventId: (string) $event->id,
                departmentId: (string) $department->id,
                after: $this->snapshot($shift),
                sourceContext: $sourceContext,
            );

            return $shift;
        });
    }

    /**
     * @param array{
     *     eligible_team_id: string,
     *     title: string,
     *     starts_at: Carbon,
     *     ends_at: Carbon,
     *     capacity?: int|null,
     *     signup_opens_at?: Carbon|null,
     *     signup_closes_at?: Carbon|null,
     *     schedule_lock_at?: Carbon|null,
     *     credit_policy_id?: string|null,
     *     required_training_ids?: list<string>,
     *     required_waiver_ids?: list<string>
     * } $attributes
     *
     * @throws ShiftAdminException
     */
    public function update(
        Shift $shift,
        array $attributes,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Shift {
        $shift->loadMissing(['department', 'event']);
        $department = $shift->department;

        if ($department === null) {
            throw new ShiftAdminException('Shift department could not be resolved.');
        }

        if ($shift->isCancelled()) {
            throw new ShiftAdminException('Cancelled shifts must be restored before editing.');
        }

        $title = trim((string) $attributes['title']);

        if ($title === '') {
            throw new ShiftAdminException('Shifts require a displayed title/function.');
        }

        $this->assertScheduleValid($attributes['starts_at'], $attributes['ends_at']);
        $this->assertSignupWindowValid(
            $attributes['signup_opens_at'] ?? null,
            $attributes['signup_closes_at'] ?? null,
        );
        $capacity = $this->normalizedCapacity($attributes['capacity'] ?? null);

        $started = Carbon::now()->greaterThanOrEqualTo($shift->starts_at);

        if ($started) {
            if (! $shift->starts_at->equalTo($attributes['starts_at'])
                || ! $shift->ends_at->equalTo($attributes['ends_at'])) {
                throw new ShiftAdminException('Scheduled times are locked once the shift has started.');
            }

            if ((string) $shift->eligible_team_id !== (string) $attributes['eligible_team_id']) {
                throw new ShiftAdminException('The eligible team is locked once the shift has started.');
            }
        }

        $team = $this->eligibleTeam($department, (string) $attributes['eligible_team_id'], $shift);

        if ($capacity !== null) {
            $activeAssignments = $shift->activeAssignments()->count();

            if ($capacity < $activeAssignments) {
                throw new ShiftAdminException('Capacity cannot be set below the current number of assigned staff.');
            }
        }

        $trainings = $this->requiredTrainings($department, $attributes['required_training_ids'] ?? []);
        $waivers = $this->requiredWaivers($department, $attributes['required_waiver_ids'] ?? []);
        $creditPolicyId = $this->creditPolicyId($department, $attributes['credit_policy_id'] ?? null, $shift);

        return DB::transaction(function () use ($shift, $department, $team, $title, $attributes, $capacity, $trainings, $waivers, $creditPolicyId, $actor, $sourceContext): Shift {
            $before = $this->snapshot($shift);

            $shift->forceFill([
                'eligible_team_id' => $team->id,
                'title' => $title,
                'starts_at' => $attributes['starts_at'],
                'ends_at' => $attributes['ends_at'],
                'capacity' => $capacity,
                'signup_opens_at' => $attributes['signup_opens_at'] ?? null,
                'signup_closes_at' => $attributes['signup_closes_at'] ?? null,
                'schedule_lock_at' => $attributes['schedule_lock_at'] ?? null,
                'credit_policy_id' => $creditPolicyId,
            ])->save();

            $this->syncTrainingRequirements($shift, $trainings);
            $this->syncWaiverRequirements($shift, $waivers);

            $shift->refresh();
            $after = $this->snapshot($shift);

            if ($before !== $after) {
                $this->audit->recordForEntity(
                    entity: $shift,
                    action: 'shift.updated',
                    actorUser: $actor,
                    organizationId: (string) $department->organization_id,
                    eventId: (string) $shift->event_id,
                    departmentId: (string) $department->id,
                    before: $before,
                    after: $after,
                    sourceContext: $sourceContext,
                );
            }

            return $shift;
        });
    }

    /**
     * @throws ShiftAdminException
     */
    public function cancel(
        Shift $shift,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Shift {
        if ($shift->isCancelled()) {
            throw new ShiftAdminException('Shift is already cancelled.');
        }

        if (Carbon::now()->greaterThanOrEqualTo($shift->starts_at)) {
            throw new ShiftAdminException('Shifts cannot be cancelled once they have started.');
        }

        return $this->transitionCancellation($shift, $actor, $sourceContext, 'shift.cancelled', Carbon::now());
    }

    /**
     * @throws ShiftAdminException
     */
    public function restore(
        Shift $shift,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Shift {
        if (! $shift->isCancelled()) {
            throw new ShiftAdminException('Shift is not cancelled.');
        }

        if (Carbon::now()->greaterThanOrEqualTo($shift->starts_at)) {
            throw new ShiftAdminException('Shifts cannot be restored after their scheduled start.');
        }

        return $this->transitionCancellation($shift, $actor, $sourceContext, 'shift.restored', null);
    }

    private function transitionCancellation(
        Shift $shift,
        User $actor,
        string $sourceContext,
        string $action,
        ?Carbon $cancelledAt,
    ): Shift {
        $shift->loadMissing('department');

        return DB::transaction(function () use ($shift, $actor, $sourceContext, $action, $cancelledAt): Shift {
            $before = $this->snapshot($shift);

            $shift->forceFill(['cancelled_at' => $cancelledAt])->save();
            $shift->refresh();

            $this->audit->recordForEntity(
                entity: $shift,
                action: $action,
                actorUser: $actor,
                organizationId: $shift->department?->organization_id,
                eventId: (string) $shift->event_id,
                departmentId: (string) $shift->department_id,
                before: $before,
                after: $this->snapshot($shift),
                sourceContext: $sourceContext,
            );

            return $shift;
        });
    }

    /**
     * @throws ShiftAdminException
     */
    private function eligibleTeam(Department $department, string $teamId, ?Shift $shift = null): Team
    {
        $team = Team::query()->find($teamId);

        if ($team === null || (string) $team->department_id !== (string) $department->id) {
            throw new ShiftAdminException('The eligible team must belong to the shift department.');
        }

        $keepingExistingTeam = $shift !== null
            && (string) $shift->eligible_team_id === (string) $team->id;

        if ($team->isArchived() && ! $keepingExistingTeam) {
            throw new ShiftAdminException('Archived teams cannot be selected as the eligible team.');
        }

        return $team;
    }

    /**
     * The credit policy a shift may name as its own rate (SHIFT-010).
     *
     * It must be one of the department organization's policies — the resolver
     * reads exactly one pointer, and a policy from another organization would
     * price this organization's work at somebody else's rate. An archived
     * policy cannot be newly chosen, but a shift already naming one keeps it
     * (CREDIT-002): archiving withdraws a policy from future selection, it
     * does not restate what scheduled work will be priced at.
     *
     * @throws ShiftAdminException
     */
    private function creditPolicyId(
        Department $department,
        ?string $creditPolicyId,
        ?Shift $shift = null,
    ): ?string {
        if ($creditPolicyId === null || $creditPolicyId === '') {
            return null;
        }

        $policy = CreditPolicy::query()->find($creditPolicyId);

        if ($policy === null || (string) $policy->organization_id !== (string) $department->organization_id) {
            throw new ShiftAdminException('The credit policy must belong to the department organization.');
        }

        $keepingExistingPolicy = $shift !== null
            && (string) $shift->credit_policy_id === (string) $policy->id;

        if ($policy->isArchived() && ! $keepingExistingPolicy) {
            throw new ShiftAdminException('Archived credit policies cannot be selected for a shift.');
        }

        return (string) $policy->id;
    }

    /**
     * @throws ShiftAdminException
     */
    private function assertScheduleValid(Carbon $startsAt, Carbon $endsAt): void
    {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new ShiftAdminException('Shift end must be after shift start.');
        }
    }

    /**
     * @throws ShiftAdminException
     */
    private function assertSignupWindowValid(?Carbon $opensAt, ?Carbon $closesAt): void
    {
        if ($opensAt !== null && $closesAt !== null && $closesAt->lessThanOrEqualTo($opensAt)) {
            throw new ShiftAdminException('Signup close must be after signup open.');
        }
    }

    /**
     * @throws ShiftAdminException
     */
    private function normalizedCapacity(int|string|null $capacity): ?int
    {
        if ($capacity === null || $capacity === '') {
            return null;
        }

        $capacity = (int) $capacity;

        if ($capacity < 1) {
            throw new ShiftAdminException('Capacity must be at least 1 when set.');
        }

        return $capacity;
    }

    /**
     * @param  list<string>  $trainingIds
     * @return list<Training>
     *
     * @throws ShiftAdminException
     */
    private function requiredTrainings(Department $department, array $trainingIds): array
    {
        $trainings = [];

        foreach (array_values(array_unique($trainingIds)) as $trainingId) {
            $training = Training::query()->find((string) $trainingId);

            if ($training === null || (string) $training->organization_id !== (string) $department->organization_id) {
                throw new ShiftAdminException('Required trainings must belong to the department organization.');
            }

            $trainings[] = $training;
        }

        return $trainings;
    }

    /**
     * @param  list<string>  $waiverIds
     * @return list<Waiver>
     *
     * @throws ShiftAdminException
     */
    private function requiredWaivers(Department $department, array $waiverIds): array
    {
        $waivers = [];

        foreach (array_values(array_unique($waiverIds)) as $waiverId) {
            $waiver = Waiver::query()->find((string) $waiverId);

            if ($waiver === null || (string) $waiver->organization_id !== (string) $department->organization_id) {
                throw new ShiftAdminException('Required waivers must belong to the department organization.');
            }

            $waivers[] = $waiver;
        }

        return $waivers;
    }

    /**
     * @param  list<Training>  $trainings
     */
    private function syncTrainingRequirements(Shift $shift, array $trainings): void
    {
        $desired = array_map(fn (Training $training): string => (string) $training->id, $trainings);

        ShiftTrainingRequirement::query()
            ->where('shift_id', $shift->id)
            ->whereNotIn('training_id', $desired)
            ->delete();

        $existing = ShiftTrainingRequirement::query()
            ->where('shift_id', $shift->id)
            ->pluck('training_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($desired as $trainingId) {
            if (! in_array($trainingId, $existing, true)) {
                ShiftTrainingRequirement::query()->create([
                    'shift_id' => $shift->id,
                    'training_id' => $trainingId,
                ]);
            }
        }
    }

    /**
     * @param  list<Waiver>  $waivers
     */
    private function syncWaiverRequirements(Shift $shift, array $waivers): void
    {
        $desired = array_map(fn (Waiver $waiver): string => (string) $waiver->id, $waivers);

        ShiftWaiverRequirement::query()
            ->where('shift_id', $shift->id)
            ->whereNotIn('waiver_id', $desired)
            ->delete();

        $existing = ShiftWaiverRequirement::query()
            ->where('shift_id', $shift->id)
            ->pluck('waiver_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($desired as $waiverId) {
            if (! in_array($waiverId, $existing, true)) {
                ShiftWaiverRequirement::query()->create([
                    'shift_id' => $shift->id,
                    'waiver_id' => $waiverId,
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Shift $shift): array
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
            'credit_policy_id' => $shift->credit_policy_id !== null
                ? (string) $shift->credit_policy_id
                : null,
            'cancelled_at' => $shift->cancelled_at?->toIso8601String(),
            'required_training_ids' => $shift->trainingRequirements()
                ->pluck('training_id')
                ->map(fn ($id): string => (string) $id)
                ->sort()
                ->values()
                ->all(),
            'required_waiver_ids' => $shift->waiverRequirements()
                ->pluck('waiver_id')
                ->map(fn ($id): string => (string) $id)
                ->sort()
                ->values()
                ->all(),
        ];
    }
}
