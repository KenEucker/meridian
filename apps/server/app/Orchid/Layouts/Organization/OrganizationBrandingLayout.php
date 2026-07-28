<?php

declare(strict_types=1);

namespace App\Orchid\Layouts\Organization;

use App\Services\Branding\BrandingAssetLimits;
use App\Services\Branding\BrandingPalette;
use Orchid\Screen\Field;
use Orchid\Screen\Fields\CheckBox;
use Orchid\Screen\Fields\Input;
use Orchid\Screen\Layouts\Rows;

/**
 * Organization branding on the Orchid administrative screen (M15A.6;
 * BRAND-001, BRAND-004, BRAND-006, BRAND-013).
 *
 * The product-path surface at `organizer.branding` is where an organizer
 * normally works: it has the live preview and the contrast verdict BRAND-018
 * requires before a save. This exists alongside it because Orchid is the
 * repair and inspection console — a God Mode operator has to be able to *see*
 * what an organization's stored branding actually is, and fix it, without
 * signing in as an organizer.
 *
 * The same services back both. Orchid gets no exemption from the contrast
 * validator, the central-node authority rule, or the active-event freeze; a
 * refusal here is refused for the same reason and with the same message.
 */
class OrganizationBrandingLayout extends Rows
{
    /**
     * @var array<string, string>
     */
    private const FIELD_TITLES = [
        'primary' => 'Platform primary',
        'secondary' => 'Platform secondary',
        'tertiary' => 'Platform tertiary',
        'accent' => 'Platform accent',
        'canvas' => 'Canvas background',
        'surface' => 'Surface background',
        'foreground' => 'Foreground text',
        'muted_foreground' => 'Muted foreground text',
        'border' => 'Border',
        'focus' => 'Focus indicator',
    ];

    /**
     * @return Field[]
     */
    public function fields(): array
    {
        $fields = [
            Input::make('branding.display_name')
                ->type('text')
                ->max(255)
                ->title(__('Branding display name'))
                ->placeholder(__('Meridian'))
                ->help(__(
                    'Replaces the Meridian name on signed-in product surfaces: application header and Home control, '
                    .'document title, generated PDF exports, and organization-identified system email. Leave empty to '
                    .'keep Meridian identity. Login, the magic-link landing, node first-run setup, this console, and '
                    .'desktop chrome always keep Meridian identity.'
                )),

            CheckBox::make('branding.department_branding_enabled')
                ->sendTrueOrFalse()
                ->title(__('Department branding overrides'))
                ->placeholder(__('Allow departments to set their own accent and surface background'))
                ->help(__(
                    'When off, departments keep logo and accent identity only and no department surface background is '
                    .'applied anywhere. Stored department values are kept, not deleted.'
                )),
        ];

        foreach (BrandingPalette::FIELDS as $field) {
            $fields[] = Input::make('branding.palette.'.$field)
                ->type('color')
                ->title(__(self::FIELD_TITLES[$field]))
                ->help($this->paletteHelp($field));
        }

        return [
            ...$fields,

            Input::make('branding.full_lockup')
                ->type('file')
                ->acceptFileTypes(implode(',', BrandingAssetLimits::permittedMimeTypes()))
                ->title(__('Full logo lockup'))
                ->help($this->assetHelp($this->currentUrl('full_lockup_url'))),

            CheckBox::make('branding.remove_full_lockup')
                ->sendTrueOrFalse()
                ->title(__('Remove the full logo lockup'))
                ->placeholder(__('Only tick this to go back to no lockup. Uploading a file above already replaces the current one.')),

            Input::make('branding.compact_mark')
                ->type('file')
                ->acceptFileTypes(implode(',', BrandingAssetLimits::permittedMimeTypes()))
                ->title(__('Compact logo mark'))
                ->help($this->assetHelp($this->currentUrl('compact_mark_url'))),

            CheckBox::make('branding.remove_compact_mark')
                ->sendTrueOrFalse()
                ->title(__('Remove the compact logo mark'))
                ->placeholder(__('Only tick this to go back to no mark. Uploading a file above already replaces the current one.')),
        ];
    }

    private function paletteHelp(string $field): string
    {
        // Every palette field carries the same warning, because the failure
        // mode is the same one: a value that looks fine in isolation is
        // refused for a pair it participates in.
        $shared = __(
            'Validated server-side against WCAG 2.1 AA. A failing combination is refused with the failing pair and the '
            .'measured ratio; nothing is auto-corrected.'
        );

        return in_array($field, ['primary', 'secondary', 'tertiary', 'accent'], true)
            ? __('Action, status, severity, priority, and chart colors derive from the four platform colors and cannot be set separately.').' '.$shared
            : $shared;
    }

    private function assetHelp(?string $currentUrl): string
    {
        $limits = __(
            'PNG, WebP, or JPEG, up to :kb KB. SVG is not accepted. Uploading replaces the current asset.',
            ['kb' => (int) (BrandingAssetLimits::MAX_BYTES / 1024)],
        );

        return $currentUrl === null
            ? __('No asset set; a generated lettermark renders in its place.').' '.$limits
            : __('Current asset: :url', ['url' => $currentUrl]).' '.$limits;
    }

    private function currentUrl(string $key): ?string
    {
        $branding = $this->query->get('branding');
        $value = is_array($branding) ? ($branding[$key] ?? null) : null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
