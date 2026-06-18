<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Department;

use App\Models\Department;
use App\Models\Organization;
use App\Orchid\Layouts\Department\DepartmentEditLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class DepartmentEditScreen extends Screen
{
    /**
     * @var Department
     */
    public $department;

    /**
     * @return array<string, Department>
     */
    public function query(Department $department): iterable
    {
        return [
            'department' => $department,
        ];
    }

    public function name(): ?string
    {
        return $this->department->exists ? 'Edit Department' : 'Create Department';
    }

    public function description(): ?string
    {
        return 'Department identity and organization ownership.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.departments',
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
                ->route('platform.departments'),

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
            Layout::block(DepartmentEditLayout::class)
                ->title(__('Department'))
                ->description(__('Departments manage persistent operational units within an organization.')),
        ];
    }

    public function save(Request $request, Department $department): RedirectResponse
    {
        $organizationId = $request->input('department.organization_id');

        $validated = $request->validate([
            'department.organization_id' => ['required', 'integer', Rule::exists(Organization::class, 'id')],
            'department.name' => ['required', 'string', 'max:255'],
            'department.code' => [
                'required',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique(Department::class, 'code')
                    ->where('organization_id', $organizationId)
                    ->ignore($department),
            ],
            'department.description' => ['nullable', 'string'],
        ]);

        $department->fill($validated['department'])->save();

        Toast::info(__('Department was saved.'));

        return redirect()->route('platform.departments');
    }
}
