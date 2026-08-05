<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Equipment;

use App\Models\Department;
use App\Models\EquipmentItem;
use App\Models\Event;
use App\Models\Organization;
use App\Orchid\Layouts\Equipment\EquipmentEditLayout;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Button;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;
use Orchid\Support\Facades\Toast;

class EquipmentEditScreen extends Screen
{
    /**
     * @var EquipmentItem
     */
    public $equipmentItem;

    /**
     * @return array<string, EquipmentItem>
     */
    public function query(EquipmentItem $equipmentItem): iterable
    {
        if (! $equipmentItem->exists) {
            $equipmentItem->status = EquipmentItem::STATUS_AVAILABLE;
            $equipmentItem->tracking = EquipmentItem::TRACKING_INDIVIDUAL;
            $equipmentItem->quantity_total = 1;
        }

        return [
            'equipmentItem' => $equipmentItem,
        ];
    }

    public function name(): ?string
    {
        return $this->equipmentItem->exists ? 'Edit Equipment' : 'Create Equipment';
    }

    public function description(): ?string
    {
        return 'Equipment identity, scope, and manual state.';
    }

    /**
     * @return iterable<string>
     */
    public function permission(): ?iterable
    {
        return [
            'platform.equipment',
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
                ->route('platform.equipment'),

            Button::make(__('Archive'))
                ->icon('bs.archive')
                ->method('archive')
                ->canSee($this->equipmentItem->exists && ! $this->equipmentItem->isArchived()),

            Button::make(__('Restore'))
                ->icon('bs.arrow-counterclockwise')
                ->method('restore')
                ->canSee($this->equipmentItem->exists && $this->equipmentItem->isArchived()),

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
            Layout::block(EquipmentEditLayout::class)
                ->title(__('Equipment'))
                ->description(__('Manage the inventory record used by shift-board check-in/check-out workflows.')),
        ];
    }

    public function save(Request $request, EquipmentItem $equipmentItem): RedirectResponse
    {
        $validated = $request->validate([
            'equipmentItem.organization_id' => ['required', 'uuid', Rule::exists(Organization::class, 'id')],
            'equipmentItem.event_id' => ['nullable', 'uuid', Rule::exists(Event::class, 'id')],
            'equipmentItem.department_id' => ['nullable', 'uuid', Rule::exists(Department::class, 'id')],
            'equipmentItem.name' => ['required', 'string', 'max:255'],
            // Nullable rather than required: God Mode is repair tooling, and a
            // repair that omits the kind means "leave it as it is" rather than
            // being refused for a field the record already has an answer for.
            'equipmentItem.tracking' => ['nullable', Rule::in(EquipmentItem::trackingKinds())],
            'equipmentItem.asset_tag' => ['nullable', 'string', 'max:255'],
            'equipmentItem.serial_number' => ['nullable', 'string', 'max:255'],
            'equipmentItem.quantity_total' => ['nullable', 'integer', 'min:0'],
            'equipmentItem.status' => ['required', Rule::in(EquipmentItem::statuses())],
        ]);

        $attributes = $validated['equipmentItem'];
        $attributes['event_id'] = $attributes['event_id'] ?? null;
        $attributes['department_id'] = $attributes['department_id'] ?? null;
        $attributes['asset_tag'] = $attributes['asset_tag'] ?? null;
        $attributes['serial_number'] = $attributes['serial_number'] ?? null;
        $attributes['tracking'] = $attributes['tracking']
            ?? ($equipmentItem->tracking ?: EquipmentItem::TRACKING_INDIVIDUAL);

        // A pool has no per-unit identifier and is never held as a whole; a
        // tracked unit is one thing (EQUIP-010, EQUIP-016). God Mode repairs
        // records rather than inventing kinds, so the shape is enforced here
        // too.
        if ($attributes['tracking'] === EquipmentItem::TRACKING_POOLED) {
            $attributes['asset_tag'] = null;
            $attributes['serial_number'] = null;
            $attributes['quantity_total'] = (int) ($attributes['quantity_total'] ?? 0);
            $attributes['status'] = EquipmentItem::STATUS_AVAILABLE;
        } else {
            $attributes['quantity_total'] = 1;
        }

        $this->validateOrganizationScope($attributes);

        $equipmentItem->fill($attributes)->save();

        Toast::info(__('Equipment was saved.'));

        return redirect()->route('platform.equipment');
    }

    public function archive(EquipmentItem $equipmentItem): RedirectResponse
    {
        $equipmentItem->forceFill([
            'archived_at' => now(),
        ])->save();

        Toast::info(__('Equipment was archived.'));

        return redirect()->route('platform.equipment');
    }

    public function restore(EquipmentItem $equipmentItem): RedirectResponse
    {
        $equipmentItem->forceFill([
            'archived_at' => null,
        ])->save();

        Toast::info(__('Equipment was restored.'));

        return redirect()->route('platform.equipment');
    }

    /**
     * @param  array<string, mixed>  $attributes
     *
     * @throws ValidationException
     */
    private function validateOrganizationScope(array $attributes): void
    {
        $organizationId = $attributes['organization_id'];

        if ($attributes['event_id'] !== null) {
            $eventMatchesOrganization = Event::query()
                ->whereKey($attributes['event_id'])
                ->where('organization_id', $organizationId)
                ->exists();

            if (! $eventMatchesOrganization) {
                throw ValidationException::withMessages([
                    'equipmentItem.event_id' => __('Event must belong to the selected organization.'),
                ]);
            }
        }

        if ($attributes['department_id'] !== null) {
            $departmentMatchesOrganization = Department::query()
                ->whereKey($attributes['department_id'])
                ->where('organization_id', $organizationId)
                ->exists();

            if (! $departmentMatchesOrganization) {
                throw ValidationException::withMessages([
                    'equipmentItem.department_id' => __('Department must belong to the selected organization.'),
                ]);
            }
        }
    }
}
