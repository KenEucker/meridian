<?php

declare(strict_types=1);

namespace App\Services\Incidents;

use Carbon\CarbonInterface;

/**
 * A server-generated incident PDF export ready for download (INC-015).
 */
final class IncidentPdfExport
{
    public const MIME_TYPE = 'application/pdf';

    public function __construct(
        public readonly string $contents,
        public readonly string $filename,
        public readonly CarbonInterface $exportedAt,
    ) {}
}
