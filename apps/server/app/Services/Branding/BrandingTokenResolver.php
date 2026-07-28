<?php

declare(strict_types=1);

namespace App\Services\Branding;

/**
 * Turns a branding profile into the CSS custom properties the surfaces render
 * with (M15A.4; BRAND-006, BRAND-007; UI implementation contract section 10).
 *
 * This layer emits **only** the settable values plus the one derivation CSS
 * cannot express. Everything else in `packages/ui-tokens/tokens.css` is written
 * as `var(--m-platform-*)` or `color-mix()` over a settable token, so action,
 * status, severity, attention, chart, and elevation tokens re-resolve on their
 * own the moment the platform palette changes. Emitting them here as well would
 * create a second definition of every derived token that could drift from the
 * stylesheet's — and a branding profile that shipped its own `--m-status-danger`
 * would be exactly the independently-settable state color BRAND-007 forbids.
 *
 * The exception is action label color. Choosing black or white text over a
 * filled button is a luminance decision, and `color-contrast()` is not
 * available across the browsers Alpha 1 targets, so it is computed here and in
 * the TypeScript resolver in `@meridian/ui-tokens`. Those two implementations
 * are held together by a shared fixture and a parity test, because a label
 * color that differed between the server-rendered admin surface and the client
 * would be a legibility bug that only shows up on one of them.
 */
class BrandingTokenResolver
{
    /**
     * Custom property name for each settable palette field.
     *
     * @var array<string, string>
     */
    public const PALETTE_TOKENS = [
        'primary' => '--m-platform-primary',
        'secondary' => '--m-platform-secondary',
        'tertiary' => '--m-platform-tertiary',
        'accent' => '--m-platform-accent',
        'canvas' => '--m-surface-app',
        'surface' => '--m-surface-base',
        'foreground' => '--m-text-primary',
        'muted_foreground' => '--m-text-muted',
        'border' => '--m-border-default',
        'focus' => '--m-focus-ring',
    ];

    /**
     * The label color for a filled platform-color action.
     *
     * Whichever of the palette's own foreground and surface reads better on the
     * color wins. Restricting the choice to two palette values rather than to
     * pure black and white keeps a button label inside the organization's own
     * palette; a warm off-white organization gets its off-white, not #ffffff.
     *
     * A tie cannot occur — the two candidates would have to have identical
     * luminance — and if the winner still falls below 4.5:1 the validator
     * refuses the palette rather than this method compensating (BRAND-016).
     */
    public static function actionLabelFor(BrandingColor $background, BrandingPalette $palette): BrandingColor
    {
        $foreground = $palette->foreground();
        $surface = $palette->surface();

        return $background->contrastRatioWith($surface) >= $background->contrastRatioWith($foreground)
            ? $surface
            : $foreground;
    }

    /**
     * Palette fields that carry into dark mode.
     *
     * The four platform colors are hues an organization owns, and they read on
     * a dark surface as well as a light one. The six neutrals are not: an
     * organization submits one light set (BRAND-006), validated against light
     * backgrounds, and painting a light canvas behind Meridian's dark-mode
     * foreground is the unreadable combination the validator exists to refuse.
     *
     * @var list<string>
     */
    private const DARK_MODE_FIELDS = ['primary', 'secondary', 'tertiary', 'accent'];

    /**
     * The custom properties an organization palette contributes, in both modes.
     *
     * This is the union of {@see platformTokens()} and
     * {@see lightModeTokens()}, and it is what the client/server parity fixture
     * pins. Anything applying these to a live document has to respect the
     * split — see {@see organizationStylesheet()}.
     *
     * @return array<string, string> custom property name => color value
     */
    public function organizationTokens(BrandingPalette $palette): array
    {
        return [
            ...$this->platformTokens($palette),
            ...$this->lightModeTokens($palette),
        ];
    }

    /**
     * The tokens that apply in light and dark mode alike.
     *
     * @return array<string, string>
     */
    public function platformTokens(BrandingPalette $palette): array
    {
        $tokens = [];

        foreach (self::DARK_MODE_FIELDS as $field) {
            $tokens[self::PALETTE_TOKENS[$field]] = $palette->color($field)->hex;
        }

        return $tokens;
    }

    /**
     * The tokens that apply only in light mode.
     *
     * The action label colors are here rather than beside the platform colors
     * because they are chosen from the palette's own foreground and surface —
     * light-mode neutrals — so carrying them into dark mode would pair a
     * light-mode label with a dark-mode surface.
     *
     * @return array<string, string>
     */
    public function lightModeTokens(BrandingPalette $palette): array
    {
        $tokens = [];

        foreach (self::PALETTE_TOKENS as $field => $property) {
            if (! in_array($field, self::DARK_MODE_FIELDS, true)) {
                $tokens[$property] = $palette->color($field)->hex;
            }
        }

        $tokens['--m-action-primary-text'] = self::actionLabelFor($palette->primary(), $palette)->hex;
        $tokens['--m-action-secondary-text'] = self::actionLabelFor($palette->secondary(), $palette)->hex;
        $tokens['--m-action-destructive-text'] = self::actionLabelFor($palette->accent(), $palette)->hex;

        return $tokens;
    }

