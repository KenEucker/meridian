<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonInterface;

/**
 * A server-generated reporting export ready for download (REPORT-001 through
 * REPORT-005; technical spec 22.2 CSV export).
 */
final class ReportingExport
{
    public const FORMAT_CSV = 'csv';

    public const MIME_TYPE_CSV = 'text/csv; charset=UTF-8';

    public function __construct(
        public readonly string $contents,
        public readonly string $filename,
        public readonly int $rowCount,
        public readonly CarbonInterface $exportedAt,
        public readonly string $format = self::FORMAT_CSV,
        public readonly string $mimeType = self::MIME_TYPE_CSV,
    ) {}
}
