<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Staff;

use App\Models\Staff;
use Illuminate\Support\Facades\Storage;
use Orchid\Screen\Actions\Link;
use Orchid\Screen\Components\Cells\DateTimeSplit;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Table;
use Orchid\Screen\TD;

class StaffListLayout extends Table
{
    /**
     * @var string
     */
    public $target = 'staff';

    /**
     * @return TD[]
     */
    public function columns(): array
    {
        return [
            TD::make('profile_picture_path', __('Picture'))
                ->render(function (Staff $staff): string {
                    if ($staff->profile_picture_path === null) {
                        return (string) __('No picture');
                    }

                    $url = e(Storage::disk('public')->url($staff->profile_picture_path));

                    return "<img src=\"{$url}\" alt=\"\" style=\"width: 40px; height: 40px; object-fit: cover; border-radius: 999px;\">";
                }),

            TD::make('legal_name', __('Legal name'))
                ->sort()
                ->cantHide()
                ->filter(Input::make())
                ->render(fn (Staff $staff) => Link::make($staff->legal_name)
                    ->route('platform.staff.edit', $staff->id)),

            TD::make('preferred_name', __('Preferred name'))
                ->sort()
                ->filter(Input::make()),

            TD::make('handle', __('Handle'))
                ->sort()
                ->cantHide()
                ->filter(Input::make()),

            TD::make('email', __('Email'))
                ->sort()
                ->filter(Input::make()),

            TD::make('city', __('City'))
                ->sort()
                ->filter(Input::make()),

            TD::make('state', __('State'))
                ->sort()
                ->filter(Input::make()),

            TD::make('archived_at', __('Archived'))
                ->render(fn (Staff $staff) => $staff->isArchived() ? __('Archived') : __('Active')),

            TD::make('updated_at', __('Last edit'))
                ->usingComponent(DateTimeSplit::class)
                ->align(TD::ALIGN_RIGHT)
                ->sort(),
        ];
    }
}
