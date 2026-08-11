<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\Organizations\OrganizationHostContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps an organization-addressed route on the host that may serve it (M19.8;
 * ORG-024; technical spec 8.7).
 *
 * Two refusals, both 404, on the routes that name an organization by slug:
 *
 * - A subdomain-form route (one whose route domain carries the organization)
 *   serves only when the request host actually resolved an organization. The
 *   route's domain pattern matches any two-label host, because the platform
 *   host is configuration the router cannot read at registration time; this is
 *   where `northwood.somewhere-else.example` — a host that is not under the
 *   platform domain and therefore falls through to path resolution — finds
 *   that these paths do not exist there. It is also what keeps a multi-label
 *   deployment root (`meridian.example.org`) from being read as a subdomain of
 *   its own parent domain.
 *
 * - On a host that did resolve an organization, a route naming a different
 *   organization is not found: an organization subdomain never serves another
 *   organization's content (ORG-024). The same organization's own path form
 *   still answers on its own subdomain — the slug segment is omitted there,
 *   not forbidden.
 *
 * Prioritized ahead of binding substitution (bootstrap/app.php), so the
 * refusal happens on the raw slug before any model resolves, and so the
 * `organizationHostSuffix` domain parameter can be forgotten in time: left in
 * place it would be handed to the controller positionally, and it would sit
 * between the organization and its child bindings, breaking the scoped
 * event-by-organization resolution the apply routes rely on.
 */
class EnforceOrganizationHostScope
{
    public function __construct(private readonly OrganizationHostContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $hostOrganization = $this->context->organization();

        if ($route?->getDomain() !== null) {
            abort_if($hostOrganization === null, 404);

            $route->forgetParameter('organizationHostSuffix');
        }

        if ($hostOrganization !== null) {
            $routeOrganization = $route?->parameter('organization');

            if ($routeOrganization instanceof Organization) {
                abort_unless($routeOrganization->is($hostOrganization), 404);
            } elseif (is_string($routeOrganization) && $routeOrganization !== '') {
                abort_unless($routeOrganization === $hostOrganization->slug, 404);
            }
        }

        return $next($request);
    }
}
