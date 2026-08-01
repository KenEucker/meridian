<?php

namespace Tests\Unit;

use App\Services\Branding\Lettermark;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Generated lettermark fallback (M15A.5; BRAND-005, BRAND-010).
 */
class BrandingLettermarkTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function nameProvider(): array
    {
        return [
            'letters come from separate words' => ['Department of Public Works', 'DPW'],
            'stop words are dropped' => ['The Rangers Department', 'RD'],
            'a single word uses two letters' => ['Rangers', 'RA'],
            'a single short word is used whole' => ['Ops', 'OP'],
            'at most three letters' => ['Northwood Volunteer Operations Group', 'NVO'],
            'punctuation is not a word' => ['Gate & Greeters', 'GG'],
            'hyphenated names split' => ['Search-and-Rescue', 'SR'],
            'digits count as letters' => ['Camp 7 Logistics', 'C7L'],
            'case is normalized up' => ['deep harbor collective', 'DHC'],
            'accented letters survive' => ['Équipe Médicale', 'ÉM'],
        ];
    }

    #[DataProvider('nameProvider')]
    public function test_lettermarks_are_built_from_separate_words(string $name, string $expected): void
    {
        $this->assertSame($expected, Lettermark::forName($name));
    }

    public function test_a_name_that_is_only_stop_words_still_yields_a_mark(): void
    {
        // Dropping every word would leave an empty box. An organization that
        // genuinely calls itself "The Org" gets a mark from what it has.
        $this->assertSame('TO', Lettermark::forName('The Of'));
    }

    public function test_an_unusable_name_is_visibly_missing_rather_than_blank(): void
    {
        $this->assertSame('?', Lettermark::forName('   '));
        $this->assertSame('?', Lettermark::forName('—'));
    }

    public function test_a_single_letter_name_is_not_padded(): void
    {
        $this->assertSame('R', Lettermark::forName('R'));
    }
}
