# Meridian Versioning Strategy

## Source of Truth

The root `package.json` `version` field is the only Meridian product version
source of truth.

Workspace package manifests under `apps/*` and `packages/*` must not declare
their own `version` fields. Build tooling, server runtime configuration,
desktop health metadata, and future mobile/desktop packaging must read the root
version instead.

Versions must be numeric `major.minor.patch` values only. Do not use prerelease
labels, build metadata, or text suffixes such as `alpha`, `beta`, or `rc`.

## Current Alpha Policy

Meridian is currently in alpha and is not deployed as a used production
platform. While alpha remains below `0.1.0`, every pull request merged into
`production` increments the root patch version.

The `.github/workflows/production-version-bump.yml` workflow owns that bump. It
runs after a pull request into `production` is merged, updates the root
`package.json` patch number, and commits the bump back to `production`.

If a merged pull request already changed the root `package.json` version, the
workflow does not apply an additional patch bump. This keeps manual alpha to
beta and beta to release promotion PRs exact.

### Committed artifacts that carry the root version

Any generated artifact that is committed to the repository and records the root
version must be regenerated inside the version bump commit itself.

Today that is the packaged technician documentation manifest,
`apps/server/resources/technician-docs/manifest.json`, which records the version
its documentation was packaged from so the God Mode Documentation page can show
it beside the running build version (GOD-017). The bump workflow runs
`scripts/release/package-technician-docs.mjs` and commits the result alongside
`package.json`.

The reason is that `docs:check` runs on every pull request. A bump that changes
the root version without regenerating the artifacts derived from it leaves the
repository in a state where every subsequent pull request fails a check for a
drift no contributor introduced and no contributor's change can fix. Bumping and
regenerating must therefore be one commit, not two.

A future artifact that embeds the root version inherits this rule and must be
added to the same workflow step.

## Where Versions Are Displayed

Technical spec 26.3 requires the running version metadata to be visible. Alpha 1
shows it in three places, all resolved from the root version:

| Surface | Shows |
|---|---|
| God Mode console footer | The server build version (GOD-032, GOD-033). |
| Shared client, Settings → Versions | The app, the mobile or desktop app version when running inside a packaged app, the client bundle version, the UI mode, the deployment target, and the server and config schema versions read from `GET /api/health`. |
| Electron health panel (`Ctrl+Shift+H`) | The server and config schema versions, the client version, and the Electron wrapper version (technical spec 25.3). |

The desktop wrapper states its own version to the client through its preload
rather than letting the client assume the bundle's version is the wrapper's; the
two are separate artifacts and the case worth seeing is the one where they
disagree. The mobile app has no version of its own to state: it is the packaged
Meridian Field artifact, whose version is baked in at build time from the root
manifest, and native project versions must be stamped from the same value when
release packaging adds them.

Docker image tags and the version-mismatch rules in technical spec 26.3 — node
pairing rejecting incompatible major versions, clients warning on an
incompatible server, and Electron warning on an unexpected local server version
— are not part of this display and arrive with their own tasks.

## Beta and Release Promotion

Promotion between lifecycle stages is manual:

- Alpha uses `0.0.x`.
- Beta starts when a maintainer manually bumps the root version to `0.1.0`.
- Release starts when a maintainer manually bumps the root version to `1.0.0`.

During beta (`0.1.0` through `0.x.y`), changes may be classified as patch or
minor updates. The automatic production workflow can remain patch-only until a
separate change teaches it to honor that classification.

At `1.0.0` and higher, Meridian follows semantic versioning strictly.

## Release Packaging

The release workflow packages Electron builds and mobile builds for their target
environments. It must derive all app package versions from the root
`package.json`; no wrapper declares a version of its own.

The desktop packaging configuration is M19.20, the committed Capacitor native
projects are M19.21, mobile release signing is M19.22, the tagged release
workflow that produces and attaches every artifact is M19.23, the runbook is
M19.24, and installed-application QA is M19.25. Technical spec 26.4 through
26.7 govern them.

### Cutting a release

A release is cut by pushing the tag `v<version>`, where `<version>` is the root
`package.json` version at the tagged commit — `v0.0.152` releases `0.0.152`.
`.github/workflows/release-artifacts.yml` refuses a tag that names any other
version, because the tag is a claim about the artifacts and every artifact
version derives from the root manifest, not from the tag.

From that one commit the workflow builds the server and web images and the
deployment configuration bundle, the Windows, macOS, and Linux desktop
installers, and the signed Android app bundle and APK; verifies that every
produced artifact carries the root version
(`scripts/release/verify-release-artifact-versions.mjs`); and attaches the
artifacts to the GitHub release. Artifacts are attached to the release, never
committed to the repository (technical spec 26.7).

The iOS application archive is not produced by the workflow: it requires a
macOS signing environment holding the Apple distribution certificate and
provisioning profile, which the automation does not have. It is produced from
the same tagged commit through the `docs/process/release-packaging.md` runbook
(M19.24), and the workflow's release notes state that rather than silently
omitting it.

Running the workflow manually (`workflow_dispatch`) is a dry run: every
artifact is built and verified exactly as a tag build would, and nothing is
attached anywhere. `scripts/release/validate-release-workflow.mjs` holds the
workflow's shape and the verifier's behavior on every pull request, since the
workflow itself only runs on a tag.

Packaged apps may only work with Meridian APIs that share the same major
version. For example, an app built as `1.4.2` can work only with `1.x.y` APIs.
Before `1.0.0`, the major version is `0`, so alpha and beta app builds must use
`0.x.y` APIs.
