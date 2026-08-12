# Meridian Release Packaging

This is the distribution runbook technical spec 26.7 names (M19.24): from a
clean checkout to an installable artifact for each target, then the store-side
path that puts Meridian Field in a tester's hands. It exists so that producing
a release is a document someone follows, not a set of steps someone remembers —
and because one artifact, the iOS application archive, is *only* produced this
way: the release automation has no macOS signing environment, so the runbook is
the iOS build path, not a fallback for it.

Technical spec 26.4 through 26.7 govern what is built here;
`docs/process/versioning-strategy.md` governs the version every artifact
carries; development process section 20 is the checklist a release candidate is
held to, and the exercise record at the end of this document is how the
"install/deployment docs tested by a second person" discipline extends to
release packaging.

Public App Store and Google Play listings — store metadata, screenshots,
privacy declarations, age ratings, review submission — are beta scope
(technical spec 26.5). This document ends at TestFlight, the Play internal
testing track, and the direct APK. Packaged applications do not self-update in
Alpha 1 (technical spec 26.6); a new version is installed the way the first one
was, following this same document.

## What a release is

A release is the tag `v<version>`, where `<version>` is the root
`package.json` version at the tagged commit. Pushing the tag runs
`.github/workflows/release-artifacts.yml`, which builds everything it can from
that one commit, verifies every artifact carries the root version, and attaches
the set to the GitHub release. See "Cutting a release" in
`docs/process/versioning-strategy.md` for the tag rules and the dry run.

| Artifact | Built by |
|---|---|
| Server image, web proxy image, deployment configuration bundle | The workflow (and this runbook, when building by hand) |
| Meridian Kiosk installers: Windows `.exe`, macOS `.dmg`, Linux `.AppImage` | The workflow, one per host runner (and this runbook, one per host OS) |
| Meridian Field Android app bundle (`.aab`) and direct-install APK | The workflow (and this runbook) |
| Meridian Field iOS application archive and `.ipa` | **This runbook only** — the automation has no macOS signing environment |

Everything the workflow builds, this document also describes building by hand,
because a runbook that only covers the gap cannot be followed end to end when
the automation is unavailable — and because the store-side steps start from an
artifact in your hands either way. When following this document for a real
release, build from the tagged commit, so the by-hand artifacts and the
attached ones are the same build input.

## Credentials

Every credential is held outside the repository and supplied to the build
through the environment; a build that cannot find one fails rather than
emitting an unsigned artifact that looks distributable (technical spec 26.5).
A repository test (`apps/mobile/src/releaseSigning.spec.ts`) fails the build if
key material is ever committed, and the environment variable inventory below is
code (`apps/mobile/src/releaseSigning.ts`) that the build configurations are
tested against.

| Credential | Supplied as | Held where |
|---|---|---|
| Android upload keystore (file) | `MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE` (path); in CI, the `MERIDIAN_ANDROID_UPLOAD_KEYSTORE_BASE64` repository secret | The release maintainer's secure credential store; a base64 copy in the GitHub repository secrets. Not recoverable if lost — Play accepts an upload-key reset only through a support request. |
| Android upload keystore password | `MERIDIAN_ANDROID_UPLOAD_KEYSTORE_PASSWORD` | Credential store; GitHub repository secret of the same name |
| Android upload key alias | `MERIDIAN_ANDROID_UPLOAD_KEY_ALIAS` | Credential store; GitHub repository secret of the same name |
| Android upload key password | `MERIDIAN_ANDROID_UPLOAD_KEY_PASSWORD` | Credential store; GitHub repository secret of the same name |
| Apple Developer team identifier | `MERIDIAN_IOS_TEAM_ID` | Visible in the Apple Developer account (Membership details); not secret, but environment-supplied like the rest |
| Apple distribution certificate | `MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE` (the signing identity name, e.g. `Apple Distribution: ...`) | The certificate and its private key live in the build Mac's login Keychain; a `.p12` export is the backup, in the credential store. The certificate is re-issuable from the developer portal, but the private key of an existing certificate is not re-downloadable. |
| App Store provisioning profile | `MERIDIAN_IOS_PROVISIONING_PROFILE` (the profile name) | Created and re-downloadable at developer.apple.com; installed on the build Mac |
| App Store Connect sign-in | — | The Apple ID of a team member with the Admin or App Manager role; used interactively for the application record and the upload |
| Play Console sign-in | — | The Google account on the Play Console developer account; used interactively for the application record and the upload |

