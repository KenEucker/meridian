<?php

declare(strict_types=1);

namespace App\Services\SystemConfig;

use App\Models\SystemConfigOverride;

/**
 * The effective state of one catalogued variable on this node (technical spec
 * 22A.6; SYS-016, SYS-017).
 *
 * `displayValue` is what any surface may show. It is already masked for
 * secrets — callers never re-derive a display value from the raw one.
 */
final class ResolvedConfigValue
{
    public const SOURCE_DATABASE_OVERRIDE = 'database_override';

    public const SOURCE_ENVIRONMENT = 'environment';

    public const SOURCE_LARAVEL_DEFAULT = 'laravel_default';

    public const SOURCE_MISSING = 'missing';

    public const SOURCE_INVALID = 'invalid';

    public const SOURCE_UNMAPPED = 'unmapped';

    public function __construct(
        public readonly CatalogEntry $entry,
        public readonly string $source,
        public readonly string $displayValue,
        public readonly ?SystemConfigOverride $override,
        public readonly ?string $overrideDisplayValue,
        public readonly bool $overridePending,
        public readonly ?string $validationError,
    ) {}

    public function sourceLabel(): string
    {
        return self::labelForSource($this->source);
    }

    public static function labelForSource(string $source): string
    {
        return match ($source) {
            self::SOURCE_DATABASE_OVERRIDE => 'Database Override',
            // Laravel loads `.env` into the process environment, so the two
            // cannot be told apart reliably; the combined label is the honest
            // one (SYS-017).
            self::SOURCE_ENVIRONMENT => 'Environment / .env',
            self::SOURCE_LARAVEL_DEFAULT => 'Laravel Default',
            self::SOURCE_MISSING => 'Missing',
            self::SOURCE_INVALID => 'Invalid',
            self::SOURCE_UNMAPPED => 'Unmapped',
            default => $source,
        };
    }
}
