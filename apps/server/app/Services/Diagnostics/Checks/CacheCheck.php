<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Cache store round-trip probe and session store configuration (SYS-033:
 * cache category). The probe key is random, written, read, and deleted, so
 * the check leaves nothing behind.
 */
class CacheCheck implements DiagnosticCheck
{
    public function key(): string
    {
        return 'cache.store';
    }

    public function label(): string
    {
        return 'Cache and session stores';
    }

    public function category(): string
    {
        return DiagnosticCategory::CACHE;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        $details = [
            'cache_store' => (string) config('cache.default'),
            'session_driver' => (string) config('session.driver'),
        ];

        $probeKey = 'meridian.diagnostics.probe.'.Str::random(16);
        $probeValue = Str::random(16);

        try {
            Cache::put($probeKey, $probeValue, 30);
            $read = Cache::get($probeKey);
            Cache::forget($probeKey);
        } catch (Throwable $exception) {
            return DiagnosticResult::critical(
                'The cache store is unreachable.',
                $details + ['exception' => $exception::class],
                'Verify the configured cache store is available.',
            );
        }

        if ($read !== $probeValue) {
            return DiagnosticResult::critical(
                'The cache store did not return what was written to it.',
                $details,
                'Verify the configured cache store is healthy.',
            );
        }

        $details['probe'] = 'write/read/delete ok';

        return DiagnosticResult::healthy('Cache round-trip succeeded.', $details);
    }
}
