#!/usr/bin/env node
/**
 * Build smoke test for the Meridian deployment configuration bundle.
 *
 * Technical spec section 4 counts the deployment configuration bundle as one of
 * the four distribution artifacts Meridian produces, alongside the server image,
 * the mobile package, and the desktop installer. The other three are exercised by
 * builds and test suites; this one is a tree of configuration files, and nothing
 * exercised it until this check existed.
 *
 * What it asserts is the set of things about the bundle that are cheap to get
 * wrong and expensive to discover at an event:
 *
 *   - every file the bundle's documentation promises is present;
 *   - base images are pinned, never floating on `latest`
 *     (docs/meridian-technology-baseline.md, Docker image rules);
 *   - the Compose stack builds the Dockerfile targets that actually exist;
 *   - the image tag is the root Meridian version and nothing else
 *     (technical spec 26.3; docs/process/versioning-strategy.md);
 *   - the deployment database publishes no host port;
 *   - the committed sample environment carries fake values and no secrets
 *     (technical spec 26.2), and is complete enough to render the stack;
 *   - no proxy configuration serves plain HTTP, and the shared site body tells
 *     browsers to refuse the plain-HTTP form (technical spec 8.2);
 *   - the event-node configuration does not depend on a certificate authority
 *     it cannot reach (technical spec 8.3, 8.6);
 *   - the entrypoint runs the server's event-mode fail-closed checks — HTTPS
 *     validation and the offline read set — after the caches it builds, so a
 *     node that fails them stops at start (technical spec 8.6, 26.2);
 *   - the DNS templates carry documentation names and private addresses only;
 *   - the containerless installation path in deploy/native/ still says what the
 *     Compose stack says: the same boot sequence in the same order, the same
 *     runtime versions, request limits, database settings and queues, no proxy
 *     configuration of its own, and a sample environment carrying no secrets.
 *
 * With --with-docker it additionally asks Docker itself: `docker build --check`
 * resolves and validates the Dockerfile without executing a build step, and
 * `docker compose config` renders both Compose files against the committed sample
 * environment, which is the assertion that the sample is genuinely sufficient.
 *
 * Those two are opt-in rather than automatic because they reach the network for
 * base image metadata and the BuildKit frontend. The checks above are hermetic and
 * belong in every run of the repository's checks; a registry that is rate-limiting
 * should not be able to fail a pull request that changed a Vue component. CI runs
 * the Docker half as its own step, where a registry failure is legible as one.
 *
 * Usage:
 *   node scripts/deploy/validate-deployment-bundle.mjs
 *   node scripts/deploy/validate-deployment-bundle.mjs --with-docker
 *   node scripts/deploy/validate-deployment-bundle.mjs --require-docker
 */

import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { IMAGE_TARGETS, meridianImageTag } from './build-images.mjs';
import { DEPLOYMENT_BUNDLE_FILES } from './deployment-bundle-manifest.mjs';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

const DOCKERFILE = 'deploy/docker/Dockerfile';
const DEPLOYMENT_COMPOSE = 'deploy/docker/compose.deployment.yaml';
const DATABASE_COMPOSE = 'deploy/docker/compose.yaml';
const DEPLOYMENT_ENV_EXAMPLE = 'deploy/docker/.env.deployment.example';
const DATABASE_ENV_EXAMPLE = 'deploy/docker/.env.example';
const CADDY_SNIPPET = 'deploy/caddy/meridian.snippet';
const CADDYFILE = 'deploy/caddy/Caddyfile';
const CADDYFILE_ONSITE = 'deploy/caddy/Caddyfile.onsite';
const CADDYFILE_WILDCARD = 'deploy/caddy/Caddyfile.wildcard';
const ENTRYPOINT = 'deploy/docker/entrypoint.sh';
const NATIVE_DIRECTORY = 'deploy/native';
const NATIVE_INSTALL = 'deploy/native/install-host.sh';
const NATIVE_RELEASE = 'deploy/native/deploy-release.sh';
const NATIVE_PREFLIGHT = 'deploy/native/preflight.php';
const NATIVE_SERVER_ENV_EXAMPLE = 'deploy/native/.env.server.example';
const NATIVE_PROXY_ENV_EXAMPLE = 'deploy/native/.env.proxy.example';
const NATIVE_CADDY_DROP_IN = 'deploy/native/systemd/caddy-meridian.conf';
const NATIVE_WORKER_UNIT = 'deploy/native/systemd/meridian-worker.service';
const NATIVE_SCHEDULER_UNIT = 'deploy/native/systemd/meridian-scheduler.service';
const NATIVE_RUNTIME_INI = 'deploy/native/php/meridian-runtime.ini';
const NATIVE_POSTGRES_CONF = 'deploy/native/postgres/meridian.conf';

/**
 * Every file the bundle ships, from the shared manifest the release packaging
 * step also tars (`scripts/deploy/deployment-bundle-manifest.mjs`). A missing
 * one is a bundle that cannot be used.
 */
const REQUIRED_FILES = DEPLOYMENT_BUNDLE_FILES;

/** Services the deployment stack cannot be complete without. */
const REQUIRED_SERVICES = ['postgres', 'server', 'worker', 'scheduler', 'web'];

/**
 * Environment keys the sample file must leave empty. Each is a secret, and a
 * sample that carried a working one would be a secret published to a public
 * repository and copied onto every node whose operator did not read the comments.
 */
const MUST_BE_EMPTY = ['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD', 'MAIL_USERNAME'];

const errors = [];
const notices = [];

function fail(message) {
  errors.push(message);
}

function read(relativePath) {
  return readFileSync(join(repositoryRoot, relativePath), 'utf8');
}

/**
 * Split a Compose file into its top-level service blocks.
 *
 * A YAML parser would be better and would be a new dependency for one check, so
 * this reads the two-space service headers under `services:` instead. It is
 * enough for the questions asked here, all of which are about whether a key
 * appears inside one named service.
 */
