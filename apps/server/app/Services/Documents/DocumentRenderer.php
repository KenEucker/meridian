<?php

namespace App\Services\Documents;

use App\Models\DocumentFragment;
use App\Models\PolicyDocument;
use App\Models\ProcedureDocument;
use Illuminate\Support\Str;

/**
 * Renders policy and procedure Markdown with current fragment content inline.
 *
 * Fragment source is rendered separately before it is included in a document,
 * so both document and fragment Markdown pass through the same raw-HTML and
 * unsafe-link safeguards (POL-022, POL-034, POL-035, POL-040, and POL-041).
 */
class DocumentRenderer
{
    public function __construct(private readonly DocumentFragmentReferenceService $references) {}

    public function render(PolicyDocument|ProcedureDocument $document): string
    {
        /** @var array<string, DocumentFragment> $fragmentsByToken */
        $fragmentsByToken = [];

        foreach ($this->references->validate($document) as $reference) {
            $fragmentsByToken[$reference['token']] = $reference['fragment'];
        }

        if ($fragmentsByToken === []) {
            return $this->renderMarkdown($document->markdown_source);
        }

        /** @var array<string, string> $placeholderByToken */
        $placeholderByToken = [];
        /** @var array<string, string> $renderedFragmentByPlaceholder */
        $renderedFragmentByPlaceholder = [];

        foreach ($fragmentsByToken as $token => $fragment) {
            $placeholder = 'MERIDIAN_DOCUMENT_FRAGMENT_'.str_replace('-', '', (string) Str::uuid());

            $placeholderByToken[$token] = $placeholder;
            $renderedFragmentByPlaceholder[$placeholder] = $this->renderMarkdown($fragment->markdown_source);
        }

        $rendered = $this->renderMarkdown(strtr($document->markdown_source, $placeholderByToken));

        foreach ($renderedFragmentByPlaceholder as $placeholder => $fragmentHtml) {
            // A token on its own Markdown line produces its own paragraph. Replacing
            // that complete paragraph preserves headings, lists, and other block
            // Markdown in the fragment as normal document content.
            $rendered = str_replace('<p>'.$placeholder.'</p>', trim($fragmentHtml), $rendered);

            // Inline references retain surrounding prose. A one-paragraph fragment
            // can safely join that prose without creating nested paragraph elements.
            $rendered = str_replace($placeholder, $this->inlineFragmentHtml($fragmentHtml), $rendered);
        }

        return $rendered;
    }

    /**
     * Resolve current fragment Markdown into document Markdown for an immutable
     * acknowledgment-version snapshot. Rendering remains separate so each
     * fragment is still sanitized independently for display.
     */
    public function resolvedMarkdown(PolicyDocument|ProcedureDocument $document): string
    {
        /** @var array<string, string> $markdownByToken */
        $markdownByToken = [];

        foreach ($this->references->validate($document) as $reference) {
            $markdownByToken[$reference['token']] = $reference['fragment']->markdown_source;
        }

        return strtr($document->markdown_source, $markdownByToken);
    }

    private function renderMarkdown(string $markdown): string
    {
        return (string) Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    private function inlineFragmentHtml(string $fragmentHtml): string
    {
        $trimmed = trim($fragmentHtml);

        if (preg_match('/^<p>(.*)<\\/p>$/s', $trimmed, $matches) === 1) {
            return $matches[1];
        }

        return $trimmed;
    }
}
