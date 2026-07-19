# Meridian Mobile

The Meridian Capacitor mobile packaging wrapper.

`apps/mobile` does not own product UI. It packages the shared Vue client from
`apps/client/dist/field` for iOS and Android as Meridian Field. Product routes,
screens, mobile-first layout, offline UX, and operational workflows are
maintained in `apps/client`.

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
