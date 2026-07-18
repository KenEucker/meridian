<?php

namespace Tests\Unit;

use App\Models\FieldReport;
use App\Services\FieldReports\FieldReportIncidentNoteCopy;
use PHPUnit\Framework\TestCase;

class FieldReportIncidentNoteCopyTest extends TestCase
{
    public function test_formats_title_aware_incident_note_copy_contract(): void
    {
        $this->assertSame(
            "Field Report: Medical assist near Gate A\nObserved a medical assist near Gate A.",
            FieldReportIncidentNoteCopy::fromParts(
                'Medical assist near Gate A',
                'Observed a medical assist near Gate A.',
            ),
        );
    }

    public function test_formats_from_field_report_model(): void
    {
        $report = new FieldReport([
            'title' => 'Radio check failed',
            'body' => "Channel 3 down.\nNeed spare.",
        ]);

        $this->assertSame(
            "Field Report: Radio check failed\nChannel 3 down.\nNeed spare.",
            FieldReportIncidentNoteCopy::fromReport($report),
        );
    }
}
