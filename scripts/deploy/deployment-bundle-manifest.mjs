/**
 * The one statement of what the deployment configuration bundle ships.
 *
 * Two consumers read it: the bundle smoke test
 * (`scripts/deploy/validate-deployment-bundle.mjs`), which fails when a listed
 * file is missing from the repository, and the release packaging step
 * (`scripts/release/package-deployment-bundle.mjs`), which tars exactly this
 * list into the bundle artifact a tagged release attaches (M19.23, technical
 * spec 26.7). One list rather than two, because a file the smoke test requires
 * and the release forgets to ship is a bundle that validates and then cannot
 * be used.
 *
 * `deploy/native/` is on the list for the same reason as everything else on it:
 * a node whose host cannot run containers still needs an installation path, and
 * a bundle that shipped only the Compose stack would leave that operator with
 * nothing to unpack.
 */

/** Every file the bundle ships. A missing one is a bundle that cannot be used. */
export const DEPLOYMENT_BUNDLE_FILES = [
  'deploy/README.md',
  'deploy/docker/Dockerfile',
  'deploy/docker/entrypoint.sh',
  'deploy/docker/compose.deployment.yaml',
  'deploy/docker/.env.deployment.example',
  'deploy/docker/compose.yaml',
  'deploy/docker/.env.example',
  'deploy/docker/README.md',
  'deploy/caddy/Caddyfile',
  'deploy/caddy/Caddyfile.onsite',
  'deploy/caddy/Caddyfile.wildcard',
  'deploy/caddy/Caddyfile.proxied',
  'deploy/caddy/meridian.snippet',
  'deploy/caddy/README.md',
  'deploy/dns/onsite-dnsmasq.conf',
  'deploy/dns/onsite-hosts.example',
  'deploy/dns/README.md',
  'deploy/native/README.md',
  'deploy/native/install-host.sh',
  'deploy/native/deploy-release.sh',
  'deploy/native/preflight.php',
  'deploy/native/.env.server.example',
  'deploy/native/.env.proxy.example',
  'deploy/native/systemd/meridian-worker.service',
  'deploy/native/systemd/meridian-scheduler.service',
  'deploy/native/systemd/caddy-meridian.conf',
  'deploy/native/php/meridian-pool.conf',
  'deploy/native/php/meridian-opcache.ini',
  'deploy/native/php/meridian-runtime.ini',
  'deploy/native/postgres/meridian.conf',
  '.dockerignore',
];
