/**
 * Tests over the Runtipi app generator (M19.28; technical spec 26.4;
 * deploy/runtipi/README.md).
 *
 * Three kinds of assertion:
 *
 *   - version integrity: the app and its image tags declare the root
 *     `package.json` version, which is the whole reason this is a generator;
 *   - schema conformance: `config.json` carries the fields Runtipi's app
 *     format requires, in the shapes it requires, checked against the format
 *     the runtipi/example-appstore schema documents — hermetically, because a
 *     registry that is rate-limiting must not fail a pull request;
 *   - stack agreement: the rendered Compose stack runs the same worker and
 *     scheduler the deployment stack runs, against the same PostgreSQL major,
 *     with every substituted variable supplied by Runtipi itself or by a
 *     declared form field.
 */
import assert from 'node:assert/strict';
import { existsSync, readFileSync, rmSync, statSync } from 'node:fs';
import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { meridianImageTag } from './build-images.mjs';
import {
  buildRuntipiApp,
  LOGO_SOURCE,
  REGISTRY_IMAGE,
  runtipiCompose,
  runtipiConfig,
  runtipiDescription,
  tipiVersion,
  writeRuntipiApp,
} from './build-runtipi-app.mjs';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function read(relativePath) {
  return readFileSync(join(repositoryRoot, relativePath), 'utf8');
}

test('the app declares the root package.json version, in config and image tags alike', () => {
  const version = meridianImageTag(repositoryRoot);
  const config = runtipiConfig();
  const compose = runtipiCompose();

  assert.equal(config.version, version);
  assert.ok(compose.includes(`image: ${REGISTRY_IMAGE}:${version}`));
  assert.ok(compose.includes(`image: ${REGISTRY_IMAGE}-web:${version}`));

  // No other tag anywhere: an image line not carrying the root version is the
  // exact drift the generator exists to make impossible.
  for (const [, image] of compose.matchAll(/image:\s*(\S+)/g)) {
    if (image.startsWith(REGISTRY_IMAGE)) {
      assert.ok(image.endsWith(`:${version}`), image);
    }
  }
});

test('tipi_version is derived and monotonic over the version ordering', () => {
  assert.equal(tipiVersion('0.0.163'), 163);
  assert.equal(tipiVersion('0.1.0'), 1_000);
  assert.equal(tipiVersion('1.0.0'), 1_000_000);
  assert.ok(tipiVersion('0.1.0') > tipiVersion('0.0.999'));
  assert.equal(runtipiConfig().tipi_version, tipiVersion(meridianImageTag(repositoryRoot)));
});

test('the app declares amd64 and only amd64, the architecture the images are built for', () => {
  assert.deepEqual(runtipiConfig().supported_architectures, ['amd64']);
});

