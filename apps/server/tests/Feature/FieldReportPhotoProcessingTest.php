<?php

namespace Tests\Feature;

use App\Exceptions\FieldReportPhotoProcessingException;
use App\Services\FieldReports\FieldReportPhotoLimits;
use App\Services\FieldReports\FieldReportPhotoProcessor;
use Tests\TestCase;

class FieldReportPhotoProcessingTest extends TestCase
{
    public function test_rejects_gif_magic_bytes_even_when_mime_claims_jpeg(): void
    {
        $gif = $this->createGifBytes();

        $this->expectException(FieldReportPhotoProcessingException::class);
        $this->expectExceptionMessage('GIF');

        app(FieldReportPhotoProcessor::class)->process($gif, 'image/jpeg');
    }

    public function test_rejects_gif_mime_type(): void
    {
        $jpeg = $this->createJpegBytes(40, 30);

        $this->expectException(FieldReportPhotoProcessingException::class);
        $this->expectExceptionMessage('GIF');

        app(FieldReportPhotoProcessor::class)->process($jpeg, 'image/gif');
    }

    public function test_fits_oversized_image_within_2560_by_1900(): void
    {
        $source = $this->createJpegBytes(4000, 3000);

        $processed = app(FieldReportPhotoProcessor::class)->process($source, 'image/jpeg');

        $this->assertLessThanOrEqual(FieldReportPhotoLimits::MAX_WIDTH, $processed['width']);
        $this->assertLessThanOrEqual(FieldReportPhotoLimits::MAX_HEIGHT, $processed['height']);
        $this->assertSame(1900, $processed['height']);
        $this->assertSame(2533, $processed['width']);
        $this->assertLessThanOrEqual(FieldReportPhotoLimits::MAX_BYTES, $processed['byte_size']);
        $this->assertContains($processed['mime_type'], [
            FieldReportPhotoLimits::PREFERRED_MIME,
            FieldReportPhotoLimits::FALLBACK_MIME,
        ]);
        $this->assertSame(hash('sha256', $processed['bytes']), $processed['checksum_sha256']);
    }

    public function test_strips_exif_including_gps_from_processed_output(): void
    {
        $withExif = $this->createJpegWithExifApp1Segment($this->createJpegBytes(80, 60));

        $this->assertTrue($this->jpegContainsExif($withExif));

        $processed = app(FieldReportPhotoProcessor::class)->process($withExif, 'image/jpeg');

        if ($processed['mime_type'] === 'image/jpeg') {
            $this->assertFalse($this->jpegContainsExif($processed['bytes']));
        } else {
            $this->assertFalse(str_contains($processed['bytes'], 'Exif'));
            $this->assertFalse(str_contains($processed['bytes'], 'GPS'));
        }

        if (function_exists('exif_read_data')) {
            $temp = tempnam(sys_get_temp_dir(), 'fr-photo-');
            $this->assertNotFalse($temp);
            file_put_contents($temp, $processed['bytes']);
            $exif = @exif_read_data($temp);
            @unlink($temp);
            $this->assertFalse($exif);
        }
    }

    public function test_accepts_png_and_webp_sources(): void
    {
        $processor = app(FieldReportPhotoProcessor::class);

        $png = $this->createPngBytes(120, 80);
        $processedPng = $processor->process($png, 'image/png');
        $this->assertSame(120, $processedPng['width']);
        $this->assertSame(80, $processedPng['height']);

        if (function_exists('imagewebp')) {
            $webp = $this->createWebpBytes(90, 70);
            $processedWebp = $processor->process($webp, 'image/webp');
            $this->assertSame(90, $processedWebp['width']);
            $this->assertSame(70, $processedWebp['height']);
        }
    }

    public function test_enforces_max_two_photos_including_existing_append_budget(): void
    {
        $processor = app(FieldReportPhotoProcessor::class);

        $this->assertSame(2, $processor->remainingSlots(0, 0));
        $this->assertSame(0, $processor->remainingSlots(1, 1));

        $processor->assertCanAdd(0, 0, 2);

        $this->expectException(FieldReportPhotoProcessingException::class);
        $this->expectExceptionMessage('at most 2 photos');
        $processor->assertCanAdd(1, 1, 1);
    }

    public function test_does_not_keep_source_dimensions_when_downscaling(): void
    {
        $source = $this->createJpegBytes(3000, 1000);
        $processed = app(FieldReportPhotoProcessor::class)->process($source, 'image/jpeg');

        $this->assertNotSame(3000, $processed['width']);
        $this->assertLessThanOrEqual(FieldReportPhotoLimits::MAX_WIDTH, $processed['width']);
        $this->assertLessThanOrEqual(FieldReportPhotoLimits::MAX_HEIGHT, $processed['height']);
    }

    private function createJpegBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 40, 120, 200);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        ob_start();
        imagejpeg($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function createPngBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 20, 180, 90);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function createWebpBytes(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 200, 80, 40);
        imagefilledrectangle($image, 0, 0, $width, $height, $color);
        ob_start();
        imagewebp($image, null, 90);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function createGifBytes(): string
    {
        $image = imagecreatetruecolor(8, 8);
        $this->assertNotFalse($image);
        $color = imagecolorallocate($image, 255, 0, 0);
        imagefilledrectangle($image, 0, 0, 8, 8, $color);
        ob_start();
        imagegif($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    private function createJpegWithExifApp1Segment(string $jpeg): string
    {
        $this->assertTrue(str_starts_with($jpeg, "\xFF\xD8"));

        $exifPayload = "Exif\x00\x00GPS".str_repeat("\x00", 8);
        $segmentLength = strlen($exifPayload) + 2;
        $app1 = "\xFF\xE1".pack('n', $segmentLength).$exifPayload;

        return "\xFF\xD8".$app1.substr($jpeg, 2);
    }

    private function jpegContainsExif(string $bytes): bool
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return false;
        }

        $length = strlen($bytes);
        $offset = 2;

        while ($offset + 4 < $length) {
            if ($bytes[$offset] !== "\xFF") {
                $offset++;

                continue;
            }

            $marker = ord($bytes[$offset + 1]);
            if ($marker === 0xD9 || $marker === 0xDA) {
                break;
            }

            $size = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            if ($size < 2 || $offset + 2 + $size > $length) {
                break;
            }

            if ($marker === 0xE1) {
                $payload = substr($bytes, $offset + 4, $size - 2);
                if (str_starts_with($payload, 'Exif')) {
                    return true;
                }
            }

            $offset += 2 + $size;
        }

        return false;
    }
}