### One-time provisioning

These exist before the first release and are not repeated per release:

- **Apple Developer Program membership** for the team, and a **Play Console
  developer account**. These are the accounts technical spec 26.5 requires to
  exist and have been exercised during Alpha 1.
- **Bundle identifier**: register `org.meridian.field` as an App ID at
  developer.apple.com under Certificates, Identifiers & Profiles → Identifiers.
- **Apple distribution certificate**: create an *Apple Distribution*
  certificate from a certificate signing request generated on the build Mac,
  install it in the login Keychain, and export a `.p12` backup into the
  credential store.
- **App Store provisioning profile**: create an *App Store Connect*
  distribution profile for `org.meridian.field` using that certificate,
  download and install it on the build Mac. `MERIDIAN_IOS_PROVISIONING_PROFILE`
  is this profile's name.
- **Android upload keystore**: generate once and store it in the credential
  store, never in the repository:

  ```bash
  keytool -genkeypair -v -keystore meridian-upload.keystore \
    -alias meridian-upload -keyalg RSA -keysize 4096 -validity 10000
  ```

  With Play App Signing (enrolled at the first app bundle upload), this
  keystore is the *upload* key: Google holds the app signing key and re-signs
  what the Play Store delivers. The upload key only ever authenticates uploads.

## From a clean checkout

Every target starts the same way. Prerequisites common to all of them: Git,
Node.js 24 with corepack (the repository pins pnpm 11 through the root
`packageManager` field).

```bash
git clone https://github.com/KenEucker/meridian.git
cd meridian
git checkout v<version>   # the tagged release commit; see "What a release is"
corepack pnpm install --frozen-lockfile
```

Clone with full history — the changelog step below reads the whole first-parent
timeline, so a shallow clone cannot build the server image correctly.

Per-target prerequisites are listed with each target. Nothing below builds a
client bundle implicitly: each packaging step packages an already built
artifact and refuses if it is missing (technical spec 26.4).

## Server image and deployment bundle

**Prerequisites:** Docker with Compose.

```bash
node scripts/release/generate-changelog.mjs   # packaged God Mode changelog, from git history
corepack pnpm run deploy:build                # builds meridian/server:<version> and meridian/server-web:<version>
mkdir -p release-artifacts
docker save meridian/server:<version> | gzip > release-artifacts/meridian-server-<version>.tar.gz
docker save meridian/server-web:<version> | gzip > release-artifacts/meridian-server-web-<version>.tar.gz
corepack pnpm run release:bundle              # tars the deployment configuration bundle into release-artifacts/
```

The changelog must be generated before the images are built: the image build
copies `apps/server` in, and a released image renders the God Mode Changelog
page without network access.

Installing from these artifacts is the deployment document's job, not this
one's: a node is brought up from the deployment bundle per
[`docs/technician/deployment.md`](../technician/deployment.md), loading the
images with `docker load` instead of building them.

## Meridian Kiosk desktop installers

