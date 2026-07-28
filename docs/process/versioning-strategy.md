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

Today that is the packaged operator documentation manifest,
`apps/server/resources/operator-docs/manifest.json`, which records the version
its documentation was packaged from so the God Mode Documentation page can show
it beside the running build version (GOD-017). The bump workflow runs
`scripts/release/package-operator-docs.mjs` and commits the result alongside
`package.json`.

The reason is that `docs:check` runs on every pull request. A bump that changes
the root version without regenerating the artifacts derived from it leaves the
repository in a state where every subsequent pull request fails a check for a
drift no contributor introduced and no contributor's change can fix. Bumping and
regenerating must therefore be one commit, not two.

A future artifact that embeds the root version inherits this rule and must be
added to the same workflow step.

## Beta and Release Promotion

Promotion between lifecycle stages is manual:

- Alpha uses `0.0.x`.
- Beta starts when a maintainer manually bumps the root version to `0.1.0`.
- Release starts when a maintainer manually bumps the root version to `1.0.0`.

During beta (`0.1.0` through `0.x.y`), changes may be classified as patch or
minor updates. The automatic production workflow can remain patch-only until a
separate change teaches it to honor that classification.

At `1.0.0` and higher, Meridian follows semantic versioning strictly.

## Future Release Packaging

A later release workflow will package Electron builds and mobile builds for
their target environments. That workflow must derive all app package versions
from the root `package.json`.

Packaged apps may only work with Meridian APIs that share the same major
version. For example, an app built as `1.4.2` can work only with `1.x.y` APIs.
Before `1.0.0`, the major version is `0`, so alpha and beta app builds must use
`0.x.y` APIs.
