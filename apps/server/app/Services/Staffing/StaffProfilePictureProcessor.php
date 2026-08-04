<?php

namespace App\Services\Staffing;

/**
 * Staff profile picture processing (M18.20C; technical spec 18A.2; data/API
 * 10.4).
 *
 * The 18A limits differ from the Field Report photo limits beside them —
 * 10 MB in rather than 5, a 1024 x 1024 square rather than 2560 x 1900, and
 * no GIF refusal because the accepted set is named positively — so this is its
 * own processor rather than a parameterization of that one. What it shares is
 * the approach: decode with GD, resize within the box preserving aspect ratio,
 * and re-encode, which strips EXIF as a consequence of rebuilding the bitmap
 * rather than as a separate scrubbing step.
 */
final class StaffProfilePictureProcessor
{
    /** Largest upload accepted before processing (technical spec 18A.2). */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    /** Stored images fit inside this box, preserving aspect ratio. */
    public const MAX_DIMENSION = 1024;

    /** @var list<string> */
    public const ACCEPTED_MIME_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    public const PREFERRED_MIME = 'image/webp';

    public const FALLBACK_MIME = 'image/jpeg';

    /**
     * @return array{
     *     bytes: string,
     *     mime_type: string,
     *     width: int,
     *     height: int,
     *     byte_size: int
     * }
     */
    public function process(string $bytes, ?string $declaredMimeType = null): array
    {
        if ($bytes === '') {
            throw new StaffProfileSelfException('The uploaded picture is empty.');
        }

        if (strlen($bytes) > self::MAX_UPLOAD_BYTES) {
            throw new StaffProfileSelfException(
                'Profile pictures are limited to 10 MB. Choose a smaller image.',
            );
        }

        $declared = strtolower(trim((string) $declaredMimeType));

        if ($declared !== '' && ! in_array($declared, self::ACCEPTED_MIME_TYPES, true)) {
            throw new StaffProfileSelfException(
                'Profile pictures must be a JPEG, PNG, or WebP image.',
            );
        }

        // The bytes decide, not the declared type: a caller may name anything.
        $size = @getimagesizefromstring($bytes);
        $detected = is_array($size) ? strtolower((string) ($size['mime'] ?? '')) : '';

        if (! in_array($detected, self::ACCEPTED_MIME_TYPES, true)) {
            throw new StaffProfileSelfException(
                'Profile pictures must be a JPEG, PNG, or WebP image.',
            );
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            throw new StaffProfileSelfException(
                'Unable to read that image. Use a JPEG, PNG, or WebP picture.',
            );
        }

        try {
            $sourceWidth = imagesx($image);
            $sourceHeight = imagesy($image);
            [$width, $height] = $this->fitDimensions($sourceWidth, $sourceHeight);

            if ($width !== $sourceWidth || $height !== $sourceHeight) {
                $resized = imagecreatetruecolor($width, $height);

                if ($resized === false) {
                    throw new StaffProfileSelfException(
                        'Unable to resize that picture on this server.',
                    );
                }

                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
                imagefilledrectangle($resized, 0, 0, $width, $height, $transparent);
                imagecopyresampled(
                    $resized,
                    $image,
                    0, 0, 0, 0,
                    $width,
                    $height,
                    $sourceWidth,
                    $sourceHeight,
                );
                imagedestroy($image);
                $image = $resized;
            }

            [$encoded, $mimeType] = $this->encode($image);

            return [
                'bytes' => $encoded,
                'mime_type' => $mimeType,
                'width' => $width,
                'height' => $height,
                'byte_size' => strlen($encoded),
            ];
        } finally {
            if (isset($image) && $image instanceof \GdImage) {
                imagedestroy($image);
            }
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function fitDimensions(int $width, int $height): array
    {
        if ($width <= 0 || $height <= 0) {
            throw new StaffProfileSelfException('That picture reports no dimensions.');
        }

        $scale = min(
            1.0,
            self::MAX_DIMENSION / $width,
            self::MAX_DIMENSION / $height,
        );

        return [
            max(1, (int) round($width * $scale)),
            max(1, (int) round($height * $scale)),
        ];
    }

    /**
     * @param  \GdImage  $image
     * @return array{0: string, 1: string}
     */
    private function encode($image): array
    {
        foreach ([self::PREFERRED_MIME, self::FALLBACK_MIME] as $mimeType) {
            if ($mimeType === self::PREFERRED_MIME && ! function_exists('imagewebp')) {
                continue;
            }

            ob_start();
            $ok = $mimeType === self::PREFERRED_MIME
                ? imagewebp($image, null, 85)
                : imagejpeg($image, null, 85);
            $encoded = (string) ob_get_clean();

            if ($ok !== false && $encoded !== '') {
                return [$encoded, $mimeType];
            }
        }

        throw new StaffProfileSelfException(
            'Unable to store that picture in a supported format.',
        );
    }
}