**Prerequisites:** the host OS you are packaging for. electron-builder packages
for the platform it runs on — a Windows installer is built on Windows, a macOS
DMG on macOS, a Linux AppImage on Linux. There is no cross-build; three
installers means three machines (or the workflow's three runners), all reading
the one committed configuration so the three cannot drift (technical spec
26.6).

```bash
corepack pnpm run kiosk:package
```

That builds the Kiosk client artifact, compiles the wrapper, and packages the
host platform's installer into `apps/kiosk/release/` (gitignored):
`meridian-kiosk-<version>-win-<arch>.exe`,
`meridian-kiosk-<version>-mac-<arch>.dmg`, or
`meridian-kiosk-<version>-linux-<arch>.AppImage`. Only the installer files are
release artifacts; blockmaps and updater metadata are not attached, because
packaged desktop applications do not self-update in Alpha 1.

### The unsigned first-run warning, per OS

Alpha 1 desktop artifacts are unsigned on purpose — code signing and macOS
notarization are beta scope (technical spec 26.6). Expect the following, and
tell the person installing to expect it; an install document that omits the
warning teaches the operator to distrust the document.

- **Windows**: running the `.exe` raises the Microsoft Defender SmartScreen
  dialog — "Windows protected your PC / Microsoft Defender SmartScreen
  prevented an unrecognized app from starting." Click **More info**, then
  **Run anyway**. The browser that downloaded it may also warn that the file
  "isn't commonly downloaded"; choose to keep it.
- **macOS**: opening the app from the mounted DMG is blocked with a dialog
  saying the app cannot be opened because Apple cannot verify it — on macOS 15
  and later, the only path past it is **System Settings → Privacy & Security**,
  scroll to the blocked-app notice, and click **Open Anyway** (then confirm).
  On older macOS versions, right-clicking the app and choosing **Open** offers
  an Open button directly.
- **Linux**: the AppImage shows no trust dialog, but it downloads without the
  execute bit — `chmod +x meridian-kiosk-<version>-linux-<arch>.AppImage`
  before running it. Some distributions additionally need FUSE 2 installed for
  AppImages to run.

## Meridian Field Android app bundle and APK

**Prerequisites:** JDK 21 and the Android SDK (command-line tools are enough;
`ANDROID_HOME` set or `apps/mobile/android/local.properties` pointing at the
SDK). Gradle itself arrives through the committed wrapper.

Supply the four signing credentials through the environment — the Gradle
task-graph guard fails a release build that is missing any of them, before it
can emit `app-release-unsigned.apk`:

```bash
export MERIDIAN_ANDROID_UPLOAD_KEYSTORE_FILE=/path/to/meridian-upload.keystore
export MERIDIAN_ANDROID_UPLOAD_KEYSTORE_PASSWORD=...
export MERIDIAN_ANDROID_UPLOAD_KEY_ALIAS=meridian-upload
export MERIDIAN_ANDROID_UPLOAD_KEY_PASSWORD=...
```

Build the Field client into the native project, then the signed artifacts:

```bash
corepack pnpm run mobile:cap:sync
cd apps/mobile/android
./gradlew --no-daemon bundleRelease assembleRelease
```

Outputs, both signed with the upload key and carrying the root version stamped
by the Gradle configuration (M19.21):

- `apps/mobile/android/app/build/outputs/bundle/release/app-release.aab` — the
  app bundle the Play internal testing track takes.
- `apps/mobile/android/app/build/outputs/apk/release/app-release.apk` — the
  direct-install APK.

When staging them as release artifacts, rename to the release names the
workflow uses: `meridian-field-<version>.aab` and
`meridian-field-<version>.apk`.

## Meridian Field iOS application archive

**Prerequisites:** a Mac with a current Xcode, the distribution certificate in
the login Keychain, and the provisioning profile installed (see One-time
provisioning). This is the artifact only this runbook produces.

Supply the three signing credentials through the environment — the script
refuses before invoking Xcode if any is missing:

```bash
export MERIDIAN_IOS_TEAM_ID=...
export MERIDIAN_IOS_DISTRIBUTION_CERTIFICATE="Apple Distribution: ..."
export MERIDIAN_IOS_PROVISIONING_PROFILE="..."
```

Build the Field client into the native project, then archive and export:

```bash
corepack pnpm run mobile:cap:sync
corepack pnpm run mobile:ios:archive
```

The script (`apps/mobile/scripts/build-ios-release.sh`) archives with manual
release signing and exports an App Store Connect `.ipa`:

- `apps/mobile/ios/App/build/MeridianField.xcarchive`
- `apps/mobile/ios/App/build/export/` — the exported `.ipa`

The build directory is gitignored, including the `ExportOptions.plist` the
script generates from the environment credentials.

## Store-side: App Store Connect and TestFlight

**Needs:** the App Store Connect sign-in (Admin or App Manager role) and the
exported `.ipa`.

**One-time — create the application record.** In App Store Connect → Apps →
**＋** → New App: platform iOS, name **Meridian Field**, the primary language,
the registered `org.meridian.field` bundle ID, and an SKU (use the bundle ID).
The record holds builds; none of the beta-scope listing metadata is filled in
for Alpha 1.

**Per release — upload and distribute:**

1. Upload the `.ipa` with the **Transporter** app (Mac App Store), signed in
   with the App Store Connect Apple ID: add the `.ipa`, Deliver. (An archive
   opened in Xcode's Organizer can upload with **Distribute App** instead;
   both land in the same place.)
2. In App Store Connect → the app → **TestFlight**, wait for the build to
   finish processing, then answer the export compliance question when the
   build asks for it (Meridian Field uses only standard HTTPS/TLS encryption,
   which falls under the standard exemption).
3. Add the build to an **internal testing** group. Internal groups hold App
   Store Connect team members (up to 100) and need no beta review. Testers get
   an email invitation and install the build through the **TestFlight** app on
   their device.

## Store-side: Play Console internal testing track

**Needs:** the Play Console sign-in and the signed `.aab`.

**One-time — create the application record.** In the Play Console → All apps →
**Create app**: name **Meridian Field**, the default language, type App, free,
and the required declarations. The store listing itself is beta scope; the
internal testing track works without a published listing.

**Per release — upload and distribute:**

1. In the app → Testing → **Internal testing** → **Create new release**.
   The first upload prompts enrollment in **Play App Signing**: accept —
   Google generates and holds the app signing key, and the keystore this
   repository's builds sign with becomes the upload key (see Credentials).
2. Upload `meridian-field-<version>.aab`, name the release after the version,
   and roll it out to internal testing.
3. On the **Testers** tab, maintain the tester email list (up to 100) and
   share the **opt-in URL**. A tester opens the opt-in link, accepts, and then
   installs Meridian Field from the Play Store like any app — except it is
   visible only to the list.

## Direct APK install, with no route to the Play Store

The direct APK is not a convenience: an on-site network may have no usable
internet route, and the installed app is exactly the client that case depends
on (technical spec 26.5). This path assumes the APK reaches the device by
local means — USB, a local file share, or `adb` from the on-site laptop.

**One device, one path.** A Play-track install is signed by Google's app
signing key; the direct APK is signed by the upload key. Android treats them
as different signers, so one will not install over the other — installing the
direct APK on a device that got Meridian Field from the Play Store (or the
reverse) requires uninstalling first. Pick the path a device will live on.

Either:

- **With `adb`** (developer options and USB debugging enabled on the device):

  ```bash
  adb install meridian-field-<version>.apk
  ```

- **By file transfer**: copy the APK to the device, open it from the device's
  file manager, and when prompted allow that app to **install unknown apps**
  (Settings → Apps → Special app access → Install unknown apps). Google Play
  Protect may interject that the app is unrecognized or, offline, that it
  cannot be scanned — choose to install anyway. Like the desktop trust
  warnings, this is the expected face of an internally distributed build, and
  the person installing should be told to expect it.

Verify the install the way M19.25's packaged-application QA does: launch
Meridian Field, open Settings → Versions, and confirm the mobile app version
reported is `<version>`.

## Exercising this document

The evidence this task (M19.24) and development process section 20 require is
second-person: someone other than this document's author performs a release
build and an internal-track upload *by following this document*, with no step
supplied from memory — the same discipline as
[`QA-RC-02`](../qa/QA-RC-02-install-deployment-dry-run.md), applied to release
packaging. What is under test is the document. A step that cannot be completed
without asking the author is a documentation failure, however easily the
author could have answered.

The exercise record captures:

1. **Starting state**: machine(s) and OS, preinstalled tooling and versions,
   and confirmation the checkout is clean and fresh.
2. **The commit**: the tag or commit the artifacts were built from, and the
   root `package.json` version at that commit.
3. **A step log**: for each section followed, whether it behaved as
   documented, diverged, or stuck — and for every stuck point, what the
   document said, what happened, and how it was resolved. Author intervention
   fails the run.
4. **The artifacts**: the produced files with their names and versions, and
   for at least one target, proof the version inside matches (Settings →
   Versions, or the installer's version stamp).
5. **The uploads**: the build visible in TestFlight and processing complete,
   and the release live on the Play internal testing track with its opt-in
   link — the proof technical spec 26.5 asks for, that the accounts, signing
   identities, and upload path exist and have been exercised.
6. **The store warnings encountered** (SmartScreen, Gatekeeper, Play
   Protect), so this document's descriptions stay honest against current OS
   behavior.
7. **Filed issues** for every stuck point and divergence.

Installed-application behavior — that what was installed actually runs against
a node and reports its versions — is `QA-PKG-01` (M19.25), not this record.
