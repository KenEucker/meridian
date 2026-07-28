<?php

declare(strict_types=1);

namespace App\Services\Branding;

/**
 * Server-side WCAG 2.1 AA gate on every submitted branding combination
 * (BRAND-014, BRAND-015, BRAND-016).
 *
 * Three thresholds, taken straight from the requirement: 4.5:1 for normal text,
 * 3:1 for large text, 3:1 for non-text user interface and graphical indicators.
 * Which threshold a pair gets is decided here rather than by the caller, because
 * "is this large text?" is a property of where the token is used and belongs
 * next to the list of pairs rather than at each admin surface.
 *
 * Nothing here corrects anything. The validator returns failures or it returns
 * nothing; there is no path that adjusts a submitted color to make it pass
 * (BRAND-016). Auto-derivation would also defeat the point of BRAND-015 — an
 * organizer told "we darkened your brand color for you" has learned nothing
 * about which pair was wrong.
 *
 * Scope. The palette an organization submits is one set of neutrals, and it is
 * validated as the light-mode surface set. Dark mode keeps Meridian's own deep
 * neutrals in Alpha 1: BRAND-006 defines a single neutral set, and painting a
 * light canvas into dark mode would break the night-operations behavior the
 * operating guide requires. Meridian's dark neutrals are therefore not a
 * branding submission and are not validated here — an organization cannot set
 * them and should not be refused a save because of them.
 */
class ContrastValidator
{
    /** Normal body text. */
    public const REQUIRED_NORMAL_TEXT = 4.5;

    /** Text at or above the large-text threshold. */
    public const REQUIRED_LARGE_TEXT = 3.0;

    /** Non-text user interface components and graphical indicators. */
    public const REQUIRED_NON_TEXT = 3.0;

    /**
     * Every pair implied by an organization palette.
     *
     * @return list<ContrastFailure>
     */
    public function failuresForOrganizationPalette(BrandingPalette $palette): array
    {
        $surface = $palette->surface();

        $failures = [];

        foreach ([['canvas', $palette->canvas()], ['surface', $surface]] as [$backgroundName, $background]) {
            $failures[] = $this->check(
                "foreground on {$backgroundName}",
                'normal text',
                $palette->foreground(),
                $background,
                self::REQUIRED_NORMAL_TEXT,
            );

            // Muted foreground is small supporting text — hints, captions,
            // table meta — not large text, so it takes the normal-text
            // threshold despite the name suggesting something quieter.
            $failures[] = $this->check(
                "muted foreground on {$backgroundName}",
                'normal text',
                $palette->mutedForeground(),
                $background,
                self::REQUIRED_NORMAL_TEXT,
            );

            $failures[] = $this->check(
                "border on {$backgroundName}",
                'non-text user interface boundaries',
                $palette->border(),
                $background,
                self::REQUIRED_NON_TEXT,
            );

            $failures[] = $this->check(
                "focus on {$backgroundName}",
                'non-text focus indicators',
                $palette->focus(),
                $background,
                self::REQUIRED_NON_TEXT,
            );

            // The primary platform color is also secondary body text
            // (`--m-text-secondary`), which the platform-color checks below
            // would not catch: a color can be a legible filled button and an
            // illegible label at the same time.
            $failures[] = $this->check(
                "primary as secondary text on {$backgroundName}",
                'normal text',
                $palette->primary(),
                $background,
                self::REQUIRED_NORMAL_TEXT,
            );
        }

        foreach (['primary', 'secondary', 'tertiary', 'accent'] as $field) {
            $platform = $palette->color($field);

            // A platform color is a filled action background carrying label
            // text, so it is checked against the label color the token
            // resolver will pair it with.
            $failures[] = $this->check(
                "{$field} action label",
                'normal text',
                BrandingTokenResolver::actionLabelFor($platform, $palette),
                $platform,
                self::REQUIRED_NORMAL_TEXT,
            );

            // The same color is also a status/severity indicator drawn as a
            // dot, bar, or outline on a surface, which is a graphical
            // indicator rather than text.
            $failures[] = $this->check(
                "{$field} indicator on surface",
                'non-text status and severity indicators',
                $platform,
                $surface,
                self::REQUIRED_NON_TEXT,
            );
        }

        return $this->compact($failures);
    }

