<?php

namespace App\Services\FieldReports;

use App\Models\FieldReport;

/**
 * Formats Field Report content for incident-note copy (FR-012; data/API 10.15).
 *
 * M9.7A owns the title-aware copy contract. M11.8 owns attaching Field Reports
 * to incidents and writing timeline notes using this formatter.
 */
final class FieldReportIncidentNoteCopy
{
    public static function fromParts(string $title, string $body): string
    {
        return 'Field Report: '.$title."\n".$body;
    }

    public static function fromReport(FieldReport $report): string
    {
        return self::fromParts((string) $report->title, (string) $report->body);
    }
}
