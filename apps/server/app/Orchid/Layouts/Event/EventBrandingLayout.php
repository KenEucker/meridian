<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Event;

use App\Services\Branding\BrandingAssetLimits;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

/**
 * Event branding on the Orchid administrative screen (BRAND-028).
 *
 * A logo and nothing else. The palette belongs to the organization, and an
 * event palette would be a second set of contrast pairs nobody validated —
 * the same reason a department stops at three values and a team at one.
 */
class EventBrandingLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('branding.logo')
                ->type('file')
                ->acceptFileTypes(implode(',', BrandingAssetLimits::permittedMimeTypes()))
                ->title(__('Event logo'))
                ->help($this->logoHelp()),

            CheckBox::make('branding.remove_logo')
                ->sendTrueOrFalse()
                ->title(__('Remove the event logo'))
                ->placeholder(__('Only tick this to go back to no logo. Uploading a file above already replaces the current one.')),
        ];
    }

    private function logoHelp(): string
    {
        $branding = $this->query->get('branding');
        $url = is_array($branding) ? ($branding['logo_url'] ?? null) : null;

        $limits = __(
            'PNG, WebP, or JPEG, up to :kb KB. SVG is not accepted. Uploading replaces the current logo.',
            ['kb' => (int) (BrandingAssetLimits::MAX_BYTES / 1024)],
        );

        $reach = __(
            'On any node locked to this event, this mark replaces the organization mark in the application header, '
            .'the browser tab icon, and the desktop window icon. Nodes not locked to this event are unaffected.'
        );

        return ($url !== null && $url !== ''
            ? __('Current logo: :url', ['url' => $url])
            : __('No logo set; nodes locked to this event show the organization mark instead.')
        ).' '.$reach.' '.$limits;
    }
}