function serviceBlocks(source) {
  const lines = source.split(/\r?\n/);
  const blocks = new Map();
  let inServices = false;
  let current = null;

  for (const line of lines) {
    if (/^services:\s*$/.test(line)) {
      inServices = true;
      continue;
    }

    if (!inServices) {
      continue;
    }

    if (/^\S/.test(line)) {
      // A new top-level key ends the services mapping.
      inServices = false;
      current = null;
      continue;
    }

    const header = line.match(/^ {2}([A-Za-z0-9_-]+):/);

    if (header) {
      current = header[1];
      blocks.set(current, []);
      continue;
    }

    if (current) {
      blocks.get(current).push(line);
    }
  }

  return new Map([...blocks].map(([name, body]) => [name, body.join('\n')]));
}

/** Read a KEY=value environment file into a Map, ignoring comments. */
function parseEnvFile(source) {
  const values = new Map();

  for (const line of source.split(/\r?\n/)) {
    const match = line.match(/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/);

    if (match) {
      values.set(match[1], match[2].trim());
    }
  }

  return values;
}

function checkRequiredFiles() {
  for (const file of REQUIRED_FILES) {
    if (!existsSync(join(repositoryRoot, file))) {
      fail(`Missing bundle file ${file}.`);
    }
  }
}

function checkDockerfile() {
  const source = read(DOCKERFILE);

  const fromLines = [...source.matchAll(/^FROM\s+(\S+)(?:\s+AS\s+(\S+))?/gim)];

  if (fromLines.length === 0) {
    fail(`${DOCKERFILE} declares no build stages.`);

    return;
  }

  for (const [, image] of fromLines) {
    // A stage may build on an earlier stage by name, which carries no tag.
    const isStageReference = fromLines.some(([, , stage]) => stage === image);

    if (isStageReference) {
      continue;
    }

    if (!image.includes(':')) {
      fail(`${DOCKERFILE} uses the untagged base image '${image}'. Pin an explicit tag.`);
      continue;
    }

    if (image.endsWith(':latest')) {
      fail(
        `${DOCKERFILE} pins '${image}' to 'latest'. The technology baseline requires explicit major/minor tags or digests.`,
      );
    }
  }

  const stages = fromLines.map(([, , stage]) => stage).filter(Boolean);

  for (const { target } of IMAGE_TARGETS) {
    if (!stages.includes(target)) {
      fail(`${DOCKERFILE} declares no '${target}' stage, which the deployment stack builds.`);
    }
  }

  // The server image serves the Admin artifact and only it (technical spec 4).
  if (!source.includes('dist/admin')) {
    fail(`${DOCKERFILE} never copies the Meridian Admin client artifact into the server image.`);
  }

  // The server reads its build version and its license from the root manifest at
  // `base_path('../..')` and throws when it is absent, so an image that does not
  // carry it fails while Composer is still discovering packages. Asserted here
  // because the failure is invisible until something actually builds the image.
  if (!/COPY\s+package\.json\s+\S+package\.json/.test(source)) {
    fail(
      `${DOCKERFILE} does not copy the root package.json into the server image. App\\Support\\RootPackageVersion and RootPackageLicense read the version and license from it and throw without it.`,
    );
  }

  // And it has to land at the depth those resolvers look at, which is what the
  // mirrored monorepo layout is for.
  const appRoot = (source.match(/^WORKDIR\s+(\S+)\/apps\/server\s*$/m) ?? [])[1];

  if (!appRoot) {
    fail(
      `${DOCKERFILE} does not place the server at <root>/apps/server. The server resolves the root manifest and the client artifact relative to that depth.`,
    );
  } else if (!source.includes(`COPY package.json ${appRoot}/package.json`)) {
    fail(
      `${DOCKERFILE} copies the root package.json somewhere other than ${appRoot}/package.json, which is where the server resolves it from.`,
    );
  } else if (!source.includes(`${appRoot}/apps/client/dist/admin`)) {
    fail(
      `${DOCKERFILE} does not place the client artifact at ${appRoot}/apps/client/dist/admin, which is where config/meridian.php resolves it from.`,
    );
  }

  for (const packaged of ['dist/field', 'dist/kiosk']) {
    if (source.includes(packaged)) {
      fail(
        `${DOCKERFILE} copies ${packaged} into a server image. The Field and Kiosk artifacts are packaged into the mobile and Electron installers instead (technical spec 4).`,
      );
    }
  }
}

