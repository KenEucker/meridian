<?php

declare(strict_types=1);

namespace App\Services\Organizations;

/**
 * Reads organization addressing off a request host (M19.8; ORG-022 through
 * ORG-025; technical spec 8.7).
 *
 * Organizations are addressable two ways on the same deployment: a path from
 * the deployment root formed from the organization slug, and an organization
 * subdomain of the deployment domain formed from the same slug. This class
 * answers the one question the subdomain form adds: is this request host
 * `<organization-slug>.<platform host>`?
 *
 * The platform domain is derived from the configured application URL's host —
 * no separate configuration key exists (technical spec 8.7). Derivation rather
 * than configuration is what keeps the two from disagreeing, and it is read
 * lazily on every call because the application URL is itself read lazily by
 * everything else that validates it (EventModeGuard, secret safeguards), and a
 * value baked at boot would be one the tests and the config override store
 * could not reach.
 *
 * Exactly one label counts. `northwood.meridian-vop.com` is organization
 * addressing; `a.b.meridian-vop.com` and every host that is not under the
 * platform host at all fall through to path resolution, which is the rule the
 * spec states — "any other host falls through to path resolution". No labels
 * are reserved: `www` is a slug like any other here, and operational hostnames
 * are the deployment's own DNS concern rather than the application's.
 */
class OrganizationHost
{
    /**
     * A label that could be an organization slug. Anything else — an ACME
     * challenge label, an underscore record name — is not organization
     * addressing and falls through.
     */
    private const SLUG_LABEL = '/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/';

    public function platformHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? strtolower($host) : null;
    }

    /**
     * The organization slug this host addresses, or null when the host is not
     * an organization subdomain of the platform host.
     */
    public function organizationSlug(string $requestHost): ?string
    {
        $platformHost = $this->platformHost();

        if ($platformHost === null) {
            return null;
        }

        $host = strtolower($requestHost);
        $suffix = '.'.$platformHost;

        if ($host === $platformHost || ! str_ends_with($host, $suffix)) {
            return null;
        }

        $label = substr($host, 0, -strlen($suffix));

        if ($label === '' || str_contains($label, '.')) {
            return null;
        }

        return preg_match(self::SLUG_LABEL, $label) === 1 ? $label : null;
    }
}
