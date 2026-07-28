<?php

declare(strict_types=1);

namespace App\Services\Branding;

/**
 * The ten colors an organization may set (BRAND-006).
 *
 * Four platform colors and six neutrals, and nothing else. Action, status,
 * severity, attention, chart series, and department accent values are derived
 * from these by {@see BrandingTokenResolver} and are deliberately absent here:
 * a palette that could carry `status_danger` would let an organization make
 * danger look like success, which is the one thing branding may never do
 * (BRAND-007, BRAND-017).
 *
 * The default values are Meridian's own, from UI style guide sections 4.1 and
 * 4.2, and are what renders before an organization has a branding profile and
 * permanently on the surfaces BRAND-003 protects.
 */
final class BrandingPalette
{
    /**
     * Settable field names, in the order an admin surface should present them.
     *
     * @var list<string>
     */
    public const FIELDS = [
        'primary',
        'secondary',
        'tertiary',
        'accent',
        'canvas',
        'surface',
        'foreground',
        'muted_foreground',
        'border',
        'focus',
    ];

    /**
     * @var array<string, string>
     */
    private const DEFAULTS = [
        'primary' => '#475157',
        'secondary' => '#6b7562',
        'tertiary' => '#a58667',
        'accent' => '#cc792f',
        'canvas' => '#f6f1e8',
        'surface' => '#fffcf6',
        'foreground' => '#151a1f',
        'muted_foreground' => '#5f665f',
        'border' => '#8d8371',
        'focus' => '#b35f14',
    ];

    /**
     * @param  array<string, BrandingColor>  $colors  keyed by {@see FIELDS}
     */
    private function __construct(private readonly array $colors) {}

    public static function meridianDefault(): self
    {
        return self::fromArray(self::DEFAULTS);
    }

    /**
     * @param  array<array-key, mixed>  $values
     *
     * @throws BrandingValidationException when a value is missing or malformed
     */
    public static function fromArray(array $values): self
    {
        $colors = [];

        foreach (self::FIELDS as $field) {
            $value = $values[$field] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw BrandingValidationException::missingColor($field);
            }

            $colors[$field] = BrandingColor::parse($value, $field);
        }

        return new self($colors);
    }

    /**
     * A stored palette, falling back to Meridian's defaults when the column is
     * empty. A stored value that no longer parses is not silently repaired —
     * BRAND-016 forbids repair — so this is used only for values that passed
     * validation on their way in.
     *
     * @param  array<array-key, mixed>|null  $values
     */
    public static function fromStored(?array $values): self
    {
        return $values === null || $values === []
            ? self::meridianDefault()
            : self::fromArray($values);
    }

    public function color(string $field): BrandingColor
    {
        return $this->colors[$field];
    }

    public function primary(): BrandingColor
    {
        return $this->colors['primary'];
    }

    public function secondary(): BrandingColor
    {
        return $this->colors['secondary'];
    }

    public function tertiary(): BrandingColor
    {
        return $this->colors['tertiary'];
    }

    public function accent(): BrandingColor
    {
        return $this->colors['accent'];
    }

    public function canvas(): BrandingColor
    {
        return $this->colors['canvas'];
    }

    public function surface(): BrandingColor
    {
        return $this->colors['surface'];
    }

    public function foreground(): BrandingColor
    {
        return $this->colors['foreground'];
    }

    public function mutedForeground(): BrandingColor
    {
        return $this->colors['muted_foreground'];
    }

    public function border(): BrandingColor
    {
        return $this->colors['border'];
    }

    public function focus(): BrandingColor
    {
        return $this->colors['focus'];
    }

    public function isMeridianDefault(): bool
    {
        return $this->toArray() === self::DEFAULTS;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        $values = [];

        foreach (self::FIELDS as $field) {
            $values[$field] = $this->colors[$field]->hex;
        }

        return $values;
    }
}
