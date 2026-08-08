<?php

declare(strict_types=1);

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\DiagnosticCategory;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\Diagnostics\DiagnosticResult;
use App\Services\EventMode\EventModeGuard;
use App\Services\Offline\OfflineReadSetProbe;

/**
 * Offline read set availability (SYS-033: sync category; ADR-0003).
 *
 * The replacement for `sync.powersync`. Required only in event mode, where the
 * event-mode guard fails closed on the same probe (technical spec 8.6, 26.2);
 * elsewhere an unservable read set is a warning for a development machine, not
 * a failure.
 *
 * What this reports is narrower than what the PowerSync check claimed, and
 * honestly so: it says the node can serve the set, not that any device is
 * holding one. A node cannot see what a device has stored, and a check that
 * implied otherwise would be the same assertion of a dependency that does not
 * exist that ADR-0003 removed.
 */
class OfflineReadSetCheck implements DiagnosticCheck
{
    public function __construct(
        private readonly OfflineReadSetProbe $readSet,
        private readonly EventModeGuard $eventMode,
    ) {}

    public function key(): string
    {
        return 'sync.offline_read_set';
    }

    public function label(): string
    {
        return 'Offline read set';
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
        $available = $this->readSet->isAvailable();

        $details = [
            'event_mode' => $eventMode,
            'route' => OfflineReadSetProbe::ROUTE,
            'servable' => $available ? 'yes' : 'no',
        ];

        if ($available) {
            return DiagnosticResult::healthy(
                'This node can serve the offline read set devices cache from.',
                $details,
            );
        }

        if ($eventMode) {
            return DiagnosticResult::critical(
                'This node cannot serve the offline read set, and it is in event mode.',
                $details,
                'Check that the API routes are loaded and the application caches are not stale; event mode fails closed without the read set.',
            );
        }

        return DiagnosticResult::warning(
            'This node cannot serve the offline read set. Devices cannot cache anything to work offline with until it can.',
            $details,
            'Clear the route and configuration caches and confirm the API routes are loaded.',
        );
    }
}
