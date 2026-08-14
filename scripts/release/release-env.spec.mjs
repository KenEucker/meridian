/**
 * Tests over the shared release `.env` loader (M19.22 / spec 26.5).
 *
 * The loader decides where a signing credential comes from, so the two
 * properties that keep the credential policy intact are held here: an
 * exported value always wins over a `.env` line, and the file never supplies
 * a default for a name it does not carry.
 */
import assert from 'node:assert/strict';
import { mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';

import { loadReleaseEnv, missingCredentials, parseEnvFile } from './release-env.mjs';

function envFileContaining(text) {
  const path = join(mkdtempSync(join(tmpdir(), 'meridian-release-env-')), '.env');
  writeFileSync(path, text);
  return path;
}

test('parses assignments, tolerating export prefixes and quoted values', () => {
  assert.deepEqual(
    parseEnvFile(
      ['# a comment', '', 'PLAIN=value', 'export EXPORTED=value', 'DOUBLE="two words"', "SINGLE='q'"].join(
        '\n',
      ),
    ),
    { PLAIN: 'value', EXPORTED: 'value', DOUBLE: 'two words', SINGLE: 'q' },
  );
});

test('keeps a value containing an equals sign whole', () => {
  assert.deepEqual(parseEnvFile('KEY=a=b=c'), { KEY: 'a=b=c' });
});

test('ignores lines that assign nothing', () => {
  assert.deepEqual(parseEnvFile(['no-separator', '=novalue', 'KEEP=1'].join('\n')), { KEEP: '1' });
});

test('an exported value wins over the file', () => {
  const path = envFileContaining('MERIDIAN_IOS_TEAM_ID=fromfile\n');
  const env = loadReleaseEnv(path, { MERIDIAN_IOS_TEAM_ID: 'fromshell' });
  assert.equal(env.MERIDIAN_IOS_TEAM_ID, 'fromshell');
});

test('the file fills a name the environment leaves empty or absent', () => {
  const path = envFileContaining('EMPTY=fromfile\nABSENT=fromfile\n');
  const env = loadReleaseEnv(path, { EMPTY: '' });
  assert.equal(env.EMPTY, 'fromfile');
  assert.equal(env.ABSENT, 'fromfile');
});

test('a missing file is not an error and supplies nothing', () => {
  const env = loadReleaseEnv(join(tmpdir(), 'meridian-release-env-absent', '.env'), { ONLY: '1' });
  assert.deepEqual(env, { ONLY: '1' });
});

test('the loader defaults no credential', () => {
  // A name in neither the environment nor the file must stay missing, so the
  // platform guard refuses the build instead of signing with a fallback.
  const path = envFileContaining('UNRELATED=1\n');
  const env = loadReleaseEnv(path, {});
  assert.equal(env.MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE, undefined);
});

test('reports missing credentials, counting an empty value as missing', () => {
  const env = { PRESENT: 'v', EMPTY: '' };
  assert.deepEqual(missingCredentials(env, ['PRESENT', 'EMPTY', 'ABSENT']), ['EMPTY', 'ABSENT']);
});
