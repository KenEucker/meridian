#!/usr/bin/env node
/**
 * Validation for the tagged release workflow (M19.23).
 *
 * Technical spec 26.7 makes a versioned release build every artifact from one
 * commit, stamp each with the root `package.json` version, and attach them to
 * the release rather than committing them. The workflow itself only runs on a
 * tag, so this is the half that holds it on every pull request:
 *
 *   - the workflow exists, triggers on version tags, and offers the
 *     workflow_dispatch dry run;
 *   - it refuses a tag that names a version other than the root manifest's;
 *   - every job builds the tag's own commit — no checkout overrides its ref;
 *   - every release artifact kind is built: the server and web images, the
 *     deployment bundle, the three desktop installers, and the Android app
 *     bundle and APK;
 *   - the Android signing credentials arrive from repository secrets through
 *     the exact environment variables the M19.22 inventory declares;
 *   - the artifact set is verified against the root version before anything
 *     is attached, and attachment happens only on a tag;
 *   - the iOS archive's runbook is stated rather than silently omitted;
 *   - no release artifact is committed to the repository, and the staging
 *     directory cannot become committable.
 *
 * The verifier the workflow relies on is exercised for real: a staged fixture
 * set with every artifact passes, and a missing artifact, a wrongly versioned
 * artifact, and an unexpected file each fail.
 *
 * Usage:
 *   node scripts/release/validate-release-workflow.mjs
 */