    /**
     * The custom properties a department override contributes.
     *
     * Returns an empty map when the organization has department overrides
     * switched off, which is how BRAND-013 is enforced at the token layer
     * rather than at each surface: with nothing emitted, `--m-department-accent`
     * keeps its derived organization value and `--m-department-surface` is
     * simply absent, so a scoped surface falls back to the organization surface.
     *
     * The accent and the surface are separable. An accent alone is valid — it
     * is the identity mark departments have always had — and a background alone
     * is valid too.
     *
     * @return array<string, string>
     */
    public function departmentTokens(
        ?BrandingColor $accent,
        ?BrandingColor $surfaceBackground,
        bool $overridesEnabled,
    ): array {
        if (! $overridesEnabled) {
            return [];
        }

        $tokens = [];

        if ($accent !== null) {
            $tokens['--m-department-accent'] = $accent->hex;
        }

        if ($surfaceBackground !== null) {
            $tokens['--m-department-surface'] = $surfaceBackground->hex;
        }

        return $tokens;
    }

    /**
     * The organization layer as a stylesheet.
     *
     * Scoped to `:root[data-organization-branding="applied"]` so the values
     * apply exactly where the shell has declared that an organization palette
     * is active (UI implementation contract 10.3). A pre-authentication surface,
     * Orchid, or desktop chrome that never sets the attribute keeps Meridian's
     * identity even if this stylesheet is loaded (BRAND-003), which means the
     * boundary does not depend on remembering not to include a file.
     */
    public function organizationStylesheet(BrandingPalette $palette): string
    {
        // Two rules, because the neutrals must not survive into dark mode.
        // `:not([data-theme="dark"])` rather than source order: the branding
        // selector is more specific than `[data-theme="dark"]`, so ordering
        // alone would leave a light canvas painted behind a dark-mode
        // foreground.
        return $this->rule(
            ':root[data-organization-branding="applied"]',
            $this->platformTokens($palette),
        )
            ."\n"
            .$this->rule(
                ':root[data-organization-branding="applied"]:not([data-theme="dark"])',
                $this->lightModeTokens($palette),
            )
            ."\n"
            ."@media (prefers-color-scheme: dark) {\n"
            .$this->rule(
                ':root[data-organization-branding="applied"]:not([data-theme])',
                $this->meridianDarkNeutrals(),
            )
            ."}\n";
    }

    /**
     * Meridian's dark neutrals, restated so a surface following the OS
     * preference with no explicit theme does not keep the light-mode branding
     * values the rule above applied to it.
     *
     * @return array<string, string>
     */
    private function meridianDarkNeutrals(): array
    {
        return [
            '--m-surface-app' => '#151a1f',
            '--m-surface-base' => '#1b222a',
            '--m-text-primary' => '#f6f1e8',
            '--m-text-muted' => '#8a929a',
            '--m-border-default' => '#384450',
            '--m-focus-ring' => 'var(--m-platform-accent)',
            '--m-action-primary-text' => '#fffcf6',
            '--m-action-secondary-text' => '#fffcf6',
            '--m-action-destructive-text' => '#151a1f',
        ];
    }

    /**
     * The department layer as a stylesheet, keyed by department id.
     *
     * The accent and the background are emitted under different selectors, and
     * the asymmetry is deliberate. An accent is a small identifier that reads
     * on any surface, so it applies unconditionally. A background is a light
     * color chosen against the organization's light neutrals; painting it
     * behind Meridian's dark-mode foreground would produce exactly the
     * unreadable combination the validator refuses at submission time. Dark
     * mode therefore keeps department identity as accent and logo, and the
     * background falls back to the organization surface.
     *
     * @param  array<string, array{accent: ?BrandingColor, surface: ?BrandingColor}>  $departments
     */
    public function departmentStylesheet(array $departments, bool $overridesEnabled): string
    {
        if (! $overridesEnabled) {
            return '';
        }

        $rules = [];

        foreach ($departments as $departmentId => $branding) {
            $selector = sprintf('[data-department-branding="%s"]', $departmentId);
            $accent = $branding['accent'] ?? null;
            $surface = $branding['surface'] ?? null;

            if ($accent !== null) {
                $rules[] = $this->rule($selector, ['--m-department-accent' => $accent->hex]);
            }

            if ($surface === null) {
                continue;
            }

            $rules[] = $this->rule(
                ':root:not([data-theme="dark"]) '.$selector,
                ['--m-department-surface' => $surface->hex],
            );

            $rules[] = "@media (prefers-color-scheme: dark) {\n"
                .$this->rule(
                    ':root:not([data-theme]) '.$selector,
                    ['--m-department-surface' => 'var(--m-surface-base)'],
                )
                ."}\n";
        }

        return implode("\n", $rules);
    }

    /**
     * @param  array<string, string>  $tokens
     */
    private function rule(string $selector, array $tokens): string
    {
        if ($tokens === []) {
            return '';
        }

        $declarations = [];

        foreach ($tokens as $property => $value) {
            $declarations[] = sprintf('  %s: %s;', $property, $value);
        }

        return $selector." {\n".implode("\n", $declarations)."\n}\n";
    }
}
