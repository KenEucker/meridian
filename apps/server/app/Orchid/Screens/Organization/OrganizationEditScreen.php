<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Organization;

use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Organization;
use App\Models\User;
use App\Orchid\Layouts\Organization\OrganizationEditLayout;
use App\Services\Events\IncidentCommandDepartmentSelectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
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
        $validator = Validator::make($request->all(), [
            'organization.name' => ['required', 'string', 'max:255'],
            'organization.slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique(Organization::class, 'slug')->ignore($organization),
            ],
            'organization.default_ic_department_id' => ['nullable', 'uuid', Rule::exists(Department::class, 'id')],
            'organization.active_inactive_threshold_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'organization.prospective_inactive_threshold_years' => ['nullable', 'integer', 'min:0', 'max:100'],
            'organization.calendar_year_start_month' => ['nullable', 'integer', 'between:1,12'],
            'organization.calendar_year_start_day' => ['nullable', 'integer', 'between:1,31'],
        ]);

        $validator->after(function ($validator) use ($request, $organization): void {
            $departmentId = $request->input('organization.default_ic_department_id');

            if (blank($departmentId)) {
                return;
            }

            if (! $organization->exists) {
                $validator->errors()->add(
                    'organization.default_ic_department_id',
                    'Default Incident Command department can only be selected after the organization exists.'
                );

                return;
            }

            $isValidDepartment = Department::query()
                ->active()
                ->whereKey($departmentId)
                ->where('organization_id', $organization->id)
                ->exists();

            if (! $isValidDepartment) {
                $validator->errors()->add(
                    'organization.default_ic_department_id',
                    'Default Incident Command department must be an active department in this organization.'
                );
            }
        });

        /** @var array{organization: array<string, mixed>} $validated */
        $validated = $validator->validate();
        $attributes = $validated['organization'];
        $defaultIcDepartmentId = $attributes['default_ic_department_id'] ?? null;
        unset($attributes['default_ic_department_id']);

        $organization->fill($attributes)->save();

        $department = filled($defaultIcDepartmentId)
            ? Department::query()->findOrFail($defaultIcDepartmentId)
            : null;

        try {
            app(IncidentCommandDepartmentSelectionService::class)->configureOrganizationDefault(
                organization: $organization,
                department: $department,
                actor: $request->user() instanceof User ? $request->user() : null,
                sourceContext: AuditEvent::SOURCE_ORCHID,
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'organization.default_ic_department_id' => $exception->getMessage(),
            ]);
        }

        Toast::info(__('Organization was saved.'));

        return redirect()->route('platform.organizations');
    }
}
