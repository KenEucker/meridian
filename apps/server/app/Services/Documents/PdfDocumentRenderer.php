<?php

declare(strict_types=1);

namespace App\Services\Documents;

/**
 * Produces a deliberately small, dependency-free PDF containing printable
 * document text. Rich PDF styling can use an approved renderer when one is
 * selected by the technology baseline; this preserves the Alpha 1 export
 * contract without introducing an unapproved package.
 */
class PdfDocumentRenderer
{
    private const PAGE_WIDTH = 595;

    private const PAGE_HEIGHT = 842;

    private const MARGIN = 50;

    private const LINE_HEIGHT = 14;

    private const MAX_LINES_PER_PAGE = 52;

    /**
     * @param  list<string>  $lines
     */
    public function render(array $lines): string
    {
        $pages = array_chunk($this->wrapLines($lines), self::MAX_LINES_PER_PAGE);

        if ($pages === []) {
            $pages = [[]];
        }

        /** @var array<int, string> $objects */
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pageIds = [];

        foreach ($pages as $index => $pageLines) {
            $pageId = 4 + ($index * 2);
            $contentId = $pageId + 1;
            $pageIds[] = $pageId;
            $stream = $this->contentStream($pageLines);

            $objects[$pageId] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $contentId,
            );
            $objects[$contentId] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(
            static fn (int $pageId): string => "{$pageId} 0 R",
            $pageIds,
        )).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        return $this->assemblePdf($objects);
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function wrapLines(array $lines): array
    {
        $wrapped = [];

        foreach ($lines as $line) {
            $parts = explode("\n", wordwrap($line, 86, "\n", true));

            foreach ($parts as $part) {
                $wrapped[] = $part;
            }
        }

        return $wrapped;
    }

    /**
     * @param  list<string>  $lines
     */
    private function contentStream(array $lines): string
    {
        $commands = [
            'BT',
            '/F1 10 Tf',
            self::MARGIN.' '.(self::PAGE_HEIGHT - self::MARGIN).' Td',
        ];

        foreach ($lines as $line) {
            $commands[] = '('.$this->escapeText($line).') Tj';
            $commands[] = '0 -'.self::LINE_HEIGHT.' Td';
        }

        $commands[] = 'ET';

        return implode("\n", $commands);
    }

    private function escapeText(string $text): string
    {
        $encoded = function_exists('iconv')
            ? iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text)
            : false;

        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '';
        }

        return strtr($encoded, [
            '\\' => '\\\\',
            '(' => '\\(',
            ')' => '\\)',
            "\r" => '',
            "\n" => '',
        ]);
    }

    /**
     * @param  array<int, string>  $objects
     */
    private function assemblePdf(array $objects): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];

        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$object}\nendobj\n";
        }

        $crossReferenceOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        foreach (array_keys($objects) as $id) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$id])."\n";
        }

        $pdf .= 'trailer << /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n";
        $pdf .= "startxref\n{$crossReferenceOffset}\n%%EOF\n";

        return $pdf;
    }
}
