<?php

declare(strict_types=1);

namespace App\Services\Diagnostics;

/**
 * Diagnostic check categories (technical spec 22A.8; SYS-033).
 */
final class DiagnosticCategory
{
    public const APPLICATION = 'application';

    public const DATABASE = 'database';

    public const CACHE = 'cache';

    public const QUEUE = 'queue';

    public const STORAGE = 'storage';

    public const WIRING = 'wiring';

    public const SYNC = 'sync';

    public const NODE = 'node';

    public const INTEGRATIONS = 'integrations';

    public const SECURITY = 'security';

    public const CONFIGURATION = 'configuration';

    public static function label(string $category): string
    {
        return match ($category) {
            self::APPLICATION => 'Application',
            self::DATABASE => 'Database',
            self::CACHE => 'Cache and session',
            self::QUEUE => 'Queue and scheduled work',
            self::STORAGE => 'Storage and filesystem',
            self::WIRING => 'Providers and application wiring',
            self::SYNC => 'PowerSync and node sync',
            self::NODE => 'Node health',
            self::INTEGRATIONS => 'External integrations',
            self::SECURITY => 'Security',
            self::CONFIGURATION => 'System configuration',
            default => $category,
        };
    }
}
