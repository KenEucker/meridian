#!/usr/bin/env node
/**
 * Build the signed Android app bundle and APK locally (M19.22 / spec 26.5).
 *
 * CI's android job supplies the MERIDIAN_ANDROID_UPLOAD_* credentials from
 * repository secrets; a local release reads them from `.env` at the repository
 * root, which is gitignored, so the release maintainer keeps them out of both
 * the commit history and the shell profile. Values already exported in the
 * environment win over `.env` lines, so a CI-style invocation still behaves
 * the same with the file present.
 *
 * The keystore path accepts `~/` and Git Bash `/c/...` spellings in addition
 * to native Windows paths, because the maintainer's interactive shell is
 * MINGW64 and Gradle's guard resolves the value with Java's file semantics,
 * which understand neither.
 *
 * Usage:
 *   corepack pnpm run mobile:android:release
 */

import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const androidDir = join(repositoryRoot, 'apps', 'mobile', 'android');
const envFilePath = join(repositoryRoot, '.env');

const REQUIRED_CREDENTIALS = [
  'MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE',
  'MERIDIAN_ANDROID_UPLOAD_KEYSTORE_PASSWORD',
  'MERIDIAN_ANDROID_UPLOAD_KEY_ALIAS',
  'MERIDIAN_ANDROID_UPLOAD_KEY_PASSWORD',
];

function parseEnvFile(text) {
  const values = {};
  for (const rawLine of text.split(/\r?\n/)) {
    const line = rawLine.trim();
    if (line === '' || line.startsWith('#')) {
      continue;
    }
    const assignment = line.startsWith('export ') ? line.slice('export '.length).trim() : line;
    const separator = assignment.indexOf('=');
    if (separator < 1) {
      continue;
    }
    const key = assignment.slice(0, separator).trim();
    let value = assignment.slice(separator + 1).trim();
    const quoted =
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"));
    if (quoted && value.length >= 2) {
      value = value.slice(1, -1);
    }
    values[key] = value;
  }
  return values;
}

const env = { ...process.env };
if (existsSync(envFilePath)) {
  const fileValues = parseEnvFile(readFileSync(envFilePath, 'utf8'));
  for (const [key, value] of Object.entries(fileValues)) {
    if (env[key] === undefined || env[key] === '') {
      env[key] = value;
    }
  }
}

const missing = REQUIRED_CREDENTIALS.filter((name) => !env[name]);
if (missing.length > 0) {
  console.error(
    `Missing Android release signing credentials: ${missing.join(', ')}.\n` +
      `Add them to ${envFilePath} (gitignored) as KEY=value lines, or export\n` +
      'them in the shell. See docs/process/release-packaging.md, Credentials.',
  );
  process.exit(1);
}

let keystorePath = env.MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE;
if (keystorePath.startsWith('~/') || keystorePath === '~') {
  keystorePath = join(homedir(), keystorePath.slice(1));
}
const msysDrive = keystorePath.match(/^\/([A-Za-z])(\/|$)/);
if (msysDrive) {
  keystorePath = `${msysDrive[1].toUpperCase()}:${keystorePath.slice(2)}`;
}
if (!isAbsolute(keystorePath)) {
  keystorePath = resolve(repositoryRoot, keystorePath);
}
env.MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE = keystorePath;

if (!existsSync(keystorePath)) {
  console.error(
    `MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE points at ${keystorePath}, which does not exist.`,
  );
  process.exit(1);
}

// The wrapper is invoked by absolute path: Git Bash exports
// NoDefaultCurrentDirectoryInExePath=1, under which cmd.exe refuses to run a
// cwd-relative batch file and reports it as not recognized.
const gradleTasks = ['bundleRelease', 'assembleRelease'];
const result =
  process.platform === 'win32'
    ? spawnSync('cmd.exe', ['/d', '/s', '/c', join(androidDir, 'gradlew.bat'), ...gradleTasks], {
        cwd: androidDir,
        env,
        stdio: 'inherit',
      })
    : spawnSync(join(androidDir, 'gradlew'), gradleTasks, { cwd: androidDir, env, stdio: 'inherit' });

if (result.status === 0) {
  const outputs = join(androidDir, 'app', 'build', 'outputs');
  console.log('\nSigned release artifacts:');
  console.log(`  ${join(outputs, 'bundle', 'release', 'app-release.aab')}`);
  console.log(`  ${join(outputs, 'apk', 'release', 'app-release.apk')}`);
}

process.exit(result.status ?? 1);
