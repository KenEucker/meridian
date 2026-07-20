<?php

namespace Tests\Unit;

use App\Models\FieldReport;
use App\Models\Staff;
use App\Models\User;
use App\Services\FieldReports\FieldReportIncidentNoteCopy;
use PHPUnit\Framework\TestCase;

class FieldReportIncidentNoteCopyTest extends TestCase
{
    public function test_formats_title_aware_incident_note_copy_contract(): void
    {
        $this->assertSame(
            "Field Report: Medical assist near Gate A\nAuthor: Vera Ranger\nObserved a medical assist near Gate A.",
            FieldReportIncidentNoteCopy::fromParts(
                'Medical assist near Gate A',
                'Observed a medical assist near Gate A.',
                'Vera Ranger',
            ),
        );
    }

    public function test_formats_from_field_report_model(): void
    {
        $report = new FieldReport([
            'title' => 'Radio check failed',
            'body' => "Channel 3 down.\nNeed spare.",
        ]);
        $report->setRelation('staff', new Staff([
            'preferred_name' => 'Omar',
            'legal_name' => 'Omar Operator',
        ]));
        $report->setRelation('submittedByUser', new User(['name' => 'Fallback User']));

        $this->assertSame(
            "Field Report: Radio check failed\nAuthor: Omar\nChannel 3 down.\nNeed spare.",
            FieldReportIncidentNoteCopy::fromReport($report),
        );
    }
}
