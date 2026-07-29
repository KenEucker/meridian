<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

/**
 * Diagnostic statuses and their aggregation rules (technical spec 22A.8;
 * SYS-030, SYS-031).
 */
final class DiagnosticStatus
{
    public const HEALTHY = 'healthy';

    public const WARNING = 'warning';

    public const CRITICAL = 'critical';

    public const UNKNOWN = 'unknown';

    public const NOT_APPLICABLE = 'not_applicable';

    /**
     * @var list<string>
     */
    public const ALL = [
        self::HEALTHY,
        self::WARNING,
        self::CRITICAL,
        self::UNKNOWN,
        self::NOT_APPLICABLE,
    ];

    public static function label(string $status): string
    {
        return match ($status) {
            self::HEALTHY => 'Healthy',
            self::WARNING => 'Warning',
            self::CRITICAL => 'Critical',
            self::UNKNOWN => 'Unknown',
            self::NOT_APPLICABLE => 'Not applicable',
            default => $status,
        };
    }
}
