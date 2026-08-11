<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\Organizations\OrganizationHost;
use App\Services\Organizations\OrganizationHostContext;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the organization from an organization-slug subdomain of the
 * platform host (M19.8; ORG-022 through ORG-025; technical spec 8.7).
 *
 * Global, ahead of routing, because host resolution is a fact about the whole
 * request rather than about any one route: a subdomain that matches no active
 * organization slug is a not-found response for every path on it (ORG-024),
 * including paths that would exist at the deployment root. An archived
 * organization's slug is not an active slug, so archiving takes the subdomain
 * out of resolution the same way it takes the root path out.
 *
 * A host that resolves an organization stores it on the request, where
 * {@see \App\Services\Organizations\OrganizationHostContext} reads it for the
 * subdomain route group, the marketing surface exclusion, host-aware link
 * generation, and host-resolved branding. A host that is not organization
 * addressing at all (the deployment root, a multi-label name, a host under
 * some other domain) stores nothing and falls through to path resolution,
 * exactly as it did before subdomains existed.
 *
 * The lookup is skipped entirely for non-subdomain hosts, so the common case
 * costs no query. A query failure resolves as unknown: the one deployment
 * state where the organizations table is unreachable is a node being set up,
 * and setup happens at the deployment root.
 */
class ResolveOrganizationHost
{
    public function __construct(private readonly OrganizationHost $hosts) {}

    public function handle(Request $request, Closure $next): Response
    {
        $slug = $this->hosts->organizationSlug($request->getHost());

        if ($slug !== null) {
            $organization = $this->activeOrganization($slug);

            abort_if($organization === null, 404);

            $request->attributes->set(OrganizationHostContext::REQUEST_ATTRIBUTE, $organization);
        }

        return $next($request);
    }

    private function activeOrganization(string $slug): ?Organization
    {
        try {
            return Organization::query()
                ->active()
                ->where('slug', $slug)
                ->first();
        } catch (QueryException) {
            return null;
        }
    }
}
