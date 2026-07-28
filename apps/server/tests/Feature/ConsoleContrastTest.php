<?php

namespace Tests\Feature;

use App\Services\Branding\BrandingColor;
use App\Services\Branding\ContrastValidator;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Contrast and focus visibility across the restyled console (M15C.9; GOD-036;
 * accessibility checklist section 6).
 *
 * The console is styled entirely in CSS custom properties, so the pairs a
 * screen actually renders are decided by the token file and the token bridge,
 * not by any PHP the request runs through. These tests therefore resolve the
 * token values the way a browser would — following `var()` chains and
 * evaluating `color-mix(in srgb, …)` — and measure the resulting pairs with
 * the same WCAG arithmetic and the same thresholds the branding validator
 * applies to a submitted organization palette.
 *
 * Both themes are checked. The console follows the operator's theme
 * preference, and Meridian's dark neutrals are not covered by the branding
 * validator (an organization cannot set them), so nothing else measures them.
 */
class ConsoleContrastTest extends TestCase
{
    /**
     * Every pair the console renders, as token expressions rather than colors.
     *
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function consolePairs(): array
    {
        $navBackground = 'var(--m-action-primary-bg)';
        $navForeground = 'var(--m-action-primary-text)';
        $navMuted = "color-mix(in srgb, {$navForeground} 78%, {$navBackground})";

        return [
            // Workspace text.
            'foreground on the canvas' => ['var(--m-text-primary)', 'var(--m-surface-app)', 4.5],
            'foreground on a card' => ['var(--m-text-primary)', 'var(--m-surface-base)', 4.5],
            'muted foreground on the canvas' => ['var(--m-text-muted)', 'var(--m-surface-app)', 4.5],
            'muted foreground on a card' => ['var(--m-text-muted)', 'var(--m-surface-base)', 4.5],
            'link on the canvas' => ['var(--m-text-secondary)', 'var(--m-surface-app)', 4.5],
            'link on a card' => ['var(--m-text-secondary)', 'var(--m-surface-base)', 4.5],

            // Table headings and captions are small supporting text, so they
            // take the normal-text threshold rather than the large-text one.
            'table heading on a card' => ['var(--m-text-muted)', 'var(--m-surface-base)', 4.5],

            // Control boundaries and focus.
            'control boundary on the canvas' => [
                'color-mix(in srgb, var(--m-border-default) 70%, var(--m-text-primary))',
                'var(--m-surface-app)',
                3.0,
            ],
            'control boundary on a card' => [
                'color-mix(in srgb, var(--m-border-default) 70%, var(--m-text-primary))',
                'var(--m-surface-base)',
                3.0,
            ],
            'focus ring on the canvas' => ['var(--m-focus-ring)', 'var(--m-surface-app)', 3.0],
            'focus ring on a card' => ['var(--m-focus-ring)', 'var(--m-surface-base)', 3.0],

            // Navigation. The sidebar is a primary-colored surface in both
            // themes, so its own foreground, its supporting text, its active
            // pill, and its focus ring are all measured against it.
            'navigation label on the navigation' => [$navForeground, $navBackground, 4.5],
            'navigation group title on the navigation' => [$navMuted, $navBackground, 4.5],
            'active navigation label on its pill' => [$navBackground, $navForeground, 4.5],
            'navigation pill against the navigation' => [$navForeground, $navBackground, 3.0],
            'navigation focus ring on the navigation' => [$navForeground, $navBackground, 3.0],

            // Filled actions carry the label color the token set pairs with
            // them, which is what keeps a button from being a legible fill
            // with an illegible label.
            'primary action label' => ['var(--m-action-primary-text)', 'var(--m-action-primary-bg)', 4.5],
            'secondary action label' => ['var(--m-action-secondary-text)', 'var(--m-action-secondary-bg)', 4.5],
            'destructive action label' => ['var(--m-action-destructive-text)', 'var(--m-action-destructive-bg)', 4.5],
            'warning action label' => ['var(--m-action-destructive-text)', 'var(--m-status-warning)', 4.5],

            // Disabled controls drop to the canvas rather than fading, so
            // their labels stay readable instead of relying on the WCAG
            // exemption for inactive components.
            'disabled control label' => ['var(--m-text-muted)', 'var(--m-surface-app)', 4.5],
            'disabled control boundary' => [
                'color-mix(in srgb, var(--m-border-default) 70%, var(--m-text-primary))',
                'var(--m-surface-app)',
                3.0,
            ],

            // Status text. A status color is validated as a fill and as an
            // indicator, never as a label, so the console pulls it toward the
            // foreground before using it as text.
            'success text on a card' => [
                'color-mix(in srgb, var(--m-status-success) 55%, var(--m-text-primary))',
                'var(--m-surface-base)',
                4.5,
            ],
            'warning text on a card' => [
                'color-mix(in srgb, var(--m-status-warning) 55%, var(--m-text-primary))',
                'var(--m-surface-base)',
                4.5,
            ],
            'danger text on a card' => [
                'color-mix(in srgb, var(--m-status-danger) 55%, var(--m-text-primary))',
                'var(--m-surface-base)',
                4.5,
            ],
            'danger text on the canvas' => [
                'color-mix(in srgb, var(--m-status-danger) 55%, var(--m-text-primary))',
                'var(--m-surface-app)',
                4.5,
            ],

            // Alert and badge tints, which the console draws as the status
            // color mixed into the surface with ordinary foreground on top.
            'alert text on a warning tint' => [
                'var(--m-text-primary)',
                'color-mix(in srgb, var(--m-status-warning) 14%, var(--m-surface-base))',
                4.5,
            ],
            'alert text on a danger tint' => [
                'var(--m-text-primary)',
                'color-mix(in srgb, var(--m-status-danger) 14%, var(--m-surface-base))',
                4.5,
            ],
            'alert text on a success tint' => [
                'var(--m-text-primary)',
                'color-mix(in srgb, var(--m-status-success) 14%, var(--m-surface-base))',
                4.5,
            ],
            'warning border on a card' => [
                'color-mix(in srgb, var(--m-status-warning) 70%, var(--m-text-primary))',
                'var(--m-surface-base)',
                3.0,
            ],
            'danger border on a card' => [
                'color-mix(in srgb, var(--m-status-danger) 70%, var(--m-text-primary))',
                'var(--m-surface-base)',
                3.0,
            ],
            'success border on a card' => [
                'color-mix(in srgb, var(--m-status-success) 70%, var(--m-text-primary))',
                'var(--m-surface-base)',
                3.0,
            ],
            'neutral border on a card' => [
                'color-mix(in srgb, var(--m-status-neutral) 70%, var(--m-text-primary))',
                'var(--m-surface-base)',
                3.0,
            ],

            // Status and severity remain distinguishable as indicators on the
            // surfaces the console draws them on (BRAND-017 in spirit: the
            // console has no branding profile, but the same floor applies).
            'danger indicator on a card' => ['var(--m-status-danger)', 'var(--m-surface-base)', 3.0],
            'warning indicator on a card' => ['var(--m-status-warning)', 'var(--m-surface-base)', 3.0],
            'success indicator on a card' => ['var(--m-status-success)', 'var(--m-surface-base)', 3.0],
        ];
    }

    #[DataProvider('consolePairs')]
    public function test_console_pairs_hold_in_light_mode(
        string $foreground,
        string $background,
        float $required,
    ): void {
        $this->assertPairHolds($foreground, $background, $required, 'light');
    }

    #[DataProvider('consolePairs')]
    public function test_console_pairs_hold_in_dark_mode(
        string $foreground,
        string $background,
        float $required,
    ): void {
        $this->assertPairHolds($foreground, $background, $required, 'dark');
    }

    public function test_the_bridge_declares_the_derived_values_the_measured_pairs_assume(): void
    {
        // The pairs above are token expressions. This is what ties them to the
        // stylesheet: if the bridge stops deriving a value this way, the
        // measurements stop describing what the console renders.
        $css = (string) file_get_contents(base_path('public/css/meridian-console.css'));

        foreach ([
            '--m-console-border: color-mix(in srgb, var(--m-border-default) 70%, var(--m-text-primary));',
            '--m-console-text-success: color-mix(in srgb, var(--m-status-success) 55%, var(--m-text-primary));',
            '--m-console-text-warning: color-mix(in srgb, var(--m-status-warning) 55%, var(--m-text-primary));',
            '--m-console-text-danger: color-mix(in srgb, var(--m-status-danger) 55%, var(--m-text-primary));',
            '--m-console-nav-bg: var(--m-action-primary-bg);',
            '--m-console-nav-fg: var(--m-action-primary-text);',
            '--m-console-nav-muted: color-mix(in srgb, var(--m-console-nav-fg) 78%, var(--m-console-nav-bg));',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $css);
        }
    }

    public function test_every_focusable_console_control_declares_a_visible_focus_indicator(): void
    {
        $css = (string) file_get_contents(base_path('public/css/meridian-console.css'));

        $this->assertStringContainsString(
            ':is(a, button, input, select, textarea, summary, [tabindex]):focus-visible',
            $css,
        );
        $this->assertStringContainsString('outline: 2px solid var(--m-focus-ring);', $css);
        $this->assertStringContainsString('outline-offset: 2px;', $css);

        // The navigation is the one region where the focus token itself does
        // not clear 3:1, so it overrides the ring rather than inheriting one
        // that would be invisible there.
        $this->assertStringContainsString(
            '.aside :is(a, button, input, select, textarea, summary, [tabindex]):focus-visible',
            $css,
        );
        $this->assertStringContainsString('outline-color: var(--m-console-nav-fg);', $css);

        // Standalone surfaces get the same treatment.
        $surface = (string) file_get_contents(base_path('public/css/meridian-surface.css'));
        $this->assertStringContainsString(
            ':is(a, button, input, select, textarea, summary, [tabindex]):focus-visible',
            $surface,
        );
    }

    public function test_the_focus_token_would_not_be_visible_on_the_navigation(): void
    {
        // The reason the navigation overrides the ring, asserted rather than
        // left as a comment: if a future palette made the focus token legible
        // there, the override could go, and if this test starts failing the
        // override is the thing that is now wrong.
        $ratio = $this->ratio(
            $this->resolve('var(--m-focus-ring)', 'light'),
            $this->resolve('var(--m-action-primary-bg)', 'light'),
        );

        $this->assertLessThan(ContrastValidator::REQUIRED_NON_TEXT, $ratio);
    }

    /* --------------------------------------------------------------------
     * Token resolution
     * ----------------------------------------------------------------- */

