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

            // The colour input beside this has no empty state — with nothing
            // stored the browser submits #000000 — so this checkbox is what
            // says whether the department has an accent at all. The screen
            // reads `set_accent`, and a differently named checkbox here left
            // the control rendered but never consulted.
            CheckBox::make('branding.set_accent')
                ->sendTrueOrFalse()
                ->title(__('This department sets an accent'))
                ->placeholder(__('Leave unticked to store no accent color')),

            Input::make('branding.surface')
                ->type('color')
                ->title(__('Department surface background'))
                ->help(__(
                    'Applied only to this department\'s own operations surfaces. Never applied to incident/IMS '
                    .'surfaces, The Briefing, or organization-level and cross-department surfaces, and not applied at '
                    .'all while the organization has department overrides switched off.'
                )),

            CheckBox::make('branding.set_surface')
                ->sendTrueOrFalse()
                ->title(__('This department sets a surface background'))
                ->placeholder(__('Leave unticked to store no surface background')),

            Input::make('branding.logo')
                ->type('file')
                ->acceptFileTypes(implode(',', BrandingAssetLimits::permittedMimeTypes()))
                ->title(__('Department logo'))
                ->help($this->logoHelp()),

            // Not needed to save an upload — choosing a file above replaces
            // the stored logo on its own. This exists only to get back to no
            // logo at all, and the wording says so, because a checkbox next to
            // a file input reads as a step in the upload rather than as its
            // opposite.
            CheckBox::make('branding.remove_logo')
                ->sendTrueOrFalse()
                ->title(__('Remove the department logo'))
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
            : __('No logo set; a generated lettermark from the department name renders in its place.').' '.$limits;
    }
}
