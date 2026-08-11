# Meridian Mobile

The Meridian Capacitor mobile packaging wrapper.

`apps/mobile` does not own product UI. It packages the shared Vue client from
`apps/client/dist/field` for iOS and Android as Meridian Field. Product routes,
screens, mobile-first layout, offline UX, and operational workflows are
maintained in `apps/client`. Native app versions must be derived from the root
`package.json` Meridian version when release packaging is added.

## Source references

- [ADR 0001: Shared Vue Client Across Web, Desktop, and Mobile](../../docs/adr/0001-shared-vue-client.md)
- Technical spec section 3.3 Mobile packaging wrapper.
- UI Implementation Contract section 3 Alpha 1 Technical Contract.

## Stack

- Capacitor 8 (`@capacitor/core`, `@capacitor/cli`) for installable mobile
  packaging.
- TypeScript and Vitest for packaging configuration validation.

## Local development

Install workspace dependencies from the repository root:

```bash
corepack pnpm install
```

Build the shared client assets that Capacitor packages:

```bash
corepack pnpm run mobile:build
```

Preview Meridian Field in a local browser dev server:

```bash
corepack pnpm run mobile:dev
```

Validate the mobile packaging configuration:

```bash
corepack pnpm run mobile:test
corepack pnpm run mobile:typecheck
```

## Capacitor

The Capacitor configuration lives in `capacitor.config.ts`:

| Field | Value | Purpose |
|---|---|---|
| `appId` | `org.meridian.field` | Reverse-domain native application identifier |
| `appName` | `Meridian Field` | Installed application display name |
| `webDir` | `../client/dist/field` | Fixed Meridian Field build output packaged into native platforms |

## Version

The mobile app version is the root `package.json` Meridian version, carried by
the packaged Meridian Field artifact: `apps/client` bakes the root version into
the bundle at build time, and `mobile:cap:copy` and `mobile:cap:sync` package
exactly that bundle. Capacitor's configuration has no version field of its own,
and per `docs/process/versioning-strategy.md` the wrapper must not declare one.

The shared client displays it in **Settings → Versions** as "Mobile app
version", together with the platform read from Capacitor's injected global
(M19.1; technical spec 26.3). The row appears only when Meridian is running as
the installed app; the same bundle opened in a phone browser reports its client
bundle version and no mobile app version, because there is no installed app to
report one for.

When native platform projects are added, their `versionName` and equivalent
must be stamped from the same root version rather than maintained separately.

Native platform projects are not committed yet. When a later milestone needs
installable native builds, add a platform and sync the built shared client
assets:

```bash
# One-time, per platform (requires platform SDKs installed locally)
corepack pnpm --filter @meridian/mobile exec cap add android
corepack pnpm --filter @meridian/mobile exec cap add ios

# After each client build, copy assets and update native dependencies
corepack pnpm run mobile:cap:sync
```

`mobile:cap:sync` builds the Field artifact and runs `cap sync`.
`mobile:cap:copy` builds the Field artifact and runs `cap copy`.
