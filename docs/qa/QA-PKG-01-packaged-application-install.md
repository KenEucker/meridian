# QA-PKG-01: Packaged Application Install

## Purpose

Verify that what a release actually ships installs and runs. Every other
script exercises builds a developer started; this one starts from the files a
release attaches and the store tracks a release uploads to — the three desktop
installers on their own operating systems, and Meridian Field from TestFlight,
from the Play internal testing track, and from the direct APK — because the
milestone 19 QA gate is explicit that the critical scripts run against
installed applications, and installation is the one step no development
environment rehearses.

The questions are the install-time ones: does each unsigned desktop artifact's
first-run trust warning match what the install document says it will be, does
each installed app launch and report the version metadata M19.1 specifies in
its Settings Versions section, does the installed Kiosk open fullscreen with
the health panel reachable, and does the installed Field app actually operate
against an on-site node rather than only launching.

This script also records what each OS's trust warning actually showed and
holds [`docs/process/release-packaging.md`](../process/release-packaging.md)
to it. Technical spec 26.6 requires the install document to state the warning,
and OS behavior moves between OS releases; a divergence between the document
and reality is a documentation issue filed against the runbook, not a QA
failure of the build.

## Requirements covered

- Development process section 20 — the packaged-install half of the milestone
  19 QA gate: a second human installs Meridian Kiosk from a desktop installer
  and Meridian Field from its internal testing track onto a real device, with
  every app reporting the same root version.
- Technical spec: Section 25.3 Health panel — reachable in the installed
  wrapper, not only in development.
- Technical spec: Section 26.3 Versioning — the Settings Versions section
  reporting the app version, UI mode, and deployment target on every installed
  artifact.
- Technical spec: Section 26.6 Desktop application distribution — unsigned
  Alpha 1 artifacts, and the install document stating the first-run trust
  warning each OS shows.
- Technical spec: Section 26.5 Mobile application distribution — the two
  internal tracks and the direct APK are the three install paths this script
  walks.

## Environment

- The release artifacts of one tagged version `v<version>`, built from that
  one commit: the Windows `.exe`, macOS `.dmg`, and Linux `.AppImage`
  installers and the direct-install APK from the release (M19.23), the same
  build uploaded to TestFlight and rolled out to the Play internal testing
  track per [`docs/process/release-packaging.md`](../process/release-packaging.md)
  (M19.24). Nothing in this script is built on the machine it is installed on.
- One machine per desktop OS: a Windows machine, a Mac, and a Linux machine,
  each without a Meridian Kiosk install already present.
- An iPhone or iPad with the TestFlight app, invited to the internal testing
  group; an Android device opted in to the Play internal testing track; and an
  Android device for the direct APK with no route to the Play Store — either a
  device without Google Play or one joined to a network with no internet
  route. One Android device can serve both paths, but not at once: a
  Play-track install and the direct APK are signed by different keys and will
  not install over each other (see the runbook's one-device-one-path note), so
  uninstall between sections F and G.
- A node deployed from the committed deployment bundle (`deploy/`) at the same
  root version, reachable from the install machines and devices over the local
  network per its own deployment documents — the on-site node
  [`QA-SYNC-01`](QA-SYNC-01-onsite-central-sync.md) stands up, or the
  `standalone` node [`QA-RC-02`](QA-RC-02-install-deployment-dry-run.md)
  stands up where the run has no central node to pair with. Not
  `php artisan serve`: the gate is installed applications against a deployed
  node.

## Personas

- The installer — the second human of the milestone 19 QA gate, performing
  every install in this script.
- A seeded Northwood staff member — the account the installed Field app signs
  in as, to prove operation rather than only launch.

## Setup data

- The seeded Northwood development scenario on the node, with a staff account
  the installer can sign in as from a phone (the sign-in paths of
  [`QA-AUTH-01`](QA-AUTH-01-email-magic-link-login.md) and
  [`QA-AUTH-04`](QA-AUTH-04-on-site-device-sign-in.md) are the ways in).
- The installer's Apple ID in the TestFlight internal testing group and their
  Google account on the Play internal testing track's tester list, per the
  runbook's store-side sections.

## Steps

### A. The version under test

1. Record the release tag and the root `package.json` version at the tagged
   commit. Every version read in this script is compared against this one
   value.
2. Confirm each artifact's filename carries that version, and confirm the node
   the installs will run against was deployed from the same version's
   deployment bundle.

### B. Meridian Kiosk on Windows

3. Copy `meridian-kiosk-<version>-win-<arch>.exe` to the Windows machine and
   run it. Record exactly what the OS showed before the installer ran: the
   SmartScreen dialog's wording and the path past it (**More info**, then
   **Run anyway**), and any browser warning if the file arrived by download.
4. Complete the install and launch Meridian Kiosk. Confirm it opens the Kiosk
   UI fullscreen with no browser chrome or menu bar.
5. Press `Ctrl+Shift+H` and confirm the health panel opens in the installed
   app. Before any node is configured, confirm it reports the default node URL
   rather than values it cannot have.
6. Point the wrapper at the node: write `{"serverUrl": "https://<node>"}` to
   `node.json` in the app's per-user data directory (on Windows,
   `%AppData%\Meridian Kiosk\node.json`), reopen the health panel, and confirm
   the server-sourced fields fill from the node on the next refresh without
   reinstalling or restarting from a shell — an installed app has no
   environment variable to receive the node through.
7. In the wrapped UI, point the device at the node from the device readiness
   surface's node connection panel, then open **Settings** and read the
   **Versions** section. Confirm: the app is named Meridian Kiosk; **Desktop
   app version** and **Client bundle version** both read `<version>`; **UI
   mode** reads Kiosk; **Deployment target** reads desktop; **Server version**
   and **Config schema version** carry the node's values rather than
   "Unavailable"; and no mobile app version row appears.

