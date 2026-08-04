<?php

namespace App\Http\Controllers\Staffing;

use App\Http\Controllers\Controller;
use App\Models\Staff;
use App\Models\StaffProfileChangeRequest;
use App\Models\User;
use App\Services\Staffing\StaffHandleChangeService;
use App\Services\Staffing\StaffProfileChangeRequestAccess;
use App\Services\Staffing\StaffProfileChangeRequestService;
use App\Services\Staffing\StaffProfilePictureProcessor;
use App\Services\Staffing\StaffProfilePictureService;
use App\Services\Staffing\StaffProfilePictureUrlService;
use App\Services\Staffing\StaffProfileSelfException;
use App\Services\Staffing\StaffProfileSelfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The staff member's own profile: one read and one command (M18.20; VOL-009,
 * VOL-014 through VOL-016, VOL-026; data/API 10.4; UI contract 12.3).
 *
 * `GET /api/me/profile` answers with the staff records the caller's login
 * speaks for and needs no authority beyond holding a credential — a person may
 * always read their own record. `POST /api/commands/update-my-profile` writes
 * the VOL-015 fields to one of those records, immediately and without review.
 *
 * The command validates identity fields as `missing` rather than leaving them
 * out of the ruleset, which is the difference between refusing a submitted
 * legal name and quietly dropping it. VOL-016 makes those fields an assisted
 * path, and a client that believes it changed an email address because the
 * request came back 200 is exactly the failure the explicit refusal prevents.
 * The handle and emergency contact are refused the same way: the handle changes
 * through the M18.20B request path, and nothing in VOL-015 makes emergency
 * contact self-service.
 */
