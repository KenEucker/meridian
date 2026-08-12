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
  'deploy/caddy/meridian.snippet',
  'deploy/caddy/README.md',
  'deploy/dns/onsite-dnsmasq.conf',
  'deploy/dns/onsite-hosts.example',
  'deploy/dns/README.md',
  '.dockerignore',
];
