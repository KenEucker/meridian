<?php

namespace Database\Seeders\Support;

use GdImage;
use RuntimeException;

/**
 * The scenario's pictures, drawn rather than shipped.
 *
 * Every image the seed needs is generated with GD at seed time instead of being
 * committed as a binary blob, for three reasons that all matter more than they
 * look. A repository that carries a folder of stock JPEGs grows by megabytes
 * that no diff can ever review. Generated art is deterministic — the same name
 * always produces the same bytes, so a re-seed writes an identical checksum and
 * the attachment paths stay idempotent. And nothing here is a photograph of a
 * real person or a real organization's logo, which is the correct thing for a
 * fixture that ends up in screenshots.
 *
 * The colour for any subject is derived from its own name, so Vera is always the
 * same rust and the Rangers mark is always the same pine. That is what makes a
 * roster of avatars readable at a glance rather than a wall of identical grey
 * circles: the eye picks out the colour before it reads the initials, which is
 * exactly what a real avatar does.
 *
 * Lettering goes through {@see stamp}, which draws into a transparent canvas at
 * GD's built-in font size and scales it up. GD only ships bitmap fonts and only
 * at fixed sizes, so this is the one way to get legible type without depending
 * on a TrueType file being installed on whichever machine runs the seed.
 */
final class ScenarioImages
{
    /**
     * Muted fills, chosen to stay legible behind white lettering.
     *
     * A curated list rather than random RGB, because random colour reads as
     * broken and one of the things these images have to do is look deliberate.
     *
     * @var list<array{int, int, int}>
     */
    private const PALETTE = [
        [176, 84, 52],   // rust
        [61, 105, 92],   // pine
        [78, 92, 140],   // slate blue
        [140, 96, 44],   // ochre
        [104, 74, 122],  // plum
        [46, 96, 116],   // teal
        [150, 66, 82],   // brick
        [86, 106, 58],   // olive
    ];

    private const PAPER = [250, 249, 246];

