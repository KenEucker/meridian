<?php

namespace App\Services\FieldReports;

use App\Models\FieldReport;

/**
 * Formats Field Report content for incident-note copy (FR-012; data/API 10.15).
 *
 * M9.7A owns the copy formatter. M11.8 owns attaching Field Reports to
 * incidents and writing timeline notes with title, author, and body.
 */
final class FieldReportIncidentNoteCopy
{
    public static function fromParts(string $title, string $body, string $authorName = 'Unknown author'): string
    {
        return 'Field Report: '.$title."\nAuthor: ".$authorName."\n".$body;
    }

    public static function fromReport(FieldReport $report): string
    {
        $staff = $report->staff;
        $authorName = $staff?->preferred_name
            ?: $staff?->legal_name
            ?: $report->submittedByUser?->name
            ?: 'Unknown author';

        return self::fromParts((string) $report->title, (string) $report->body, $authorName);
    }
}
