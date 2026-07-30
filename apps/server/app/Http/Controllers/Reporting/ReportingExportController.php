<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Domain\Permissions\PermissionCatalog;
use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\User;
use App\Services\Downloads\ShortLivedDownloadUrlService;
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
 * Delivered as ordinary GETs so an authorized organizer or department lead can
 * save the file directly. Exports are server-generated and online-only in Alpha
 * 1; nothing here is offline-capable.
 *
 * Credential eligibility additionally answers the short-lived download URL path
 * (M16.12; CLIENT-019, CLIENT-020; technical spec 11A.6), which is how a client
 * holding a bearer token rather than a session reaches it: the token asks for a
 * URL, and the browser navigates to it.
 */
final class ReportingExportController extends Controller
{
    public function credentialEligibility(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        CredentialEligibilityExportService $exports,
    ): Response|JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return $this->download(
            $user,
            $this->requestedDepartmentId($request),
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
            'credential eligibility',
            fn (ReportingExportScope $scope, User $user): ReportingExport => $exports
                ->export($event, $scope, $user, AuditEvent::SOURCE_API),
        );
    }

    /**
     * Issue a short-lived URL for the credential eligibility export
     * (CLIENT-019, CLIENT-020; technical spec 11A.6; data/API 5.7).
     *
     * The same authorization a direct request runs, run here instead: an
     * unauthorized caller is refused a URL rather than handed one that would be
     * refused later. Any `department_id` narrowing is decided now and signed
     * into the URL, so the file that arrives is the file that was authorized.
     */
    public function issueCredentialEligibilityDownloadUrl(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $departmentId = $this->requestedDepartmentId($request);

        // Issuance asks the question a download asks and throws the answer
        // away: a URL is only worth issuing when the export behind it would be
        // generated.
        $scope = $this->resolveScope(
            $user,
            $departmentId,
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT,
            'credential eligibility',
        );

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $parameters = ['event' => $event->getRouteKey()];

        if ($departmentId !== null) {
            $parameters['department_id'] = $departmentId;
        }

        return response()->json(
            $downloadUrls->issue($user, 'downloads.exports.credential-eligibility', $parameters)->toArray(),
        );
    }

    /**
     * Serve the credential eligibility export to the user its URL was issued to.
     *
     * The navigation carries no credential, so the signature names the person
     * and their own scope generates the file. Resolving that scope again rather
     * than trusting issuance is what keeps a role removed in the meantime from
     * being exported anyway.
     */
    public function signedCredentialEligibility(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        CredentialEligibilityExportService $exports,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|JsonResponse {
        return $this->download(
            $downloadUrls->actor($request),
            $this->requestedDepartmentId($request),
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
        $user = $request->user();
        abort_unless($user !== null, 401);

        return $this->download(
            $user,
            $this->requestedDepartmentId($request),
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
        $user = $request->user();
        abort_unless($user !== null, 401);

        return $this->download(
            $user,
            $this->requestedDepartmentId($request),
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
        $user = $request->user();
        abort_unless($user !== null, 401);

        return $this->download(
            $user,
            $this->requestedDepartmentId($request),
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
        $user = $request->user();
        abort_unless($user !== null, 401);

        return $this->download(
            $user,
            $this->requestedDepartmentId($request),
            $event,
            $access,
            PermissionCatalog::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT,
            'credits earned',
            fn (ReportingExportScope $scope, User $user): ReportingExport => $exports
                ->export($event, $scope, $user, AuditEvent::SOURCE_API),
        );
    }

    /**
     * Resolve the user's own export scope, honor an optional `department_id`
     * narrowing, and return the generated file.
     *
     * @param  string  $subject  Names the report in the denial message.
     * @param  callable(ReportingExportScope, User): ReportingExport  $generate
     */
    private function download(
        User $user,
        ?string $requestedDepartmentId,
        Event $event,
        ReportingExportAccess $access,
        string $permission,
        string $subject,
        callable $generate,
    ): Response|JsonResponse {
        $scope = $this->resolveScope($user, $requestedDepartmentId, $event, $access, $permission, $subject);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $export = $generate($scope, $user);

        return response($export->contents, 200, [
            'Content-Type' => $export->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
        ]);
    }

    /**
     * The user's export scope for this report, or the refusal to send back.
     *
     * `department_id` may only narrow a scope the user already holds, so a
     * department lead cannot reach another department's rows by asking for
     * them, while an organizer can still pull one department without a second
     * endpoint.
     *
     * @param  string  $subject  Names the report in the denial message.
     */
    private function resolveScope(
        User $user,
        ?string $requestedDepartmentId,
        Event $event,
        ReportingExportAccess $access,
        string $permission,
        string $subject,
    ): ReportingExportScope|JsonResponse {
        $scope = $access->resolve($user, $event, $permission);

        if ($scope === null) {
            return response()->json([
                'message' => "You do not have permission to export {$subject} for this event.",
            ], 403);
        }

        if ($requestedDepartmentId === null) {
            return $scope;
        }

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

        return $scope->restrictedToDepartment((string) $department->id);
    }

    /**
     * The `department_id` narrowing the request asked for, if it asked for one.
     *
     * Read from the query string of a download and the body of a URL request,
     * which `input()` covers both of.
     */
    private function requestedDepartmentId(Request $request): ?string
    {
        $departmentId = $request->input('department_id');

        return is_string($departmentId) && $departmentId !== '' ? $departmentId : null;
    }
}