    private function assertPairHolds(
        string $foreground,
        string $background,
        float $required,
        string $theme,
    ): void {
        $measured = $this->ratio(
            $this->resolve($foreground, $theme),
            $this->resolve($background, $theme),
        );

        // Compared against the rounded value, matching how ContrastValidator
        // decides, so a pair is never accepted on digits nobody would report.
        $this->assertGreaterThanOrEqual(
            $required,
            floor($measured * 100) / 100,
            sprintf(
                'In %s mode, %s on %s measures %.2f:1 and needs %.1f:1.',
                $theme,
                $foreground,
                $background,
                $measured,
                $required,
            ),
        );
    }

    private function ratio(BrandingColor $first, BrandingColor $second): float
    {
        return app(ContrastValidator::class)->ratio($first, $second);
    }

    /**
     * Resolve a CSS value the way a browser would: `var()` chains against the
     * theme's declarations, and `color-mix(in srgb, …)` by interpolating
     * gamma-encoded sRGB channels, which is what the `srgb` color space means.
     */
    private function resolve(string $expression, string $theme, int $depth = 0): BrandingColor
    {
        $expression = trim($expression);

        if ($depth > 12) {
            throw new AssertionFailedError("Token expression did not resolve: {$expression}");
        }

        if (str_starts_with($expression, '#')) {
            return BrandingColor::parse($expression, 'console token');
        }

        if (preg_match('/^var\(\s*(--[\w-]+)\s*\)$/', $expression, $matches) === 1) {
            $tokens = $this->tokens($theme);
            $name = $matches[1];

            if (! array_key_exists($name, $tokens)) {
                throw new AssertionFailedError("{$name} is not declared in the {$theme} token set.");
            }

            return $this->resolve($tokens[$name], $theme, $depth + 1);
        }

        if (preg_match('/^color-mix\(\s*in srgb\s*,\s*(.*?)\s+([\d.]+)%\s*,\s*(.*)\)$/s', $expression, $matches) === 1) {
            $first = $this->resolve($matches[1], $theme, $depth + 1);
            $second = $this->resolve($matches[3], $theme, $depth + 1);
            $weight = ((float) $matches[2]) / 100;

            return BrandingColor::parse(sprintf(
                '#%02x%02x%02x',
                (int) round($first->red * $weight + $second->red * (1 - $weight)),
                (int) round($first->green * $weight + $second->green * (1 - $weight)),
                (int) round($first->blue * $weight + $second->blue * (1 - $weight)),
            ), 'console token');
        }

        throw new AssertionFailedError("Unsupported token expression: {$expression}");
    }

