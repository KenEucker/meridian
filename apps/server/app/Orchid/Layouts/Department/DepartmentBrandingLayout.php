<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Department;

use App\Services\Branding\BrandingAssetLimits;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

/**
 * Department branding on the Orchid administrative screen (M15A.7;
 * BRAND-009 through BRAND-013).
 *
 * Three values and no more: logo, accent, surface background. Foreground,
 * border, focus, status, severity, attention, and chart colors resolve from
 * the organization palette and are deliberately absent — a department
 * "override" for any of them is what BRAND-011 forbids, and an input here
 * would be the first step toward one.
 */
class DepartmentBrandingLayout extends Rows
{
    /**
     * @return Field[]
     */
    public function fields(): array
    {
        return [
            Input::make('branding.accent')
                ->type('color')
                ->title(__('Department accent'))
                ->help(__(
                    'A small identifier on department badges and department context. It renders wherever the '
                    .'department appears, including surfaces that never take a department background.'
                )),

            CheckBox::make('branding.clear_accent')
                ->sendTrueOrFalse()
                ->title(__('Clear accent'))
                ->placeholder(__('Remove the stored accent color on save')),

            Input::make('branding.surface')
                ->type('color')
                ->title(__('Department surface background'))
                ->help(__(
                    'Applied only to this department\'s own operations surfaces. Never applied to incident/IMS '
                    .'surfaces, The Briefing, or organization-level and cross-department surfaces, and not applied at '
                    .'all while the organization has department overrides switched off.'
                )),

            CheckBox::make('branding.clear_surface')
                ->sendTrueOrFalse()
                ->title(__('Clear surface background'))
                ->placeholder(__('Remove the stored surface background on save')),

            Input::make('branding.logo')
                ->type('file')
                ->acceptFileTypes(implode(',', BrandingAssetLimits::permittedMimeTypes()))
                ->title(__('Department logo'))
                ->help($this->logoHelp()),

            CheckBox::make('branding.remove_logo')
                ->sendTrueOrFalse()
                ->title(__('Remove department logo'))
                ->placeholder(__('Clear the current logo on save')),
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
            : __('No logo set; a generated lettermark from the department name renders in its place.').' '.$limits;
    }
}
