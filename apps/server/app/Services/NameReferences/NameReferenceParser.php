<?php

namespace App\Services\NameReferences;

/**
 * Extracts Name Reference tokens from IMS / Field Report source text
 * (NR-001, NR-005, NR-006; technical spec 17.7).
 *
 * A Name Reference starts with `@` and continues through letters, numbers,
 * hyphens, and underscores until whitespace or punctuation. Matching is
 * case-insensitive; the first-seen casing is preserved for display metadata.
 */
final class NameReferenceParser
{
    private const TOKEN_PATTERN = '/@([A-Za-z0-9_-]+)/u';

    /**
     * @return list<array{token: string, normalized_token: string}>
     */
    public function parse(string $text): array
    {
        preg_match_all(self::TOKEN_PATTERN, $text, $matches, PREG_SET_ORDER);

        $tokens = [];

        foreach ($matches as $match) {
            $token = $match[1];
            $normalized = mb_strtolower($token, 'UTF-8');

            if (array_key_exists($normalized, $tokens)) {
                continue;
            }

            $tokens[$normalized] = [
                'token' => $token,
                'normalized_token' => $normalized,
            ];
        }

        return array_values($tokens);
    }

    /**
     * Normalize a search query with or without a leading `@` (NR-006, NR-010).
     */
    public function normalizeQuery(string $query): string
    {
        $trimmed = trim($query);

        if (str_starts_with($trimmed, '@')) {
            $trimmed = substr($trimmed, 1);
        }

        return mb_strtolower($trimmed, 'UTF-8');
    }
}