    /**
     * The declarations a browser would have in effect for one theme: the
     * light `:root` block, with the dark block layered over it for dark mode,
     * exactly as the cascade would apply them.
     *
     * The console-only derivations from the token bridge are layered on top,
     * because the navigation resolves through them.
     *
     * @return array<string, string>
     */
    private function tokens(string $theme): array
    {
        static $cache = [];

        if (isset($cache[$theme])) {
            return $cache[$theme];
        }

        $tokensCss = (string) file_get_contents(public_path('css/meridian-tokens.css'));
        $bridgeCss = (string) file_get_contents(public_path('css/meridian-console.css'));

        $tokens = $this->declarationsIn($this->blockAfter($tokensCss, ':root {'));

        if ($theme === 'dark') {
            $tokens = array_merge(
                $tokens,
                $this->declarationsIn($this->blockAfter($tokensCss, '[data-theme="dark"] {')),
            );
        }

        $tokens = array_merge(
            $tokens,
            $this->declarationsIn($this->blockAfter($bridgeCss, '[data-bs-theme="light"] {')),
            $this->declarationsIn($this->blockAfter($bridgeCss, '.aside {')),
        );

        return $cache[$theme] = $tokens;
    }

    private function blockAfter(string $css, string $opener): string
    {
        $start = strpos($css, $opener);

        if ($start === false) {
            throw new AssertionFailedError("Expected a `{$opener}` block in the stylesheet.");
        }

        $start += strlen($opener);
        $end = strpos($css, '}', $start);

        return substr($css, $start, ($end === false ? strlen($css) : $end) - $start);
    }

    /**
     * @return array<string, string>
     */
    private function declarationsIn(string $block): array
    {
        // Comments can contain colons and semicolons, so they come out first.
        $block = (string) preg_replace('#/\*.*?\*/#s', '', $block);

        $declarations = [];

        foreach (explode(';', $block) as $declaration) {
            if (preg_match('/^\s*(--[\w-]+)\s*:\s*(.+)$/s', $declaration, $matches) === 1) {
                $declarations[$matches[1]] = trim($matches[2]);
            }
        }

        return $declarations;
    }
}
