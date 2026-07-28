<?php

namespace Tests\Unit;

use App\Services\Branding\BrandingColor;
use App\Services\Branding\BrandingPalette;
use App\Services\Branding\BrandingValidationException;
use App\Services\Branding\ContrastFailure;
use App\Services\Branding\ContrastValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * WCAG 2.1 AA branding validation (M15A.3; BRAND-014, BRAND-015, BRAND-016).
 */
class BrandingContrastValidatorTest extends TestCase
{
    private ContrastValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ContrastValidator;
    }

    public function test_ratio_matches_the_wcag_reference_values(): void
    {
        $black = BrandingColor::parse('#000000', 'a');
        $white = BrandingColor::parse('#ffffff', 'b');

        $this->assertEqualsWithDelta(21.0, $this->validator->ratio($black, $white), 0.0001);
        $this->assertEqualsWithDelta(1.0, $this->validator->ratio($black, $black), 0.0001);
    }

    public function test_ratio_is_independent_of_argument_order(): void
    {
        $first = BrandingColor::parse('#475157', 'a');
        $second = BrandingColor::parse('#fffcf6', 'b');

        $this->assertEqualsWithDelta(
            $this->validator->ratio($first, $second),
            $this->validator->ratio($second, $first),
            0.0000001,
        );
    }

    public function test_short_hex_is_expanded_and_case_is_normalized(): void
    {
        $this->assertSame('#aabbcc', BrandingColor::parse('#ABC', 'primary')->hex);
        $this->assertSame('#112233', BrandingColor::parse('  #112233  ', 'primary')->hex);
    }

    /**
     * @return list<array{string}>
     */
    public static function malformedColorProvider(): array
    {
        return [['red'], ['#12345'], ['rgba(0,0,0,0.5)'], ['#12345678'], ['#gggggg'], ['']];
    }

    #[DataProvider('malformedColorProvider')]
    public function test_malformed_colors_are_refused(string $value): void
    {
        $this->expectException(BrandingValidationException::class);

        BrandingColor::parse($value, 'primary');
    }

    public function test_meridian_default_palette_passes_its_own_validator(): void
    {
        // Meridian's defaults are the palette every organization starts from.
        // A default that its own validator refused would leave an organizer
        // unable to save the look they already have.
        $this->assertSame(
            [],
            $this->validator->failuresForOrganizationPalette(BrandingPalette::meridianDefault()),
        );
    }

    public function test_a_failing_pair_reports_the_pair_measured_and_required_ratios(): void
    {
        $palette = $this->palette(['muted_foreground' => '#b9bdb9']);

        $failures = $this->validator->failuresForOrganizationPalette($palette);

        $muted = $this->failureFor($failures, 'muted foreground on surface');

        $this->assertNotNull($muted);
        $this->assertSame('#b9bdb9', $muted->foreground->hex);
        $this->assertSame('#fffcf6', $muted->background->hex);
        $this->assertSame(ContrastValidator::REQUIRED_NORMAL_TEXT, $muted->requiredRatio);
        $this->assertLessThan(4.5, $muted->measuredRatioRounded());
        $this->assertStringContainsString('measures', $muted->describe());
        $this->assertStringContainsString('4.5:1 required', $muted->describe());
    }

    public function test_the_exception_carries_every_failure_as_structured_data(): void
    {
        $palette = $this->palette(['muted_foreground' => '#b9bdb9']);

        try {
            $this->validator->assertOrganizationPalette($palette);
            $this->fail('Expected the palette to be refused.');
        } catch (BrandingValidationException $exception) {
            $this->assertNotEmpty($exception->failures);

            foreach ($exception->failurePayload() as $failure) {
                $this->assertArrayHasKey('pair', $failure);
                $this->assertArrayHasKey('foreground', $failure);
                $this->assertArrayHasKey('background', $failure);
                $this->assertArrayHasKey('measured_ratio', $failure);
                $this->assertArrayHasKey('required_ratio', $failure);
            }
        }
    }

    public function test_nothing_is_auto_corrected(): void
    {
        // BRAND-016: a refused palette comes back unchanged. The validator has
        // no path that rewrites a submitted value to make it pass.
        $submitted = ['muted_foreground' => '#b9bdb9'];
        $palette = $this->palette($submitted);

        try {
            $this->validator->assertOrganizationPalette($palette);
            $this->fail('Expected the palette to be refused.');
        } catch (BrandingValidationException) {
            $this->assertSame('#b9bdb9', $palette->mutedForeground()->hex);
        }
    }

    public function test_normal_text_boundary_passes_at_the_required_ratio_and_fails_below_it(): void
    {
        // #767676 on #ffffff is the canonical 4.54:1 pair; #777777 drops to
        // 4.47:1. The boundary is exercised on the muted foreground because it
        // is the one normal-text token most likely to be set too light.
        $passing = $this->palette([
            'canvas' => '#ffffff',
            'surface' => '#ffffff',
            'muted_foreground' => '#767676',
        ]);

        $failing = $this->palette([
            'canvas' => '#ffffff',
            'surface' => '#ffffff',
            'muted_foreground' => '#777777',
        ]);

        $this->assertNull($this->failureFor(
            $this->validator->failuresForOrganizationPalette($passing),
            'muted foreground on surface',
        ));

        $this->assertNotNull($this->failureFor(
            $this->validator->failuresForOrganizationPalette($failing),
            'muted foreground on surface',
        ));
    }

    public function test_non_text_boundary_uses_three_to_one_for_borders_and_focus(): void
    {
        // #949494 on #ffffff is 3.03:1; #999999 is 2.85:1.
        $passing = $this->palette([
            'canvas' => '#ffffff',
            'surface' => '#ffffff',
            'border' => '#949494',
        ]);

        $failing = $this->palette([
            'canvas' => '#ffffff',
            'surface' => '#ffffff',
            'border' => '#999999',
        ]);

        $this->assertNull($this->failureFor(
            $this->validator->failuresForOrganizationPalette($passing),
            'border on surface',
        ));

        $borderFailure = $this->failureFor(
            $this->validator->failuresForOrganizationPalette($failing),
            'border on surface',
        );

        $this->assertNotNull($borderFailure);
        $this->assertSame(ContrastValidator::REQUIRED_NON_TEXT, $borderFailure->requiredRatio);
    }

    public function test_a_measured_ratio_is_never_rounded_up_past_its_threshold(): void
    {
        // A pair measuring just under the threshold must not be reported as
        // having met it. Rounding to nearest would print "3.00:1 against the
        // 3.0:1 required", which reads as a contradiction.
        $failure = new ContrastFailure(
            'border on surface',
            'non-text user interface boundaries',
            BrandingColor::parse('#959595', 'border'),
            BrandingColor::parse('#ffffff', 'surface'),
            2.9999,
            3.0,
        );

        $this->assertSame(2.99, $failure->measuredRatioRounded());
    }

    public function test_a_platform_color_illegible_as_a_button_label_is_refused(): void
    {
        // A mid-tone platform color where neither the palette foreground nor
        // the palette surface clears 4.5:1 on it.
        $palette = $this->palette(['secondary' => '#7d7d7d']);

        $failure = $this->failureFor(
            $this->validator->failuresForOrganizationPalette($palette),
            'secondary action label',
        );

        $this->assertNotNull($failure);
        $this->assertSame(ContrastValidator::REQUIRED_NORMAL_TEXT, $failure->requiredRatio);
    }

    public function test_department_branding_is_checked_against_the_organization_palette(): void
    {
        $organization = BrandingPalette::meridianDefault();

        $this->assertSame([], $this->validator->failuresForDepartmentBranding(
            $organization,
            BrandingColor::parse('#1f5f4b', 'accent'),
            BrandingColor::parse('#eef6f2', 'surface'),
        ));
    }

    public function test_a_department_background_that_hides_state_is_refused(): void
    {
        // BRAND-017: a department may not choose a background that makes the
        // organization's status and severity indicators unreadable.
        $organization = BrandingPalette::meridianDefault();

        $failures = $this->validator->failuresForDepartmentBranding(
            $organization,
            null,
            BrandingColor::parse('#cc792f', 'surface'),
        );

        $this->assertNotSame([], $failures);
        $this->assertNotNull($this->failureFor($failures, 'accent indicator on department background'));
    }

    public function test_a_department_accent_is_checked_on_the_organization_surface_too(): void
    {
        // The accent travels to surfaces that never take the department
        // background — a badge in The Briefing, for instance — so a low
        // contrast there is still a failure.
        $organization = BrandingPalette::meridianDefault();

        $failures = $this->validator->failuresForDepartmentBranding(
            $organization,
            BrandingColor::parse('#fbf7ef', 'accent'),
            null,
        );

        $this->assertNotNull($this->failureFor($failures, 'department accent on organization surface'));
    }

    public function test_a_department_with_no_overrides_has_nothing_to_validate(): void
    {
        $this->assertSame([], $this->validator->failuresForDepartmentBranding(
            BrandingPalette::meridianDefault(),
            null,
            null,
        ));
    }

    public function test_a_missing_palette_value_is_refused_rather_than_defaulted(): void
    {
        $this->expectException(BrandingValidationException::class);

        $values = BrandingPalette::meridianDefault()->toArray();
        unset($values['focus']);

        BrandingPalette::fromArray($values);
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function palette(array $overrides): BrandingPalette
    {
        return BrandingPalette::fromArray(
            array_merge(BrandingPalette::meridianDefault()->toArray(), $overrides),
        );
    }

    /**
     * @param  list<ContrastFailure>  $failures
     */
    private function failureFor(array $failures, string $pair): ?ContrastFailure
    {
        foreach ($failures as $failure) {
            if ($failure->pair === $pair) {
                return $failure;
            }
        }

        return null;
    }
}