### C. Meridian Kiosk on macOS

8. Mount `meridian-kiosk-<version>-mac-<arch>.dmg` on the Mac and open the
   app. Record exactly what Gatekeeper showed and the path past it (on current
   macOS, **System Settings → Privacy & Security → Open Anyway**; on older
   versions, right-click **Open**).
9. Repeat steps 4 through 7 on the installed app, with the macOS entries: the
   health panel shortcut is `Cmd+Shift+H`, and the per-user data directory is
   `~/Library/Application Support/Meridian Kiosk`.

### D. Meridian Kiosk on Linux

10. Copy `meridian-kiosk-<version>-linux-<arch>.AppImage` to the Linux
    machine, set the execute bit (`chmod +x`), and run it. Record what the OS
    showed — the expected answer is no trust dialog at all, and the execute
    bit standing in for one — and whether FUSE had to be installed first.
11. Repeat steps 4 through 7 on the running app, with the Linux per-user data
    directory `~/.config/Meridian Kiosk`.

### E. Meridian Field from TestFlight

12. On the iPhone or iPad, install the `<version>` build from the TestFlight
    app and launch it.
13. Point the device at the node from the device readiness surface's node
    connection panel, and sign in as the seeded staff member.
14. Confirm the app operates against the node: the home surface renders the
    signed-in session's content read from the node, not an error and not an
    empty shell.
15. Open **Settings → Versions**. Confirm: the app is named Meridian Field;
    **Mobile app version** reads `<version> (ios)`; **Client bundle version**
    reads `<version>`; **UI mode** reads Field; **Deployment target** reads
    mobile; **Server version** and **Config schema version** carry the node's
    values; and no desktop app version row appears.

### F. Meridian Field from the Play internal testing track

16. On the Android device, open the internal testing opt-in link, accept, and
    install Meridian Field from the Play Store. Confirm the listed version is
    `<version>`.
17. Repeat steps 13 through 15, expecting **Mobile app version** to read
    `<version> (android)`.

### G. Meridian Field from the direct APK, with no route to the Play Store

18. Get `meridian-field-<version>.apk` onto the direct-install device by local
    means — USB, a local file share, or `adb install` — with the device on a
    network with no internet route (or with no Play Store at all). If this is
    the section F device, uninstall the Play-track copy first.
19. Install it. Record exactly what the device showed: the
    **install unknown apps** permission prompt if the APK was opened from a
    file manager, and Play Protect's wording — unrecognized app, or unable to
    scan while offline — and the choice made to proceed.
20. Repeat steps 13 through 15 against the on-site node over the local
    network, expecting **Mobile app version** to read `<version> (android)`.
    This is the install path the spec keeps for exactly this network, so the
    sign-in and the home surface loading here prove the case the APK exists
    for.

### H. The install document held to reality

21. Compare the warnings recorded in steps 3, 8, 10, and 19 against the
    descriptions in [`docs/process/release-packaging.md`](../process/release-packaging.md)
    ("The unsigned first-run warning, per OS", and the Play Protect note in
    the direct-APK section). File a documentation issue against the runbook
    for every divergence — a warning worded differently enough to confuse, a
    step past it that no longer exists, a warning the document does not
    mention, or a documented warning that no longer appears.
22. Confirm every version read in this script — three desktop installs, three
    mobile installs — equals the step 1 root version.

## Expected results

- Each desktop installer installs and launches on its own OS, fullscreen with
  no browser chrome, with the health panel opening on the shortcut and
  filling from the configured node.
- Each installed app's Settings Versions section reports the M19.1 metadata:
  the product name, the app version equal to the root version (**Desktop app
  version** on Kiosk, **Mobile app version** with the platform on Field), the
  client bundle version, the UI mode, the deployment target, and the node's
  server and config schema versions once connected. The wrapper rows appear
  only in their own wrapper — no mobile row on desktop, no desktop row on
  mobile.
- The installed Field app signs in and operates against the deployed node
  from all three install paths, including the direct APK on a network with no
  internet route.
- Every install reports the same root version; no artifact was rebuilt or
  patched on an install machine to make it do so.
- The trust warnings each OS actually showed are recorded, and the install
  document's descriptions match them — or a documentation issue is filed for
  each place they do not.

## Evidence to capture

- The release tag and root version, and where each artifact came from (the
  release attachment, TestFlight, the Play track).
- A photo or screenshot of each trust warning encountered (Windows
  SmartScreen, macOS Gatekeeper, Android install-unknown-apps and Play
  Protect), and a note that Linux showed none.
- A screenshot of the Settings Versions section on every install — three
  desktop, three mobile — and of the health panel filled from the node on one
  desktop install.
- A screenshot or photo of the installed Field app's home surface signed in
  against the node, from the direct-APK device.
- The OS name and version of every machine and device used, since trust
  warning behavior moves with the OS.
- Links to any documentation issues filed in step 21.

## Failure notes

- Distinguish the two failure kinds: an installed app that does not install,
  launch, connect, or report its versions fails this script against the
  build; a trust warning that does not match the install document is a
  documentation issue against the runbook and does not by itself fail the
  build.
- Record the OS or device model and OS version with every failure — the same
  installer can pass on one Windows build and trip a different SmartScreen
  policy on another.
- If the direct APK refuses to install over a Play-track copy (or the
  reverse), that is the documented signer conflict, not a build failure:
  uninstall and install again, and record that the runbook's
  one-device-one-path note was exercised.
- Record the node URL configured and whether the health panel and the client
  readiness surface agreed about reachability; a version row reading
  "Unavailable" with the node reachable is a finding, not an environment
  problem.
