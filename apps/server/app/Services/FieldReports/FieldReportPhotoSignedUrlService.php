<?php

namespace App\Services\FieldReports;

use App\Models\Attachment;
use App\Models\FieldReport;
use App\Models\User;
use App\Services\Downloads\ShortLivedDownloadUrl;
use App\Services\Downloads\ShortLivedDownloadUrlService;
use RuntimeException;

/**
 * Short-lived signed URLs for Field Report photo preview/download
 * (technical spec 18.6; data/API 10.17).
 *
 * The general mechanism lives in `ShortLivedDownloadUrlService` (technical spec
 * 11A.6). What stays here is the part that is about Field Report photos: which
 * policy decides preview and which decides download.
 */
final class FieldReportPhotoSignedUrlService
{
    public function __construct(private readonly ShortLivedDownloadUrlService $downloadUrls) {}

    public function previewUrl(User $user, Attachment $attachment): ShortLivedDownloadUrl
    {
        $report = $this->fieldReport($attachment);

        if (! $user->can('view', $report)) {
            throw new RuntimeException('Not authorized to preview this Field Report photo.');
        }

        return $this->downloadUrls->issue($user, 'field-report-photos.preview', [
            'attachment' => $attachment->getKey(),
        ]);
    }

    public function downloadUrl(User $user, Attachment $attachment): ShortLivedDownloadUrl
    {
        $report = $this->fieldReport($attachment);

        if (! $user->can('downloadPhoto', $report)) {
            throw new RuntimeException('Not authorized to download this Field Report photo.');
        }

        return $this->downloadUrls->issue($user, 'field-report-photos.download', [
            'attachment' => $attachment->getKey(),
        ]);
    }

    private function fieldReport(Attachment $attachment): FieldReport
    {
        if (! $attachment->isFieldReportPhoto()) {
            throw new RuntimeException('Attachment is not a Field Report photo.');
        }

        $report = $attachment->attachable;

        if (! $report instanceof FieldReport) {
            $report = FieldReport::query()->find($attachment->attachable_id);
        }

        if (! $report instanceof FieldReport) {
            throw new RuntimeException('Field Report for attachment does not exist.');
        }

        return $report;
    }
}
