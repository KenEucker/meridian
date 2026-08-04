<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Organization;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Fields\Select;
use Orchid\Screen\Layouts\Rows;

class OrganizationEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('organization.name')
                ->type('text')
                ->max(255)
                ->required()
                ->set('data-meridian-slug-target', 'organization[slug]')
                ->title(__('Name'))
                ->placeholder(__('Northwood Collective')),

            Input::make('organization.slug')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Slug'))
                ->placeholder(__('northwood-collective'))
                ->help(__('Stable URL-safe organization identifier.')),

            Select::make('organization.default_ic_department_id')
                ->options($this->defaultIcDepartmentOptions())
                ->empty(__('No default Incident Command department'), '')
                ->title(__('Default Incident Command department'))
                ->help(__('Optional organization default. Events may override this with an active participating department.')),

            Select::make('organization.organizers_department_id')
                ->options($this->departmentOptions())
                ->empty(__('Not designated'), '')
                ->title(__('Organizers Department'))
                ->help(__('Where organizer authority resolves (ORG-005). Organizer roles cannot be granted until this is set.')),

            Select::make('organization.default_placement_department_id')
                ->options($this->departmentOptions())
                ->empty(__('Not designated'), '')
                ->title(__('Default Placement department'))
                ->help(__('Seeds new events with the Placement function.')),

            Input::make('organization.active_inactive_threshold_years')
                ->type('number')
                ->min(1)
                ->max(100)
                ->title(__('Active-to-inactive threshold years'))
                ->help(__('Active staff become Inactive after this long without recorded work (ORG-019). Blank disables the threshold.')),

            Input::make('organization.prospective_inactive_threshold_years')
                ->type('number')
                ->min(1)
                ->max(100)
                ->title(__('Prospective-to-inactive threshold years'))
                ->help(__('Prospective staff past this become Inactive and must reapply. Blank disables the threshold.')),

            Input::make('organization.hours_correction_grace_period_days')
                ->type('number')
                ->min(0)
                ->max(365)
                ->title(__('Hours correction grace period (days after event end)'))
                ->help(__('Hours can be corrected until this window closes and freeze after it (ORG-017). Every organization has one; 14 days is the default.')),

            Select::make('organization.default_credit_policy_id')
                ->options($this->creditPolicyOptions())
                ->empty(__('No default'), '')
                ->title(__('Default credit policy'))
                ->help(__('Credits any shift that names no policy of its own (ORG-009). Create policies under Credit Policies.')),

            Input::make('organization.calendar_year_start_month')
                ->type('number')
                ->min(1)
                ->max(12)
                ->title(__('Calendar year start month'))
                ->help(__('Use 1 through 12. Month and day travel together, or neither.')),

            Input::make('organization.calendar_year_start_day')
                ->type('number')
                ->min(1)
                ->max(31)
                ->title(__('Calendar year start day'))
                ->help(__('Use 1 through 31.')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function defaultIcDepartmentOptions(): array
    {
        return $this->departmentOptions();
    }

    /**
     * @return array<string, string>
     */
    private function departmentOptions(): array
    {
        $organization = $this->query->get('organization');

        if (! $organization instanceof Organization || ! $organization->exists) {
            return [];
        }

        return Department::query()
            ->active()
            ->where('organization_id', $organization->id)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * The organization-level active policies eligible to be the default. A
     * shift-scoped custom rate is one shift's price and cannot credit the rest
     * of the organization's work.
     *
     * @return array<string, string>
     */
    private function creditPolicyOptions(): array
    {
        $organization = $this->query->get('organization');

        if (! $organization instanceof Organization || ! $organization->exists) {
            return [];
        }

        return CreditPolicy::query()
            ->active()
            ->where('organization_id', $organization->id)
            ->whereNull('shift_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (CreditPolicy $policy): array => [
                (string) $policy->id => sprintf('%s (%s/hr)', $policy->name, $policy->credit_multiplier),
            ])
            ->all();
    }
}
