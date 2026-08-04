<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use App\Domain\Staffing\ProfileChangePolicy;
use App\Models\CreditPolicy;
use App\Models\Department;
use App\Models\Organization;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
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

            /*
             * Staff profile approval policy (VOL-027, VOL-028). Handles and
             * pictures are configured separately because they are different
             * kinds of fact about a person: a handle is spoken on a radio and
             * has to be unambiguous, a picture is how a desk recognises
             * somebody. Organizers reach the same settings from the product
             * configuration surface (ORG-018); this is the God Mode copy.
             */
            Select::make('organization.handle_change_policy')
                ->options($this->changePolicyOptions('handle'))
                ->empty(__('Use the default (approved by organizers)'), '')
                ->title(__('Handle change approval'))
                ->help(__('How a staff member\'s handle change is decided (VOL-027).')),

            Input::make('organization.handle_self_service_change_limit')
                ->type('number')
                ->min(0)
                ->max(50)
                ->title(__('Handle changes applied without review'))
                ->help(__('How many handle changes apply immediately while the policy above allows any (VOL-028). Blank uses the default of two; 0 turns the allowance off without changing the policy.')),

            Select::make('organization.profile_picture_change_policy')
                ->options($this->changePolicyOptions('profile picture'))
                ->empty(__('Use the default (approved by organizers)'), '')
                ->title(__('Profile picture approval'))
                ->help(__('How a submitted profile picture is decided (VOL-027). Pictures applied without review are not rationed the way handles are.')),

            /*
             * The per-organization send-suppression switch (NOTIFY-009). It
             * lives beside the other organization settings rather than on a
             * screen of its own because it is one boolean, and it is stated
             * here in full because switching it on silently stops every
             * transactional email this organization's staff would receive.
             * The global switch is separate and either alone stops a send;
             * both are reported on the God Mode landing screen.
             */
            CheckBox::make('organization.notifications_suppressed')
                ->sendTrueOrFalse()
                ->title(__('Suppress notification email'))
                ->placeholder(__('Send no notification email for this organization'))
                ->help(__('Notifications are still recorded with their recipient, type, and subject record; none are sent. Use this for a rehearsal or a restored copy of this organization\'s data (NOTIFY-009).')),
        ];
    }

    /**
     * The four VOL-027 policies, labelled with what each means for this kind.
     *
     * @return array<string, string>
     */
    private function changePolicyOptions(string $noun): array
    {
        $options = [];

        foreach (ProfileChangePolicy::cases() as $policy) {
            $options[$policy->value] = $policy->label().' — '.$policy->description($noun);
        }

        return $options;
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
