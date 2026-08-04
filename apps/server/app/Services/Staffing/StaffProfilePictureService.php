<?php

namespace App\Services\Staffing;

use App\Models\AuditEvent;
use App\Models\Staff;
use App\Models\StaffProfileChangeRequest;
use App\Models\User;
use App\Services\Audit\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Profile picture submission, decision, and removal (M18.20C; VOL-013,
 * VOL-021 through VOL-023, VOL-026; technical spec 18A).
 *
 * A submission is a change request carrying its own processed image
 * (VOL-021). The staff record's current picture is untouched while the request
 * is pending, which is the property that matters: the person keeps the picture
 * everybody already knows them by until somebody decides on the new one, and
 * the desk comparing a face to a photo is never comparing it to an unreviewed
 * upload.
 *
 * The submitted image goes on the private attachments disk rather than the
 * public one the current picture uses, because a pending picture is readable
 * only by its submitter and its reviewers (VOL-021) — a public URL would be
 * readable by anybody who guessed it. Approval moves it across to the public
 * disk, which is where profile-visible pictures live; rejection and withdrawal
 * delete it and leave nothing behind (VOL-022).
 *
 * Removing one's own current picture creates no request and needs no review
 * (VOL-023): nobody needs permission to stop displaying a picture of
 * themselves.
 */
