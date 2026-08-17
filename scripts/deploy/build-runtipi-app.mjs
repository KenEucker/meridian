#!/usr/bin/env node
/**
 * Generate the Meridian Runtipi app from the root `package.json` (M19.28;
 * technical spec 26.4; deploy/runtipi/README.md is the agreed design).
 *
 * A Runtipi app is a directory in an app-store repository: `config.json`
 * describing the app and its install form, `docker-compose.yml` describing
 * the stack, and `metadata/` holding the store listing. Every version-bearing
 * line in those files — the app version and the image tags — derives from the
 * root `package.json`, which is why this is a generator rather than committed
 * app files: a published app maintained by hand can pin an image tag the code
 * has moved past, and that is exactly the failure the deployment bundle
 * validator already exists to prevent.
 *
 * The generated app runs the M19.2 deployment images unchanged, pulled from
 * GHCR (M19.26, amd64 only), behind Runtipi's Traefik in the M19.27
 * reverse-proxy deployment mode: Caddy serves the shared site body on :80
 * from Caddyfile.proxied, the seed-derived APP_KEY replaces the
 * `key:generate` step a one-click install does not have, and
 * MERIDIAN_TRUSTED_PROXIES lets Laravel read the forwarded scheme.
 *
 * Usage:
 *   node scripts/deploy/build-runtipi-app.mjs --out <store-checkout>/apps/meridian
 *   node scripts/deploy/build-runtipi-app.mjs --print config.json
 *
 * The output directory is an app directory in a store repository checkout
 * (deploy/runtipi/README.md, Path A2) — the folder name must equal the app id
 * `meridian`. Nothing is written into this repository: generated app files
 * are derived artifacts, regenerated from the release being published.
 */

