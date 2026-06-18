# Meridian Technology Baseline

Status: Draft  
Baseline date: 2026-06-16  
Owner: Meridian maintainers  
Applies to: all Meridian feature work, refactors, migrations, CI changes, packaging work, and dependency updates

## Purpose

This document defines the approved technology baseline for Meridian so that human contributors and AI coding agents implement features using current, supported, secure, and project-aligned packages.

The goal is not to chase every newest release immediately. The goal is to keep Meridian on modern, supported versions while protecting the project from unnecessary churn, unstable prereleases, abandoned libraries, duplicated framework stacks, and dependency drift.

This document should be reviewed before each milestone begins and whenever a task proposes a new dependency or major version change.

## Core rule

Every implementation task must use this document as the dependency baseline unless the task explicitly updates this document.

Codex and other coding agents must not introduce a new runtime, framework, package manager, database, sync engine, desktop wrapper, mobile wrapper, authentication system, UI framework, component library, or test runner without documenting the reason and updating this baseline.

Exact patch versions are enforced by lockfiles and CI. This document defines the approved major/minor baseline and dependency policy.

## Approved stack baseline

| Area | Approved baseline | Version constraint / pinning guidance | Notes |
|---|---:|---|---|
| Backend framework | Laravel 13.x | `laravel/framework:^13.0` | Primary application framework. Laravel business rules, authorization, validation, auditing, and mutation handling remain server-owned. |
| PHP runtime | PHP 8.5.x target | `>=8.5 <8.6` for project runtime once available in all target environments | Laravel 13 requires PHP 8.3+, but Meridian should target PHP 8.5.x for active support and forward compatibility. PHP 8.4.x may be used only as a temporary local/dev fallback if needed. |
| PHP dependency manager | Composer 2.10.x | Use latest stable `2.10.x`; commit `composer.lock` | Composer audit and dependency policy must run in CI. |
| Database | PostgreSQL 18.x | Use current patched `18.x`; avoid floating `latest` tags | PostgreSQL is the canonical server database. Client-side SQLite exists only for offline sync/client state. |
| Offline sync service | PowerSync Service 1.22.x | Prefer explicit Docker tag such as `journeyapps/powersync-service:1.22.0` until reviewed | Self-hosted sync service. PowerSync is sync infrastructure, not the source of business-rule truth. |
| Offline sync web/client SDK | PowerSync JavaScript/Web Client SDK 1.38.x | Use latest compatible `1.38.x`; lock exact package version | Used for offline-first web/PWA/Electron client state. |
| Admin/back-office | Orchid Platform 14.x | `orchid/platform:^14.0`, locked by Composer | Used for admin/god-mode and generic data administration, not as the primary staff-facing UI. |
| JavaScript runtime | Node.js 24 LTS | `24.x`; prefer current patched 24.x in CI | Do not move to Node 26 until it is LTS and Meridian compatibility is verified. |
| JavaScript package manager | pnpm 11.x | Set `packageManager` in `package.json`; commit `pnpm-lock.yaml` | Use Corepack. Do not use npm or yarn for project installs unless explicitly approved. |
| Frontend build tool | Vite 8.x | `vite:^8.0`, locked by pnpm | Used for Laravel asset builds and the Vue field app. Vue plugin via `@vitejs/plugin-vue:^6.0`. |
| Field app framework | Vue 3.x | `vue:^3.5`, locked by pnpm | Staff-facing field/mobile application framework. Do not introduce a competing SPA framework. |
| Field app router | Vue Router 4.x | `vue-router:^4.5`, locked by pnpm | Client-side routing for the Vue field app. |
| Frontend language | TypeScript 5.x | `typescript:^5.7`, locked by pnpm | Used for the Vue field app and generated clients. Type-check Vue single-file components with `vue-tsc`. |
| CSS framework | Tailwind CSS 4.3.x | `tailwindcss:^4.3`, locked by pnpm | Use Meridian semantic tokens and component rules on top of Tailwind. |
| Desktop wrapper | Electron 42.x | Use latest compatible patched `42.x`; lock exact version | Used for installable on-site/kiosk workstation. Keep patched aggressively due to Chromium security updates. |
| Mobile wrapper | Capacitor 8.x | `@capacitor/*:^8.0`, locked by pnpm | Used for installable mobile builds where needed. The PWA/browser experience must remain functional for MVP-critical workflows. |
| PHP testing | Pest 4.x | `pestphp/pest:^4.0`, locked by Composer | Preferred PHP test runner. PHPUnit may remain as the underlying compatibility layer. |
| JavaScript/Vue testing | Vitest 4.x | `vitest:^4.1`, locked by pnpm | Vite-native unit/component test runner for the field app. Use with `@vue/test-utils:^2.4` and a `jsdom` environment. |
| PHP formatting | Laravel Pint 1.x | `laravel/pint:^1.0`, locked by Composer | Required in CI. |
| PHP static analysis | PHPStan + Larastan current stable | Prefer maintained `larastan/larastan`, not abandoned package names | Required before merging once configured. |
| Containers | Docker Compose current stable | Pin production-like images by major/minor or digest | Used for local, central, and on-site deployment profiles. |
| CI | GitHub Actions maintained action majors | Pin actions by major; update regularly | CI is the source of truth for accepted runtime versions. |

