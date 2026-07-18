<?php

namespace App\Services\FieldReports;

use App\Models\Attachment;
use App\Models\FieldReport;
use App\Models\User;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Short-lived signed URLs for Field Report photo preview/download
 * (technical spec 18.6; data/API 10.17).
 */
final class FieldReportPhotoSignedUrlService
{
    public function previewUrl(User $user, Attachment $attachment): string
    {
        $report = $this->fieldReport($attachment);

        if (! $user->can('view', $report)) {
            throw new RuntimeException('Not authorized to preview this Field Report photo.');
        }

        return $this->temporaryUrl('field-report-photos.preview', $attachment);
    }

    public function downloadUrl(User $user, Attachment $attachment): string
    {
        $report = $this->fieldReport($attachment);

        if (! $user->can('downloadPhoto', $report)) {
            throw new RuntimeException('Not authorized to download this Field Report photo.');
        }

        return $this->temporaryUrl('field-report-photos.download', $attachment);
    }

    private function temporaryUrl(string $routeName, Attachment $attachment): string
    {
        $minutes = (int) config('meridian.field_report_photos.signed_url_expires_minutes', 5);

        $relativeSignedUrl = URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes($minutes),
            ['attachment' => $attachment->getKey()],
            absolute: false,
        );

        return URL::to($relativeSignedUrl);
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
