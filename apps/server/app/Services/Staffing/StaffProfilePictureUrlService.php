<?php

namespace App\Services\Staffing;

use App\Models\StaffProfileChangeRequest;
use App\Models\User;
use App\Services\Downloads\ShortLivedDownloadUrl;
use App\Services\Downloads\ShortLivedDownloadUrlService;

/**
 * Short-lived scoped URLs for a pending profile picture (M18.20C; VOL-021;
 * technical spec 11A.6).
 *
 * The submitted image is not public: it is readable by its submitter and by
 * the users who may review it, and by nobody else. That is the same shape
 * Field Report photo preview already uses, so this reuses the mechanism and
 * supplies only the part that is about profile pictures — which policy decides
 * whether the URL is issued at all.
 */
final class StaffProfilePictureUrlService
{
    public function __construct(
        private readonly ShortLivedDownloadUrlService $downloadUrls,
        private readonly StaffProfilePictureService $pictures,
    ) {}

    public function pendingPictureUrl(
        User $user,
        StaffProfileChangeRequest $request,
    ): ShortLivedDownloadUrl {
        if ($request->pending_picture_path === null || $request->pending_picture_path === '') {
            throw new StaffProfileSelfException('This request carries no submitted picture.');
        }

        if (! $this->pictures->canReadPendingPicture($user, $request)) {
            throw new StaffProfileSelfException('You may not view this submitted picture.');
        }

        return $this->downloadUrls->issue($user, 'staff-profile-pictures.pending', [
            'changeRequest' => $request->getKey(),
        ]);
    }
}