## Non-goals

This document does not replace:

- `composer.lock`
- `pnpm-lock.yaml`
- Docker image tags
- CI workflow runtime declarations
- local setup documentation
- architecture decision records
- security review for sensitive features

This document describes the approved baseline. The lockfiles and CI enforce exact installable versions.

## Versioning policy

### Runtime versions

Runtime versions must be declared in project configuration, CI, and developer setup files.

Required files should include, as applicable:

- `.tool-versions`, `.node-version`, `.php-version`, or equivalent runtime declarations
- `composer.json`
- `composer.lock`
- `package.json`
- `pnpm-lock.yaml`
- Dockerfiles
- Docker Compose files
- GitHub Actions workflows

The runtime version in CI is the source of truth for whether a task is acceptable.

### Lockfiles

Lockfiles are mandatory.

- Commit `composer.lock`.
- Commit `pnpm-lock.yaml`.
- Do not hand-edit lockfiles.
- Regenerate lockfiles only with the approved package manager.
- Dependency update PRs must clearly describe why the lockfile changed.

### Composer dependency rules

Use Composer constraints that allow safe minor and patch updates within approved major versions.

Recommended pattern:

- First-party framework packages: caret constraints within the approved major.
- Infrastructure-sensitive packages: narrower constraints where appropriate.
- No abandoned Composer packages unless explicitly approved.
- No prerelease packages unless explicitly approved.
- No packages with incompatible licenses.
- Run Composer audit in CI.
- Prefer package names that are currently maintained. For Larastan, prefer `larastan/larastan`; do not introduce abandoned package names such as `nunomaduro/larastan`.

### JavaScript dependency rules

Use pnpm for JavaScript dependencies.

Recommended pattern:

- Set the `packageManager` field in `package.json`.
- Use Corepack to activate the pinned pnpm major.
- Commit `pnpm-lock.yaml`.
- Prefer exact saved versions where practical.
- Avoid duplicate framework stacks.
- Do not introduce React, Vue, Svelte, Inertia, Livewire, or a SPA architecture unless the UI architecture document is updated first.
- Do not introduce a new component library unless the component library specification is updated first.
- Avoid packages with low maintenance, unclear license status, or unnecessary runtime weight.

### Docker image rules

Docker images must use explicit major/minor tags or digests where appropriate.

Do not use floating `latest` tags in production-like Compose files.

Development-only examples may use floating tags only when the file clearly states that they are not production baselines.

### Prerelease rules

Alpha, beta, release candidate, nightly, dev-main, and branch-alias dependencies are not allowed for production or MVP-critical features unless explicitly approved.

An exception must include:

- why a stable release is not viable
- what behavior depends on the prerelease
- how the risk is mitigated
- what test coverage protects the integration
- the expected removal or review date

## Meridian-specific architecture constraints

### Laravel remains the backend source of truth

Business rules, validation, authorization, auditing, and mutation handling belong in Laravel.

PowerSync is used for offline sync, local reads, and resilient client operation. It must not become the business-rule authority.

