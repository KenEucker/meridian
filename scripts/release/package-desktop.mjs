#!/usr/bin/env node
/**
 * Run electron-builder for the Meridian Kiosk desktop installers (M19.20).
 *
 * A thin launcher rather than a plain package.json script line for one
 * Windows-shaped reason: electron-builder's node-module collector spawns
 * `pnpm` from the PATH to enumerate production dependencies, and when the
 * packaging run itself was started through `corepack pnpm ...`, the inherited
 * `COREPACK_*` variables make a standalone pnpm launcher believe corepack
 * invoked it. It then refuses to self-switch to the project's pinned pnpm
 * version and fails its own version check. Scrubbing the corepack variables
 * from the child environment lets pnpm resolve the pinned version from the
 * nearest manifest the way it does in any other shell.
 *
 * The electron-builder CLI is resolved from `@meridian/kiosk`'s own
 * dependencies and run with this Node, so no `.cmd` shim or shell is involved.
 *
 * Usage:
 *   node scripts/release/package-desktop.mjs
 */

import { spawnSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const kioskDir = join(repositoryRoot, 'apps', 'kiosk');

const kioskRequire = createRequire(join(kioskDir, 'package.json'));
const electronBuilderCli = kioskRequire.resolve('electron-builder/out/cli/cli.js');

const env = { ...process.env };
for (const key of Object.keys(env)) {
  if (key.toUpperCase().startsWith('COREPACK')) {
    delete env[key];
  }
}

const result = spawnSync(
  process.execPath,
  [electronBuilderCli, '--config', 'electron-builder.config.cjs', '--publish', 'never'],
  { cwd: kioskDir, env, stdio: 'inherit' },
);

process.exit(result.status ?? 1);
