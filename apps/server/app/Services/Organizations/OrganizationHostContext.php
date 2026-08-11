<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\Organization;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The organization the current request's host addresses, when it addresses one
 * (M19.8; ORG-023, ORG-024; technical spec 8.7).
 *
 * Stored on the request by {@see \App\Http\Middleware\ResolveOrganizationHost}
 * and read back through the container's current request here, rather than held
 * in this object, because the framework caches resolved controllers on their
 * routes: an instance injected once would keep answering for the request it
 * was built during. Reading the current request keeps the answer true however
 * long the consumer lives — across requests in one test process, and under a
 * long-running application server.
 *
 * A request to the deployment root, to an on-site node's event hostname, or
 * from anywhere that is not HTTP at all (console, queue) reads null, which is
 * the honest answer: no host named an organization. The middleware has already
 * refused a subdomain that matches no active organization, so a non-null
 * answer always carries an active organization.
 */
class OrganizationHostContext
{
    public const REQUEST_ATTRIBUTE = 'meridian.organization_host';

    public function __construct(private readonly Application $app) {}

    public function organization(): ?Organization
    {
        if (! $this->app->bound('request')) {
            return null;
        }

        $request = $this->app->make('request');

        if (! $request instanceof Request) {
            return null;
        }

        $organization = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $organization instanceof Organization ? $organization : null;
    }

    public function isOrganizationHost(): bool
    {
        return $this->organization() !== null;
    }
}
