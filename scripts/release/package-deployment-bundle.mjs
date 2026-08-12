#!/usr/bin/env node
/**
 * Package the deployment configuration bundle for a release (M19.23).
 *
 * Technical spec 26.7 attaches the deployment bundle to a versioned release
 * beside the server image it configures. The bundle is the committed tree the
 * smoke test validates on every pull request, so this step packages exactly
 * the shared manifest (`scripts/deploy/deployment-bundle-manifest.mjs`) that
 * the smoke test requires — a file the release shipped and the smoke test
 * never checked, or the reverse, would be two different bundles under one
 * name.
 *
 * The artifact is named with the root `package.json` version, which is the
 * only Meridian product version (docs/process/versioning-strategy.md). A root
 * version this script cannot resolve fails the run rather than producing an
 * artifact that looks distributable.
 *
 * Usage:
 *   node scripts/release/package-deployment-bundle.mjs [--out <dir>]
 *
 * The default output directory is `release-artifacts` at the repository root,
 * which is gitignored: release artifacts are attached to the release, never
 * committed (technical spec 26.7).
 */

import { spawnSync } from 'node:child_process';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

import { DEPLOYMENT_BUNDLE_FILES } from '../deploy/deployment-bundle-manifest.mjs';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

function argument(name, fallback) {
  const index = process.argv.indexOf(name);

  return index === -1 || index === process.argv.length - 1 ? fallback : process.argv[index + 1];
}

function main() {
  const manifest = JSON.parse(readFileSync(join(repositoryRoot, 'package.json'), 'utf8'));

  if (manifest.name !== 'meridian') {
    console.error('The root package.json is not the Meridian root package; refusing to package.');

    return 1;
  }

  if (typeof manifest.version !== 'string' || !/^\d+\.\d+\.\d+$/.test(manifest.version)) {
    console.error(
      `The root package.json version must be numeric major.minor.patch, received: ${manifest.version}`,
    );

    return 1;
  }

  const missing = DEPLOYMENT_BUNDLE_FILES.filter(
    (file) => !existsSync(join(repositoryRoot, file)),
  );

  if (missing.length > 0) {
    console.error('Cannot package an incomplete deployment bundle:');

    for (const file of missing) {
      console.error(`- ${file} is missing.`);
    }

    return 1;
  }

  const outputDir = resolve(repositoryRoot, argument('--out', 'release-artifacts'));
  const artifactName = `meridian-deployment-bundle-${manifest.version}.tar.gz`;
  const artifact = join(outputDir, artifactName);

  mkdirSync(outputDir, { recursive: true });

  // tar runs from the output directory and writes the archive by bare name:
  // GNU tar reads a colon in `-f` as a remote host, so a Windows drive-letter
  // path there breaks a script that works everywhere else.
  const result = spawnSync(
    'tar',
    ['-czf', artifactName, '-C', repositoryRoot, ...DEPLOYMENT_BUNDLE_FILES],
    { cwd: outputDir, stdio: 'inherit' },
  );

  if (result.status !== 0) {
    console.error(`Packaging the deployment bundle failed with status ${result.status ?? 'unknown'}.`);

    return result.status ?? 1;
  }

  console.log(`Packaged the deployment bundle into ${artifact}.`);

  return 0;
}

process.exit(main());
