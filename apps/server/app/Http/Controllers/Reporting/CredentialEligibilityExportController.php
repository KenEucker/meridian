<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Department;
use App\Models\Event;
use App\Services\Reporting\CredentialEligibilityExportAccess;
use App\Services\Reporting\CredentialEligibilityExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Event credential eligibility export download (M13.1; REPORT-001, REPORT-006,
 * REPORT-007).
 *
 * Delivered as an ordinary browser GET so an authorized organizer or
 * department lead can save the file directly. Exports are server-generated and
 * online-only in Alpha 1; nothing here is offline-capable.
 */
final class CredentialEligibilityExportController extends Controller
{
    public function credentialEligibility(
        Request $request,
        Event $event,
        CredentialEligibilityExportAccess $access,
        CredentialEligibilityExportService $exports,
    ): Response|JsonResponse {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $scope = $access->resolve($user, $event);

        if ($scope === null) {
            return response()->json([
                'message' => 'You do not have permission to export credential eligibility for this event.',
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
                    'message' => 'You do not have permission to export credential eligibility for that department.',
                ], 403);
            }

            $scope = $scope->restrictedToDepartment((string) $department->id);
        }

        $export = $exports->export($event, $scope, $user, AuditEvent::SOURCE_API);

        return response($export->contents, 200, [
            'Content-Type' => $export->mimeType,
            'Content-Disposition' => 'attachment; filename="'.$export->filename.'"',
        ]);
    }
}
