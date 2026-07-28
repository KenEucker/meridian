<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Team;

use App\Services\Branding\BrandingAssetLimits;
use App\Services\Branding\TeamBranding;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

/**
 * Team branding on the Orchid administrative screen (BRAND-025).
 *
 * One value: a logo. A team has no accent and no surface background, and the
 * absence is deliberate — see {@see TeamBranding}. An input for either here
 * would be the first step toward two identity colors competing on the same
 * department screen.
 */
class TeamBrandingLayout extends Rows
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
                ->title(__('Team logo'))
                ->help($this->logoHelp()),

            CheckBox::make('branding.remove_logo')
                ->sendTrueOrFalse()
                ->title(__('Remove the team logo'))
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

        return is_string($url) && $url !== ''
            ? __('Current logo: :url', ['url' => $url]).' '.$limits
            : __('No logo set; a generated lettermark from the team name renders in its place.').' '.$limits;
    }
}
