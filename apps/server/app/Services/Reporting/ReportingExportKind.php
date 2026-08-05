<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Domain\Permissions\PermissionCatalog;

/**
 * The Alpha 1 reporting exports, and the four facts each of them is served by
 * (M13.1 through M13.4 and M13.6; M18.25; REPORT-001 through REPORT-005).
 *
 * Every export is reachable three ways — a direct download under a bearer
 * token, a request for a short-lived URL, and the signed navigation that
 * follows it — and each way needs the same four answers: which permission
 * admits the caller, how to name the report when refusing them, which signed
 * route serves the file, and which service writes it. Stated once here rather
 * than fifteen times in the controller, so a report added to one path cannot go
 * missing from another.
 *
 * The backed value is the path segment the routes already used, which is why
 * the route names derive from it instead of being listed again: the signed
 * route for credential eligibility was named `downloads.exports.credential-eligibility`
 * by M16.12 and keeps that name here.
 */
enum ReportingExportKind: string
{
    case CredentialEligibility = 'credential-eligibility';
    case ShiftRoster = 'shift-roster';
    case StaffContact = 'staff-contact';
    case HoursWorked = 'hours-worked';
    case CreditsEarned = 'credits-earned';

    /** The permission that admits a caller to this report. */
    public function permission(): string
    {
        return match ($this) {
            self::CredentialEligibility => PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
            self::ShiftRoster => PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT,
            self::StaffContact => PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT,
            self::HoursWorked => PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT,
            self::CreditsEarned => PermissionCatalog::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
        };
    }

    /**
     * How the report is named in a refusal, reading as "You do not have
     * permission to export {subject} for this event."
     */
    public function subject(): string
    {
        return match ($this) {
            self::CredentialEligibility => 'credential eligibility',
            self::ShiftRoster => 'the shift roster',
            self::StaffContact => 'staff contacts',
            self::HoursWorked => 'hours worked',
            self::CreditsEarned => 'credits earned',
        };
    }

    /** The signed route in `routes/web.php` that serves this report's file. */
    public function signedRouteName(): string
    {
        return 'downloads.exports.'.$this->value;
    }

    /** @return class-string<ReportingExportGenerator> */
    public function generatorClass(): string
    {
        return match ($this) {
            self::CredentialEligibility => CredentialEligibilityExportService::class,
            self::ShiftRoster => ShiftRosterExportService::class,
            self::StaffContact => StaffContactExportService::class,
            self::HoursWorked => HoursWorkedExportService::class,
            self::CreditsEarned => CreditsEarnedExportService::class,
        };
    }

    /**
     * The service that writes this report.
     *
     * Resolved from the container at the moment it is needed rather than
     * injected into the controller, because a request runs exactly one of the
     * five and constructing the other four to reach it would be waste.
     */
    public function generator(): ReportingExportGenerator
    {
        return app($this->generatorClass());
    }
}
