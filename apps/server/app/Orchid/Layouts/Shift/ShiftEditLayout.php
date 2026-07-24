<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Shift;

use App\Models\Department;
use App\Models\Event;
use App\Models\Team;
use App\Models\Training;
use App\Models\Waiver;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\DateTimer;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class ShiftEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Select::make('shift.event_id')
                ->fromModel(Event::class, 'name')
                ->required()
                ->title(__('Event'))
                ->help(__('Shifts belong to one event and one department (SHIFT-001).')),

            Select::make('shift.department_id')
                ->fromModel(Department::class, 'name')
                ->required()
                ->title(__('Department')),

            Select::make('shift.eligible_team_id')
                ->fromModel(Team::class, 'name')
                ->required()
                ->title(__('Eligible team'))
                ->help(__('Exactly one team from the shift department defines eligibility (SHIFT-004).')),

            Input::make('shift.title')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Title / function'))
                ->placeholder(__('Dirt Patrol (Day)')),

            DateTimer::make('shift.starts_at')
                ->required()
                ->enableTime()
                ->format24hr()
                ->title(__('Starts at')),

            DateTimer::make('shift.ends_at')
                ->required()
                ->enableTime()
                ->format24hr()
                ->title(__('Ends at'))
                ->help(__('Must be after the scheduled start (SHIFT-002).')),

            Input::make('shift.capacity')
                ->type('number')
                ->min(1)
                ->title(__('Capacity'))
                ->help(__('Leave blank for no cap. Cannot drop below current assignments (SHIFT-007).')),

            DateTimer::make('shift.signup_opens_at')
                ->enableTime()
                ->format24hr()
                ->title(__('Signup opens')),

            DateTimer::make('shift.signup_closes_at')
                ->enableTime()
                ->format24hr()
                ->title(__('Signup closes'))
                ->help(__('Must be after signup open when both are set (SHIFT-008).')),

            DateTimer::make('shift.schedule_lock_at')
                ->enableTime()
                ->format24hr()
                ->title(__('Schedule lock / cutoff'))
                ->help(__('Blocks staff self-service schedule changes from this moment (SHIFT-009).')),

            Select::make('shift.required_training_ids')
                ->fromModel(Training::class, 'name')
                ->multiple()
                ->title(__('Required trainings'))
                ->help(__('Enforced for scheduled and unscheduled additions (SHIFT-005, SHIFT-016).')),

            Select::make('shift.required_waiver_ids')
                ->fromModel(Waiver::class, 'name')
                ->multiple()
                ->title(__('Required waivers'))
                ->help(__('Enforced for scheduled and unscheduled additions (SHIFT-006, SHIFT-016).')),
        ];
    }
}
