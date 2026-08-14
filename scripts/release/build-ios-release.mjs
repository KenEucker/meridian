#!/usr/bin/env node
/**
 * Archive and export the Meridian Field iOS application locally
 * (M19.22 / spec 26.5).
 *
 * This is the iOS counterpart of `build-android-release.mjs`, and it exists
 * for the same reason: the signing credentials are supplied through the
 * environment (technical spec 26.5), and on the release maintainer's machine
 * the environment is populated from `.env` at the repository root, which is
 * gitignored. Exported variables win over `.env` lines, so the same command
 * works unchanged on a machine that exports them instead.
 *
 * The archive itself is still `apps/mobile/scripts/build-ios-release.sh`,
 * unchanged: that script is what `apps/mobile/src/releaseSigning.spec.ts`
 * holds the signing configuration against, and it keeps its own refusal for a
 * missing credential. This wrapper only resolves where the values come from,
 * and fails earlier with a message that names the file to put them in.
 *
 * Usage:
 *   corepack pnpm run mobile:ios:release
 */

import { spawnSync } from 'node:child_process';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { loadReleaseEnv, missingCredentials } from './release-env.mjs';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');
const mobileDir = join(repositoryRoot, 'apps', 'mobile');
const archiveScript = join(mobileDir, 'scripts', 'build-ios-release.sh');
const envFilePath = join(repositoryRoot, '.env');

const REQUIRED_CREDENTIALS = [
  'MERIDIAN_IOS_TEAM_ID',
  'MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE',
  'MERIDIAN_IOS_PROVISIONING_PROFILE',
];

// Xcode is the only thing that produces this artifact, and it runs on macOS
// alone. Saying so here beats a `xcodebuild: not found` several steps in.
if (process.platform !== 'darwin') {
  console.error(
    `The Meridian Field iOS archive is built with Xcode and requires macOS; this is ${process.platform}.\n` +
      'See docs/process/release-packaging.md, Meridian Field iOS application archive.',
  );
  process.exit(1);
}

const env = loadReleaseEnv(envFilePath);

const missing = missingCredentials(env, REQUIRED_CREDENTIALS);
if (missing.length > 0) {
  console.error(
    `Missing iOS release signing credentials: ${missing.join(', ')}.\n` +
      `Add them to ${envFilePath} (gitignored) as KEY=value lines, or export\n` +
      'them in the shell. See docs/process/release-packaging.md, Credentials.',
  );
  process.exit(1);
}

const result = spawnSync('sh', [archiveScript], { cwd: mobileDir, env, stdio: 'inherit' });

process.exit(result.status ?? 1);
