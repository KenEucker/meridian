<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Shift;

use App\Models\AuditEvent;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Event;
use App\Models\Shift;
use App\Models\ShiftTrainingRequirement;
use App\Models\ShiftWaiverRequirement;
use App\Models\Team;
use App\Models\Training;
use App\Models\Waiver;
use App\Orchid\Layouts\Shift\ShiftEditLayout;
use App\Services\Audit\AuditService;
use App\Services\Shift\ShiftAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

/**
 * God Mode shift repair tooling (technical spec 22.1).
 *
 * Structural rules that would corrupt data are enforced here (event/department
 * organization agreement, one eligible team from the shift department, end
 * after start, signup window ordering). The product-path time restrictions in
 * {@see ShiftAdminService} — locking a started shift's
 * schedule and team — are deliberately not applied, because repairing a
 * mis-scheduled shift after it starts is the reason this screen exists. Every
 * write is audited with the Orchid source context.
 */
class ShiftEditScreen extends Screen
{
    /**
     * @var Shift
     */
    public $shift;

    /**
     * @return array<string, mixed>
     */
    public function query(Shift $shift): iterable
    {
        $shift->setAttribute(
            'required_training_ids',
            $shift->exists ? $shift->trainingRequirements()->pluck('training_id')->all() : [],
        );
        $shift->setAttribute(
            'required_waiver_ids',
            $shift->exists ? $shift->waiverRequirements()->pluck('waiver_id')->all() : [],
        );

        // A custom rate renders in its own field, and the policy select stays
        // on "Organization default" rather than pointing at the shift-scoped
        // row the select deliberately does not offer.
        $custom = $shift->exists ? $shift->customCreditMultiplier() : null;
        $shift->setAttribute('custom_credit_multiplier', $custom);
        if ($custom !== null) {
            $shift->setAttribute('credit_policy_id', null);
        }

        return [
            'shift' => $shift,
        ];
    }

    public function name(): ?string
    {
        return $this->shift->exists ? 'Edit Shift' : 'Create Shift';
    }

