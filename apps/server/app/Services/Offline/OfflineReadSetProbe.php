<?php

declare(strict_types=1);

namespace App\Services\Offline;

use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Throwable;

/**
 * Whether this node can serve the offline read set (ADR-0003; technical spec
 * 8.6, 9.1, 22A.8, 26.2).
 *
 * This replaces the PowerSync liveness probe as the thing event mode fails
 * closed on. The dependency it asserts is the real one: offline readiness now
 * means a device can fetch `GET /api/offline-read-set` from the node it is
 * pointed at, and that is served by this application rather than by a separate
 * container.
 *
 * Because the endpoint is in-process, the probe asks the two questions that can
 * actually be false on a running node — the route is registered, and the
 * composer behind it can be constructed — rather than issuing an HTTP request to
 * itself. A node that answers its own probe over the loopback interface has
 * proved that the web server is up, which is already true of the process running
 * the check, and would fail whenever a reverse proxy was in front of it.
 *
 * The probe composes nothing. Composition needs a caller, and a readiness check
 * has none; a check that had to invent one would be asserting a different fact
 * from the one it reports.
 */
class OfflineReadSetProbe
{
    /** Route name the client fetches the offline read set from. */
    public const ROUTE = 'api.offline-read-set';

    public function __construct(
        private readonly Router $router,
        private readonly Container $container,
    ) {}

    /**
     * Check that the offline read set is servable without changing any state.
     */
    public function isAvailable(): bool
    {
        if ($this->router->getRoutes()->getByName(self::ROUTE) === null) {
            return false;
        }

        try {
            $this->container->make(OfflineReadSetComposer::class);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
