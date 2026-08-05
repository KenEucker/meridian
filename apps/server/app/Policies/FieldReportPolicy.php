<?php

namespace App\Policies;

use App\Models\FieldReport;
use App\Models\User;
use App\Services\FieldReports\FieldReportPhotoAccess;
use App\Services\FieldReports\FieldReportVisibilityAccess;

/**
 * Field Report authorization (FR-004 through FR-009; technical spec 17.4, 17.6,
 * 18.6).
 *
 * Authors may view their own submitted reports. IC roles with
 * `field_reports.view_event` may view all Field Reports for the granted event.
 * Non-authors without that permission are denied, including department leads,
 * organizers, and shift leads. Updates and deletes are always denied (FR-007).
 * Only the original author may append (FR-009); elevated IC roles cannot append
 * to other users' Field Reports. Photo upload is author-only. Photo download is
 * restricted to `field_reports.download_photo` (ic_lead).
 *
 * A report taken on behalf of somebody else (M18.24A; FR-015, FR-016) splits
 * those two words apart, and this policy is where the split is enforced.
 * *Author* is the reporting staff member — the person whose account it is — and
 * append follows them and nobody else. *Submitter* is the operator who wrote it
 * down; they may read the report they typed, and that is the whole of what
 * taking it gave them. FR-016 is explicit that "the submitter shall not gain
 * append authority from having taken the report", and an operator who sat at a
 * radio for a shift would otherwise end up able to add to every account they
 * had transcribed.
 */
class FieldReportPolicy
{
    public function __construct(
        private readonly FieldReportVisibilityAccess $visibility,
        private readonly FieldReportPhotoAccess $photoAccess,
    ) {}

    public function view(User $user, FieldReport $fieldReport): bool
    {
        if ($this->wasSubmittedBy($user, $fieldReport) || $fieldReport->isAuthoredBy($user)) {
            return true;
        }

        $event = $fieldReport->event;

        if ($event === null) {
            return false;
        }

        return $this->visibility->canViewEventFieldReports($user, $event);
    }

    /**
     * Only the recorded author (FR-009, FR-016).
     *
     * Not the submitter of a taken report, however senior they are and however
     * recently they typed it: the append is a correction to somebody else's
     * account, and the person who gave the account is the one who may make it.
     */
    public function append(User $user, FieldReport $fieldReport): bool
    {
        return $fieldReport->isAuthoredBy($user);
    }

    /**
     * Photos belong to the account, so they follow the author too.
     *
     * The submitter of a taken report is transcribing what they were told over
     * a radio; they were not at the scene and have no photograph of it. If they
     * do, it is their own observation and their own report to file.
     */
    public function uploadPhoto(User $user, FieldReport $fieldReport): bool
    {
        return $fieldReport->isAuthoredBy($user);
    }

    public function downloadPhoto(User $user, FieldReport $fieldReport): bool
    {
        $event = $fieldReport->event;

        if ($event === null) {
            return false;
        }

        return $this->photoAccess->canDownloadEventFieldReportPhotos($user, $event);
    }

    public function update(User $user, FieldReport $fieldReport): bool
    {
        return false;
    }

    public function delete(User $user, FieldReport $fieldReport): bool
    {
        return false;
    }

    private function wasSubmittedBy(User $user, FieldReport $fieldReport): bool
    {
        return (string) $fieldReport->submitted_by_user_id === (string) $user->getKey();
    }
}
