/**
 * Tests over the Caddyfile kind rules (M19.27; technical spec 8.2).
 *
 * The property that matters most is the one the proxied mode could have
 * eroded: a TLS-terminating configuration is *still* refused when it serves
 * plain HTTP. The rule was taught the difference between the two kinds, not
 * loosened, and these tests hold that against synthetic configurations as
 * well as the four committed ones.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

import { caddyfileErrors, classifyCaddyfile, PROXIED, TLS_TERMINATING } from './caddyfile-rules.mjs';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function read(relativePath) {
  return readFileSync(join(repositoryRoot, relativePath), 'utf8');
}

const CONFORMING_PROXIED = [
  '{',
  '\tauto_https off',
  '\tservers {',
  '\t\ttrusted_proxies static private_ranges',
  '\t}',
  '}',
  '',
  'import /etc/caddy/meridian.snippet',
  '',
  ':80 {',
  '\timport meridian-app',
  '}',
  '',
].join('\n');

test('a TLS-terminating Caddyfile serving plain HTTP is refused', () => {
  const source = [
    'import /etc/caddy/meridian.snippet',
    '',
    'http://meridian.example.org {',
    '\timport meridian-app',
    '}',
  ].join('\n');

  const errors = caddyfileErrors('Caddyfile.synthetic', source, TLS_TERMINATING);

  assert.equal(errors.length, 1);
  assert.match(errors[0], /http:\/\/ site address/);
});

test('a TLS-terminating Caddyfile that renounces certificates entirely is refused', () => {
  const source = [
    '{',
    '\tauto_https off',
    '}',
    '',
    'import /etc/caddy/meridian.snippet',
    '',
    '{$MERIDIAN_SITE_ADDRESS} {',
    '\timport meridian-app',
    '}',
  ].join('\n');

  const errors = caddyfileErrors('Caddyfile.synthetic', source, TLS_TERMINATING);

  assert.equal(errors.length, 1);
  assert.match(errors[0], /auto_https off/);
});

test('a conforming proxied configuration passes', () => {
  assert.deepEqual(caddyfileErrors('Caddyfile.proxied', CONFORMING_PROXIED, PROXIED), []);
});

test('a proxied configuration that forgets auto_https off is refused', () => {
  const source = CONFORMING_PROXIED.replace('\tauto_https off\n', '');

  const errors = caddyfileErrors('Caddyfile.proxied', source, PROXIED);

  assert.equal(errors.length, 1);
  assert.match(errors[0], /auto_https off/);
});

test('a proxied configuration serving a hostname or its own TLS is refused', () => {
  const hostname = CONFORMING_PROXIED.replace(':80 {', '{$MERIDIAN_SITE_ADDRESS} {');
  const hostnameErrors = caddyfileErrors('Caddyfile.proxied', hostname, PROXIED);

  assert.ok(hostnameErrors.some((error) => /hostname or scheme site address/.test(error)));
  assert.ok(hostnameErrors.some((error) => /does not serve :80/.test(error)));

  const tls = CONFORMING_PROXIED.replace(
    '\timport meridian-app',
    '\ttls /etc/caddy/tls/node.crt /etc/caddy/tls/node.key\n\timport meridian-app',
  );

  assert.ok(caddyfileErrors('Caddyfile.proxied', tls, PROXIED).some((error) => /TLS of its own/.test(error)));
});

test('a proxied configuration trusting forwarded headers from anywhere is refused', () => {
  const source = CONFORMING_PROXIED.replace('\t\ttrusted_proxies static private_ranges\n', '');

  const errors = caddyfileErrors('Caddyfile.proxied', source, PROXIED);

  assert.equal(errors.length, 1);
  assert.match(errors[0], /private ranges/);
});

test('a configuration that drops the shared snippet is refused whatever its kind', () => {
  for (const kind of [TLS_TERMINATING, PROXIED]) {
    const errors = caddyfileErrors('Caddyfile.synthetic', ':80 {\n}\n', kind);

    assert.ok(errors.some((error) => /shared Meridian snippet/.test(error)), kind);
    assert.ok(errors.some((error) => /\(meridian-app\) snippet/.test(error)), kind);
  }
});

test('the four committed Caddyfiles classify as the kinds the validator holds them to', () => {
  for (const file of ['deploy/caddy/Caddyfile', 'deploy/caddy/Caddyfile.onsite', 'deploy/caddy/Caddyfile.wildcard']) {
    const source = read(file);

    assert.equal(classifyCaddyfile(source), TLS_TERMINATING, file);
    assert.deepEqual(caddyfileErrors(file, source, TLS_TERMINATING), []);
  }

  const proxied = read('deploy/caddy/Caddyfile.proxied');

  assert.equal(classifyCaddyfile(proxied), PROXIED);
  assert.deepEqual(caddyfileErrors('deploy/caddy/Caddyfile.proxied', proxied, PROXIED), []);
});
