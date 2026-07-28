<?php

declare(strict_types=1);

namespace App\Services\Branding;

/**
 * One branding color, and the WCAG arithmetic that decides whether it may be
 * used next to another one (BRAND-014).
 *
 * Only opaque `#rgb` / `#rrggbb` values are accepted. Alpha is refused rather
 * than supported because a contrast ratio against a translucent color is not a
 * property of the two colors — it depends on whatever happens to be painted
 * underneath — and a validator that quietly composited over an assumed
 * background would be reporting a ratio the user may never see.
 */
final class BrandingColor
{
    private function __construct(
        public readonly string $hex,
        public readonly int $red,
        public readonly int $green,
        public readonly int $blue,
    ) {}

    /**
     * @throws BrandingValidationException when the value is not an opaque hex color
     */
    public static function parse(string $value, string $field): self
    {
        $candidate = strtolower(trim($value));

        if (preg_match('/^#([0-9a-f]{3})$/', $candidate, $matches) === 1) {
            $candidate = '#'.preg_replace('/(.)/', '$1$1', $matches[1]);
        }

        if (preg_match('/^#([0-9a-f]{6})$/', $candidate) !== 1) {
            throw BrandingValidationException::malformedColor($field, $value);
        }

        return new self(
            $candidate,
            (int) hexdec(substr($candidate, 1, 2)),
            (int) hexdec(substr($candidate, 3, 2)),
            (int) hexdec(substr($candidate, 5, 2)),
        );
    }

    public static function tryParse(?string $value, string $field): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::parse($value, $field);
    }

    /**
     * WCAG 2.1 relative luminance.
     */
    public function relativeLuminance(): float
    {
        return 0.2126 * $this->channel($this->red)
            + 0.7152 * $this->channel($this->green)
            + 0.0722 * $this->channel($this->blue);
    }

    /**
     * WCAG 2.1 contrast ratio, always >= 1.0 and independent of argument order.
     */
    public function contrastRatioWith(self $other): float
    {
        $first = $this->relativeLuminance();
        $second = $other->relativeLuminance();

        $lighter = max($first, $second);
        $darker = min($first, $second);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public function equals(self $other): bool
    {
        return $this->hex === $other->hex;
    }

    private function channel(int $value): float
    {
        $normalized = $value / 255;

        return $normalized <= 0.03928
            ? $normalized / 12.92
            : (($normalized + 0.055) / 1.055) ** 2.4;
    }
}
