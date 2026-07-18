<?php

namespace App\Services\FieldReports;

use App\Exceptions\FieldReportPhotoProcessingException;

/**
 * Server-side Field Report photo processing (M9.7).
 *
 * Defensive counterpart to the device capture pipeline. Enforces technical
 * spec 18.3 / data/API 10.17 limits using PHP GD (already required for Orchid):
 * reject GIFs, fit within 2560×1900, compress to ≤ 5 MB, strip EXIF by
 * re-encoding, and emit a single WebP (JPEG fallback) bitmap. Upload persistence
 * and download authorization remain M9.8.
 */
final class FieldReportPhotoProcessor
{
    /**
     * @return array{
     *     bytes: string,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     byte_size: int,
     *     checksum_sha256: string
     * }
     */
    public function process(string $bytes, ?string $declaredMimeType = null): array
    {
        if ($bytes === '') {
            throw new FieldReportPhotoProcessingException('Field Report photo bytes are empty.');
        }

        $mime = strtolower(trim((string) $declaredMimeType));

        if ($mime === FieldReportPhotoLimits::REJECTED_MIME || $this->isGifBytes($bytes)) {
            throw new FieldReportPhotoProcessingException(
                'GIF images are not supported for Field Report photos.'
            );
        }

        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new FieldReportPhotoProcessingException(
                'Unable to decode this image. Use a JPEG, PNG, or WebP photo.'
            );
        }

        try {
            $sourceWidth = imagesx($image);
            $sourceHeight = imagesy($image);
            [$width, $height] = $this->fitDimensions($sourceWidth, $sourceHeight);

            if ($width !== $sourceWidth || $height !== $sourceHeight) {
                $resized = imagecreatetruecolor($width, $height);
                if ($resized === false) {
                    throw new FieldReportPhotoProcessingException(
                        'Unable to allocate a resized Field Report photo canvas.'
                    );
                }

                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefilledrectangle($resized, 0, 0, $width, $height, $transparent);
                imagecopyresampled(
                    $resized,
                    $image,
                    0,
                    0,
                    0,
                    0,
                    $width,
                    $height,
                    $sourceWidth,
                    $sourceHeight
                );
                imagedestroy($image);
                $image = $resized;
            }

            [$encodedBytes, $mimeType] = $this->encodeWithinBudget($image);

            return [
                'bytes' => $encodedBytes,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
                'byte_size' => strlen($encodedBytes),
                'checksum_sha256' => hash('sha256', $encodedBytes),
            ];
        } finally {
            if (is_resource($image) || $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    public function remainingSlots(int $existingCount, int $selectedCount = 0): int
    {
        if ($existingCount < 0 || $selectedCount < 0) {
            throw new FieldReportPhotoProcessingException(
                'Field Report photo counts must be non-negative integers.'
            );
        }

        return max(0, FieldReportPhotoLimits::MAX_COUNT - $existingCount - $selectedCount);
    }

    public function assertCanAdd(int $existingCount, int $selectedCount, int $incomingCount): void
    {
        if ($incomingCount < 0) {
            throw new FieldReportPhotoProcessingException(
                'Incoming Field Report photo count must be a non-negative integer.'
            );
        }

        $remaining = $this->remainingSlots($existingCount, $selectedCount);
        if ($incomingCount > $remaining) {
            throw new FieldReportPhotoProcessingException(
                sprintf(
                    'Field Reports allow at most %d photos total. %d slot%s remaining.',
                    FieldReportPhotoLimits::MAX_COUNT,
                    $remaining,
                    $remaining === 1 ? '' : 's'
                )
            );
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function fitDimensions(int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            throw new FieldReportPhotoProcessingException(
                'Field Report photo dimensions must be positive.'
            );
        }

        $scale = min(
            1.0,
            FieldReportPhotoLimits::MAX_WIDTH / $width,
            FieldReportPhotoLimits::MAX_HEIGHT / $height
        );

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    private function isGifBytes(string $bytes): bool
    {
        return str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a');
    }

    /**
     * @param  \GdImage|resource  $image
     * @return array{0: string, 1: string}
     */
    private function encodeWithinBudget($image): array
    {
        $candidates = [
            FieldReportPhotoLimits::PREFERRED_MIME,
            FieldReportPhotoLimits::FALLBACK_MIME,
        ];

        $lastError = null;

        foreach ($candidates as $mimeType) {
            try {
                return [$this->encodeWithQualitySteps($image, $mimeType), $mimeType];
            } catch (FieldReportPhotoProcessingException $exception) {
                $lastError = $exception;
            }
        }

        throw $lastError ?? new FieldReportPhotoProcessingException(
            'Unable to encode the Field Report photo into a supported stored format.'
        );
    }

    /**
     * @param  \GdImage|resource  $image
     */
    private function encodeWithQualitySteps($image, string $mimeType): string
    {
        $qualities = [92, 84, 76, 68, 60, 52, 44, 36, 28, 20];
        $last = null;

        foreach ($qualities as $quality) {
            $encoded = $this->encodeOnce($image, $mimeType, $quality);
            $last = $encoded;
            if (strlen($encoded) <= FieldReportPhotoLimits::MAX_BYTES) {
                return $encoded;
            }
        }

        if (is_string($last) && strlen($last) <= FieldReportPhotoLimits::MAX_BYTES) {
            return $last;
        }

        throw new FieldReportPhotoProcessingException(
            sprintf(
                'Unable to compress the photo under %d bytes.',
                FieldReportPhotoLimits::MAX_BYTES
            )
        );
    }

    /**
     * @param  \GdImage|resource  $image
     */
    private function encodeOnce($image, string $mimeType, int $quality): string
    {
        ob_start();

        $ok = match ($mimeType) {
            FieldReportPhotoLimits::PREFERRED_MIME => function_exists('imagewebp')
                ? imagewebp($image, null, $quality)
                : false,
            FieldReportPhotoLimits::FALLBACK_MIME => imagejpeg($image, null, $quality),
            default => false,
        };

        $bytes = (string) ob_get_clean();

        if ($ok === false || $bytes === '') {
            throw new FieldReportPhotoProcessingException(
                sprintf('Unable to encode Field Report photo as %s.', $mimeType)
            );
        }

        return $bytes;
    }
}
