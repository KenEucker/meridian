/**
 * The inertness guarantees of the reverse-proxy deployment mode (M19.27).
 *
 * The task's contract is that every one of its three features — the proxied
 * Caddyfile, the trusted-proxies setting, and the APP_KEY seed — is inert
 * unless a deployment configures it, so an unchanged `.env.deployment`
 * renders the same stack as before. These tests pin that contract where it
 * could quietly break: a new required Compose variable, a changed default,
 * or an unguarded derivation in the entrypoint.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function read(relativePath) {
  return readFileSync(join(repositoryRoot, relativePath), 'utf8');
}

test('an unchanged .env.deployment renders the same stack: no new required Compose variable', () => {
  const compose = read('deploy/docker/compose.deployment.yaml');
  const required = new Set([...compose.matchAll(/\$\{([A-Z0-9_]+):\?/g)].map(([, name]) => name));

  // The exact required set the stack had before M19.27. A deployment file
  // written before the reverse-proxy mode existed satisfies it, so it still
  // renders — growing this set is a breaking change to every deployed node
  // and must be a decision, not a side effect.
  assert.deepEqual(
    [...required].sort(),
    ['DB_DATABASE', 'DB_PASSWORD', 'DB_USERNAME', 'MERIDIAN_IMAGE_TAG', 'MERIDIAN_SITE_ADDRESS'],
  );

  // The stack itself never references the two new settings: they reach the
  // containers through env_file, so their absence from an older environment
  // file is simply an empty value.
  assert.ok(!compose.includes('MERIDIAN_TRUSTED_PROXIES'));
  assert.ok(!compose.includes('MERIDIAN_APP_KEY_SEED'));

  // And the proxy still defaults to the TLS-terminating configuration.
  assert.match(compose, /\$\{MERIDIAN_CADDYFILE:-\/etc\/caddy\/Caddyfile\}/);
});

test('the sample environment leaves both new settings empty', () => {
  const example = read('deploy/docker/.env.deployment.example');

  for (const key of ['MERIDIAN_TRUSTED_PROXIES', 'MERIDIAN_APP_KEY_SEED']) {
    assert.match(example, new RegExp(`^${key}=$`, 'm'), key);
  }
});

test('the entrypoint derives APP_KEY only when it is unset and a seed is present', () => {
  const entrypoint = read('deploy/docker/entrypoint.sh')
    .split(/\r?\n/)
    .filter((line) => !/^\s*#/.test(line))
    .join('\n');

  // Both guards, in one condition: a deployment that sets APP_KEY keeps it
  // even when a seed is also present, and a deployment with neither still
  // reaches the meridian:secrets refusal untouched.
  const guardAt = entrypoint.indexOf('[ -z "${APP_KEY:-}" ] && [ -n "${MERIDIAN_APP_KEY_SEED:-}" ]');

  assert.notEqual(guardAt, -1);
  assert.ok(entrypoint.includes('base64_encode(hash("sha256"'));
  assert.ok(entrypoint.includes('export APP_KEY'));

  // Before the secret safeguards, so a seeded node is not refused over the
  // key it was about to have.
  assert.ok(guardAt < entrypoint.indexOf('meridian:secrets'));
});
