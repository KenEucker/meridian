<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Staff;

use App\Models\Staff;
use App\Orchid\Layouts\Staff\StaffEditLayout;
use App\Orchid\Layouts\Staff\StaffOrganizationStatusLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class StaffEditScreen extends Screen
{
    /**
     * @var Staff
     */
    public $staff;

    /**
     * @return array<string, Staff>
     */
    public function query(Staff $staff): iterable
    {
        return [
            'staff' => $staff,
            'organizationStatuses' => $staff->organizationStatuses()
                ->with('organization')
                ->orderBy('created_at')
                ->get(),
        ];
    }

    public function name(): ?string
    {
        return $this->staff->exists ? 'Edit Staff' : 'Create Staff';
    }

    public function description(): ?string
    {
        return 'Staff identity and emergency contact profile.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.staff',
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
                ->route('platform.staff'),

            Button::make(__('Archive'))
                ->icon('bs.archive')
                ->method('archive')
                ->canSee($this->staff->exists && ! $this->staff->isArchived()),

            Button::make(__('Restore'))
                ->icon('bs.arrow-counterclockwise')
                ->method('restore')
                ->canSee($this->staff->exists && $this->staff->isArchived()),

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
            Layout::block(StaffEditLayout::class)
                ->title(__('Staff profile'))
                ->description(__('Profile fields for this staff member.')),

            Layout::block(StaffOrganizationStatusLayout::class)
                ->title(__('Organization statuses'))
                ->description(__('Per-organization status records for this staff member.')),
        ];
    }

    public function save(Request $request, Staff $staff): RedirectResponse
    {
        $validated = $request->validate([
            'staff.legal_name' => ['required', 'string', 'max:255'],
            'staff.preferred_name' => ['required', 'string', 'max:255'],
            'staff.handle' => ['required', 'string', 'max:255'],
            'staff.formerly_known_as' => ['nullable', 'string'],
            'staff.email' => ['required', 'email', 'max:255', Rule::unique(Staff::class, 'email')->ignore($staff)],
            'staff.phone' => ['required', 'string', 'max:255'],
            'staff.city' => ['required', 'string', 'max:255'],
            'staff.state' => ['required', 'string', 'max:255'],
            'staff.date_of_birth' => ['required', 'date', 'before_or_equal:today'],
            'staff.emergency_contact_name' => ['required', 'string', 'max:255'],
            'staff.emergency_contact_phone' => ['required', 'string', 'max:255'],
            'staff.profile_picture_path' => ['nullable', 'string', 'max:2048'],
        ]);

        if (($validated['staff']['profile_picture_path'] ?? null) !== $staff->profile_picture_path) {
            $validated['staff']['profile_picture_uploaded_at'] = $validated['staff']['profile_picture_path'] === null ? null : now();
        }

        $staff->fill($validated['staff'])->save();

        Toast::info(__('Staff profile was saved.'));

        return redirect()->route('platform.staff');
    }

    public function archive(Staff $staff): RedirectResponse
    {
        $staff->forceFill([
            'archived_at' => now(),
        ])->save();

        Toast::info(__('Staff profile was archived.'));

        return redirect()->route('platform.staff');
    }

    public function restore(Staff $staff): RedirectResponse
    {
        $staff->forceFill([
            'archived_at' => null,
        ])->save();

        Toast::info(__('Staff profile was restored.'));

        return redirect()->route('platform.staff');
    }
}
