<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use Illuminate\Support\Str;
use Throwable;

/**
 * Storage directories, temporary-file capability, and disk space (SYS-033:
 * storage category). The probe file is created and removed in the same run;
 * nothing destructive is written.
 */
class StorageCheck implements DiagnosticCheck
{
    private const LOW_DISK_WARNING_FRACTION = 0.10;

    private const LOW_DISK_CRITICAL_FRACTION = 0.03;

    public function key(): string
    {
        return 'storage.filesystem';
    }

    public function label(): string
    {
        return 'Storage and disk space';
    }

    public function category(): string
    {
        return DiagnosticCategory::STORAGE;
    }

    public function required(): bool
    {
        return true;
    }

    public function run(): DiagnosticResult
    {
        $problems = [];
        $details = [];

        foreach ([
            'storage_app' => storage_path('app'),
            'storage_logs' => storage_path('logs'),
            'storage_framework' => storage_path('framework'),
            'bootstrap_cache' => base_path('bootstrap/cache'),
        ] as $key => $path) {
            $writable = is_dir($path) && is_writable($path);
            $details[$key.'_writable'] = $writable;

            if (! $writable) {
                $problems[] = str_replace('_', '/', $key).' is not writable.';
            }
        }

        $details['temp_file_probe'] = $this->tempFileProbe();

        if ($details['temp_file_probe'] === false) {
            $problems[] = 'A temporary file could not be created in storage/app.';
        }

        [$freeBytes, $totalBytes] = $this->diskSpace();
        $details['disk_free_bytes'] = $freeBytes;
        $details['disk_total_bytes'] = $totalBytes;

        if ($freeBytes !== null && $totalBytes !== null && $totalBytes > 0) {
            $fraction = $freeBytes / $totalBytes;
            $details['disk_free_percent'] = round($fraction * 100, 1);

            if ($fraction < self::LOW_DISK_CRITICAL_FRACTION) {
                return DiagnosticResult::critical(
                    'Disk space is nearly exhausted.',
                    $details,
                    'Free disk space on this node before it stops accepting writes.',
                );
            }

            if ($fraction < self::LOW_DISK_WARNING_FRACTION) {
                $problems[] = 'Disk space is low.';
            }
        }

        if ($problems !== []) {
            $critical = array_filter($details, static fn ($value, $key): bool => str_ends_with((string) $key, '_writable') && $value === false, ARRAY_FILTER_USE_BOTH);

            return $critical !== []
                ? DiagnosticResult::critical(implode(' ', $problems), $details, 'Fix directory ownership/permissions for the paths reported not writable.')
                : DiagnosticResult::warning(implode(' ', $problems), $details);
        }

        return DiagnosticResult::healthy('Storage directories are writable and disk space is adequate.', $details);
    }

    private function tempFileProbe(): bool
    {
        $path = storage_path('app/meridian-diagnostics-probe-'.Str::random(12).'.tmp');

        try {
            if (@file_put_contents($path, 'probe') === false) {
                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            @unlink($path);
        }
    }

    /**
     * @return array{0: ?int, 1: ?int}
     */
    private function diskSpace(): array
    {
        try {
            $free = @disk_free_space(storage_path());
            $total = @disk_total_space(storage_path());

            return [
                $free === false ? null : (int) $free,
                $total === false ? null : (int) $total,
            ];
        } catch (Throwable) {
            return [null, null];
        }
    }
}
