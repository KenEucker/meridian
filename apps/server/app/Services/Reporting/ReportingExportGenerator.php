<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\AuditEvent;
use App\Models\Event;
use App\Models\User;

/**
 * One Alpha 1 reporting export (REPORT-001 through REPORT-005; technical spec
 * 22.2).
 *
 * The five export services were written to the same signature before this
 * interface existed, because they answer the same question about different
 * records: given an event and the slice of it this caller may see, produce the
 * file and audit who pulled it. Naming that shape lets {@see ReportingExportKind}
 * hold the report list once, so a report cannot be added to the routes and
 * forgotten in the surface that offers it.
 *
 * Nothing here decides authority. The scope arrives resolved from
 * {@see ReportingExportAccess} and an implementation reads it rather than
 * re-deriving it, which is what keeps the five exports agreeing on who may see
 * what (REPORT-006, REPORT-007).
 */
interface ReportingExportGenerator
{
    /**
     * Generate and audit the export for one caller.
     *
     * @param  string  $sourceContext  How the request arrived, recorded on the audit entry.
     */
    public function export(
        Event $event,
        ReportingExportScope $scope,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): ReportingExport;
}
