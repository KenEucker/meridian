<?php

declare(strict_types=1);

namespace App\Services\Branding;

use RuntimeException;

/**
 * A branding submission that cannot be stored (BRAND-014 through BRAND-016).
 *
 * The failures are carried as structured data rather than only as a sentence,
 * because BRAND-015 requires the caller to be told the failing pair, the
 * measured ratio, and the required ratio, and an admin surface has to render
 * those three things per failure rather than parse them back out of prose.
 */
class BrandingValidationException extends RuntimeException
{
    /**
     * @param  list<ContrastFailure>  $failures
     */
    private function __construct(
        string $message,
        public readonly array $failures = [],
        public readonly ?string $field = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  list<ContrastFailure>  $failures
     */
    public static function contrast(array $failures): self
    {
        $summary = implode(' ', array_map(
            static fn (ContrastFailure $failure): string => $failure->describe(),
            $failures,
        ));

        return new self(
            'This color combination does not meet WCAG 2.1 AA and was not saved. '.$summary,
            $failures,
        );
    }

    public static function malformedColor(string $field, string $value): self
    {
        return new self(
            sprintf(
                'Branding color "%s" must be an opaque hex color such as #1a2b3c; received "%s".',
                $field,
                $value,
            ),
            [],
            $field,
        );
    }

    public static function missingColor(string $field): self
    {
        return new self(
            sprintf('Branding color "%s" is required.', $field),
            [],
            $field,
        );
    }

    public static function departmentOverridesDisabled(): self
    {
        return new self(
            'Department branding overrides are switched off for this organization. '
            .'A department may not set an accent or surface background until an organizer re-enables them.',
        );
    }

    public static function unsupportedAsset(string $slot): self
    {
        return new self(sprintf('"%s" is not a branding asset slot.', $slot), [], $slot);
    }

    public static function rejectedAsset(string $message): self
    {
        return new self($message, [], 'logo');
    }

    /**
     * @return list<array{pair: string, foreground: string, background: string, measured_ratio: float, required_ratio: float, usage: string}>
     */
    public function failurePayload(): array
    {
        return array_map(
            static fn (ContrastFailure $failure): array => $failure->toArray(),
            $this->failures,
        );
    }
}
