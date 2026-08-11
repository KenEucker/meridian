<?php

declare(strict_types=1);

namespace App\Services\Organizations;

use App\Models\Organization;
use Illuminate\Support\Facades\Route;

/**
 * Host-aware URL generation for organization-scoped routes (M19.9; ORG-023,
 * ORG-024; technical spec 8.7).
 *
 * A visitor on an organization subdomain stays on it: a link generated for
 * that organization's own routes takes the subdomain form — the same host, the
 * slug segment omitted — rather than sending them back to the deployment
 * root's path form. Everywhere else the path form is generated exactly as
 * before: at the deployment root, on an on-site node's event hostname, and for
 * any organization other than the one the host resolved, whose content the
 * host would refuse to serve anyway (ORG-024).
 *
 * The mapping is by route name: a path-form route named `x` has its subdomain
 * form registered as `subdomain.x`, with the organization moved from the first
 * path segment into the route domain. The platform host the domain needs is
 * derived per request from the application URL, the same derivation resolution
 * uses (technical spec 8.7).
 */
class OrganizationHostUrls
{
    public function __construct(
        private readonly OrganizationHost $hosts,
        private readonly OrganizationHostContext $context,
    ) {}

    /**
     * @param  array<string, mixed>  $parameters  Route parameters, including
     *                                            `organization`.
     */
    public function route(string $name, array $parameters): string
    {
        $subdomainName = 'subdomain.'.$name;

        if ($this->linksToHostOrganization($parameters['organization'] ?? null)
            && Route::has($subdomainName)) {
            return route($subdomainName, [
                ...$parameters,
                'organizationHostSuffix' => $this->hosts->platformHost(),
            ]);
        }

        return route($name, $parameters);
    }

    private function linksToHostOrganization(mixed $organization): bool
    {
        $hostOrganization = $this->context->organization();

        if ($hostOrganization === null) {
            return false;
        }

        if ($organization instanceof Organization) {
            return $organization->is($hostOrganization);
        }

        return is_string($organization) && $organization === $hostOrganization->slug;
    }
}
