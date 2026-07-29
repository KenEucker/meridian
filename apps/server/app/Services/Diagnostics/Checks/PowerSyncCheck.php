<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\EventMode\EventModeGuard;
use App\Services\PowerSync\PowerSyncHealthClient;

/**
 * PowerSync service reachability (SYS-033: sync category). Required only in
 * event mode, where the event-mode guard fails closed on the same probe
 * (technical spec 8.6, 26.2); elsewhere an unreachable PowerSync is a warning
 * for a development machine, not a failure.
 */
class PowerSyncCheck implements DiagnosticCheck
{
    public function __construct(
        private readonly PowerSyncHealthClient $powerSync,
        private readonly EventModeGuard $eventMode,
    ) {}

    public function key(): string
    {
        return 'sync.powersync';
    }

    public function label(): string
    {
        return 'PowerSync service';
    }

    public function category(): string
    {
        return DiagnosticCategory::SYNC;
    }

    public function required(): bool
    {
        return $this->eventMode->isEventMode();
    }

    public function run(): DiagnosticResult
    {
        $eventMode = $this->eventMode->isEventMode();
        $available = $this->powerSync->isAvailable();

        $details = [
            'event_mode' => $eventMode,
            'liveness_probe' => $available ? 'answered' : 'no answer',
        ];

        if ($available) {
            return DiagnosticResult::healthy('PowerSync answers its liveness probe.', $details);
        }

        if ($eventMode) {
            return DiagnosticResult::critical(
                'PowerSync does not answer its liveness probe, and this node is in event mode.',
                $details,
                'Start the PowerSync service; event mode fails closed without it.',
            );
        }

        return DiagnosticResult::warning(
            'PowerSync does not answer its liveness probe. Devices cannot sync until it does.',
            $details,
            'Start the PowerSync service if device sync is needed on this install.',
        );
    }
}
