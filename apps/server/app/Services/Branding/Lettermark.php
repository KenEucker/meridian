<?php

declare(strict_types=1);

namespace App\Services\Branding;

/**
 * The generated fallback mark for an organization or department with no logo
 * (BRAND-005, BRAND-010).
 *
 * Letters come from separate words, which is what both requirements ask for
 * and what makes the result recognisable: "Department of Public Works" reads as
 * DPW, not as DE. A single-word name falls back to its first two letters,
 * because one letter is not an identity — half the departments in an
 * organization would render the same glyph.
 *
 * Words that carry no identity are dropped before initials are taken. "The
 * Rangers Department" is the Rangers, and TRD would be a worse mark than R...
 * except that R alone is one letter, so "Rangers" then yields RA. The stop-word
 * list is deliberately short; an organization that genuinely calls itself "The
 * Org" still gets a mark, from the letters that remain.
 */
final class Lettermark
{
    private const MAX_LETTERS = 3;

    /**
     * @var list<string>
     */
    private const STOP_WORDS = ['the', 'of', 'and', 'a', 'an', 'for', 'de', 'la'];

    /**
     * The letters to render, uppercased. Never empty for a name containing at
     * least one letter or digit; an unusable name yields a single question
     * mark rather than an empty box, so a missing name is visibly missing.
     */
    public static function forName(string $name): string
    {
        $words = self::words($name);

        if ($words === []) {
            return '?';
        }

        if (count($words) === 1) {
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        $letters = '';

        foreach (array_slice($words, 0, self::MAX_LETTERS) as $word) {
            $letters .= mb_substr($word, 0, 1);
        }

        return mb_strtoupper($letters);
    }

    /**
     * @return list<string>
     */
    private static function words(string $name): array
    {
        $normalized = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';
        $candidates = preg_split('/\s+/u', trim($normalized), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $meaningful = array_values(array_filter(
            $candidates,
            static fn (string $word): bool => ! in_array(mb_strtolower($word), self::STOP_WORDS, true),
        ));

        // Every word was a stop word, so the stop words are the name.
        return $meaningful === [] ? array_values($candidates) : $meaningful;
    }
}
