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
 * and download authorization are owned by FieldReportPhotoUploadService /
 * FieldReportPolicy (M9.8).
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

        $restoreMemoryLimit = $this->reserveMemoryFor($bytes);

        try {
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                throw new FieldReportPhotoProcessingException(
                    'Unable to decode this image. Use a JPEG, PNG, or WebP photo.'
                );
            }

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
            if (isset($image) && ($image instanceof \GdImage || is_resource($image))) {
                imagedestroy($image);
                unset($image);
            }

            if ($restoreMemoryLimit !== null) {
                // Best effort: PHP refuses a limit below the memory still held,
                // in which case the raise stays for the rest of this process.
                @ini_set('memory_limit', $restoreMemoryLimit);
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
     * Reserve enough memory to decode, resize, and re-encode this photo.
     *
     * GD bitmaps cost 4 bytes per pixel and count against `memory_limit`, so a
     * routine 12 MP phone photo needs more than PHP's 128 MB default: the
     * decoded source, the resized canvas, and the RGBA copy the encoder builds
     * are all resident at once. Without this the request dies with a fatal
     * allocation error instead of a handled response, so raise the limit for
     * the duration of one photo and refuse anything above the ceiling.
     *
     * @return string|null the previous `memory_limit` when it was raised
     */
    private function reserveMemoryFor(string $bytes): ?string
    {
        $size = @getimagesizefromstring($bytes);

        if (! is_array($size)) {
            // Undecodable header; imagecreatefromstring reports the real error.
            return null;
        }

        $width = (int) ($size[0] ?? 0);
        $height = (int) ($size[1] ?? 0);

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        $sourcePixels = $width * $height;

        if ($sourcePixels > FieldReportPhotoLimits::MAX_SOURCE_PIXELS) {
            throw new FieldReportPhotoProcessingException(sprintf(
                'This photo is %s by %s pixels, above the %d megapixel limit for Field Report photos.',
                $width,
                $height,
                intdiv(FieldReportPhotoLimits::MAX_SOURCE_PIXELS, 1_000_000),
            ));
        }

        [$fitWidth, $fitHeight] = $this->fitDimensions($width, $height);

        $required = (int) ceil(
            (memory_get_usage()
                + ($sourcePixels * 4)
                + ($fitWidth * $fitHeight * 4 * 2)
                + (strlen($bytes) * 2)
            ) * 1.25
        ) + FieldReportPhotoLimits::MEMORY_HEADROOM_BYTES;

        $currentLimit = $this->currentMemoryLimitBytes();

        if ($currentLimit === null || $currentLimit >= $required) {
            return null;
        }

        if ($required > FieldReportPhotoLimits::MEMORY_CEILING_BYTES) {
            throw new FieldReportPhotoProcessingException(
                'This photo is too large for this server to process. Use a smaller photo.'
            );
        }

        $previous = ini_get('memory_limit');

        if (@ini_set('memory_limit', (string) $required) === false) {
            throw new FieldReportPhotoProcessingException(
                'This photo is too large for this server to process. Use a smaller photo.'
            );
        }

        return is_string($previous) ? $previous : null;
    }

    /**
     * @return int|null null when memory is unlimited
     */
    private function currentMemoryLimitBytes(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return null;
        }

        $unit = strtolower(substr($limit, -1));
        $value = (int) $limit;

        return match ($unit) {
            'g' => $value * 1024 * 1024 * 1024,
            'm' => $value * 1024 * 1024,
            'k' => $value * 1024,
            default => $value,
        };
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
