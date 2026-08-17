/**
 * The rules a deployment Caddyfile is held to, by kind (M19.27; technical
 * spec 8.2; deploy/runtipi/README.md).
 *
 * Meridian's secure connection policy is HTTPS-only in production and event
 * modes. That used to mean one rule — no deployment Caddyfile serves plain
 * HTTP — until the proxied deployment mode arrived: behind a TLS-terminating
 * proxy the stack does not own, Caddy serves plain HTTP on :80 *because* TLS
 * moved one hop out, not because it became optional. So the validator learns
 * the difference between the two kinds rather than loosening the rule:
 *
 *   - a TLS-terminating configuration (Caddyfile, Caddyfile.onsite,
 *     Caddyfile.wildcard) is still refused the moment it declares a plain-HTTP
 *     site address or switches automatic HTTPS off entirely;
 *   - a proxied configuration must declare `auto_https off`, serve `:80` and
 *     no hostname site, configure no TLS of its own, and believe forwarded
 *     headers only from private ranges, so a client on the open internet
 *     cannot hand it a forged scheme.
 *
 * Exported as data-in, errors-out functions so the rules are testable against
 * synthetic configurations (`caddyfile-rules.spec.mjs`), not only against the
 * four committed files.
 */

/** A configuration that obtains or serves certificates itself. */
export const TLS_TERMINATING = 'tls-terminating';

/** A configuration serving plain HTTP behind a proxy that terminates TLS. */
export const PROXIED = 'proxied';

/**
 * What a Caddyfile declares itself to be. `auto_https off` is the marker: it
 * is the one directive that renounces certificates entirely, which only the
 * proxied configuration may do. (`auto_https disable_certs`, which the
 * on-site file uses, still serves TLS — from a pre-provisioned certificate —
 * and is therefore TLS-terminating.)
 */
export function classifyCaddyfile(source) {
  return /^\s*auto_https\s+off\s*$/m.test(source) ? PROXIED : TLS_TERMINATING;
}

/**
 * Every finding against one Caddyfile, held to the rules of the kind it is
 * expected to be. Returns a list of error strings; empty means conforming.
 */
export function caddyfileErrors(name, source, expectedKind) {
  const errors = [];

  // Both kinds serve the one shared site body, so a header or a limit cannot
  // drift between them.
  if (!source.includes('import /etc/caddy/meridian.snippet')) {
    errors.push(
      `${name} does not import the shared Meridian snippet, so its behavior can drift from the other Caddyfiles.`,
    );
  }

  if (!source.includes('import meridian-app')) {
    errors.push(`${name} does not use the (meridian-app) snippet.`);
  }

  if (expectedKind === TLS_TERMINATING) {
    if (classifyCaddyfile(source) !== TLS_TERMINATING) {
      errors.push(
        `${name} switches automatic HTTPS off entirely (auto_https off), which only the proxied configuration may do. A TLS-terminating configuration obtains or serves its certificate (technical spec 8.2).`,
      );
    }

    // A site address written as http:// tells Caddy to serve that site over
    // plain HTTP and skip TLS entirely.
    if (/^\s*http:\/\//m.test(source)) {
      errors.push(
        `${name} declares an http:// site address. Meridian never serves plain HTTP in production or event mode (technical spec 8.2); only the proxied configuration serves plain HTTP, and only because TLS terminates one hop out.`,
      );
    }

    return errors;
  }

  if (classifyCaddyfile(source) !== PROXIED) {
    errors.push(
      `${name} does not switch automatic HTTPS off (auto_https off). Behind a proxy that already owns TLS, obtaining certificates is a retry loop against a challenge that cannot succeed.`,
    );
  }

  if (!/^:80\s*\{\s*$/m.test(source)) {
    errors.push(`${name} does not serve :80, which is the only address a proxied node serves.`);
  }

  if (/^\s*tls\s/m.test(source)) {
    errors.push(`${name} configures TLS of its own, which belongs to the proxy in front of it.`);
  }

  if (source.includes('MERIDIAN_SITE_ADDRESS') || /^\s*https?:\/\//m.test(source)) {
    errors.push(
      `${name} declares a hostname or scheme site address. A proxied node serves :80 and lets the outer proxy own the name and the certificate.`,
    );
  }

  if (!/trusted_proxies\s+static\s+private_ranges/.test(source)) {
    errors.push(
      `${name} does not restrict forwarded-header trust to private ranges (servers > trusted_proxies), so a client on the open internet could hand it a forged forwarded scheme.`,
    );
  }

  return errors;
}