    /**
     * Every pair implied by a department override sitting inside an
     * organization palette (BRAND-011, BRAND-012, BRAND-017).
     *
     * A department sets two values but is responsible for more than two pairs:
     * its background is what the organization's text, borders, focus ring, and
     * status indicators are read against on department-scoped surfaces.
     *
     * @return list<ContrastFailure>
     */
    public function failuresForDepartmentBranding(
        BrandingPalette $organizationPalette,
        ?BrandingColor $accent,
        ?BrandingColor $surfaceBackground,
    ): array {
        $failures = [];
        $background = $surfaceBackground ?? $organizationPalette->surface();

        if ($surfaceBackground !== null) {
            $failures[] = $this->check(
                'foreground on department background',
                'normal text',
                $organizationPalette->foreground(),
                $background,
                self::REQUIRED_NORMAL_TEXT,
            );

            $failures[] = $this->check(
                'muted foreground on department background',
                'normal text',
                $organizationPalette->mutedForeground(),
                $background,
                self::REQUIRED_NORMAL_TEXT,
            );

            $failures[] = $this->check(
                'border on department background',
                'non-text user interface boundaries',
                $organizationPalette->border(),
                $background,
                self::REQUIRED_NON_TEXT,
            );

            $failures[] = $this->check(
                'focus on department background',
                'non-text focus indicators',
                $organizationPalette->focus(),
                $background,
                self::REQUIRED_NON_TEXT,
            );

            // State legibility under a department background is the whole
            // point of BRAND-017: a department may not choose a background
            // that hides the organization's status colors.
            foreach (['primary', 'secondary', 'tertiary', 'accent'] as $field) {
                $failures[] = $this->check(
                    "{$field} indicator on department background",
                    'non-text status and severity indicators',
                    $organizationPalette->color($field),
                    $background,
                    self::REQUIRED_NON_TEXT,
                );
            }
        }

        if ($accent !== null) {
            $failures[] = $this->check(
                'department accent on department background',
                'non-text identity indicators',
                $accent,
                $background,
                self::REQUIRED_NON_TEXT,
            );

            // The accent also appears on organization surfaces — a badge in
            // The Briefing, for instance — which never take the department
            // background.
            $failures[] = $this->check(
                'department accent on organization surface',
                'non-text identity indicators',
                $accent,
                $organizationPalette->surface(),
                self::REQUIRED_NON_TEXT,
            );
        }

        return $this->compact($failures);
    }

    /**
     * @throws BrandingValidationException when any pair fails
     */
    public function assertOrganizationPalette(BrandingPalette $palette): void
    {
        $this->assert($this->failuresForOrganizationPalette($palette));
    }

    /**
     * @throws BrandingValidationException when any pair fails
     */
    public function assertDepartmentBranding(
        BrandingPalette $organizationPalette,
        ?BrandingColor $accent,
        ?BrandingColor $surfaceBackground,
    ): void {
        $this->assert($this->failuresForDepartmentBranding(
            $organizationPalette,
            $accent,
            $surfaceBackground,
        ));
    }

    public function ratio(BrandingColor $first, BrandingColor $second): float
    {
        return $first->contrastRatioWith($second);
    }

    /**
     * @param  list<ContrastFailure>  $failures
     *
     * @throws BrandingValidationException
     */
    private function assert(array $failures): void
    {
        if ($failures !== []) {
            throw BrandingValidationException::contrast($failures);
        }
    }

    private function check(
        string $pair,
        string $usage,
        BrandingColor $foreground,
        BrandingColor $background,
        float $required,
    ): ?ContrastFailure {
        $measured = $foreground->contrastRatioWith($background);

        // Compared against the rounded value the failure would report, so a
        // pair is never refused with a message claiming it met the threshold.
        if (floor($measured * 100) / 100 >= $required) {
            return null;
        }

        return new ContrastFailure($pair, $usage, $foreground, $background, $measured, $required);
    }

    /**
     * @param  list<ContrastFailure|null>  $failures
     * @return list<ContrastFailure>
     */
    private function compact(array $failures): array
    {
        return array_values(array_filter(
            $failures,
            static fn (?ContrastFailure $failure): bool => $failure instanceof ContrastFailure,
        ));
    }
}