final class MyProfileController extends Controller
{
    public function show(
        Request $request,
        StaffProfileChangeRequestService $requests,
        StaffProfileChangeRequestAccess $access,
        StaffProfilePictureUrlService $pictureUrls,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $profiles = $user->staffProfiles()
            ->whereNull('archived_at')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'profiles' => $profiles
                ->map(fn (Staff $staff): array => [
                    ...$this->profile($staff),
                    /*
                     * What this staff member may do about their handle and
                     * picture right now, and what is already in flight
                     * (VOL-017, VOL-021, VOL-024). The surface renders the
                     * node's answer rather than deriving one (CLIENT-006).
                     */
                    'can_submit_picture' => $access->isActiveSomewhere($staff),
                    'remaining_self_service_handle_changes' =>
                        $requests->remainingSelfServiceHandleChanges($staff),
                    'pending_handle_request' => $this->pendingRequest(
                        $requests->pendingRequest($staff, StaffProfileChangeRequest::KIND_HANDLE),
                        $user,
                        $pictureUrls,
                    ),
                    'pending_picture_request' => $this->pendingRequest(
                        $requests->pendingRequest($staff, StaffProfileChangeRequest::KIND_PROFILE_PICTURE),
                        $user,
                        $pictureUrls,
                    ),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Submit a new profile picture for review (VOL-021).
     *
     * Multipart rather than the JSON every other command takes, because the
     * body is an image. Online-only by requirement (VOL-014), which the
     * client's command catalog also enforces.
     */
    public function submitPicture(Request $request, StaffProfilePictureService $pictures): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
            'picture' => [
                'required',
                'file',
                'max:'.(int) (StaffProfilePictureProcessor::MAX_UPLOAD_BYTES / 1024),
                'mimetypes:'.implode(',', StaffProfilePictureProcessor::ACCEPTED_MIME_TYPES),
            ],
        ], [
            'picture.max' => 'Profile pictures are limited to 10 MB. Choose a smaller image.',
            'picture.mimetypes' => 'Profile pictures must be a JPEG, PNG, or WebP image.',
        ]);

        [$staff, $refusal] = $this->staffProfileFor($user, $validated['staff_id'] ?? null);

        if ($staff === null) {
            return $refusal;
        }

        $upload = $request->file('picture');
        $bytes = $upload === null ? '' : (string) file_get_contents($upload->getRealPath());

        try {
            $changeRequest = $pictures->submit(
                staff: $staff,
                actor: $user,
                bytes: $bytes,
                declaredMimeType: $upload?->getMimeType(),
            );
        } catch (StaffProfileSelfException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'request' => $this->requestPayload($changeRequest),
        ], 201);
    }

    /** Remove one's own current picture, immediately and with no review (VOL-023). */
    public function removePicture(Request $request, StaffProfilePictureService $pictures): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
        ]);

        [$staff, $refusal] = $this->staffProfileFor($user, $validated['staff_id'] ?? null);

        if ($staff === null) {
            return $refusal;
        }

        try {
            $updated = $pictures->removeCurrent($staff, $user);
        } catch (StaffProfileSelfException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['profile' => $this->profile($updated)]);
    }

    /**
     * Request a handle (VOL-017).
     *
     * The response says which of the two things happened: an applied change
     * within the allowance, or a request waiting for review.
     */
    public function requestHandle(Request $request, StaffHandleChangeService $handles): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'staff_id' => ['sometimes', 'nullable', 'uuid'],
            'handle' => ['required', 'string', 'max:255'],
        ]);

        [$staff, $refusal] = $this->staffProfileFor($user, $validated['staff_id'] ?? null);

        if ($staff === null) {
            return $refusal;
        }

        try {
            $changeRequest = $handles->requestHandle($staff, $user, (string) $validated['handle']);
        } catch (StaffProfileSelfException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'request' => $this->requestPayload($changeRequest),
            'profile' => $this->profile($staff->refresh()),
        ], 201);
    }

    /** Withdraw one's own pending request of either kind (VOL-024). */
    public function withdrawRequest(
        Request $request,
        StaffProfilePictureService $pictures,
        StaffProfileChangeRequestService $requests,
        StaffProfileChangeRequestAccess $access,
    ): JsonResponse {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate([
            'request_id' => ['required', 'uuid'],
        ]);

        $changeRequest = StaffProfileChangeRequest::query()->find((string) $validated['request_id']);

        // A request belonging to somebody else is refused the same way a
        // missing one is, so the refusal discloses nothing about whose it was
        // or whether it exists.
        if ($changeRequest === null
            || ! $access->isOwnProfile($user, (string) $changeRequest->staff_id)) {
            return response()->json([
                'message' => 'You can only withdraw your own profile change request.',
            ], 403);
        }

        try {
            $withdrawn = $changeRequest->kind === StaffProfileChangeRequest::KIND_PROFILE_PICTURE
                ? $pictures->withdraw($changeRequest, $user)
                : $requests->withdraw($changeRequest, $user);
        } catch (StaffProfileSelfException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['request' => $this->requestPayload($withdrawn)]);
    }

    public function update(Request $request, StaffProfileSelfService $profiles): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $validated = $request->validate(
            [
                'staff_id' => ['sometimes', 'nullable', 'uuid'],
                'preferred_name' => ['sometimes', 'nullable', 'string', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:64'],
                'city' => ['sometimes', 'nullable', 'string', 'max:255'],
                'state' => ['sometimes', 'nullable', 'string', 'max:255'],
                'legal_name' => ['missing'],
                'email' => ['missing'],
                'date_of_birth' => ['missing'],
                'handle' => ['missing'],
                'formerly_known_as' => ['missing'],
                'emergency_contact_name' => ['missing'],
                'emergency_contact_phone' => ['missing'],
            ],
            [
                'legal_name.missing' => 'Legal name identifies you to the organization and cannot be changed here. Ask an organizer to change it.',
                'email.missing' => 'Email identifies you to sign-in and cannot be changed here. Ask an organizer to change it.',
                'date_of_birth.missing' => 'Date of birth governs age eligibility and cannot be changed here. Ask an organizer to change it.',
                'handle.missing' => 'Handles do not change here. Handle changes go through a change request.',
                'formerly_known_as.missing' => 'Formerly-known-as is maintained by an organizer and cannot be changed here.',
                'emergency_contact_name.missing' => 'Emergency contact changes are an assisted path. Ask an organizer to change it.',
                'emergency_contact_phone.missing' => 'Emergency contact changes are an assisted path. Ask an organizer to change it.',
            ],
        );

        [$staff, $refusal] = $this->staffProfileFor($user, $validated['staff_id'] ?? null);

        if ($staff === null) {
            return $refusal;
        }

        $attributes = array_intersect_key(
            $validated,
            array_flip(StaffProfileSelfService::SELF_EDITABLE_FIELDS),
        );

        try {
            $updated = $profiles->updateOwnProfile($staff, $user, $attributes);
        } catch (StaffProfileSelfException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['profile' => $this->profile($updated)]);
    }

    /**
     * Which of the caller's staff records the command acts on.
     *
     * Named explicitly when the login speaks for more than one, because "the
     * first" of several people's records is nobody's profile in particular. A
     * `staff_id` outside the caller's own is refused with 403 and without
     * confirming whether such a record exists.
     *
     * @return array{0: Staff|null, 1: JsonResponse|null}
     */
    private function staffProfileFor(User $user, ?string $staffId): array
    {
        $own = $user->staffProfiles()->whereNull('archived_at')->get();

        if ($staffId !== null) {
            $staff = $own->firstWhere('id', $staffId);

            if ($staff instanceof Staff) {
                return [$staff, null];
            }

            return [null, response()->json([
                'message' => 'You can only edit your own staff profile.',
            ], 403)];
        }

        if ($own->isEmpty()) {
            return [null, response()->json([
                'message' => 'This login is not linked to a staff profile, so there is no profile to edit.',
            ], 422)];
        }

        if ($own->count() > 1) {
            return [null, response()->json([
                'message' => 'This login speaks for more than one staff record. Name the one to edit with `staff_id`.',
            ], 422)];
        }

        $first = $own->first();

        return $first instanceof Staff ? [$first, null] : [null, response()->json([
            'message' => 'This login is not linked to a staff profile, so there is no profile to edit.',
        ], 422)];
    }

    /**
     * The VOL-009 field set, with which fields this surface may write so the
     * client renders the same boundary the server enforces (CLIENT-006).
     *
     * @return array<string, mixed>
     */
    private function profile(Staff $staff): array
    {
        return [
            'id' => (string) $staff->id,
            'legal_name' => $staff->legal_name,
            'preferred_name' => $staff->preferred_name,
            'handle' => $staff->handle,
            'formerly_known_as' => $staff->formerly_known_as,
            'email' => $staff->email,
            'phone' => $staff->phone,
            'city' => $staff->city,
            'state' => $staff->state,
            'date_of_birth' => $staff->date_of_birth?->toDateString(),
            'emergency_contact_name' => $staff->emergency_contact_name,
            'emergency_contact_phone' => $staff->emergency_contact_phone,
            'profile_picture_url' => $staff->profilePictureUrl(),
            'self_editable_fields' => StaffProfileSelfService::SELF_EDITABLE_FIELDS,
        ];
    }

    /**
     * A pending request as the submitter's own surface reads it, with a
     * short-lived URL for the submitted image where there is one (VOL-021,
     * VOL-024).
     *
     * @return array<string, mixed>|null
     */
    private function pendingRequest(
        ?StaffProfileChangeRequest $request,
        User $user,
        StaffProfilePictureUrlService $pictureUrls,
    ): ?array {
        if ($request === null) {
            return null;
        }

        $payload = $this->requestPayload($request);

        if ($request->pending_picture_path === null) {
            return $payload;
        }

        try {
            $payload['submitted_picture_url'] = $pictureUrls
                ->pendingPictureUrl($user, $request)
                ->url;
        } catch (StaffProfileSelfException) {
            $payload['submitted_picture_url'] = null;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestPayload(StaffProfileChangeRequest $request): array
    {
        return [
            'id' => (string) $request->id,
            'kind' => $request->kind,
            'status' => $request->status,
            'previous_handle' => $request->previous_handle,
            'requested_handle' => $request->requested_handle,
            'self_service' => $request->self_service,
            'decision_reason' => $request->decision_reason,
            'decided_at' => $request->decided_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }
}
