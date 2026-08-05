<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Models\User;
use App\Services\Downloads\ShortLivedDownloadUrlService;
use App\Services\Reporting\ReportingExportAccess;
use App\Services\Reporting\ReportingExportKind;
use App\Services\Reporting\ReportingExportScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Alpha 1 reporting export downloads (M13.1 through M13.4 and M13.6; M18.25;
 * REPORT-001 through REPORT-007, REPORT-015).
 *
 * Each of the five exports is reachable three ways. A plain GET saves the file
 * directly, which is what a session-holding browser or a shell script uses. A
 * POST asks for a short-lived scoped URL, and a signed GET serves the file to
 * whoever that URL was issued to — the pair M16.12 established (CLIENT-019,
 * CLIENT-020; technical spec 11A.6), and how a client holding a bearer token
 * rather than a cookie reaches an export, because a token cannot ride along on
 * a plain browser navigation.
 *
 * M16.12 built that pair for credential eligibility alone; M18.25 extends it to
 * the remaining four, so the reporting surfaces of M18.26 have one download
 * path to offer rather than one export that works differently from the others.
 *
 * The three ways differ only in where the caller's name comes from — the
 * bearer token, the session, or the signature — and every one of them resolves
 * scope through {@see ReportingExportAccess} at the moment the file is
 * generated. Nothing is trusted from issuance, which is what stops a role
 * withdrawn in the meantime from being exported anyway.
 *
 * Exports are server-generated and online-only in Alpha 1; nothing here is
 * offline-capable.
 */
final class ReportingExportController extends Controller
{
    public function credentialEligibility(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
    ): Response|JsonResponse {
        return $this->downloadForClient(ReportingExportKind::CredentialEligibility, $request, $event, $access);
    }

    public function shiftRoster(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
    ): Response|JsonResponse {
        return $this->downloadForClient(ReportingExportKind::ShiftRoster, $request, $event, $access);
    }

    public function staffContact(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
    ): Response|JsonResponse {
        return $this->downloadForClient(ReportingExportKind::StaffContact, $request, $event, $access);
    }

    public function hoursWorked(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
    ): Response|JsonResponse {
        return $this->downloadForClient(ReportingExportKind::HoursWorked, $request, $event, $access);
    }

    public function creditsEarned(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
    ): Response|JsonResponse {
        return $this->downloadForClient(ReportingExportKind::CreditsEarned, $request, $event, $access);
    }

    public function issueCredentialEligibilityDownloadUrl(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        return $this->issueDownloadUrl(ReportingExportKind::CredentialEligibility, $request, $event, $access, $downloadUrls);
    }

    public function issueShiftRosterDownloadUrl(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        return $this->issueDownloadUrl(ReportingExportKind::ShiftRoster, $request, $event, $access, $downloadUrls);
    }

    public function issueStaffContactDownloadUrl(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        return $this->issueDownloadUrl(ReportingExportKind::StaffContact, $request, $event, $access, $downloadUrls);
    }

    public function issueHoursWorkedDownloadUrl(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        return $this->issueDownloadUrl(ReportingExportKind::HoursWorked, $request, $event, $access, $downloadUrls);
    }

    public function issueCreditsEarnedDownloadUrl(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): JsonResponse {
        return $this->issueDownloadUrl(ReportingExportKind::CreditsEarned, $request, $event, $access, $downloadUrls);
    }

    public function signedCredentialEligibility(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|JsonResponse {
        return $this->signedDownload(ReportingExportKind::CredentialEligibility, $request, $event, $access, $downloadUrls);
    }

    public function signedShiftRoster(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|JsonResponse {
        return $this->signedDownload(ReportingExportKind::ShiftRoster, $request, $event, $access, $downloadUrls);
    }

    public function signedStaffContact(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|JsonResponse {
        return $this->signedDownload(ReportingExportKind::StaffContact, $request, $event, $access, $downloadUrls);
    }

    public function signedHoursWorked(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|JsonResponse {
        return $this->signedDownload(ReportingExportKind::HoursWorked, $request, $event, $access, $downloadUrls);
    }

    public function signedCreditsEarned(
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|JsonResponse {
        return $this->signedDownload(ReportingExportKind::CreditsEarned, $request, $event, $access, $downloadUrls);
    }

    /**
     * Serve one report to the authenticated caller.
     */
    private function downloadForClient(
        ReportingExportKind $kind,
        Request $request,
        Event $event,
        ReportingExportAccess $access,
    ): Response|JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        return $this->download($kind, $user, $this->requestedDepartmentId($request), $event, $access);
    }

    /**
     * Issue a short-lived URL for one report (CLIENT-019, CLIENT-020; technical
     * spec 11A.6; data/API 5.7).
     *
     * The same authorization a direct request runs, run here instead: an
     * unauthorized caller is refused a URL rather than handed one that would be
     * refused later. Any `department_id` narrowing is decided now and signed
     * into the URL, so the file that arrives is the file that was authorized.
     */
    private function issueDownloadUrl(
        ReportingExportKind $kind,
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
        $scope = $this->resolveScope($kind, $user, $departmentId, $event, $access);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $parameters = ['event' => $event->getRouteKey()];

        if ($departmentId !== null) {
            $parameters['department_id'] = $departmentId;
        }

        return response()->json(
            $downloadUrls->issue($user, $kind->signedRouteName(), $parameters)->toArray(),
        );
    }

    /**
     * Serve one report to the user its URL was issued to.
     *
     * The navigation carries no credential, so the signature names the person
     * and their own scope generates the file. Resolving that scope again rather
     * than trusting issuance is what keeps a role removed in the meantime from
     * being exported anyway.
     */
    private function signedDownload(
        ReportingExportKind $kind,
        Request $request,
        Event $event,
        ReportingExportAccess $access,
        ShortLivedDownloadUrlService $downloadUrls,
    ): Response|JsonResponse {
        return $this->download(
            $kind,
            $downloadUrls->actor($request),
            $this->requestedDepartmentId($request),
            $event,
            $access,
        );
    }

    /**
     * Resolve the user's own export scope, honor an optional `department_id`
     * narrowing, and return the generated file.
     */
    private function download(
        ReportingExportKind $kind,
        User $user,
        ?string $requestedDepartmentId,
        Event $event,
        ReportingExportAccess $access,
    ): Response|JsonResponse {
        $scope = $this->resolveScope($kind, $user, $requestedDepartmentId, $event, $access);

        if ($scope instanceof JsonResponse) {
            return $scope;
        }

        $export = $kind->generator()->export($event, $scope, $user, AuditEvent::SOURCE_API);

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
     */
    private function resolveScope(
        ReportingExportKind $kind,
        User $user,
        ?string $requestedDepartmentId,
        Event $event,
        ReportingExportAccess $access,
    ): ReportingExportScope|JsonResponse {
        $subject = $kind->subject();
        $scope = $access->resolve($user, $event, $kind->permission());

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