### PostgreSQL is the canonical database

PostgreSQL is the canonical server database for Meridian.

Client-side SQLite exists because of PowerSync and offline operation. Client-side data must be treated as synced local state, not as the final authority for permission-sensitive decisions.

### Offline-first behavior is mandatory

New features must state whether they are:

- fully offline-capable
- read-only offline
- queue-write offline
- online-required
- server-only/admin-only

Any feature that affects event operations, shift boards, check-in/out, deployments, equipment, field reports, or IMS must be designed with offline operation in mind.

### IMS and sensitive records require extra caution

Incident records, DNS status, sensitive staff information, exports, and audit logs require strict permission checks and audit behavior.

Dependencies that touch these areas must be conservative, well-maintained, and easy to inspect.

### Orchid is for admin/god-mode surfaces

Orchid is approved for internal administration and generic back-office workflows.

Orchid should not define the primary staff-facing, department lead, shift board, kiosk, or IMS user experience unless explicitly approved by the UI specification.

### Electron is for the on-site workstation

Electron is approved for the installable on-site/kiosk workstation.

Electron work must account for:

- kiosk/shared workstation use
- user switching
- 12-hour sessions
- offline operation
- local network/self-signed certificate realities
- reliable recovery after app or machine restart
- Chromium security updates

### Capacitor is for mobile packaging

Capacitor is approved for installable mobile builds where needed.

The browser/PWA experience should remain functional without requiring native-only behavior for MVP-critical workflows.

### Authentication remains external-provider based

Do not introduce internal username/password login.

Authentication must remain aligned with the Meridian technical spec:

- external providers only
- minimum email identity
- magic link acceptable
- Google and Discord acceptable
- provider association by email
- multiple providers per email
- kiosk/shared workstation user switching
- offline token behavior for trusted on-site nodes

## Dependency approval checklist

When a task proposes a new dependency, the PR must answer:

1. What package is being added?
2. What exact version is being added?
3. What problem does it solve?
4. Why are Laravel, PHP, browser APIs, existing project utilities, or current dependencies insufficient?
5. Is the package actively maintained?
6. What is the license?
7. Does it affect offline behavior?
8. Does it affect sync behavior?
9. Does it affect kiosk/shared-device behavior?
10. Does it touch IMS, DNS, emergency contacts, credentials, exports, audit logs, or other sensitive data?
11. What is the bundle/runtime impact?
12. What tests prove the dependency is integrated correctly?
13. What is the rollback plan?

If these questions cannot be answered, the dependency should not be added.

## Codex instructions

Every Codex task prompt should include the following instruction:

> Before coding, read `docs/meridian-technology-baseline.md`. Use the approved stack and versions. Do not introduce, replace, or upgrade dependencies unless the PR explicitly documents the reason and satisfies the dependency approval checklist. Keep exact installed versions in lockfiles. If a requested implementation appears to require a dependency outside this baseline, stop and document the proposed dependency change before implementing the feature.

When Codex changes dependencies, the PR notes must include:

- dependency name
- old version, if applicable
- new version
- why the change was necessary
- whether the change is a patch, minor, major, or new dependency
- affected lockfiles
- affected CI files
- local setup impact
- tests run
- rollback notes

## CI requirements

Every PR must run:

- Composer install from lockfile
- pnpm install from lockfile
- Laravel tests
- frontend build
- PHP formatting check
- PHP static analysis once configured
- JavaScript/TypeScript linting once configured
- dependency audit checks
- migration checks
- offline/sync-relevant tests when affected

CI must fail when:

- unsupported runtime versions are used
- lockfiles are missing or inconsistent
- Composer audit reports unacceptable vulnerabilities
- pnpm audit reports unacceptable vulnerabilities
- formatting fails
- tests fail
- migrations fail
- frontend build fails
- generated client/sync artifacts are stale

## Suggested CI/runtime declarations

These examples are guidance only. Keep them aligned with actual project files.

### `package.json`

```json
{
  "packageManager": "pnpm@11.x",
  "engines": {
    "node": ">=24 <25",
    "pnpm": ">=11 <12"
  }
}
```

