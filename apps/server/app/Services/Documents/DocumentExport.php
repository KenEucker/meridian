<?php

declare(strict_types=1);

namespace App\Services\Documents;

use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * A server-generated policy or procedure export ready for download.
 */
final class DocumentExport
{
    public const FORMAT_MARKDOWN = 'markdown';

    public const FORMAT_PDF = 'pdf';

    public function __construct(
        public readonly string $format,
        public readonly string $contents,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly string $documentType,
        public readonly string $version,
        public readonly string $scope,
        public readonly CarbonInterface $exportedAt,
    ) {}

    /**
     * @return list<string>
     */
    public static function formats(): array
    {
        return [
            self::FORMAT_MARKDOWN,
            self::FORMAT_PDF,
        ];
    }

    public static function extensionFor(string $format): string
    {
        return match ($format) {
            self::FORMAT_MARKDOWN => 'md',
            self::FORMAT_PDF => 'pdf',
            default => throw new InvalidArgumentException("Unsupported document export format '{$format}'."),
        };
    }

    public static function mimeTypeFor(string $format): string
    {
        return match ($format) {
            self::FORMAT_MARKDOWN => 'text/markdown; charset=UTF-8',
            self::FORMAT_PDF => 'application/pdf',
            default => throw new InvalidArgumentException("Unsupported document export format '{$format}'."),
        };
    }
}
