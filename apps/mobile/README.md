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
- [Release packaging runbook](../../docs/process/release-packaging.md) (M19.24):
  release builds for both platforms, signing credentials, TestFlight and Play
  internal testing track uploads, and the direct APK install.

## Stack

- Capacitor 8 (`@capacitor/core`, `@capacitor/cli`, `@capacitor/android`,
  `@capacitor/ios`) for installable mobile packaging.
- Committed native platform projects under `android/` and `ios/`
  (technical spec 26.4; M19.21). The iOS project resolves Capacitor through
  Swift Package Manager (`ios/App/CapApp-SPM`); no CocoaPods step exists.
- TypeScript and Vitest for packaging configuration validation.
- `sharp` (dev-only) for regenerating the committed launcher icons and splash
  screens from the Meridian identity mark.

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

## Native platform projects (M19.21)

The Android and iOS projects are committed under `android/` and `ios/` rather
than generated on each machine, so the application identifier, product name,
permission declarations, icons, and build settings are reviewable in a diff
and identical on every machine that builds a release (technical spec 26.4).
The copied web bundles (`android/app/src/main/assets/public`,
`ios/App/App/public`) and the per-sync generated config files are build
artifacts and stay gitignored; `mobile:cap:sync` builds the Field artifact and
places them.

```bash
# After each client build, copy assets and update native dependencies
corepack pnpm run mobile:cap:sync
```

### Version derivation

Neither native project declares a version of its own:

- **Android** — `android/app/build.gradle` reads the root `package.json` at
  build time, setting `versionName` to the root version and `versionCode` to
  `major*1_000_000 + minor*1_000 + patch`. An unreadable manifest or a
  non-numeric version fails the build.
- **iOS** — the project declares a `0.0.0` placeholder, and the
  "Stamp Meridian Version" build phase writes `CFBundleShortVersionString`
  (root version) and `CFBundleVersion` (same packed form as Android's
  `versionCode`) into the built `Info.plist`. A version the phase cannot
  resolve fails the build.

Because the version is read at build time, the production version-bump
workflow never regenerates either project.

### Native permissions

The permission inventory is code, not convention:
[`src/nativePermissions.ts`](src/nativePermissions.ts) lists every native
permission with the shipped surface that asks for it, and
`src/nativeProjects.spec.ts` fails the build when the Android manifest or the
iOS `Info.plist` drifts from it in either direction. Alpha 1 declares
`INTERNET` (every surface talks to a node) and the camera — the M18.61
`staff.workstation-code` QR path scans through the WebView's `getUserMedia`
with `BarcodeDetector`, so no native barcode plugin exists and the camera
feature stays optional (`android:required="false"`) because the typed
short-code fallback always works.

### Launcher icons and splash screens

All committed launcher icons, adaptive-icon layers, and splash screens are
generated from the Meridian identity mark (`assets/icon.png`, the same 512px
image `apps/kiosk/assets/icon.png` builds the desktop icons from) over the
`--m-surface-app` light token `#f6f1e8`:

```bash
corepack pnpm run mobile:assets:generate
```

Rerun it only when the identity mark or the surface color changes, and commit
the regenerated files.
