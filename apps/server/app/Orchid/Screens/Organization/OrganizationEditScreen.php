<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Models\Organization;
use App\Orchid\Layouts\Organization\OrganizationEditLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class OrganizationEditScreen extends Screen
{
    /**
     * @var Organization
     */
    public $organization;

    /**
     * @return array<string, Organization>
     */
    public function query(Organization $organization): iterable
    {
        return [
            'organization' => $organization,
        ];
    }

    public function name(): ?string
    {
        return $this->organization->exists ? 'Edit Organization' : 'Create Organization';
    }

    public function description(): ?string
    {
        return 'Organization identity and core configuration.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.organizations',
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
                ->route('platform.organizations'),

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
            Layout::block(OrganizationEditLayout::class)
                ->title(__('Organization'))
                ->description(__('Organizations produce events, define departments, and manage volunteers.')),
        ];
    }

    public function save(Request $request, Organization $organization): RedirectResponse
    {
        $validated = $request->validate([
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique(Organization::class, 'slug')->ignore($organization),
            ],
            'organization.active_inactive_threshold_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'organization.prospective_inactive_threshold_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'organization.calendar_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'organization.calendar_year_start_day' => ['nullable', 'integer', 'between:1,31'],
        ]);

        $organization->fill($validated['organization'])->save();

        Toast::info(__('Organization was saved.'));

        return redirect()->route('platform.organizations');
    }
}