test('config.json conforms to the Runtipi app format', () => {
  const config = runtipiConfig();

  // The field set and shapes the runtipi/example-appstore app-info schema
  // requires of a store app.
  assert.equal(typeof config.name, 'string');
  assert.equal(config.id, 'meridian');
  assert.equal(config.available, true);
  assert.equal(typeof config.short_desc, 'string');
  assert.equal(typeof config.author, 'string');
  assert.ok(Number.isInteger(config.port) && config.port > 1024 && config.port < 65_535);
  assert.ok(Array.isArray(config.categories) && config.categories.length > 0);
  assert.ok(Number.isInteger(config.tipi_version));
  assert.match(config.version, /^\d+\.\d+\.\d+$/);
  assert.match(config.source, /^https:\/\//);
  assert.equal(typeof config.exposable, 'boolean');
  assert.equal(typeof config.dynamic_config, 'boolean');

  // The install form: every field carries a known type, a label, a unique
  // environment variable, and an explicit required flag.
  const knownTypes = new Set(['text', 'password', 'email', 'number', 'fqdn', 'ip', 'fqdnip', 'url', 'random']);
  const envVariables = config.form_fields.map((field) => field.env_variable);

  for (const field of config.form_fields) {
    assert.ok(knownTypes.has(field.type), field.type);
    assert.equal(typeof field.label, 'string');
    assert.match(field.env_variable, /^[A-Z][A-Z0-9_]*$/);
    assert.equal(typeof field.required, 'boolean');
  }

  assert.equal(new Set(envVariables).size, envVariables.length, 'form field env variables must be unique');
});

test('the app requires a domain and working mail, because the node refuses without them', () => {
  const config = runtipiConfig();

  // Any non-development node refuses plain HTTP (EventModeGuard), so an app
  // installable at http://<ip>:<port> would install cleanly and answer 503 to
  // everything. force_expose is the line that prevents that install.
  assert.equal(config.force_expose, true);
  assert.equal(config.exposable, true);

  // Login is a mailed code, so the mail fields that make mail work are
  // required rather than optional.
  const required = Object.fromEntries(config.form_fields.map((field) => [field.env_variable, field.required]));

  assert.equal(required.MERIDIAN_MAIL_HOST, true);
  assert.equal(required.MERIDIAN_MAIL_PORT, true);
  assert.equal(required.MERIDIAN_MAIL_FROM, true);
  assert.equal(required.MERIDIAN_APP_KEY_SEED, true);
  assert.equal(required.MERIDIAN_DB_PASSWORD, true);
});

test('the rendered stack carries the worker and the scheduler', () => {
  const compose = runtipiCompose();

  for (const service of ['meridian-web:', 'meridian-server:', 'meridian-worker:', 'meridian-scheduler:', 'meridian-db:']) {
    assert.ok(compose.includes(`  ${service}`), service);
  }

  // The same worker invocation the deployment stack runs: a queue named in
  // one and not the other is a queue nothing drains.
  const deploymentCompose = read('deploy/docker/compose.deployment.yaml');
  const deploymentQueues = deploymentCompose.match(/--queue=(\S+)/)[1];

  assert.ok(compose.includes(`"--queue=${deploymentQueues}"`), `worker must work the queues '${deploymentQueues}'`);
  assert.ok(compose.includes('"schedule:work"'));
});

test('the stack runs behind Runtipi rather than beside it', () => {
  const compose = runtipiCompose();

  // Traefik owns the ports; the app publishes none and names its internal
  // port through the x-runtipi extension on the main service.
  assert.ok(!/^\s+ports:/m.test(compose));
  assert.ok(compose.includes('is_main: true'));
  assert.ok(compose.includes('internal_port: 80'));
  assert.match(compose, /x-runtipi:\s*\n\s+schema_version: 2/);

  // The proxy runs the M19.27 proxied configuration, and Laravel is told to
  // believe the forwarded scheme from the platform network.
  assert.ok(compose.includes('/etc/caddy/Caddyfile.proxied'));
  assert.ok(compose.includes('MERIDIAN_TRUSTED_PROXIES: "*"'));
  assert.ok(compose.includes('MERIDIAN_APP_KEY_SEED: ${MERIDIAN_APP_KEY_SEED}'));
});

test('the app database runs the PostgreSQL major the deployment stack runs', () => {
  const appMajor = runtipiCompose().match(/image:\s*postgres:(\d+)/)[1];
  const deploymentMajor = read('deploy/docker/compose.deployment.yaml').match(/image:\s*postgres:(\d+)/)[1];

  assert.equal(appMajor, deploymentMajor);
});

test('every substituted variable is supplied by Runtipi or by a declared form field', () => {
  // What Runtipi itself injects into every app's environment.
  const runtipiProvided = new Set(['APP_DATA_DIR', 'APP_PROTOCOL', 'APP_DOMAIN', 'APP_PORT']);
  const declared = new Set(runtipiConfig().form_fields.map((field) => field.env_variable));

  for (const [, name] of runtipiCompose().matchAll(/\$\{([A-Z0-9_]+)\}/g)) {
    assert.ok(
      runtipiProvided.has(name) || declared.has(name),
      `\${${name}} is substituted by the Compose stack but supplied by neither Runtipi nor a form field`,
    );
  }
});

test('the description states the constraints where a person installing will read them', () => {
  const description = runtipiDescription();

  // amd64-only on its own line, not only in supported_architectures — a
  // person reading about Meridian elsewhere should not discover the
  // constraint by failing to find the app (deploy/runtipi/README.md).
  assert.match(description, /amd64 only/i);
  // Login is a mailed code; the description says so above the fold.
  assert.match(description, /SMTP is required/i);
  // An update is also a schema migration.
  assert.match(description, /back up before updating/i);
  assert.match(description, /migrations?\s+automatically/i);
  // The seed trade-off, stated plainly.
  assert.match(description, /cleartext/);
  // The AGPL obligation: the listing links the source clearly.
  assert.match(description, /AGPL-3\.0-or-later/);
  assert.match(description, /github\.com\/KenEucker\/meridian/);
});

test('the committed logo exists and the writer emits the complete app directory', () => {
  assert.ok(existsSync(join(repositoryRoot, LOGO_SOURCE)), `${LOGO_SOURCE} is missing`);
  assert.ok(statSync(join(repositoryRoot, LOGO_SOURCE)).size > 0);

  const outDir = mkdtempSync(join(tmpdir(), 'meridian-runtipi-app-'));

  try {
    writeRuntipiApp(outDir);

    for (const file of ['config.json', 'docker-compose.yml', 'metadata/description.md', 'metadata/logo.jpg']) {
      assert.ok(existsSync(join(outDir, file)), file);
    }

    // The written config parses back to what the generator declared.
    const written = JSON.parse(readFileSync(join(outDir, 'config.json'), 'utf8'));

    assert.equal(written.version, meridianImageTag(repositoryRoot));
  } finally {
    rmSync(outDir, { recursive: true, force: true });
  }
});

test('the generated file set is exactly what the writer writes', () => {
  assert.deepEqual(Object.keys(buildRuntipiApp()), ['config.json', 'docker-compose.yml', 'metadata/description.md']);
});