function checkDeploymentCompose() {
  const source = read(DEPLOYMENT_COMPOSE);
  const services = serviceBlocks(source);

  for (const service of REQUIRED_SERVICES) {
    if (!services.has(service)) {
      fail(`${DEPLOYMENT_COMPOSE} declares no '${service}' service.`);
    }
  }

  // Every build target named in the stack has to exist in the Dockerfile.
  const dockerfile = read(DOCKERFILE);
  const declaredStages = [...dockerfile.matchAll(/^FROM\s+\S+\s+AS\s+(\S+)/gim)].map(
    ([, stage]) => stage,
  );

  for (const [, target] of source.matchAll(/^\s+target:\s*(\S+)\s*$/gm)) {
    if (!declaredStages.includes(target)) {
      fail(`${DEPLOYMENT_COMPOSE} builds Dockerfile target '${target}', which ${DOCKERFILE} does not declare.`);
    }
  }

  // The tag is required rather than defaulted, so a stack cannot silently run
  // whatever image happens to be tagged locally.
  if (!/MERIDIAN_IMAGE_TAG:\?/.test(source)) {
    fail(
      `${DEPLOYMENT_COMPOSE} does not require MERIDIAN_IMAGE_TAG. A deployment must state the version it runs (technical spec 26.3).`,
    );
  }

  if (/:latest/.test(source)) {
    fail(`${DEPLOYMENT_COMPOSE} references a 'latest' tag.`);
  }

  const postgres = services.get('postgres') ?? '';

  if (/^\s+ports:/m.test(postgres)) {
    fail(
      `${DEPLOYMENT_COMPOSE} publishes a host port for the deployment database. It should be reachable only from the stack's network.`,
    );
  }

  if (!/POSTGRES_PASSWORD:\s*\$\{DB_PASSWORD:\?/.test(postgres)) {
    fail(
      `${DEPLOYMENT_COMPOSE} lets the deployment database fall back to a default password. Require DB_PASSWORD instead (technical spec 26.2).`,
    );
  }

  if (!/required:\s*true/.test(services.get('server') ?? '')) {
    fail(`${DEPLOYMENT_COMPOSE} does not require the deployment environment file for the server.`);
  }

  // The two Compose files must agree on the PostgreSQL major version. A
  // deployment tested against one major and run against another is a migration
  // surprise nobody chose.
  const deploymentImage = postgres.match(/image:\s*(postgres:\S+)/);
  const databaseImage = read(DATABASE_COMPOSE).match(/image:\s*(postgres:\S+)/);

  if (!deploymentImage || !databaseImage) {
    fail('Could not read the PostgreSQL image from both Compose files.');
  } else if (deploymentImage[1] !== databaseImage[1]) {
    fail(
      `${DEPLOYMENT_COMPOSE} runs ${deploymentImage[1]} while ${DATABASE_COMPOSE} runs ${databaseImage[1]}. Development and deployment must share the database major version.`,
    );
  }
}

function checkDeploymentEnvExample() {
  const source = read(DEPLOYMENT_ENV_EXAMPLE);
  const values = parseEnvFile(source);
  const compose = read(DEPLOYMENT_COMPOSE);

  // Everything the stack requires has to be present in the sample, or the sample
  // cannot start the stack it documents.
  for (const [, key] of compose.matchAll(/\$\{([A-Z0-9_]+):\?/g)) {
    if (!values.has(key)) {
      fail(`${DEPLOYMENT_ENV_EXAMPLE} does not document ${key}, which ${DEPLOYMENT_COMPOSE} requires.`);
    }
  }

  for (const key of MUST_BE_EMPTY) {
    if (!values.has(key)) {
      fail(`${DEPLOYMENT_ENV_EXAMPLE} does not document ${key}.`);
      continue;
    }

    if (values.get(key) !== '') {
      fail(
        `${DEPLOYMENT_ENV_EXAMPLE} ships a value for ${key}. Secrets in a sample configuration must be empty (technical spec 26.2).`,
      );
    }
  }

  // Sample hostnames must be documentation names. A real one invites a node to be
  // deployed pointing at somebody else's domain.
  const appUrl = values.get('APP_URL') ?? '';

  if (!appUrl.startsWith('https://')) {
    fail(
      `${DEPLOYMENT_ENV_EXAMPLE} sets APP_URL to '${appUrl}'. Production and event modes never use plain HTTP (technical spec 8.2).`,
    );
  }

  if (!/\.example\.(org|com|net)(\/|$)/.test(appUrl)) {
    fail(`${DEPLOYMENT_ENV_EXAMPLE} sets APP_URL to '${appUrl}', which is not a documentation domain.`);
  }

  if (values.get('APP_ENV') === 'local') {
    fail(`${DEPLOYMENT_ENV_EXAMPLE} sets APP_ENV=local, which disables the event-mode safeguards.`);
  }

  if (values.get('APP_DEBUG') !== 'false') {
    fail(`${DEPLOYMENT_ENV_EXAMPLE} must set APP_DEBUG=false.`);
  }

  if (values.get('MERIDIAN_NODE_ROLE') === 'development') {
    fail(
      `${DEPLOYMENT_ENV_EXAMPLE} sets MERIDIAN_NODE_ROLE=development, which is not a deployment role (technical spec 26.1).`,
    );
  }

  if (values.get('SESSION_DOMAIN') !== '') {
    fail(
      `${DEPLOYMENT_ENV_EXAMPLE} sets SESSION_DOMAIN. A host-only cookie is what keeps organization subdomains isolated from one another (technical spec 8.7).`,
    );
  }

  // The wildcard proxy configuration reads these; a sample that does not carry
  // them leaves the operator to discover the names inside a Caddyfile.
  for (const key of ['MERIDIAN_WILDCARD_TLS_CERTIFICATE', 'MERIDIAN_WILDCARD_TLS_KEY']) {
    if (!values.has(key)) {
      fail(`${DEPLOYMENT_ENV_EXAMPLE} does not document ${key}, which ${CADDYFILE_WILDCARD} serves the wildcard host from.`);
    }
  }

  // And the stack has to hand them to the proxy container, or setting them in
  // the env file does nothing.
  const web = serviceBlocks(compose).get('web') ?? '';

  for (const key of ['MERIDIAN_WILDCARD_TLS_CERTIFICATE', 'MERIDIAN_WILDCARD_TLS_KEY']) {
    if (!web.includes(key)) {
      fail(`${DEPLOYMENT_COMPOSE} does not pass ${key} to the web service.`);
    }
  }
}

function checkImageTag() {
  const tag = meridianImageTag(repositoryRoot);

  if (!/^\d+\.\d+\.\d+$/.test(tag)) {
    fail(
      `The root package.json version '${tag}' is not a numeric major.minor.patch value, so it cannot be a Docker image tag (docs/process/versioning-strategy.md).`,
    );
  }

  const manifests = ['apps/client/package.json', 'apps/kiosk/package.json', 'apps/mobile/package.json'];

  for (const manifest of manifests) {
    const parsed = JSON.parse(read(manifest));

    if (parsed.version !== undefined) {
      fail(
        `${manifest} declares its own version. The root package.json version is the only Meridian product version, and the image tag reads it.`,
      );
    }
  }
}

function checkCaddyConfiguration() {
  const snippet = read(CADDY_SNIPPET);

  if (!/\(meridian-app\)\s*\{/.test(snippet)) {
    fail(`${CADDY_SNIPPET} does not define the (meridian-app) snippet.`);
  }

  if (!snippet.includes('php_fastcgi')) {
    fail(`${CADDY_SNIPPET} does not proxy PHP to the server container.`);
  }

  for (const file of [CADDYFILE, CADDYFILE_ONSITE, CADDYFILE_WILDCARD]) {
    const source = read(file);

    if (!source.includes('import /etc/caddy/meridian.snippet')) {
      fail(`${file} does not import the shared Meridian snippet, so its behavior can drift from the other Caddyfiles.`);
    }

    if (!source.includes('import meridian-app')) {
      fail(`${file} does not use the (meridian-app) snippet.`);
    }

    // A site address written as http:// tells Caddy to serve that site over plain
    // HTTP and skip TLS entirely.
    if (/^\s*http:\/\//m.test(source)) {
      fail(`${file} declares an http:// site address. Meridian never serves plain HTTP in production or event mode (technical spec 8.2).`);
    }
  }

  // The policy is HTTPS-only, not HTTPS-mostly: the shared site body has to
  // tell browsers to refuse the plain-HTTP form of the site as well as never
  // serving it (technical spec 8.2). In the snippet rather than per-file, so
  // neither Caddyfile can lose it alone.
  if (!snippet.includes('Strict-Transport-Security')) {
    fail(
      `${CADDY_SNIPPET} does not send Strict-Transport-Security. Meridian is HTTPS-only in production and event modes (technical spec 8.2).`,
    );
  }

  const onsite = read(CADDYFILE_ONSITE);

  if (!/auto_https\s+disable_certs/.test(onsite)) {
    fail(
      `${CADDYFILE_ONSITE} does not disable automatic certificate issuance. An event network cannot answer an ACME challenge (technical spec 8.3, 8.6).`,
    );
  }

  if (!/tls\s+\{\$MERIDIAN_TLS_CERTIFICATE\}/.test(onsite)) {
    fail(`${CADDYFILE_ONSITE} does not serve the certificate provisioned before the event.`);
  }

  if (/auto_https\s+disable_certs/.test(read(CADDYFILE))) {
    fail(`${CADDYFILE} disables automatic certificate issuance, which is the only way an internet-reachable node gets one.`);
  }

  // The wildcard configuration serves organization subdomains beside the
  // deployment root (technical spec 8.7): the root site keeps ACME — so it must
  // not disable issuance — and the wildcard host serves the pre-provisioned
  // wildcard certificate, because a CA issues a wildcard only against a DNS-01
  // challenge the stock proxy image cannot answer.
  const wildcard = read(CADDYFILE_WILDCARD);

  if (!/^\*\.\{\$MERIDIAN_SITE_ADDRESS\}\s*\{/m.test(wildcard)) {
    fail(
      `${CADDYFILE_WILDCARD} does not declare the wildcard site *.{$MERIDIAN_SITE_ADDRESS}, which is what serves organization subdomains (technical spec 8.7).`,
    );
  }

  if (!/^\{\$MERIDIAN_SITE_ADDRESS\}\s*\{/m.test(wildcard)) {
    fail(`${CADDYFILE_WILDCARD} does not serve the deployment root beside the wildcard host.`);
  }

  if (!/tls\s+\{\$MERIDIAN_WILDCARD_TLS_CERTIFICATE\}\s+\{\$MERIDIAN_WILDCARD_TLS_KEY\}/.test(wildcard)) {
    fail(
      `${CADDYFILE_WILDCARD} does not serve the pre-provisioned wildcard certificate for the wildcard host (technical spec 8.7).`,
    );
  }

  if (/auto_https\s+disable_certs/.test(wildcard)) {
    fail(`${CADDYFILE_WILDCARD} disables automatic certificate issuance, which the deployment-root site still needs.`);
  }

  // Whether a Caddyfile actually parses is a question only Caddy can answer, and
  // the `web` image build asks it. This asserts the build still does: without that
  // step a malformed proxy configuration ships and is discovered when a container
  // will not start, which on an event network is the worst possible moment.
  const dockerfile = read(DOCKERFILE);

  if (!/caddy adapt/.test(dockerfile)) {
    fail(
      `${DOCKERFILE} does not run \`caddy adapt\` over the proxy configuration, so nothing checks that the Caddyfiles parse.`,
    );
  }

  for (const file of [CADDYFILE, CADDYFILE_ONSITE, CADDYFILE_WILDCARD]) {
    const name = file.split('/').pop();

    if (!dockerfile.includes(`/etc/caddy/${name}`)) {
      fail(`${DOCKERFILE} does not adapt /etc/caddy/${name}, so that configuration is never parsed at build time.`);
    }
  }
}

/**
 * The deployment documentation half of the wildcard host handling (technical
 * spec 8, 8.7, 26): the bundle's own READMEs have to say how the subdomain
 * form is served — the wildcard DNS record, the wildcard Caddyfile and its
 * certificate, and the `*.localhost` development shape — because a wildcard
 * that exists only as configuration is a wildcard the next operator deletes.
 */
function checkSubdomainDocumentation() {
  const caddyReadme = read('deploy/caddy/README.md');
  const dnsReadme = read('deploy/dns/README.md');

  if (!caddyReadme.includes('Caddyfile.wildcard')) {
    fail('deploy/caddy/README.md does not document Caddyfile.wildcard, the configuration that serves organization subdomains.');
  }

  if (!caddyReadme.includes('MERIDIAN_WILDCARD_TLS_CERTIFICATE')) {
    fail('deploy/caddy/README.md does not document the wildcard certificate variables.');
  }

  if (!/\*\.<deployment-domain>|\*\.meridian\.example\.org/.test(dnsReadme)) {
    fail('deploy/dns/README.md does not document the wildcard DNS record for organization subdomains (technical spec 8.7).');
  }

  for (const [file, source] of [['deploy/caddy/README.md', caddyReadme], ['deploy/dns/README.md', dnsReadme]]) {
    if (!source.includes('*.localhost')) {
      fail(`${file} does not document the \`*.localhost\` development shape (technical spec 8.7).`);
    }
  }
}

function checkDnsTemplates() {
  const files = ['deploy/dns/onsite-dnsmasq.conf', 'deploy/dns/onsite-hosts.example'];

  for (const file of files) {
    const source = read(file);
    const addresses = source.match(/\b\d{1,3}(?:\.\d{1,3}){3}\b/g) ?? [];

    for (const address of addresses) {
      const isPrivate =
        /^10\./.test(address) ||
        /^192\.168\./.test(address) ||
        /^172\.(1[6-9]|2\d|3[01])\./.test(address) ||
        address === '0.0.0.0';

      if (!isPrivate) {
        fail(`${file} contains the routable address ${address}. On-site templates must use private addresses only.`);
      }
    }

    const hostnames = source.match(/\b[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:org|com|net)\b/g) ?? [];

    for (const hostname of hostnames) {
      // `example.org` and any name under it are reserved for documentation
      // (RFC 2606), so they are the only names a sample may carry.
      if (!/(^|\.)example\.(org|com|net)$/.test(hostname)) {
        fail(`${file} names the real hostname ${hostname}. Sample configuration carries fake values only (technical spec 26.2).`);
      }
    }
  }
}

/**
 * The once-per-boot sequence a node runs before it serves traffic, wherever it
 * is written down.
 *
 * Two files implement it: the container entrypoint, and the native release
 * script for a host that cannot run containers. The order is the substance
 * rather than the wording — a warning that arrives with the result is a note,
 * secrets generated after the config cache are secrets the served requests
 * never see, and event-mode checks run before that cache validate a
 * configuration nothing will read. Asserted for both, so the second path cannot
 * quietly lose a step the first one has.
 */
function checkBootSequence(file) {
  // Comments stripped first, because both files document the order in prose
  // above the code that implements it, and a command named in a comment would
  // otherwise be read as the step running there.
  const source = read(file)
    .split(/\r?\n/)
    .filter((line) => !/^\s*#/.test(line))
    .join('\n');

  if (!source.includes('migrate --force')) {
    fail(
      `${file} does not run migrations. Production and event modes run them automatically (technical spec 26.2).`,
    );

    return;
  }

  const warningIndex = source.indexOf('Take a database backup');
  const migrateIndex = source.indexOf('migrate --force');

  if (warningIndex === -1) {
    fail(`${file} runs migrations without a backup warning (technical spec 26.2).`);
  } else if (warningIndex > migrateIndex) {
    fail(`${file} prints its backup warning after running migrations, which is too late to act on.`);
  }

  // Generation covers the secrets Meridian owns and the rest are named and the
  // boot stops (technical spec 7.4, 26.2). After the migration because the node
  // keypair is stored as node configuration, and before the config cache
  // because a generated key has to be in the cache served requests read.
  const secretsIndex = source.indexOf('meridian:secrets');

  if (secretsIndex === -1) {
    fail(
      `${file} does not run the secret safeguards. A node still holding a sample secret must stop at start rather than serving (technical spec 7.4, 26.2).`,
    );
  } else if (secretsIndex < migrateIndex) {
    fail(
      `${file} runs the secret safeguards before migrations, but the node keypair they generate is stored as node configuration and needs its table.`,
    );
  }

  const configCacheIndex = source.indexOf('config:cache');

  if (configCacheIndex === -1) {
    fail(`${file} never builds the configuration cache the served requests read.`);

    return;
  }

  if (secretsIndex > configCacheIndex) {
    fail(
      `${file} runs the secret safeguards after the config cache is built, so a generated key is not in the cache the served requests read.`,
    );
  }

  // The event-mode fail-closed checks: HTTPS validation and the offline read
  // set (technical spec 8.2, 8.6, 26.2). The refusal itself belongs to the
  // server and holds however a node is started; running the command here is
  // what stops a failing node at start with one legible line. It has to run
  // after `config:cache`, or it validates a configuration the served requests
  // will not read.
  const eventModeIndex = source.indexOf('meridian:event-mode');

  if (eventModeIndex === -1) {
    fail(
      `${file} does not run the event-mode fail-closed checks. A node that fails HTTPS validation or cannot serve the offline read set must fail closed at start in event mode (technical spec 8.6, 26.2).`,
    );
  } else if (eventModeIndex < configCacheIndex) {
    fail(
      `${file} runs the event-mode checks before the config cache is built, so it validates a configuration the served requests will not read.`,
    );
  }
}

/**
 * The containerless installation path (`deploy/native/`), read against the
 * Compose stack it mirrors.
 *
 * A second way to install the same node is a second thing to forget when the
 * first one changes, and the failure mode is quiet: a native node running a
 * PHP the server's platform requirement excludes, or a PostgreSQL without
 * logical replication, looks installed until node sync or an upload does not
 * work. So nothing here asks whether the scripts are good — only whether they
 * still say what the Compose stack says. What they cannot be asked without a
 * Debian host and systemd is whether they run, which is what the first
 * `install-host.sh` on a node is for.
 */
function checkNativeBundle() {
  const dockerfile = read(DOCKERFILE);
  const compose = read(DEPLOYMENT_COMPOSE);
  const services = serviceBlocks(compose);
  const install = read(NATIVE_INSTALL);
  const release = read(NATIVE_RELEASE);

  // The boot sequence, held to the same order as the entrypoint's.
  checkBootSequence(NATIVE_RELEASE);

  // ---- Runtime versions ---------------------------------------------------
  // apps/server/composer.json pins `php: >=8.5 <8.6`, so a native install that
  // provisions a different minor installs a server Composer will refuse.
  const imagePhp = (dockerfile.match(/^FROM\s+php:(\d+\.\d+)-/im) ?? [])[1];

  for (const file of [NATIVE_INSTALL, NATIVE_RELEASE]) {
    const nativePhp = (read(file).match(/PHP_VERSION:-(\d+\.\d+)\}/) ?? [])[1];

    if (!nativePhp) {
      fail(`${file} does not declare a default PHP_VERSION.`);
    } else if (nativePhp !== imagePhp) {
      fail(
        `${file} provisions PHP ${nativePhp} while ${DOCKERFILE} builds on PHP ${imagePhp}. Both must satisfy the platform requirement in apps/server/composer.json.`,
      );
    }
  }

  const imagePostgres = (services.get('postgres')?.match(/image:\s*postgres:(\d+)/) ?? [])[1];
  const nativePostgres = (install.match(/PG_VERSION:-(\d+)\}/) ?? [])[1];

  if (!nativePostgres) {
    fail(`${NATIVE_INSTALL} does not declare a default PG_VERSION.`);
  } else if (nativePostgres !== imagePostgres) {
    fail(
      `${NATIVE_INSTALL} provisions PostgreSQL ${nativePostgres} while ${DEPLOYMENT_COMPOSE} runs postgres:${imagePostgres}. A deployment tested against one major and run against another is a migration surprise nobody chose.`,
    );
  }

  // ---- The services the containers were ----------------------------------
  // A worker or scheduler that drifted from its container is a node where
  // queued notifications are never delivered, or where on-site never pushes
  // back to central, with nothing visibly broken.
  const units = [
    { unit: NATIVE_WORKER_UNIT, service: 'worker', command: 'queue:work' },
    { unit: NATIVE_SCHEDULER_UNIT, service: 'scheduler', command: 'schedule:work' },
  ];

  for (const { unit, service, command } of units) {
    const source = read(unit);
    const block = services.get(service) ?? '';

    if (!source.includes(`artisan ${command}`)) {
      fail(`${unit} does not run \`artisan ${command}\`, which is what the '${service}' service runs.`);
    }

    if (!/^ExecStart=/m.test(source)) {
      fail(`${unit} declares no ExecStart.`);
    }

    if (!/^Restart=always/m.test(source)) {
      fail(
        `${unit} does not restart automatically. The '${service}' service runs with \`restart: unless-stopped\`, and the worker additionally exits by design on --max-time.`,
      );
    }

    const composeQueues = (block.match(/--queue=(\S+)/) ?? [])[1];
    const unitQueues = (source.match(/--queue=(\S+)/) ?? [])[1];

    if (composeQueues !== unitQueues) {
      fail(
        `${unit} works the queues '${unitQueues ?? 'none'}' while ${DEPLOYMENT_COMPOSE} works '${composeQueues ?? 'none'}'. A queue named in one and not the other is a queue nothing drains.`,
      );
    }
  }

  // ---- PHP request limits -------------------------------------------------
  // Uploaded Field Report photos and spreadsheet imports arrive through PHP, so
  // a native pool with the stock 2M post_max_size refuses uploads the same node
  // accepts under Compose.
  for (const setting of ['memory_limit', 'upload_max_filesize', 'post_max_size']) {
    const imageValue = (dockerfile.match(new RegExp(`${setting}=([^']+)'`)) ?? [])[1];
    const nativeValue = (read(NATIVE_RUNTIME_INI).match(new RegExp(`^${setting}=(.+)$`, 'm')) ?? [])[1];

    if (imageValue !== nativeValue) {
      fail(
        `${NATIVE_RUNTIME_INI} sets ${setting}=${nativeValue ?? 'nothing'} while ${DOCKERFILE} sets ${imageValue ?? 'nothing'}.`,
      );
    }
  }

  // ---- PostgreSQL settings ------------------------------------------------
  // Logical replication is what node-to-node sync needs (technical spec section
  // 10), and a server started without it cannot be given one later without a
  // restart nobody planned for.
  const postgresConf = read(NATIVE_POSTGRES_CONF);

  for (const [, setting, value] of (services.get('postgres') ?? '').matchAll(
    /^\s+-\s+"([a-z_]+)=(\S+)"\s*$/gm,
  )) {
    const configured = (postgresConf.match(new RegExp(`^${setting}\\s*=\\s*(\\S+)`, 'm')) ?? [])[1];

    if (configured !== value) {
      fail(
        `${NATIVE_POSTGRES_CONF} sets ${setting} to '${configured ?? 'nothing'}' while ${DEPLOYMENT_COMPOSE} starts the database with '${value}'.`,
      );
    }
  }

  // The deployment database publishes no host port, so the native equivalent
  // must not leave the loopback interface either.
  if (!/^listen_addresses\s*=\s*'localhost'/m.test(postgresConf)) {
    fail(
      `${NATIVE_POSTGRES_CONF} does not bind the database to localhost. ${DEPLOYMENT_COMPOSE} publishes no host port for it, and an event network is no place to start.`,
    );
  }

  // ---- One proxy configuration, not two -----------------------------------
  // The shared snippet exists so a header or a limit cannot drift between the
  // Caddyfiles; a copy of them under deploy/native would reintroduce exactly
  // that, one directory further away.
  for (const entry of readdirSync(join(repositoryRoot, NATIVE_DIRECTORY), { recursive: true })) {
    const name = String(entry).split(/[\\/]/).pop();

    if (/^Caddyfile/i.test(name) || name === 'meridian.snippet') {
      fail(
        `${NATIVE_DIRECTORY} ships its own ${name}. The native install must serve deploy/caddy/, or the two installation paths grow separate proxy configurations.`,
      );
    }
  }

  if (!install.includes('deploy/caddy/Caddyfile')) {
    fail(`${NATIVE_INSTALL} does not install the bundle's own Caddyfiles from deploy/caddy/.`);
  }

  if (!read(NATIVE_CADDY_DROP_IN).includes('${MERIDIAN_CADDYFILE}')) {
    fail(
      `${NATIVE_CADDY_DROP_IN} does not select the configuration with MERIDIAN_CADDYFILE, so an event node cannot run Caddyfile.onsite.`,
    );
  }

  // The public root the proxy serves is written into the shared snippet, so the
  // native install has to put the checkout exactly there.
  const snippetRoot = (read(CADDY_SNIPPET).match(/^\s*root\s+\*\s+(\S+)\/apps\/server\/public\s*$/m) ?? [])[1];
  const installRoot = (install.match(/MERIDIAN_ROOT:-(\S+?)\}/) ?? [])[1];

  if (snippetRoot !== installRoot) {
    fail(
      `${NATIVE_INSTALL} installs Meridian at '${installRoot ?? 'nothing'}' while ${CADDY_SNIPPET} roots the site at '${snippetRoot ?? 'nothing'}/apps/server/public'. The server also resolves the root package.json and the client artifact relative to that path.`,
    );
  }

  // ---- Everything the release script has to build -------------------------
  // The image build did these; on a host, nothing else will. A missing one is a
  // node that serves a stale client, or a God Mode page rendering nothing.
  const built = [
    ['composer install', 'the server dependencies'],
    ['build:admin', 'the Meridian Admin client artifact'],
    ['docs:package', 'the packaged technician documentation (GOD-012)'],
    ['changelog:generate', 'the packaged changelog (GOD-021)'],
  ];

  for (const [needle, what] of built) {
    if (!release.includes(needle)) {
      fail(
        `${NATIVE_RELEASE} does not build ${what}. The image build produced it, so on a host nothing else does.`,
      );
    }
  }

  checkNativeEnvExamples();
}

/**
 * The native sample configuration, held to the same rules as the Compose one
 * (technical spec 26.2: sample configs carry fake values only), plus the two
 * questions only this path raises — whether the proxy's own environment file
 * documents every variable the Caddyfiles substitute, and whether the values
 * that were container-network names have been translated.
 */
function checkNativeEnvExamples() {
  const server = parseEnvFile(read(NATIVE_SERVER_ENV_EXAMPLE));
  const proxy = parseEnvFile(read(NATIVE_PROXY_ENV_EXAMPLE));

  for (const key of MUST_BE_EMPTY) {
    if (!server.has(key)) {
      fail(`${NATIVE_SERVER_ENV_EXAMPLE} does not document ${key}.`);
      continue;
    }

    if (server.get(key) !== '') {
      fail(
        `${NATIVE_SERVER_ENV_EXAMPLE} ships a value for ${key}. Secrets in a sample configuration must be empty (technical spec 26.2).`,
      );
    }
  }

  const appUrl = server.get('APP_URL') ?? '';

  if (!appUrl.startsWith('https://')) {
    fail(
      `${NATIVE_SERVER_ENV_EXAMPLE} sets APP_URL to '${appUrl}'. Production and event modes never use plain HTTP (technical spec 8.2).`,
    );
  }

  for (const [file, value] of [
    [NATIVE_SERVER_ENV_EXAMPLE, appUrl],
    [NATIVE_PROXY_ENV_EXAMPLE, proxy.get('MERIDIAN_SITE_ADDRESS') ?? ''],
  ]) {
    if (!/\.example\.(org|com|net)(\/|$)/.test(value)) {
      fail(`${file} names '${value}', which is not a documentation domain.`);
    }
  }

  if (server.get('APP_ENV') === 'local') {
    fail(`${NATIVE_SERVER_ENV_EXAMPLE} sets APP_ENV=local, which disables the event-mode safeguards.`);
  }

  if (server.get('APP_DEBUG') !== 'false') {
    fail(`${NATIVE_SERVER_ENV_EXAMPLE} must set APP_DEBUG=false.`);
  }

  if (server.get('MERIDIAN_NODE_ROLE') === 'development') {
    fail(
      `${NATIVE_SERVER_ENV_EXAMPLE} sets MERIDIAN_NODE_ROLE=development, which is not a deployment role (technical spec 26.1).`,
    );
  }

  if (server.get('SESSION_DOMAIN') !== '') {
    fail(
      `${NATIVE_SERVER_ENV_EXAMPLE} sets SESSION_DOMAIN. A host-only cookie is what keeps organization subdomains isolated from one another (technical spec 8.7).`,
    );
  }

  // The image set this as a build-time ENV, so a deployed server always served
  // the built artifact. Nothing sets it on a host, and the default follows
  // APP_ENV — which is right today and is one edit away from not being.
  if (server.get('MERIDIAN_CLIENT_USE_DEV_SERVER') !== 'false') {
    fail(
      `${NATIVE_SERVER_ENV_EXAMPLE} does not set MERIDIAN_CLIENT_USE_DEV_SERVER=false. The server image sets it as an ENV; on a host this file is the only thing that can.`,
    );
  }

  // `postgres` was the Compose service name and resolves to nothing on a host.
  if (server.get('DB_HOST') === 'postgres') {
    fail(
      `${NATIVE_SERVER_ENV_EXAMPLE} sets DB_HOST=postgres, which is the Compose service name and resolves to nothing on a host.`,
    );
  }

  // Every variable the proxy configuration substitutes has to be documented in
  // the file the proxy service actually reads, or the operator is left to find
  // the names inside a Caddyfile.
  const proxySources = [CADDY_SNIPPET, CADDYFILE, CADDYFILE_ONSITE, CADDYFILE_WILDCARD, NATIVE_CADDY_DROP_IN];
  const substituted = new Set();

  for (const file of proxySources) {
    for (const [, key] of read(file).matchAll(/\{\$([A-Z0-9_]+)(?::[^}]*)?\}/g)) {
      substituted.add(key);
    }
  }

  for (const key of [...substituted].sort()) {
    if (!proxy.has(key)) {
      fail(`${NATIVE_PROXY_ENV_EXAMPLE} does not document ${key}, which the proxy configuration substitutes.`);
    }
  }

  if (proxy.get('MERIDIAN_CADDYFILE') !== '/etc/caddy/Caddyfile') {
    fail(
      `${NATIVE_PROXY_ENV_EXAMPLE} does not default MERIDIAN_CADDYFILE to /etc/caddy/Caddyfile, which is the configuration an internet-reachable node runs.`,
    );
  }

  // The socket the proxy dials is named after the PHP version the pool runs, so
  // bumping one and not the other produces a proxy dialing a socket nothing
  // listens on.
  const upstream = proxy.get('MERIDIAN_SERVER_UPSTREAM') ?? '';
  const installPhp = (read(NATIVE_INSTALL).match(/PHP_VERSION:-(\d+\.\d+)\}/) ?? [])[1];

  if (installPhp && !upstream.includes(`php${installPhp}-`)) {
    fail(
      `${NATIVE_PROXY_ENV_EXAMPLE} points MERIDIAN_SERVER_UPSTREAM at '${upstream}', which does not name PHP ${installPhp} — the version ${NATIVE_INSTALL} provisions the pool with.`,
    );
  }

  // REQUIRED_FILES is the release manifest itself, so it cannot catch a file
  // being dropped from it — the check and the list move together. What it can
  // be held to is the scripts: anything they run out of their own directory has
  // to be in the manifest, or a node installed from a release tarball unpacks a
  // script that calls a file the tarball does not contain.
  for (const script of [NATIVE_INSTALL, NATIVE_RELEASE]) {
    // The capture has to swallow any `${...}` in the path rather than stop at
    // it, or an interpolated reference matches as its literal prefix and is
    // reported as a missing directory.
    const references = read(script).matchAll(/\$\{BUNDLE_DIR\}\/((?:[A-Za-z0-9._\/-]|\$\{[^}]+\})+)/g);

    for (const [, referenced] of references) {
      // `${BUNDLE_DIR}/systemd/${unit}.service` and friends are resolved at run
      // time; the literal references are the ones a manifest can be checked against.
      if (referenced.includes('$')) {
        continue;
      }

      const bundled = `${NATIVE_DIRECTORY}/${referenced}`;

      if (!REQUIRED_FILES.includes(bundled)) {
        fail(
          `${script} uses ${bundled}, which is not in the release bundle manifest (scripts/deploy/deployment-bundle-manifest.mjs). A node installed from a release tarball would not have it.`,
        );
      }
    }
  }

  // The preflight is only worth having if it cannot be skipped by accident, so
  // the release has to run it, and it has to run it before it builds or
  // migrates anything — a check that reports a bad database password after the
  // migration has already run is not a preflight.
  const release = read(NATIVE_RELEASE);
  const preflightAt = release.indexOf('preflight.php');

  if (preflightAt === -1) {
    fail(
      `${NATIVE_RELEASE} does not run ${NATIVE_PREFLIGHT}. A release that starts against a configuration that cannot work fails late and obscurely, which is what the preflight exists to prevent.`,
    );
  } else {
    for (const [what, marker] of [
      ['installs dependencies', 'composer install'],
      ['migrates', 'artisan migrate'],
    ]) {
      const markerAt = release.indexOf(marker);

      if (markerAt !== -1 && markerAt < preflightAt) {
        fail(`${NATIVE_RELEASE} ${what} before it runs the preflight. The preflight has to come first to be worth running.`);
      }
    }
  }

  // README step 2 copies this file to become the server's own `.env`, so
  // phpdotenv parses it — and phpdotenv refuses an unquoted value containing
  // whitespace outright. The failure is not a bad value but an unparseable
  // file, which takes down every `artisan` invocation on the node, including
  // the `composer install` that runs `package:discover`. The Compose sibling
  // carries the same values but is read by Compose's `env_file:` parser, which
  // accepts them, so this is the only file where the rule bites — which is
  // exactly why it reached a node.
  for (const [index, line] of read(NATIVE_SERVER_ENV_EXAMPLE).split(/\r?\n/).entries()) {
    const assignment = line.match(/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/);

    if (!assignment) {
      continue;
    }

    const [, name, rawValue] = assignment;
    // A trailing comment is phpdotenv's, not part of the value.
    const value = rawValue.replace(/\s+#.*$/, '').trim();

    if (/^(".*"|'.*')$/s.test(value) || !/\s/.test(value)) {
      continue;
    }

    fail(
      `${NATIVE_SERVER_ENV_EXAMPLE}:${index + 1} leaves ${name} unquoted with whitespace in its value. phpdotenv cannot parse the file this becomes, so every artisan command on the node fails.`,
    );
  }
}

function dockerAvailable() {
  const result = spawnSync('docker', ['version', '--format', '{{.Server.Version}}'], {
    cwd: repositoryRoot,
    stdio: 'pipe',
  });

  return result.status === 0;
}

function runDocker(description, args, env = {}) {
  const result = spawnSync('docker', args, {
    cwd: repositoryRoot,
    stdio: 'pipe',
    env: { ...process.env, ...env },
  });

  if (result.error) {
    fail(`${description} could not be run: ${result.error.message}`);

    return false;
  }

  if (result.status !== 0) {
    const output = `${result.stdout ?? ''}${result.stderr ?? ''}`.trim();

    fail(`${description} failed:\n${output}`);

    return false;
  }

  return true;
}

function checkWithDocker() {
  // Renders the stack against the committed sample, which is the assertion that
  // the sample is complete: every required variable resolves, or this fails.
  //
  // The secrets the sample deliberately leaves empty are supplied here as
  // placeholders, because the stack requires them and a sample that satisfied
  // that requirement on its own would be a sample shipping a password. So what
  // this proves is the real question: the committed sample plus the values an
  // operator must fill in renders a valid stack, and nothing else is missing.
  const suppliedSecrets = Object.fromEntries(
    MUST_BE_EMPTY.map((key) => [key, `placeholder-${key.toLowerCase()}`]),
  );

  runDocker(
    `\`docker compose config\` for ${DEPLOYMENT_COMPOSE}`,
    [
      'compose',
      '--env-file',
      DEPLOYMENT_ENV_EXAMPLE,
      '--file',
      DEPLOYMENT_COMPOSE,
      'config',
      '--quiet',
    ],
    { ...suppliedSecrets, MERIDIAN_ENV_FILE: './.env.deployment.example' },
  );

  runDocker(`\`docker compose config\` for ${DATABASE_COMPOSE}`, [
    'compose',
    '--env-file',
    DATABASE_ENV_EXAMPLE,
    '--file',
    DATABASE_COMPOSE,
    'config',
    '--quiet',
  ]);

  // `--check` resolves and validates the whole Dockerfile without executing a
  // build step, so it catches a broken stage reference or an invalid instruction
  // in seconds rather than after a multi-minute build.
  for (const { target } of IMAGE_TARGETS) {
    runDocker(`\`docker build --check\` for target ${target}`, [
      'build',
      '--check',
      '--file',
      DOCKERFILE,
      '--target',
      target,
      '.',
    ]);
  }
}

function main() {
  const args = process.argv.slice(2);

  checkRequiredFiles();

  if (errors.length > 0) {
    report();

    return 1;
  }

  checkDockerfile();
  checkDeploymentCompose();
  checkDeploymentEnvExample();
  checkImageTag();
  checkCaddyConfiguration();
  checkSubdomainDocumentation();
  checkDnsTemplates();
  checkBootSequence(ENTRYPOINT);
  checkNativeBundle();

  const requireDocker = args.includes('--require-docker');

  if (requireDocker || args.includes('--with-docker')) {
    if (dockerAvailable()) {
      checkWithDocker();
    } else if (requireDocker) {
      fail('Docker is not available and --require-docker was passed.');
    } else {
      notices.push('Docker is not available, so the Compose render and Dockerfile build check were skipped.');
    }
  } else {
    notices.push(
      'Static checks only. Pass --with-docker to also render both Compose files and run `docker build --check`.',
    );
  }

  return report();
}

function report() {
  for (const notice of notices) {
    console.log(`Note: ${notice}`);
  }

  if (errors.length > 0) {
    console.error('Deployment bundle validation failed:');

    for (const error of errors) {
      console.error(`- ${error}`);
    }

    return 1;
  }

  console.log('Deployment bundle validation passed.');

  return 0;
}

process.exit(main());