    /** A square avatar: initials over a colour the name chose for itself. */
    public static function avatar(string $name, int $size = 320): string
    {
        $image = self::canvas($size, $size, self::colorFor($name));

        try {
            self::place(
                $image,
                self::stamp(self::initials($name), [255, 255, 255]),
                (int) round($size * 0.52),
                (int) round($size / 2),
                (int) round($size / 2),
            );

            return self::encodePng($image);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * A wide lockup: a mark on the left and the name beside it.
     *
     * The shape a header lockup actually takes. A square logo stretched across a
     * banner is the usual way a seeded brand looks wrong, and the product puts
     * these into headers where the proportions show.
     */
    public static function logo(string $label, int $width = 640, int $height = 200): string
    {
        [$r, $g, $b] = self::colorFor($label);
        $image = self::canvas($width, $height, self::PAPER);

        try {
            $ink = imagecolorallocate($image, $r, $g, $b);
            $mark = (int) round($height * 0.56);
            $markX = (int) round($height * 0.22);
            $markY = (int) round(($height - $mark) / 2);

            // A ring with a bite out of the lower right, so it reads as a device
            // rather than as a coloured square somebody forgot to replace.
            imagefilledellipse($image, $markX + (int) ($mark / 2), $markY + (int) ($mark / 2), $mark, $mark, $ink);
            imagefilledellipse(
                $image,
                $markX + (int) ($mark / 2),
                $markY + (int) ($mark / 2),
                (int) round($mark * 0.46),
                (int) round($mark * 0.46),
                imagecolorallocate($image, self::PAPER[0], self::PAPER[1], self::PAPER[2]),
            );
            imagefilledrectangle(
                $image,
                $markX + (int) round($mark * 0.5),
                $markY + (int) round($mark * 0.72),
                $markX + $mark + 2,
                $markY + $mark + 2,
                imagecolorallocate($image, self::PAPER[0], self::PAPER[1], self::PAPER[2]),
            );

            $textLeft = $markX + $mark + (int) round($height * 0.20);
            $stamp = self::stamp(mb_strtoupper($label), [$r, $g, $b]);
            $textWidth = min($width - $textLeft - (int) round($height * 0.16), (int) round($height * 0.30) * mb_strlen($label));

            self::place(
                $image,
                $stamp,
                max(80, $textWidth),
                $textLeft + (int) round(max(80, $textWidth) / 2),
                (int) round($height / 2),
            );

            return self::encodePng($image);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * A landscape stand-in for a photo taken in the field.
     *
     * Dust-toned rather than colourful: these hang off Field Reports written at
     * a desert event, and a saturated abstract reads as a rendering bug next to
     * a report about a dust storm. A graded sky, a horizon, ground, and a few
     * silhouettes are enough for the eye to accept it as a scene at thumbnail
     * size, which is the size it is usually seen at. The caption is drawn into
     * the image so a screenshot carries what the picture is meant to be.
     */
    public static function fieldPhoto(string $caption, int $width = 960, int $height = 640): string
    {
        [$r, $g, $b] = self::colorFor($caption);
        $image = self::canvas($width, $height, [214, 198, 172]);

        try {
            $horizon = (int) round($height * 0.58);

            // Sky: pale dust at the horizon, warming upward, with a hint of the
            // subject colour so two photos are still told apart.
            for ($y = 0; $y < $horizon; $y++) {
                $t = $y / max(1, $horizon);
                imageline($image, 0, $y, $width, $y, imagecolorallocate(
                    $image,
                    self::clamp((int) (196 + $t * 42 + ($r - 120) * 0.06)),
                    self::clamp((int) (182 + $t * 38 + ($g - 120) * 0.06)),
                    self::clamp((int) (162 + $t * 30 + ($b - 120) * 0.06)),
                ));
            }

            // Ground: darker, and darker still toward the bottom of the frame.
            for ($y = $horizon; $y < $height; $y++) {
                $t = ($y - $horizon) / max(1, $height - $horizon);
                imageline($image, 0, $y, $width, $y, imagecolorallocate(
                    $image,
                    self::clamp((int) (172 - $t * 46 + ($r - 120) * 0.10)),
                    self::clamp((int) (152 - $t * 42 + ($g - 120) * 0.10)),
                    self::clamp((int) (124 - $t * 34 + ($b - 120) * 0.10)),
                ));
            }

            /*
             * Silhouettes on the horizon: a low ridge and a couple of shapes.
             * Mixed most of the way toward the ground tone rather than drawn in
             * the subject colour directly — a saturated ridge reads as a graphic
             * shape sitting on top of the picture, where terrain at distance is
             * mostly the colour of the air in front of it.
             */
            $far = imagecolorallocate(
                $image,
                self::clamp((int) (0.30 * $r + 0.70 * 132)),
                self::clamp((int) (0.30 * $g + 0.70 * 116)),
                self::clamp((int) (0.30 * $b + 0.70 * 96)),
            );
            imagefilledpolygon($image, [
                0, $horizon,
                (int) round($width * 0.22), (int) round($horizon - $height * 0.07),
                (int) round($width * 0.44), $horizon,
            ], $far);
            imagefilledpolygon($image, [
                (int) round($width * 0.52), $horizon,
                (int) round($width * 0.70), (int) round($horizon - $height * 0.11),
                (int) round($width * 0.92), $horizon,
            ], $far);

            $near = imagecolorallocate(
                $image,
                self::clamp((int) (0.35 * $r + 0.65 * 92)),
                self::clamp((int) (0.35 * $g + 0.65 * 78)),
                self::clamp((int) (0.35 * $b + 0.65 * 62)),
            );
            imagefilledrectangle(
                $image,
                (int) round($width * 0.14),
                (int) round($horizon - $height * 0.05),
                (int) round($width * 0.18),
                $horizon,
                $near,
            );
            imagefilledrectangle(
                $image,
                (int) round($width * 0.78),
                (int) round($horizon - $height * 0.08),
                (int) round($width * 0.84),
                $horizon,
                $near,
            );

            // Caption plate along the bottom, the way a field photo is labelled.
            $plateTop = $height - (int) round($height * 0.11);
            imagefilledrectangle($image, 0, $plateTop, $width, $height, imagecolorallocate($image, 24, 22, 20));
            self::place(
                $image,
                self::stamp($caption, [244, 240, 234]),
                min($width - 48, (int) round(mb_strlen($caption) * $height * 0.0155)),
                (int) round($width / 2),
                $plateTop + (int) round(($height - $plateTop) / 2),
            );

            return self::encodePng($image);
        } finally {
            imagedestroy($image);
        }
    }

    /**
     * At most three letters, from the words that carry meaning.
     *
     * The same shape the product's own lettermark uses, so a seeded avatar and a
     * generated lettermark beside it do not disagree about somebody's name.
     */
    public static function initials(string $name): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';

        foreach ($words as $word) {
            if (in_array(mb_strtolower($word), ['the', 'of', 'and', 'for'], true)) {
                continue;
            }

            $letters .= mb_substr($word, 0, 1);

            if (mb_strlen($letters) === 3) {
                break;
            }
        }

        if ($letters === '') {
            $letters = mb_substr($name, 0, 2);
        }

        return mb_strtoupper($letters === '' ? '??' : $letters);
    }

    /**
     * Text on a transparent canvas, at GD's own font size.
     *
     * Kept separate from placing it because the only way to get large lettering
     * out of GD's bitmap fonts is to draw small and scale up, and that needs a
     * real alpha channel on both sides — draw onto an opaque stamp and the
     * background travels with the letters.
     *
     * @param  array{int, int, int}  $color
     */
    private static function stamp(string $text, array $color): GdImage
    {
        $font = 5;
        $width = max(1, imagefontwidth($font) * mb_strlen($text));
        $height = max(1, imagefontheight($font));
        $stamp = imagecreatetruecolor($width, $height);

        if ($stamp === false) {
            throw new RuntimeException('The scenario text stamp could not be created.');
        }

        imagealphablending($stamp, false);
        imagesavealpha($stamp, true);
        imagefilledrectangle($stamp, 0, 0, $width, $height, imagecolorallocatealpha($stamp, 0, 0, 0, 127));
        imagealphablending($stamp, true);
        imagestring($stamp, $font, 0, 0, $text, imagecolorallocate($stamp, $color[0], $color[1], $color[2]));

        return $stamp;
    }

    /** Scale a stamp to `$targetWidth` and composite it centred on `$centerX/Y`. */
    private static function place(GdImage $image, GdImage $stamp, int $targetWidth, int $centerX, int $centerY): void
    {
        try {
            $targetWidth = max(1, $targetWidth);
            $targetHeight = max(1, (int) round($targetWidth * imagesy($stamp) / imagesx($stamp)));

            imagealphablending($image, true);
            imagecopyresampled(
                $image,
                $stamp,
                $centerX - (int) round($targetWidth / 2),
                $centerY - (int) round($targetHeight / 2),
                0,
                0,
                $targetWidth,
                $targetHeight,
                imagesx($stamp),
                imagesy($stamp),
            );
        } finally {
            imagedestroy($stamp);
        }
    }

    /**
     * @return array{int, int, int}
     */
    private static function colorFor(string $subject): array
    {
        $index = (int) hexdec(substr(md5($subject), 0, 8)) % count(self::PALETTE);

        return self::PALETTE[$index];
    }

    /**
     * @param  array{int, int, int}  $background
     */
    private static function canvas(int $width, int $height, array $background): GdImage
    {
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('The scenario image canvas could not be created.');
        }

        imagefilledrectangle(
            $image,
            0,
            0,
            $width,
            $height,
            imagecolorallocate($image, $background[0], $background[1], $background[2]),
        );

        return $image;
    }

    private static function encodePng(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 6);
        $bytes = (string) ob_get_clean();

        if ($bytes === '') {
            throw new RuntimeException('The scenario image could not be encoded.');
        }

        return $bytes;
    }

    private static function clamp(int $value): int
    {
        return max(0, min(255, $value));
    }
}
