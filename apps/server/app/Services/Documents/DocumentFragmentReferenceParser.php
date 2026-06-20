<?php

namespace App\Services\Documents;

/**
 * Parses the Markdown token used by policy and procedure documents.
 *
 * Tokens intentionally remain human-friendly fragment slugs in Markdown. The
 * reference service resolves each slug to a UUID before persistence.
 */
class DocumentFragmentReferenceParser
{
    private const TOKEN_PREFIX = '{{fragment:';

    private const TOKEN_PATTERN = '/\{\{fragment:([a-z0-9]+(?:-[a-z0-9]+)*)\}\}/';

    /**
     * @return list<array{token: string, slug: string}>
     *
     * @throws MalformedDocumentFragmentReferenceException
     */
    public function parse(string $markdown): array
    {
        $remaining = preg_replace(self::TOKEN_PATTERN, '', $markdown);

        if ($remaining === null || str_contains($remaining, self::TOKEN_PREFIX)) {
            throw new MalformedDocumentFragmentReferenceException(
                'Fragment references must use the form {{fragment:fragment-slug}}.',
            );
        }

        preg_match_all(self::TOKEN_PATTERN, $markdown, $matches, PREG_SET_ORDER);

        $references = [];

        foreach ($matches as $match) {
            $token = $match[0];

            $references[$token] = [
                'token' => $token,
                'slug' => $match[1],
            ];
        }

        return array_values($references);
    }

    /**
     * @throws NestedDocumentFragmentReferenceException
     */
    public function assertFragmentMarkdownHasNoReferences(string $markdown): void
    {
        if (str_contains($markdown, self::TOKEN_PREFIX)) {
            throw new NestedDocumentFragmentReferenceException(
                'Document fragments cannot reference other fragments.',
            );
        }
    }
}