import { copyFileSync, mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { meridianImageTag } from './build-images.mjs';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

/** The registry prefix M19.26 publishes both images under, amd64 only. */
export const REGISTRY_IMAGE = 'ghcr.io/keneucker/meridian-server';

/** The committed square logo the store listing serves (metadata/logo.jpg). */
export const LOGO_SOURCE = 'deploy/runtipi/metadata/logo.jpg';

/**
 * Runtipi's per-app release counter, derived from the root version so it is
 * monotonic without being maintained by hand: `tipi_version` must increase on
 * every app update for Runtipi to offer one, and major.minor.patch ordering
 * maps onto integers as long as minor and patch stay below 1000 — which the
 * versioning strategy's numeric major.minor.patch rule keeps true in practice.
 */
export function tipiVersion(version) {
  const [major, minor, patch] = version.split('.').map(Number);

  return major * 1_000_000 + minor * 1_000 + patch;
}

/**
 * The environment every server-side container receives. One list, expanded
 * per service with only MERIDIAN_CONTAINER_ROLE differing, so the worker and
 * scheduler cannot drift from the web container they decrypt for.
 */
function serverEnvironment(role) {
  return [
    ['MERIDIAN_CONTAINER_ROLE', role],
    ['MERIDIAN_NODE_ROLE', '${MERIDIAN_NODE_ROLE}'],
    ['MERIDIAN_NODE_NAME', '${MERIDIAN_NODE_NAME}'],
    // The entrypoint derives APP_KEY from the seed (M19.27); Runtipi's
    // `random` form field generates the seed once at install time.
    ['MERIDIAN_APP_KEY_SEED', '${MERIDIAN_APP_KEY_SEED}'],
    // Traefik terminates TLS one hop out; Laravel reads the forwarded scheme
    // from the platform's own private network (M19.27).
    ['MERIDIAN_TRUSTED_PROXIES', '"*"'],
    ['APP_NAME', 'Meridian'],
    ['APP_ENV', 'production'],
    ['APP_DEBUG', '"false"'],
    // Runtipi injects both. Exposed with a domain this is https://<domain>,
    // which is what event mode requires (technical spec 8.2, 8.6).
    ['APP_URL', '${APP_PROTOCOL}://${APP_DOMAIN}'],
    ['DB_CONNECTION', 'pgsql'],
    ['DB_HOST', 'meridian-db'],
    ['DB_PORT', '"5432"'],
    ['DB_DATABASE', 'meridian'],
    ['DB_USERNAME', 'meridian'],
    ['DB_PASSWORD', '${MERIDIAN_DB_PASSWORD}'],
    ['SESSION_DRIVER', 'database'],
    ['CACHE_STORE', 'database'],
    ['QUEUE_CONNECTION', 'database'],
    ['SESSION_SECURE_COOKIE', '"true"'],
    ['MAIL_MAILER', 'smtp'],
    ['MAIL_HOST', '${MERIDIAN_MAIL_HOST}'],
    ['MAIL_PORT', '${MERIDIAN_MAIL_PORT}'],
    ['MAIL_USERNAME', '${MERIDIAN_MAIL_USERNAME}'],
    ['MAIL_PASSWORD', '${MERIDIAN_MAIL_PASSWORD}'],
    ['MAIL_FROM_ADDRESS', '${MERIDIAN_MAIL_FROM}'],
    ['MAIL_FROM_NAME', 'Meridian'],
    ['LOG_CHANNEL', 'stderr'],
    ['LOG_LEVEL', 'warning'],
  ];
}

function environmentBlock(role, indent) {
  return serverEnvironment(role)
    .map(([key, value]) => `${indent}${key}: ${value}`)
    .join('\n');
}

/** The app's `config.json`, as an object (deploy/runtipi/README.md). */
export function runtipiConfig(root = repositoryRoot) {
  const version = meridianImageTag(root);

  return {
    $schema: '../schema.json',
    name: 'Meridian',
    id: 'meridian',
    available: true,
    short_desc: 'Open-source volunteer operations platform for events.',
    author: 'Ken Eucker',
    port: 8390,
    categories: ['utilities'],
    description: 'See metadata/description.md',
    tipi_version: tipiVersion(version),
    version,
    source: 'https://github.com/KenEucker/meridian',
    website: 'https://github.com/KenEucker/meridian',
    exposable: true,
    // The load-bearing line: any non-development node refuses plain HTTP
    // (EventModeGuard), so an app installable at http://<ip>:<port> would
    // install cleanly and then answer 503 to everything. Requiring a domain
    // makes ${APP_PROTOCOL} resolve to https and the node start.
    force_expose: true,
    dynamic_config: true,
    // amd64 only, decided in deploy/runtipi/README.md: declaring it makes
    // Runtipi hide the app on hardware it cannot run on rather than offering
    // an install that fails at first start.
    supported_architectures: ['amd64'],
    form_fields: [
      {
        type: 'random',
        label: 'Application key seed',
        hint: 'Generated once. Changing it makes every existing session and encrypted value unreadable.',
        min: 64,
        encoding: 'hex',
        env_variable: 'MERIDIAN_APP_KEY_SEED',
        required: true,
      },
      {
        type: 'random',
        label: 'Database password',
        min: 32,
        encoding: 'hex',
        env_variable: 'MERIDIAN_DB_PASSWORD',
        required: true,
      },
      {
        type: 'text',
        label: 'Node name',
        placeholder: 'Signal Camp Standalone',
        env_variable: 'MERIDIAN_NODE_NAME',
        required: true,
      },
      {
        type: 'text',
        label: 'Node role',
        hint: 'standalone, central, or onsite',
        default: 'standalone',
        options: [
          { label: 'Standalone', value: 'standalone' },
          { label: 'Central', value: 'central' },
          { label: 'On-site (event node)', value: 'onsite' },
        ],
        env_variable: 'MERIDIAN_NODE_ROLE',
        required: true,
      },
      // SMTP is required rather than optional: Meridian's login is a mailed
      // code, so a node that cannot send mail is a node nobody can sign in to.
      { type: 'fqdn', label: 'SMTP host', env_variable: 'MERIDIAN_MAIL_HOST', required: true },
      { type: 'number', label: 'SMTP port', default: '587', env_variable: 'MERIDIAN_MAIL_PORT', required: true },
      { type: 'text', label: 'SMTP username', env_variable: 'MERIDIAN_MAIL_USERNAME', required: false },
      { type: 'password', label: 'SMTP password', env_variable: 'MERIDIAN_MAIL_PASSWORD', required: false },
      { type: 'email', label: 'Send mail from', env_variable: 'MERIDIAN_MAIL_FROM', required: true },
    ],
  };
}

/** The app's `docker-compose.yml` (deploy/runtipi/README.md). */
export function runtipiCompose(root = repositoryRoot) {
  const version = meridianImageTag(root);

  return `# Generated by scripts/deploy/build-runtipi-app.mjs — do not edit by hand.
# The Meridian ${version} deployment images (M19.2), pulled from GHCR (M19.26),
# behind Runtipi's Traefik in the reverse-proxy deployment mode (M19.27).
services:
  meridian-web:
    image: ${REGISTRY_IMAGE}-web:${version}
    restart: unless-stopped
    # Caddy on :80 with no TLS: Traefik terminates it one hop out.
    command: ["run", "--config", "/etc/caddy/Caddyfile.proxied", "--adapter", "caddyfile"]
    environment:
      MERIDIAN_SERVER_UPSTREAM: meridian-server:9000
    depends_on:
      meridian-server:
        condition: service_healthy
    # No \`ports:\`. Runtipi maps \${APP_PORT} to internal_port itself.
    x-runtipi:
      is_main: true
      internal_port: 80

  meridian-server:
    image: ${REGISTRY_IMAGE}:${version}
    restart: unless-stopped
    environment:
${environmentBlock('web', '      ')}
    volumes:
      - \${APP_DATA_DIR}/storage:/var/www/meridian/apps/server/storage
    depends_on:
      meridian-db:
        condition: service_healthy
    healthcheck:
      test: ["CMD-SHELL", "php artisan db:show --quiet"]
      interval: 30s
      timeout: 10s
      retries: 5
      start_period: 60s

  # The worker and scheduler are not optional. Without the worker, magic-link
  # login mail is queued and never sent; without the scheduler, on-site never
  # pushes back to central.
  meridian-worker:
    image: ${REGISTRY_IMAGE}:${version}
    restart: unless-stopped
    command: ["php", "artisan", "queue:work", "--queue=notifications,default", "--tries=3", "--max-time=3600"]
    environment:
${environmentBlock('worker', '      ')}
    volumes:
      - \${APP_DATA_DIR}/storage:/var/www/meridian/apps/server/storage
    depends_on:
      meridian-server:
        condition: service_healthy

  meridian-scheduler:
    image: ${REGISTRY_IMAGE}:${version}
    restart: unless-stopped
    command: ["php", "artisan", "schedule:work"]
    environment:
${environmentBlock('scheduler', '      ')}
    volumes:
      - \${APP_DATA_DIR}/storage:/var/www/meridian/apps/server/storage
    depends_on:
      meridian-server:
        condition: service_healthy

  meridian-db:
    image: postgres:18
    restart: unless-stopped
    # wal_level=logical keeps logical replication available for node-to-node
    # sync, exactly as the deployment stack starts it (technical spec 10).
    command:
      ["postgres", "-c", "wal_level=logical", "-c", "max_wal_senders=10", "-c", "max_replication_slots=10"]
    environment:
      POSTGRES_DB: meridian
      POSTGRES_USER: meridian
      POSTGRES_PASSWORD: \${MERIDIAN_DB_PASSWORD}
    volumes:
      - \${APP_DATA_DIR}/postgres:/var/lib/postgresql
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U meridian -d meridian"]
      interval: 10s
      timeout: 3s
      retries: 10
      start_period: 20s

x-runtipi:
  schema_version: 2
`;
}

/** The store listing (`metadata/description.md`). */
export function runtipiDescription(root = repositoryRoot) {
  const version = meridianImageTag(root);

  return `# Meridian

Meridian is an open-source volunteer operations platform for events: staff
intake, scheduling, attendance, incident management, and the reporting that
holds it together, built to keep working on a field network with no internet.

This app runs a complete Meridian node — the server, its PostgreSQL database,
a queue worker, and a scheduler — behind your Runtipi's own reverse proxy.
Currently packaged version: ${version}.

## Read this before installing

- **A domain is required.** Meridian refuses to serve over plain HTTP outside
  development, so this app must be installed exposed, with a domain. Runtipi's
  local-domain certificate works for a LAN-only install; browsers will warn
  until the certificate is trusted.
- **Working SMTP is required.** Signing in to Meridian is a code sent by
  email. A node that cannot send mail is a node nobody can sign in to, which
  is why the SMTP form fields are not optional.
- **amd64 only.** The Meridian images are published for amd64 and nothing
  else, so this app will not appear on a Raspberry Pi or any other ARM host.
- **Back up before updating.** A Meridian update runs its database migrations
  automatically at start, so pressing Update is also a schema migration. Take
  a backup first, every time.

## Backup and restore

Runtipi's app backup covers this app's data directory, which holds both the
PostgreSQL data and Meridian's uploaded files — but a database directory
copied while the server is running is not a dependable backup. Before an
update, either stop the app first and back it up cold, or take a live dump:

\`\`\`sh
docker exec meridian-db pg_dump -U meridian -Fc meridian > meridian.dump
\`\`\`

Restore by installing the app, stopping it, restoring the data directory (or
\`pg_restore\` for a dump), and starting it again.

## About the application key seed

The install form generates a random seed the node derives its encryption key
from, so the key survives updates, container recreation, and restores. The
seed is stored in the app's environment in cleartext — the same way every
Runtipi app stores its secrets — and anyone who can read it can decrypt this
node's sessions and encrypted configuration. Changing it makes every existing
session and encrypted value unreadable.

## Source and license

Meridian is AGPL-3.0-or-later. Source, documentation, and issue tracking:
<https://github.com/KenEucker/meridian>.
`;
}

/** Every generated file, path-relative to the app directory. */
export function buildRuntipiApp(root = repositoryRoot) {
  return {
    'config.json': `${JSON.stringify(runtipiConfig(root), null, 2)}\n`,
    'docker-compose.yml': runtipiCompose(root),
    'metadata/description.md': runtipiDescription(root),
  };
}

/** Write the app directory, including the committed logo. */
export function writeRuntipiApp(outDir, root = repositoryRoot) {
  const files = buildRuntipiApp(root);

  for (const [relative, content] of Object.entries(files)) {
    const target = join(outDir, relative);

    mkdirSync(dirname(target), { recursive: true });
    writeFileSync(target, content);
  }

  copyFileSync(join(root, LOGO_SOURCE), join(outDir, 'metadata', 'logo.jpg'));

  return [...Object.keys(files), 'metadata/logo.jpg'];
}

function main() {
  const args = process.argv.slice(2);

  if (args.includes('--print')) {
    const name = args[args.indexOf('--print') + 1];
    const files = buildRuntipiApp();

    if (!files[name]) {
      console.error(`Unknown file '${name}'. Known files: ${Object.keys(files).join(', ')}.`);

      return 1;
    }

    process.stdout.write(files[name]);

    return 0;
  }

  const outIndex = args.indexOf('--out');

  if (outIndex === -1 || !args[outIndex + 1]) {
    console.error('Usage: node scripts/deploy/build-runtipi-app.mjs --out <store-checkout>/apps/meridian');
    console.error('       node scripts/deploy/build-runtipi-app.mjs --print <config.json|docker-compose.yml|metadata/description.md>');

    return 1;
  }

  const outDir = resolve(args[outIndex + 1]);
  const written = writeRuntipiApp(outDir);
  const version = meridianImageTag(repositoryRoot);

  console.log(`Wrote the Meridian ${version} Runtipi app (tipi_version ${tipiVersion(version)}) to ${outDir}:`);

  for (const file of written) {
    console.log(`  ${file}`);
  }

  console.log('\nThe app pulls ghcr.io images published by the tagged release workflow;');
  console.log(`version ${version} must be a published release for the install to pull.`);

  return 0;
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  process.exit(main());
}
