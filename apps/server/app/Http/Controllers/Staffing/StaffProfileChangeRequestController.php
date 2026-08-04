<?php

namespace App\Http\Controllers\Staffing;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffProfileChangeRequest;
use App\Models\User;
use App\Services\Downloads\ShortLivedDownloadUrlService;
use App\Services\Staffing\StaffHandleChangeService;
use App\Services\Staffing\StaffProfileChangeRequestAccess;
use App\Services\Staffing\StaffProfileChangeRequestService;
use App\Services\Staffing\StaffProfilePictureService;
use App\Services\Staffing\StaffProfilePictureUrlService;
use App\Services\Staffing\StaffProfileSelfException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The reviewer's side of profile change requests (M18.20A, M18.20C, M18.20D;
 * VOL-019 through VOL-022, VOL-024; UI contract 12.6).
 *
 * One read and two commands. The read answers with the pending requests in the
 * organizations this caller actually holds review authority in — never a
 * request from an organization they do not review — and carries the two things
 * a decision needs: the previous and requested handle side by side, and a
 * short-lived URL for each of the current and submitted pictures.
 *
 * A requested handle already in use by another active staff member in the
 * organization is *named* on the request rather than blocking it (VOL-020).
 * Two people may legitimately be told apart by their departments, and a
 * reviewer who is shown the collision can decide; a refusal here would decide
 * for them.
 */
final class StaffProfileChangeRequestController extends Controller
{
    public function index(
        Request $request,
        StaffProfileChangeRequestAccess $access,
        StaffProfileChangeRequestService $requests,
        StaffProfilePictureUrlService $pictureUrls,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $organizationIds = $access->reviewableOrganizationIds($user);

        if ($organizationIds->isEmpty()) {
            return response()->json([
                'message' => 'You do not review profile change requests for any organization.',
            ], 403);
        }

        $pending = StaffProfileChangeRequest::query()
            ->pending()
            ->whereIn('organization_id', $organizationIds)
            ->with(['staff', 'organization'])
            ->orderBy('created_at')
            ->get()
            // A reviewer never decides their own request, so one is not listed
            // for them to try (the service refuses it either way).
            ->reject(fn (StaffProfileChangeRequest $row): bool => $access->isOwnProfile($user, (string) $row->staff_id));

        return response()->json([
            'organization_ids' => $organizationIds->all(),
            'requests' => $pending
                ->map(fn (StaffProfileChangeRequest $row): array => $this->reviewRow(
                    $row,
                    $user,
                    $requests,
                    $pictureUrls,
                ))
                ->values()
                ->all(),
        ]);
    }

    public function approve(
        Request $request,
        StaffHandleChangeService $handles,
        StaffProfilePictureService $pictures,
    ): JsonResponse {
        return $this->decide($request, $handles, $pictures, approve: true);
    }

    public function reject(
        Request $request,
        StaffHandleChangeService $handles,
        StaffProfilePictureService $pictures,
    ): JsonResponse {
        return $this->decide($request, $handles, $pictures, approve: false);
    }

    /**
     * Stream a submitted picture to the user its URL was issued to.
     *
     * The navigation carries no token — that is why the signed URL exists — so
     * the person is named by the signature and their authority is re-checked
     * here rather than taken on trust from issuance. A decision made a minute
     * ago therefore takes effect now rather than when the link expires.
     */
    public function pendingPicture(
        Request $request,
        StaffProfileChangeRequest $changeRequest,
        ShortLivedDownloadUrlService $downloadUrls,
        StaffProfilePictureService $pictures,
    ): Response {
        $user = $downloadUrls->actor($request);

        abort_unless($pictures->canReadPendingPicture($user, $changeRequest), 403);

        $path = $changeRequest->pending_picture_path;
        $disk = $pictures->pendingDisk();

        if ($path === null || $path === '' || ! Storage::disk($disk)->exists($path)) {
            throw new NotFoundHttpException('This submitted picture is no longer stored.');
        }

        return response(Storage::disk($disk)->get($path), 200, [
            'Content-Type' => (string) $changeRequest->pending_picture_mime_type,
            'Content-Disposition' => 'inline; filename="submitted-profile-picture"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function decide(
        Request $request,
        StaffHandleChangeService $handles,
        StaffProfilePictureService $pictures,
        bool $approve,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
            // A rejection carries the reason the submitter is told (VOL-025).
            'reason' => [$approve ? 'nullable' : 'required', 'string', 'max:2000'],
        ], [
            'reason.required' => 'A rejection needs a reason, because the staff member is told why.',
        ]);

        $changeRequest = StaffProfileChangeRequest::query()->find((string) $validated['request_id']);

        if ($changeRequest === null) {
            return response()->json([
                'message' => 'You do not review profile change requests for this staff member.',
            ], 403);
        }

        $reason = $validated['reason'] ?? null;
        $isPicture = $changeRequest->kind === StaffProfileChangeRequest::KIND_PROFILE_PICTURE;

        try {
            $decided = match (true) {
                $isPicture && $approve => $pictures->approve($changeRequest, $user, $reason),
                $isPicture => $pictures->reject($changeRequest, $user, $reason),
                $approve => $handles->approve($changeRequest, $user, $reason),
                default => $handles->reject($changeRequest, $user, $reason),
            };
        } catch (StaffProfileSelfException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'request' => [
                'id' => (string) $decided->id,
                'kind' => $decided->kind,
                'status' => $decided->status,
                'decision_reason' => $decided->decision_reason,
                'decided_at' => $decided->decided_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewRow(
        StaffProfileChangeRequest $row,
        User $user,
        StaffProfileChangeRequestService $requests,
        StaffProfilePictureUrlService $pictureUrls,
    ): array {
        $staff = $row->staff;

        return [
            'id' => (string) $row->id,
            'kind' => $row->kind,
            'status' => $row->status,
            'organization_id' => (string) $row->organization_id,
            'organization_name' => $row->organization?->name,
            'staff_id' => (string) $row->staff_id,
            'staff_name' => $staff instanceof Staff
                ? ($staff->preferred_name ?: $staff->legal_name)
                : 'Unknown staff member',
            'previous_handle' => $row->previous_handle,
            'requested_handle' => $row->requested_handle,
            // Named, not blocking (VOL-020).
            'handle_collisions' => $requests->handleCollisions($row),
            'current_picture_url' => $staff instanceof Staff ? $staff->profilePictureUrl() : null,
            'submitted_picture_url' => $this->submittedPictureUrl($row, $user, $pictureUrls),
            'created_at' => $row->created_at?->toIso8601String(),
        ];
    }

    private function submittedPictureUrl(
        StaffProfileChangeRequest $row,
        User $user,
        StaffProfilePictureUrlService $pictureUrls,
    ): ?string {
        if ($row->pending_picture_path === null) {
            return null;
        }

        try {
            return $pictureUrls->pendingPictureUrl($user, $row)->url;
        } catch (StaffProfileSelfException) {
            return null;
        }
    }
}
