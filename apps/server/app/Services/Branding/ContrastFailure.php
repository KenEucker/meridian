<?php

declare(strict_types=1);

namespace App\Services\Branding;

/**
 * One color pair that did not meet its required ratio (BRAND-015).
 *
 * The measured ratio is rounded down to two decimals rather than to nearest.
 * A pair measuring 4.4999 is a failure, and rounding it to "4.50" in the very
 * message explaining that 4.5 was required would read as a contradiction.
 */
final class ContrastFailure
{
    public function __construct(
        public readonly string $pair,
        public readonly string $usage,
        public readonly BrandingColor $foreground,
        public readonly BrandingColor $background,
        public readonly float $measuredRatio,
        public readonly float $requiredRatio,
    ) {}

    public function measuredRatioRounded(): float
    {
        return floor($this->measuredRatio * 100) / 100;
    }

    public function describe(): string
    {
        return sprintf(
            '%s (%s on %s) measures %.2f:1 against the %.1f:1 required for %s.',
            $this->pair,
            $this->foreground->hex,
            $this->background->hex,
            $this->measuredRatioRounded(),
            $this->requiredRatio,
            $this->usage,
        );
    }

    /**
     * @return array{pair: string, foreground: string, background: string, measured_ratio: float, required_ratio: float, usage: string}
     */
    public function toArray(): array
    {
        return [
            'pair' => $this->pair,
            'foreground' => $this->foreground->hex,
            'background' => $this->background->hex,
            'measured_ratio' => $this->measuredRatioRounded(),
            'required_ratio' => $this->requiredRatio,
            'usage' => $this->usage,
        ];
    }
}
