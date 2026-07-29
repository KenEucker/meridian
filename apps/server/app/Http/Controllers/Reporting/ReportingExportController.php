<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Domain\Permissions\PermissionCatalog;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\User;
use App\Services\Reporting\CredentialEligibilityExportService;
use App\Services\Reporting\CreditsEarnedExportService;
use App\Services\Reporting\HoursWorkedExportService;
use App\Services\Reporting\ReportingExport;
use App\Services\Reporting\ReportingExportAccess;
use App\Services\Reporting\ReportingExportScope;
use App\Services\Reporting\ShiftRosterExportService;
use App\Services\Reporting\StaffContactExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Alpha 1 reporting export downloads (M13.1 through M13.4 and M13.6;
 * REPORT-001 through REPORT-007).
 *
 * Delivered as ordinary browser GETs so an authorized organizer or department
 * lead can save the file directly. Exports are server-generated and online-only
 * in Alpha 1; nothing here is offline-capable.
 */
final class ReportingExportController extends Controller
{
    public function credentialEligibility(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        CredentialEligibilityExportService $exports,
    ): Response|JsonResponse {
        return $this->download(
            $request,
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
            'credential eligibility',
            fn (ReportingExportScope $scope, User $user): ReportingExport => $exports
                ->export($event, $scope, $user, AuditEvent::SOURCE_API),
        );
    }

    public function shiftRoster(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShiftRosterExportService $exports,
    ): Response|JsonResponse {
        return $this->download(
            $request,
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT,
            'the shift roster',
            fn (ReportingExportScope $scope, User $user): ReportingExport => $exports
                ->export($event, $scope, $user, AuditEvent::SOURCE_API),
        );
    }

    public function staffContact(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        StaffContactExportService $exports,
    ): Response|JsonResponse {
        return $this->download(
            $request,
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT,
            'staff contacts',
            fn (ReportingExportScope $scope, User $user): ReportingExport => $exports
                ->export($event, $scope, $user, AuditEvent::SOURCE_API),
        );
    }

    public function hoursWorked(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        HoursWorkedExportService $exports,
    ): Response|JsonResponse {
        return $this->download(
            $request,
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT,
            'hours worked',
            fn (ReportingExportScope $scope, User $user): ReportingExport => $exports
                ->export($event, $scope, $user, AuditEvent::SOURCE_API),
        );
    }

    public function creditsEarned(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        CreditsEarnedExportService $exports,
    ): Response|JsonResponse {
        return $this->download(
            $request,
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
            'credits earned',
            fn (ReportingExportScope $scope, User $user): ReportingExport => $exports
                ->export($event, $scope, $user, AuditEvent::SOURCE_API),
        );
    }

    /**
     * Resolve the caller's own export scope, honor an optional `department_id`
     * narrowing, and return the generated file.
     *
     * `department_id` may only narrow a scope the caller already holds, so a
     * department lead cannot reach another department's rows by asking for
     * them, while an organizer can still pull one department without a second
     * endpoint.
     *
     * @param  string  $subject  Names the report in the denial message.
     * @param  callable(ReportingExportScope, User): ReportingExport  $generate
     */
    private function download(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        string $permission,
        string $subject,
        callable $generate,
    ): Response|JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $scope = $access->resolve($user, $event, $permission);

        if ($scope === null) {
            return response()->json([
                'message' => "You do not have permission to export {$subject} for this event.",
            ], 403);
        }

        $requestedDepartmentId = $request->query('department_id');

        if ($requestedDepartmentId !== null && $requestedDepartmentId !== '') {
            $department = Department::query()->find($requestedDepartmentId);

            if ($department === null
                || (string) $department->organization_id !== (string) $event->organization_id) {
                return response()->json([
                    'message' => 'That department is not part of this event\'s organization.',
                ], 404);
            }

            if (! $scope->includesDepartment((string) $department->id)) {
                return response()->json([
                    'message' => "You do not have permission to export {$subject} for that department.",
                ], 403);
            }

            $scope = $scope->restrictedToDepartment((string) $department->id);
        }

        $export = $generate($scope, $user);

        return response($export->contents, 200, [
            'Content-Type' => $export->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
        ]);
    }
}