### `composer.json`

```json
{
  "require": {
    "php": ">=8.5 <8.6",
    "laravel/framework": "^13.0",
    "orchid/platform": "^14.0"
  },
  "require-dev": {
    "pestphp/pest": "^4.0",
    "laravel/pint": "^1.0",
    "larastan/larastan": "^3.0"
  }
}
```

If PHP 8.5 is not yet available in a specific local developer environment, use a documented temporary fallback rather than silently lowering project requirements.

## Update cadence

### Security updates

Security updates should be handled immediately.

A security update PR may change package versions outside the normal update cadence, but it must still pass all required checks.

### Routine updates

Routine dependency review should happen at least monthly during active development.

The review should check:

- PHP supported versions
- Laravel supported versions
- Composer release/security status
- Node LTS status
- pnpm release status
- PostgreSQL minor releases
- PowerSync Service and SDK releases
- Electron stable/security releases
- Capacitor support status
- Vite supported versions
- Tailwind supported versions
- Orchid compatibility
- Pest/PHPStan/Larastan compatibility
- GitHub Actions deprecations
- Docker image security updates

### Major upgrades

Major upgrades require a dedicated PR.

A major upgrade PR must include:

- why the upgrade is needed
- compatibility notes
- migration notes
- local setup changes
- CI changes
- data migration impact
- sync/offline impact
- rollback notes
- acceptance criteria
- human QA checklist

Do not mix major upgrades with feature work unless the feature cannot be completed without the upgrade.

## Version update checklist

When updating this document, also update:

- `composer.json`
- `composer.lock`
- `package.json`
- `pnpm-lock.yaml`
- Dockerfiles
- Docker Compose files
- GitHub Actions workflows
- local setup documentation
- Codex task prompt templates
- acceptance criteria templates
- deployment documentation

## Exception policy

Exceptions are allowed only when documented.

An exception must include:

- the package or version being excepted
- why the baseline cannot be followed
- the risk
- the mitigation
- the expected removal date or review date

Temporary exceptions should not become permanent architecture.

## Review triggers

Review this document:

- before each milestone begins
- before dependency-heavy work
- before packaging the on-site Electron app
- before pilot deployment
- after any major security advisory
- after Laravel, PHP, Node, PostgreSQL, PowerSync, Electron, Capacitor, Vite, Tailwind, Orchid, or Pest releases a new major version

## Source references checked for this baseline

These references were checked when drafting this baseline on 2026-06-16:

- Laravel 13 release notes: <https://laravel.com/docs/13.x/releases>
- PHP supported versions: <https://www.php.net/supported-versions.php>
- Composer 2.10.1 changelog: <https://getcomposer.org/changelog/2.10.1>
- Composer 2.10 release notes: <https://blog.packagist.com/composer-2-10-release/>
- Node.js releases: <https://nodejs.org/en/about/previous-releases>
- Node.js 26 current release note: <https://nodejs.org/en/blog/release/v26.0.0>
- PostgreSQL release notes: <https://www.postgresql.org/docs/release/>
- PowerSync Service 1.22.0 release note: <https://releases.powersync.com/announcements/powersync-service>
- PowerSync JavaScript/Web Client SDK 1.38.3 release note: <https://releases.powersync.com/announcements/powersync-js-web-client-sdk>
- PowerSync local Docker documentation: <https://docs.powersync.com/tools/local-development>
- Electron releases: <https://github.com/electron/electron/releases>
- Electron release cadence: <https://electronjs.org/docs/latest/tutorial/electron-timelines>
- Capacitor 8 update guide: <https://capacitorjs.com/docs/updating/8-0>
- Capacitor 8 announcement: <https://ionic.io/blog/announcing-capacitor-8>
- Vite 8 announcement: <https://vite.dev/blog/announcing-vite8>
- Tailwind CSS 4.3 announcement: <https://tailwindcss.com/blog/tailwindcss-v4-3>
- Orchid documentation: <https://orchid.software/en/docs/>
- Pest v4 documentation: <https://pestphp.com/docs/pest-v4-is-here-now-with-browser-testing>
- Larastan repository: <https://github.com/larastan/larastan>
