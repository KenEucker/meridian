<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Staff;

use Orchid\Screen\Field;
use Orchid\Screen\Fields\Cropper;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

class StaffEditLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('staff.legal_name')
                ->type('text')
                ->max(255)
                ->required()
                ->title(__('Legal name'))
                ->placeholder(__('Jordan Reed')),

            Input::make('staff.preferred_name')
                ->type('text')
                ->max(255)
                ->title(__('Preferred name'))
                ->placeholder(__('Jordan')),

            Input::make('staff.handle')
                ->type('text')
                ->max(255)
                ->title(__('Handle'))
                ->placeholder(__('Signal')),

            Input::make('staff.formerly_known_as')
                ->type('text')
                ->title(__('Formerly known as'))
                ->placeholder(__('Prior handle or operational name')),

            Input::make('staff.email')
                ->type('email')
                ->max(255)
                ->required()
                ->title(__('Email'))
                ->placeholder(__('jordan@example.org')),

            Input::make('staff.phone')
                ->type('tel')
                ->max(255)
                ->title(__('Phone'))
                ->placeholder(__('555-0100')),

            Input::make('staff.city')
                ->type('text')
                ->max(255)
                ->title(__('City'))
                ->placeholder(__('Boise')),

            Input::make('staff.state')
                ->type('text')
                ->max(255)
                ->title(__('State'))
                ->placeholder(__('ID')),

            Input::make('staff.date_of_birth')
                ->type('date')
                ->title(__('Date of birth')),

            Input::make('staff.emergency_contact_name')
                ->type('text')
                ->max(255)
                ->title(__('Emergency contact name'))
                ->placeholder(__('Casey Reed')),

            Input::make('staff.emergency_contact_phone')
                ->type('tel')
                ->max(255)
                ->title(__('Emergency contact phone'))
                ->placeholder(__('555-0101')),

            Cropper::make('staff.profile_picture_path')
                ->targetRelativeUrl()
                ->storage('public')
                ->path('staff/profile-pictures')
                ->acceptedFiles('image/jpeg,image/png,image/webp')
                ->maxCanvas(1024)
                ->title(__('Profile picture'))
                ->help(__('JPEG, PNG, or WebP. Stored image is limited to 1024 x 1024.')),
        ];
    }
}