    public function description(): ?string
    {
        return 'Shift schedule, eligible team, capacity, and requirements.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.shifts',
        ];
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [
            Link::make(__('Cancel'))
                ->icon('bs.x-circle')
                ->route('platform.shifts'),

            Button::make(__('Cancel shift'))
                ->icon('bs.slash-circle')
                ->method('cancelShift')
                ->canSee($this->shift->exists && ! $this->shift->isCancelled()),

            Button::make(__('Restore'))
                ->icon('bs.arrow-counterclockwise')
                ->method('restore')
                ->canSee($this->shift->exists && $this->shift->isCancelled()),

            Button::make(__('Save'))
                ->icon('bs.check-circle')
                ->method('save'),
        ];
    }

    /**
     * @return \Orchid\Screen\Layout[]
     */
    public function layout(): iterable
    {
        return [
            Layout::block(ShiftEditLayout::class)
                ->title(__('Shift'))
                ->description(__('Repair tooling: product-path start-time locks do not apply here.')),
        ];
    }

    public function save(Request $request, Shift $shift, AuditService $audit): RedirectResponse
    {
        $validated = $request->validate([
            'shift.event_id' => ['required', 'uuid', Rule::exists(Event::class, 'id')],
            'shift.department_id' => ['required', 'uuid', Rule::exists(Department::class, 'id')],
            'shift.eligible_team_id' => ['required', 'uuid', Rule::exists(Team::class, 'id')],
            'shift.title' => ['required', 'string', 'max:255'],
            'shift.starts_at' => ['required', 'date'],
            'shift.ends_at' => ['required', 'date'],
            'shift.capacity' => ['nullable', 'integer', 'min:1'],
            'shift.signup_opens_at' => ['nullable', 'date'],
            'shift.signup_closes_at' => ['nullable', 'date'],
            'shift.schedule_lock_at' => ['nullable', 'date'],
            'shift.credit_policy_id' => ['nullable', 'uuid', Rule::exists(CreditPolicy::class, 'id')],
            'shift.custom_credit_multiplier' => ['nullable', 'numeric', 'between:0,2'],
            'shift.required_training_ids' => ['array'],
            'shift.required_training_ids.*' => ['uuid', Rule::exists(Training::class, 'id')],
            'shift.required_waiver_ids' => ['array'],
            'shift.required_waiver_ids.*' => ['uuid', Rule::exists(Waiver::class, 'id')],
        ]);

        $attributes = $validated['shift'];
        $trainingIds = array_values(array_unique($attributes['required_training_ids'] ?? []));
        $waiverIds = array_values(array_unique($attributes['required_waiver_ids'] ?? []));
        unset($attributes['required_training_ids'], $attributes['required_waiver_ids']);

        $this->validateStructure($attributes, $trainingIds, $waiverIds);

        $customMultiplier = filled($attributes['custom_credit_multiplier'] ?? null)
            ? number_format((float) $attributes['custom_credit_multiplier'], 3, '.', '')
            : null;
        $creditPolicyId = $customMultiplier !== null
            ? null
            : (filled($attributes['credit_policy_id'] ?? null) ? (string) $attributes['credit_policy_id'] : null);

        $existed = $shift->exists;
        $before = $existed ? $this->snapshot($shift) : null;

        DB::transaction(function () use ($shift, $attributes, $trainingIds, $waiverIds, $creditPolicyId, $customMultiplier): void {
            $shift->fill([
                'event_id' => $attributes['event_id'],
                'department_id' => $attributes['department_id'],
                'eligible_team_id' => $attributes['eligible_team_id'],
                'title' => trim((string) $attributes['title']),
                'starts_at' => Carbon::parse((string) $attributes['starts_at']),
                'ends_at' => Carbon::parse((string) $attributes['ends_at']),
                'capacity' => $attributes['capacity'] ?? null,
                'signup_opens_at' => $this->nullableDate($attributes['signup_opens_at'] ?? null),
                'signup_closes_at' => $this->nullableDate($attributes['signup_closes_at'] ?? null),
                'schedule_lock_at' => $this->nullableDate($attributes['schedule_lock_at'] ?? null),
                'credit_policy_id' => $creditPolicyId,
            ])->save();

            $this->syncRequirements($shift, $trainingIds, $waiverIds);

            // The same shift-scoped upsert the product path performs, so the
            // one-row-per-shift rule holds whichever door the rate came in by.
            if ($customMultiplier !== null) {
                app(ShiftAdminService::class)->applyCustomRate(
                    $shift,
                    Department::query()->findOrFail((string) $shift->department_id),
                    $customMultiplier,
                );
            }
        });

        $shift->refresh();

        $audit->recordForEntity(
            entity: $shift,
            action: $existed ? 'shift.updated' : 'shift.created',
            actorUser: $request->user(),
            organizationId: $shift->department?->organization_id,
            eventId: (string) $shift->event_id,
            departmentId: (string) $shift->department_id,
            before: $before,
            after: $this->snapshot($shift),
            sourceContext: AuditEvent::SOURCE_ORCHID,
        );

        Toast::info(__('Shift was saved.'));

        return redirect()->route('platform.shifts');
    }

    public function cancelShift(Request $request, Shift $shift, AuditService $audit): RedirectResponse
    {
        return $this->transition($request, $shift, $audit, 'shift.cancelled', now(), __('Shift was cancelled.'));
    }

    public function restore(Request $request, Shift $shift, AuditService $audit): RedirectResponse
    {
        return $this->transition($request, $shift, $audit, 'shift.restored', null, __('Shift was restored.'));
    }

    private function transition(
        Request $request,
        Shift $shift,
        AuditService $audit,
        string $action,
        ?Carbon $cancelledAt,
        string $message,
    ): RedirectResponse {
        $before = $this->snapshot($shift);

        $shift->forceFill(['cancelled_at' => $cancelledAt])->save();
        $shift->refresh();

        $audit->recordForEntity(
            entity: $shift,
            action: $action,
            actorUser: $request->user(),
            organizationId: $shift->department?->organization_id,
            eventId: (string) $shift->event_id,
            departmentId: (string) $shift->department_id,
            before: $before,
            after: $this->snapshot($shift),
            sourceContext: AuditEvent::SOURCE_ORCHID,
        );

        Toast::info($message);

        return redirect()->route('platform.shifts');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $trainingIds
     * @param  list<string>  $waiverIds
     *
     * @throws ValidationException
     */
    private function validateStructure(array $attributes, array $trainingIds, array $waiverIds): void
    {
        $department = Department::query()->findOrFail($attributes['department_id']);
        $organizationId = (string) $department->organization_id;

        $eventMatchesOrganization = Event::query()
            ->whereKey($attributes['event_id'])
            ->where('organization_id', $organizationId)
            ->exists();

        if (! $eventMatchesOrganization) {
            throw ValidationException::withMessages([
                'shift.event_id' => __('Event must belong to the same organization as the department.'),
            ]);
        }

        $teamMatchesDepartment = Team::query()
            ->whereKey($attributes['eligible_team_id'])
            ->where('department_id', $department->id)
            ->exists();

        if (! $teamMatchesDepartment) {
            throw ValidationException::withMessages([
                'shift.eligible_team_id' => __('Eligible team must belong to the shift department.'),
            ]);
        }

        $startsAt = Carbon::parse((string) $attributes['starts_at']);
        $endsAt = Carbon::parse((string) $attributes['ends_at']);

        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw ValidationException::withMessages([
                'shift.ends_at' => __('Shift end must be after shift start.'),
            ]);
        }

        $opensAt = $this->nullableDate($attributes['signup_opens_at'] ?? null);
        $closesAt = $this->nullableDate($attributes['signup_closes_at'] ?? null);

        if ($opensAt !== null && $closesAt !== null && $closesAt->lessThanOrEqualTo($opensAt)) {
            throw ValidationException::withMessages([
                'shift.signup_closes_at' => __('Signup close must be after signup open.'),
            ]);
        }

        if ($trainingIds !== [] && Training::query()
            ->whereIn('id', $trainingIds)
            ->where('organization_id', '!=', $organizationId)
            ->exists()) {
            throw ValidationException::withMessages([
                'shift.required_training_ids' => __('Required trainings must belong to the department organization.'),
            ]);
        }

        if ($waiverIds !== [] && Waiver::query()
            ->whereIn('id', $waiverIds)
            ->where('organization_id', '!=', $organizationId)
            ->exists()) {
            throw ValidationException::withMessages([
                'shift.required_waiver_ids' => __('Required waivers must belong to the department organization.'),
            ]);
        }

        if (filled($attributes['credit_policy_id'] ?? null) && filled($attributes['custom_credit_multiplier'] ?? null)) {
            throw ValidationException::withMessages([
                'shift.custom_credit_multiplier' => __('A shift takes a named credit policy or a custom rate, not both.'),
            ]);
        }

        if (filled($attributes['custom_credit_multiplier'] ?? null)
            && round((float) $attributes['custom_credit_multiplier'], 3) !== (float) $attributes['custom_credit_multiplier']) {
            throw ValidationException::withMessages([
                'shift.custom_credit_multiplier' => __('The custom credit rate holds at most three decimal places.'),
            ]);
        }

        if (filled($attributes['credit_policy_id'] ?? null)) {
            $policy = CreditPolicy::query()->findOrFail((string) $attributes['credit_policy_id']);

            if ((string) $policy->organization_id !== $organizationId) {
                throw ValidationException::withMessages([
                    'shift.credit_policy_id' => __('The credit policy must belong to the department organization.'),
                ]);
            }

            // A shift-scoped row is one shift's custom rate: another shift
            // naming it would let a re-rate of one shift reprice a second.
            if ($policy->shift_id !== null) {
                throw ValidationException::withMessages([
                    'shift.credit_policy_id' => __('Another shift\'s custom rate cannot be chosen as this shift\'s policy.'),
                ]);
            }
        }
    }

    /**
     * @param  list<string>  $trainingIds
     * @param  list<string>  $waiverIds
     */
    private function syncRequirements(Shift $shift, array $trainingIds, array $waiverIds): void
    {
        ShiftTrainingRequirement::query()
            ->where('shift_id', $shift->id)
            ->whereNotIn('training_id', $trainingIds)
            ->delete();

        $existingTrainings = ShiftTrainingRequirement::query()
            ->where('shift_id', $shift->id)
            ->pluck('training_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($trainingIds as $trainingId) {
            if (! in_array($trainingId, $existingTrainings, true)) {
                ShiftTrainingRequirement::query()->create([
                    'shift_id' => $shift->id,
                    'training_id' => $trainingId,
                ]);
            }
        }

        ShiftWaiverRequirement::query()
            ->where('shift_id', $shift->id)
            ->whereNotIn('waiver_id', $waiverIds)
            ->delete();

        $existingWaivers = ShiftWaiverRequirement::query()
            ->where('shift_id', $shift->id)
            ->pluck('waiver_id')
            ->map(fn ($id): string => (string) $id)
            ->all();

        foreach ($waiverIds as $waiverId) {
            if (! in_array($waiverId, $existingWaivers, true)) {
                ShiftWaiverRequirement::query()->create([
                    'shift_id' => $shift->id,
                    'waiver_id' => $waiverId,
                ]);
            }
        }
    }

    private function nullableDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse((string) $value);
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
            'custom_credit_multiplier' => $shift->customCreditMultiplier(),
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