import { spawnSync } from 'node:child_process';
import { existsSync, mkdtempSync, readFileSync, rmSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..', '..');

const WORKFLOW = '.github/workflows/release-artifacts.yml';
const VERIFY_SCRIPT = 'scripts/release/verify-release-artifact-versions.mjs';
const SIGNING_INVENTORY = 'apps/mobile/src/releaseSigning.ts';

const errors = [];

function fail(message) {
  errors.push(message);
}

function read(relativePath) {
  return readFileSync(join(repositoryRoot, relativePath), 'utf8');
}

function checkWorkflowShape(workflow) {
  if (!/tags:\s*\n\s*-\s*"v\[0-9\]\+\.\[0-9\]\+\.\[0-9\]\+"/.test(workflow)) {
    fail(`${WORKFLOW} does not trigger on version tags (v<major>.<minor>.<patch>).`);
  }

  if (!workflow.includes('workflow_dispatch:')) {
    fail(`${WORKFLOW} offers no workflow_dispatch dry run.`);
  }

  if (!workflow.includes('does not name the root package.json version')) {
    fail(
      `${WORKFLOW} does not refuse a tag that names a version other than the root package.json version.`,
    );
  }

  // One build of the tagged commit (technical spec 26.7): every job checks
  // out the commit the run was triggered for, and none may point its checkout
  // somewhere else.
  if (/^\s+ref:\s/m.test(workflow)) {
    fail(
      `${WORKFLOW} overrides a checkout ref. Every artifact must be built from the one tagged commit (technical spec 26.7).`,
    );
  }

  // Every artifact kind is built.
  const requiredReferences = [
    ['scripts/deploy/build-images.mjs', 'the deployment images build'],
    ['scripts/release/generate-changelog.mjs', 'the packaged changelog the server image carries (GOD-021)'],
    ['docker save "meridian/server:$VERSION"', 'saving the server image'],
    ['docker save "meridian/server-web:$VERSION"', 'saving the web image'],
    ['scripts/release/package-deployment-bundle.mjs', 'packaging the deployment bundle'],
    ['scripts/release/package-desktop.mjs', 'packaging the desktop installers'],
    ['bundleRelease', 'the Android app bundle'],
    ['assembleRelease', 'the Android APK'],
  ];

  for (const [reference, description] of requiredReferences) {
    if (!workflow.includes(reference)) {
      fail(`${WORKFLOW} never references ${reference} (${description}).`);
    }
  }

  // The three desktop installers come from three host platforms, one
  // packaging configuration (technical spec 26.6).
  if (!/os:\s*\[ubuntu-latest,\s*windows-latest,\s*macos-latest\]/.test(workflow)) {
    fail(`${WORKFLOW} does not build the desktop installers on ubuntu, windows, and macos runners.`);
  }

  // Verification precedes attachment, and attachment only happens on a tag.
  const verifyIndex = workflow.indexOf(VERIFY_SCRIPT);
  const attachIndex = workflow.indexOf('gh release create');

  if (verifyIndex === -1) {
    fail(`${WORKFLOW} never runs ${VERIFY_SCRIPT}, so nothing holds the artifact set to the root version.`);
  }

  if (attachIndex === -1) {
    fail(`${WORKFLOW} never attaches the artifacts to a release.`);
  } else {
    if (verifyIndex === -1 || verifyIndex > attachIndex) {
      fail(`${WORKFLOW} attaches artifacts before verifying their versions.`);
    }

    const gateIndex = workflow.indexOf("if: github.ref_type == 'tag'");

    if (gateIndex === -1 || gateIndex > attachIndex) {
      fail(
        `${WORKFLOW} does not gate release creation on a tag, so a workflow_dispatch dry run would publish a release.`,
      );
    }
  }

  // Attached, never committed: only the release job may write, and it writes
  // releases, not the repository.
  if (!/^permissions:\s*\n\s+contents: read/m.test(workflow)) {
    fail(`${WORKFLOW} does not default its permissions to contents: read.`);
  }

  if (!/permissions:\s*\n\s+contents: write/.test(workflow)) {
    fail(`${WORKFLOW} gives the release job no contents: write permission, so it cannot attach artifacts.`);
  }

  // The iOS archive is produced through the M19.24 runbook where the
  // automation has no macOS signing environment, and the workflow says so
  // rather than silently omitting it (technical spec 26.7).
  if (!workflow.includes('iOS') || !workflow.includes('docs/process/release-packaging.md')) {
    fail(
      `${WORKFLOW} does not state that the iOS archive is produced through the docs/process/release-packaging.md runbook (M19.24).`,
    );
  }
}

function checkAndroidCredentials(workflow) {
  // The M19.22 inventory is the single statement of which environment
  // variables carry the Android signing credentials; the workflow must supply
  // exactly those names, from secrets, with no in-repo value.
  const inventory = [
    ...read(SIGNING_INVENTORY).matchAll(/env: "(MERIDIAN_ANDROID_[A-Z_]+)"/g),
  ].map(([, name]) => name);

  if (inventory.length === 0) {
    fail(`Could not read the Android credential inventory from ${SIGNING_INVENTORY}.`);

    return;
  }

  for (const name of inventory) {
    if (!workflow.includes(name)) {
      fail(`${WORKFLOW} never supplies ${name}, which the Android release build demands (M19.22).`);
      continue;
    }

    // The keystore file is materialized from the base64 secret onto the
    // runner; every other credential is passed straight from its secret.
    if (name === 'MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE') {
      if (!new RegExp(`${name}: \\$\\{\\{ runner\\.temp \\}\\}/upload\\.keystore`).test(workflow)) {
        fail(`${WORKFLOW} does not point ${name} at the keystore materialized on the runner.`);
      }
      continue;
    }

    if (!new RegExp(`${name}: \\$\\{\\{ secrets\\.${name} \\}\\}`).test(workflow)) {
      fail(`${WORKFLOW} does not supply ${name} from the repository secret of the same name.`);
    }
  }

  if (!workflow.includes('secrets.MERIDIAN_ANDROID_UPLOAD_KEYSTORE_BASE64')) {
    fail(`${WORKFLOW} never materializes the upload keystore from its base64 secret.`);
  }
}

function checkNoCommittedArtifacts() {
  // Release artifacts are attached to the release, never committed (technical
  // spec 26.7). Any tracked file shaped like one fails, wherever it is.
  const tracked = spawnSync('git', ['ls-files', '-z'], {
    cwd: repositoryRoot,
    encoding: 'utf8',
    maxBuffer: 64 * 1024 * 1024,
  });

  if (tracked.status !== 0) {
    fail('Could not list tracked files with git ls-files.');

    return;
  }

  const paths = tracked.stdout.split('\0').filter((path) => path.length > 0);
  const artifactShapes = [
    /\.(exe|dmg|appimage|apk|aab|ipa)$/i,
    /^release-artifacts\//,
    /meridian-(server|server-web|deployment-bundle)-\d+\.\d+\.\d+\.tar\.gz$/,
  ];

  for (const path of paths) {
    if (artifactShapes.some((shape) => shape.test(path))) {
      fail(`${path} is a committed release artifact. Artifacts are attached to releases, never committed (technical spec 26.7).`);
    }
  }

  if (!/^release-artifacts\/$/m.test(read('.gitignore'))) {
    fail('.gitignore does not ignore release-artifacts/, the staging directory the workflow assembles.');
  }
}

function checkVersioningStrategyDocumentsWorkflow() {
  if (!read('docs/process/versioning-strategy.md').includes('release-artifacts.yml')) {
    fail(
      'docs/process/versioning-strategy.md does not name the release workflow; the Release Packaging section must describe how a release is cut.',
    );
  }
}

/**
 * Run the artifact verifier against a staged fixture set. This is the dry-run
 * half: the workflow's gate is only as good as the script it runs, so the
 * script is held to its three failure modes here, hermetically.
 */
function checkVerifierBehavior() {
  const version = JSON.parse(read('package.json')).version;
  const completeSet = [
    `meridian-server-${version}.tar.gz`,
    `meridian-server-web-${version}.tar.gz`,
    `meridian-deployment-bundle-${version}.tar.gz`,
    `meridian-kiosk-${version}-win-x64.exe`,
    `meridian-kiosk-${version}-mac-arm64.dmg`,
    `meridian-kiosk-${version}-linux-x86_64.AppImage`,
    `meridian-field-${version}.aab`,
    `meridian-field-${version}.apk`,
  ];

  const stagingDir = mkdtempSync(join(tmpdir(), 'meridian-release-fixture-'));

  try {
    for (const name of completeSet) {
      writeFileSync(join(stagingDir, name), 'fixture');
    }

    const verify = () =>
      spawnSync(process.execPath, [join(repositoryRoot, VERIFY_SCRIPT), stagingDir], {
        cwd: repositoryRoot,
        encoding: 'utf8',
      });

    const complete = verify();

    if (complete.status !== 0) {
      fail(
        `${VERIFY_SCRIPT} rejected a complete, correctly versioned artifact set:\n${complete.stderr.trim()}`,
      );
    }

    // An artifact carrying a version other than the root version is refused,
    // even beside a complete correct set.
    writeFileSync(join(stagingDir, 'meridian-field-9.9.9.apk'), 'fixture');

    if (verify().status === 0) {
      fail(`${VERIFY_SCRIPT} accepted an artifact whose version differs from the root package.json version.`);
    }

    unlinkSync(join(stagingDir, 'meridian-field-9.9.9.apk'));

    // A file no artifact rule vouches for is refused.
    writeFileSync(join(stagingDir, 'debug-symbols.zip'), 'fixture');

    if (verify().status === 0) {
      fail(`${VERIFY_SCRIPT} accepted an unexpected file in the staging directory.`);
    }

    unlinkSync(join(stagingDir, 'debug-symbols.zip'));

    // A missing artifact is refused: the release carries the complete set or
    // does not exist.
    unlinkSync(join(stagingDir, `meridian-kiosk-${version}-linux-x86_64.AppImage`));

    if (verify().status === 0) {
      fail(`${VERIFY_SCRIPT} accepted an artifact set with the Linux desktop installer missing.`);
    }
  } finally {
    rmSync(stagingDir, { recursive: true, force: true });
  }
}

function main() {
  if (!existsSync(join(repositoryRoot, WORKFLOW))) {
    fail(`${WORKFLOW} is missing. The tagged release workflow is M19.23 (technical spec 26.7).`);

    return report();
  }

  const workflow = read(WORKFLOW);

  checkWorkflowShape(workflow);
  checkAndroidCredentials(workflow);
  checkNoCommittedArtifacts();
  checkVersioningStrategyDocumentsWorkflow();
  checkVerifierBehavior();

  return report();
}

function report() {
  if (errors.length > 0) {
    console.error('Release workflow validation failed:');

    for (const error of errors) {
      console.error(`- ${error}`);
    }

    return 1;
  }

  console.log('Release workflow validation passed.');

  return 0;
}

process.exit(main());
