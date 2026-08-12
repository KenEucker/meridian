#!/usr/bin/env node
/**
 * Build smoke test for the Meridian Kiosk desktop installer packaging (M19.20).
 *
 * Technical spec 26.6 builds Windows, macOS, and Linux installers from one
 * packaging configuration; the configuration itself is unit tested in
 * `apps/kiosk/src/packagingConfig.spec.ts`, and this script is the half that
 * only a real packaging run can prove: that electron-builder accepts the
 * configuration and produces the host platform's installer, stamped with the
 * root Meridian version, whose packaged resources contain the Kiosk client
 * build and no other UI mode's build (technical spec 26.4).
 *
 * It packages for the host platform only. Cross-platform packaging is what the
 * M19.23 release workflow exists for; a smoke test that tried to emit all
 * three would fail on every machine that is not a mac.
 *
 * The compiled wrapper and the built Kiosk client are prerequisites rather
 * than build steps here, mirroring how CI already builds them: run
 * `corepack pnpm run kiosk:build` first, or `corepack pnpm run kiosk:package`
 * to build and package in one step.
 *
 * Usage:
 *   node scripts/release/validate-desktop-packaging.mjs
 */

import { spawnSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const kioskDir = join(repositoryRoot, 'apps', 'kiosk');
const releaseDir = join(kioskDir, 'release');

const errors = [];

function fail(message) {
  errors.push(message);
}

function report() {
  if (errors.length > 0) {
    console.error('Desktop packaging smoke test failed:');
    for (const error of errors) {
      console.error(`- ${error}`);
    }
    process.exit(1);
  }
  console.log('Desktop packaging smoke test passed.');
}

const rootVersion = JSON.parse(
  readFileSync(join(repositoryRoot, 'package.json'), 'utf8'),
).version;

// Prerequisites: the compiled wrapper and the built Kiosk client artifact.
const prerequisites = [
  ['apps/kiosk/dist/main.js', 'the compiled wrapper'],
  ['apps/kiosk/dist/packagingConfig.js', 'the compiled packaging configuration'],
  ['apps/client/dist/kiosk/index.html', 'the built Kiosk client'],
];
for (const [relative, description] of prerequisites) {
  if (!existsSync(join(repositoryRoot, relative))) {
    fail(`${relative} is missing (${description}); run \`corepack pnpm run kiosk:build\` first.`);
  }
}
if (errors.length > 0) {
  report();
}

// Package for the host platform. electron-builder downloads the Electron
// distribution for the bundle, which is why CI runs this as its own step: a
// mirror outage should be legible as itself, not as a repository failure.
const build = spawnSync(
  process.execPath,
  [join(repositoryRoot, 'scripts', 'release', 'package-desktop.mjs')],
  {
    cwd: repositoryRoot,
    stdio: 'inherit',
  },
);
if (build.status !== 0) {
  fail(`electron-builder exited with status ${build.status ?? 'unknown'}.`);
  report();
}

// The host platform's installer exists and carries the root version.
const installerSuffix = { win32: '.exe', darwin: '.dmg', linux: '.AppImage' }[process.platform];
const artifacts = existsSync(releaseDir) ? readdirSync(releaseDir) : [];
const installers = artifacts.filter((name) => name.endsWith(installerSuffix));

if (installers.length === 0) {
  fail(`no ${installerSuffix} installer was produced in apps/kiosk/release.`);
} else {
  for (const installer of installers) {
    if (!installer.includes(`meridian-kiosk-${rootVersion}-`)) {
      fail(
        `installer ${installer} does not carry the root package.json version ${rootVersion}.`,
      );
    }
  }
}

// The packaged resources contain the Kiosk client build and no other UI
// mode's build (technical spec 26.4).
const resourcesDir = {
  win32: join(releaseDir, 'win-unpacked', 'resources'),
  linux: join(releaseDir, 'linux-unpacked', 'resources'),
  darwin: join(releaseDir, 'mac', 'Meridian Kiosk.app', 'Contents', 'Resources'),
}[process.platform];

if (!existsSync(resourcesDir)) {
  fail(`the unpacked resources directory ${resourcesDir} was not produced.`);
} else {
  if (!existsSync(join(resourcesDir, 'app.asar'))) {
    fail('the packaged application archive (app.asar) is missing from resources.');
  }
  if (!existsSync(join(resourcesDir, 'client', 'kiosk', 'index.html'))) {
    fail('the packaged resources do not contain the Kiosk client build at client/kiosk.');
  }
  for (const otherMode of ['admin', 'field']) {
    if (existsSync(join(resourcesDir, 'client', otherMode))) {
      fail(
        `the packaged resources contain the ${otherMode} client build; ` +
          'the desktop installer packages Meridian Kiosk and no other UI mode (technical spec 26.4).',
      );
    }
  }
}

report();
