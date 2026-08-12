#!/usr/bin/env node
/**
 * Verify a staged set of release artifacts against the root version (M19.23).
 *
 * Technical spec 26.7: a versioned release produces its artifacts from one
 * build of the tagged commit, and each artifact carries the root version that
 * tag names. The release workflow runs this over the staging directory after
 * every build job and before anything is attached, so the release either
 * carries the complete, correctly versioned set or does not exist.
 *
 * Three ways to fail, each named separately because each has a different fix:
 *
 *   - an expected artifact is missing, which means a build job silently
 *     produced nothing;
 *   - an artifact carries a version other than the root `package.json`
 *     version, which means some build step resolved its version from
 *     somewhere else;
 *   - a file nobody expected is in the staging directory, which would be
 *     attached to the release without any rule having vouched for it.
 *
 * The version is read from the root `package.json` and nowhere else
 * (docs/process/versioning-strategy.md); there is deliberately no flag to
 * supply a different one.
 *
 * The iOS application archive is not in the expected set: it is produced from
 * the same tagged commit through the `docs/process/release-packaging.md`
 * runbook (M19.24), because building it requires a macOS signing environment
 * holding the Apple distribution certificate and provisioning profile that
 * the release automation does not have. The workflow's release notes state
 * that rather than silently omitting it (technical spec 26.7).
 *
 * Usage:
 *   node scripts/release/verify-release-artifact-versions.mjs <staging-dir>
 */

import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

/**
 * The complete artifact set a release attaches, as filename patterns over the
 * root version: the server and web images and the deployment bundle
 * (technical spec 26.4's "server image and deployment bundle"), the three
 * desktop installers (26.6), and the Android app bundle and APK (26.5).
 */
export function expectedReleaseArtifacts(version) {
  const v = version.replaceAll('.', '\\.');

  return [
    { name: 'server image', pattern: new RegExp(`^meridian-server-${v}\\.tar\\.gz$`) },
    { name: 'web image', pattern: new RegExp(`^meridian-server-web-${v}\\.tar\\.gz$`) },
    {
      name: 'deployment bundle',
      pattern: new RegExp(`^meridian-deployment-bundle-${v}\\.tar\\.gz$`),
    },
    {
      name: 'Windows desktop installer',
      pattern: new RegExp(`^meridian-kiosk-${v}-win-[A-Za-z0-9_]+\\.exe$`),
    },
    {
      name: 'macOS desktop installer',
      pattern: new RegExp(`^meridian-kiosk-${v}-mac-[A-Za-z0-9_]+\\.dmg$`),
    },
    {
      name: 'Linux desktop installer',
      pattern: new RegExp(`^meridian-kiosk-${v}-linux-[A-Za-z0-9_]+\\.AppImage$`),
    },
    { name: 'Android app bundle', pattern: new RegExp(`^meridian-field-${v}\\.aab$`) },
    { name: 'Android APK', pattern: new RegExp(`^meridian-field-${v}\\.apk$`) },
  ];
}

function main() {
  const stagingDir = process.argv[2];

  if (!stagingDir) {
    console.error('Usage: node scripts/release/verify-release-artifact-versions.mjs <staging-dir>');

    return 1;
  }

  const manifest = JSON.parse(readFileSync(join(repositoryRoot, 'package.json'), 'utf8'));

  if (manifest.name !== 'meridian' || typeof manifest.version !== 'string') {
    console.error('Cannot resolve the root Meridian version from package.json.');

    return 1;
  }

  const version = manifest.version;

  if (!/^\d+\.\d+\.\d+$/.test(version)) {
    console.error(
      `The root package.json version must be numeric major.minor.patch, received: ${version}`,
    );

    return 1;
  }

  const directory = resolve(stagingDir);
  const files = readdirSync(directory).filter((name) =>
    statSync(join(directory, name)).isFile(),
  );
  const expected = expectedReleaseArtifacts(version);
  const errors = [];

  for (const artifact of expected) {
    if (!files.some((name) => artifact.pattern.test(name))) {
      errors.push(
        `The ${artifact.name} is missing: no file matches ${artifact.pattern}. ` +
          'A release attaches the complete artifact set or does not exist (technical spec 26.7).',
      );
    }
  }

  for (const name of files) {
    if (!expected.some((artifact) => artifact.pattern.test(name))) {
      errors.push(
        `Unexpected file '${name}' in the staging directory. Every attached artifact must ` +
          `carry the root version ${version} and match a known artifact kind.`,
      );
    }
  }

  if (errors.length > 0) {
    console.error(`Release artifact verification failed against root version ${version}:`);

    for (const error of errors) {
      console.error(`- ${error}`);
    }

    return 1;
  }

  console.log(
    `Release artifact verification passed: ${files.length} artifact(s), every one carrying the root version ${version}.`,
  );

  return 0;
}

if (process.argv[1] && resolve(process.argv[1]) === resolve(fileURLToPath(import.meta.url))) {
  process.exit(main());
}
