<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Equipment;

use App\Models\EquipmentItem;
use App\Orchid\Layouts\Equipment\EquipmentListLayout;
use Orchid\Screen\Action;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Layout;
use Orchid\Screen\Screen;

class EquipmentListScreen extends Screen
{
    /**
     * @return array<string, mixed>
     */
    public function query(): iterable
    {
        return [
            'equipmentItems' => EquipmentItem::query()
                ->with(['organization', 'event', 'department'])
                ->filters()
                ->defaultSort('name')
                ->paginate(),
        ];
    }

    public function name(): ?string
    {
        return 'Equipment';
    }

    public function description(): ?string
    {
        return 'Manual MVP equipment inventory and current item state.';
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
            Link::make(__('Add'))
                ->icon('bs.plus-circle')
                ->route('platform.equipment.create'),
        ];
    }

    /**
     * @return string[]|Layout[]
     */
    public function layout(): iterable
    {
        return [
            EquipmentListLayout::class,
        ];
    }
}
