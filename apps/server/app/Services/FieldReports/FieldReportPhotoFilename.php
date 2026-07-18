<?php

namespace App\Services\FieldReports;

use App\Models\Event;
use App\Models\FieldReport;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Plain-text Field Report photo filenames (technical spec 18.5).
 *
 * Example: EVENT-2027_FRA-2027-000123_2027-07-04T13-22-10Z_01.webp
 */
final class FieldReportPhotoFilename
{
    public static function make(
        FieldReport $report,
        int $slot,
        string $mimeType,
        ?DateTimeInterface $uploadedAt = null,
    ): string {
        $event = $report->event;
        if (! $event instanceof Event) {
            $event = Event::query()->findOrFail($report->event_id);
        }

        $year = self::eventYear($event);
        $fra = $report->fra_number ?? 'FRA-PENDING';
        $timestamp = CarbonImmutable::instance($uploadedAt ?? now())
            ->utc()
            ->format('Y-m-d\TH-i-s\Z');
        $extension = self::extensionForMime($mimeType);

        return sprintf(
            'EVENT-%04d_%s_%s_%02d.%s',
            $year,
            $fra,
            $timestamp,
            $slot,
            $extension,
        );
    }

    private static function eventYear(Event $event): int
    {
        if ($event->starts_at === null) {
            return (int) now()->year;
        }

        $timezone = $event->timezone ?? 'UTC';

        return (int) $event->starts_at->copy()->setTimezone($timezone)->year;
    }

    private static function extensionForMime(string $mimeType): string
    {
        return match (strtolower($mimeType)) {
            FieldReportPhotoLimits::PREFERRED_MIME => 'webp',
            FieldReportPhotoLimits::FALLBACK_MIME => 'jpg',
            default => 'bin',
        };
    }
}