final class StaffProfilePictureService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly StaffProfilePictureProcessor $processor,
        private readonly StaffProfileChangeRequestService $requests,
        private readonly StaffProfileChangeRequestAccess $access,
    ) {}

    /**
     * Submit a picture for review (VOL-021).
     *
     * Refused for a staff member who is not `active` in any organization,
     * which is technical spec 18A.1's gate on the upload control existing at
     * all.
     */
    public function submit(
        Staff $staff,
        User $actor,
        string $bytes,
        ?string $declaredMimeType = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        if (! $this->access->isOwnProfile($actor, (string) $staff->id)) {
            throw new StaffProfileSelfException('You can only submit a picture for your own staff profile.');
        }

        if (! $this->access->isActiveSomewhere($staff)) {
            throw new StaffProfileSelfException(
                'Profile pictures can be submitted once you are an active staff member in an organization.',
            );
        }

        $processed = $this->processor->process($bytes, $declaredMimeType);
        $disk = $this->pendingDisk();
        $path = 'staff-profile-pictures/pending/'.$staff->id.'/'.Str::uuid()->toString().$this->extension($processed['mime_type']);

        Storage::disk($disk)->put($path, $processed['bytes']);

        try {
            return $this->requests->open(
                staff: $staff,
                actor: $actor,
                kind: StaffProfileChangeRequest::KIND_PROFILE_PICTURE,
                attributes: [
                    'pending_picture_path' => $path,
                    'pending_picture_mime_type' => $processed['mime_type'],
                    'pending_picture_size_bytes' => $processed['byte_size'],
                    'pending_picture_width' => $processed['width'],
                    'pending_picture_height' => $processed['height'],
                ],
                // Never self-service: every picture submission is reviewed
                // (VOL-021), unlike the first two handle changes.
                selfService: false,
                sourceContext: $sourceContext,
            );
        } catch (StaffProfileSelfException $exception) {
            // The row was refused — an outstanding request, most likely — so
            // the image it would have carried is not left orphaned on the disk.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }
    }

    /** Approve a submission, promoting it to the current picture (VOL-022). */
    public function approve(
        StaffProfileChangeRequest $request,
        User $reviewer,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        return $this->requests->decide(
            request: $request,
            reviewer: $reviewer,
            approve: true,
            reason: $reason,
            apply: fn (StaffProfileChangeRequest $decided) => $this->promote($decided, $reviewer, $sourceContext),
            sourceContext: $sourceContext,
        );
    }

    /** Reject a submission, discarding its image (VOL-022). */
    public function reject(
        StaffProfileChangeRequest $request,
        User $reviewer,
        ?string $reason = null,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        return $this->requests->decide(
            request: $request,
            reviewer: $reviewer,
            approve: false,
            reason: $reason,
            discard: fn (StaffProfileChangeRequest $decided) => $this->discard($decided),
            sourceContext: $sourceContext,
        );
    }

    /** Withdraw one's own submission, discarding its image (VOL-024). */
    public function withdraw(
        StaffProfileChangeRequest $request,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): StaffProfileChangeRequest {
        return $this->requests->withdraw(
            request: $request,
            actor: $actor,
            discard: fn (StaffProfileChangeRequest $withdrawn) => $this->discard($withdrawn),
            sourceContext: $sourceContext,
        );
    }

    /**
     * Remove one's own current picture, immediately (VOL-023).
     *
     * No request row, no reviewer, and no trace of the image: Alpha 1 does not
     * preserve previous pictures (data/API 10.4).
     */
    public function removeCurrent(
        Staff $staff,
        User $actor,
        string $sourceContext = AuditEvent::SOURCE_API,
    ): Staff {
        if (! $this->access->isOwnProfile($actor, (string) $staff->id)) {
            throw new StaffProfileSelfException('You can only remove the picture on your own staff profile.');
        }

        return DB::transaction(function () use ($staff, $actor, $sourceContext): Staff {
            $locked = Staff::query()->whereKey($staff->id)->lockForUpdate()->firstOrFail();
            $path = $locked->profile_picture_path;

            if ($path === null || $path === '') {
                return $locked;
            }

            $locked->forceFill([
                'profile_picture_path' => null,
                'profile_picture_mime_type' => null,
                'profile_picture_size_bytes' => null,
                'profile_picture_width' => null,
                'profile_picture_height' => null,
                'profile_picture_uploaded_at' => null,
            ])->save();

            Storage::disk('public')->delete($path);

            $this->audit->recordForEntity(
                entity: $locked,
                action: 'staff.profile.picture_removed',
                actorUser: $actor,
                before: ['profile_picture_path' => $path],
                after: ['profile_picture_path' => null],
                sourceContext: $sourceContext,
            );

            return $locked->refresh();
        });
    }

    /** Whether this user may read the pending image on this request (VOL-021). */
    public function canReadPendingPicture(User $user, StaffProfileChangeRequest $request): bool
    {
        return $this->access->isOwnProfile($user, (string) $request->staff_id)
            || $this->access->canReview($user, $request);
    }

    public function pendingDisk(): string
    {
        return (string) config('filesystems.attachments_disk', 'attachments');
    }

    /**
     * Move a submitted image onto the staff record as its current picture.
     *
     * Copied to the public disk and deleted from the pending one, so the
     * approved picture lives exactly where an unreviewed one never did.
     */
    private function promote(
        StaffProfileChangeRequest $request,
        User $reviewer,
        string $sourceContext,
    ): void {
        $staff = Staff::query()->whereKey($request->staff_id)->lockForUpdate()->firstOrFail();
        $pendingDisk = $this->pendingDisk();
        $pendingPath = (string) $request->pending_picture_path;

        if ($pendingPath === '' || ! Storage::disk($pendingDisk)->exists($pendingPath)) {
            throw new StaffProfileSelfException(
                'The submitted picture is no longer stored, so it cannot be approved.',
            );
        }

        $previousPath = $staff->profile_picture_path;
        $currentPath = 'staff/profile-pictures/'.$staff->id.'/'.Str::uuid()->toString()
            .$this->extension((string) $request->pending_picture_mime_type);

        Storage::disk('public')->put($currentPath, Storage::disk($pendingDisk)->get($pendingPath));
        Storage::disk($pendingDisk)->delete($pendingPath);

        $staff->forceFill([
            'profile_picture_path' => $currentPath,
            'profile_picture_mime_type' => $request->pending_picture_mime_type,
            'profile_picture_size_bytes' => $request->pending_picture_size_bytes,
            'profile_picture_width' => $request->pending_picture_width,
            'profile_picture_height' => $request->pending_picture_height,
            'profile_picture_uploaded_at' => now(),
        ])->save();

        if ($previousPath !== null && $previousPath !== '') {
            // Alpha 1 keeps only the current picture (data/API 10.4).
            Storage::disk('public')->delete($previousPath);
        }

        $request->forceFill([
            'pending_picture_path' => null,
            'pending_picture_mime_type' => null,
            'pending_picture_size_bytes' => null,
            'pending_picture_width' => null,
            'pending_picture_height' => null,
        ])->save();

        $this->audit->recordForEntity(
            entity: $staff,
            action: 'staff.profile.picture_changed',
            actorUser: $reviewer,
            organizationId: (string) $request->organization_id,
            before: ['profile_picture_path' => $previousPath],
            after: ['profile_picture_path' => $currentPath],
            sourceContext: $sourceContext,
        );
    }

    /** Delete a submitted image that will never become anybody's picture. */
    private function discard(StaffProfileChangeRequest $request): void
    {
        $path = $request->pending_picture_path;

        if ($path !== null && $path !== '') {
            Storage::disk($this->pendingDisk())->delete($path);
        }

        $request->forceFill([
            'pending_picture_path' => null,
            'pending_picture_mime_type' => null,
            'pending_picture_size_bytes' => null,
            'pending_picture_width' => null,
            'pending_picture_height' => null,
        ])->save();
    }

    private function extension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/webp' => '.webp',
            'image/png' => '.png',
            default => '.jpg',
        };
    }
}
