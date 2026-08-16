# QA-RUNTIPI-01: Runtipi App Install

## Purpose

Prove that Meridian installs on a real Runtipi host from the app definition
the M19.28 generator produces, by having a person follow
[`docs/process/runtipi-distribution.md`](../process/runtipi-distribution.md)
with no step supplied from memory — and, on the first run, resolve the two
open questions `deploy/runtipi/README.md` carries, which were drawn from
Runtipi's documentation rather than a running instance and can only be closed
by observed behavior (M19.29; technical spec 26.2, 26.7).

A Runtipi install is one click against an app store repository, which means
everything this script exercises was decided earlier: the images must be
pullable (M19.26), the stack must serve correctly behind a proxy it does not
own (M19.27), and the app definition must refuse the installs that cannot
work (M19.28). What is under test here is whether those decisions survive
contact with a real host.

## Requirements covered

- Technical spec 26.2 (production/event safeguards hold under a platform
  install: HTTPS required, migrations run automatically with the backup
  warning, default secrets refused).
- Technical spec 26.7 (a release's published images are what a pulling
  platform actually installs).
- Technical spec 8.2, 8.6 (the node serves HTTPS-only through the platform's
  proxy; the forwarded scheme reaches Laravel).
- `deploy/runtipi/README.md` — the agreed design, including its two open
  questions, both of which this script closes with observed behavior.
- Development process section 20 (install/deployment documentation tested by
  a second person).

## Environment

- A real Runtipi host: an amd64 Linux machine running a current Runtipi
  release, with Docker working and the dashboard reachable. Record the
  Runtipi version — it becomes the version the app is documented as tested
  against.
- A Meridian release tagged **after** M19.26 landed, so
  `ghcr.io/keneucker/meridian-server:<version>` and
  `ghcr.io/keneucker/meridian-server-web:<version>` exist to pull.
- A domain for the app (Runtipi's local domain is acceptable for a LAN-only
  install; note the browser trust warning it produces), and working SMTP
  credentials the node can send login mail through.
- A workstation with a browser, on a network that reaches the Runtipi host.

## Personas

- **The installer:** a second person — not an author of the Runtipi
  distribution documentation — who follows the documents and records where
  they were insufficient. The author may watch and may not help.
- **The first user:** any person with a working email address, used to prove
  login mail is actually delivered. May be the installer.

## Setup data

- The app store repository URL produced by following the "Create the store
  repository" half of `docs/process/runtipi-distribution.md`, holding the
  generated app for the release under test.
- SMTP host, port, credentials, and a from-address the mail provider will
  accept.
- No Meridian data: this script starts from an empty node on purpose, because
  the install path is what is under test.

## Steps

1. On the Runtipi dashboard, add the Meridian app store under **Settings →
   App Stores** by pasting the store repository URL, following
   `docs/process/runtipi-distribution.md`. Record any step the document did
   not cover.
2. Confirm the Meridian app appears in the store listing with its logo and
   description, and that the description states the amd64-only constraint,
   the SMTP requirement, and the back-up-before-updating warning.
3. Open the app's install form. Confirm the application key seed and database
   password fields are pre-generated `random` fields, the node name, node
   role, SMTP host/port/from fields are required, and the form cannot be
   submitted without exposing the app on a domain (`force_expose`).
4. **Open question 1 (record the answer):** after install, inspect the app's
   stored environment (Runtipi's app settings or the generated app env file
   on disk) and record what `MERIDIAN_APP_KEY_SEED` actually holds for a
   `random` field with `encoding: "hex"` and `min: 64` — 64 hex characters,
   or 64 bytes rendered as 128. Either works; the observed answer closes the
   question in `deploy/runtipi/README.md`.
5. Install the app and watch its logs. Confirm: both images are pulled from
   `ghcr.io/keneucker` at the release version; the entrypoint logs
   `APP_KEY derived from MERIDIAN_APP_KEY_SEED`; the backup warning prints
   before migrations run; migrations complete; the secret safeguards pass;
   and the event-mode checks pass rather than stopping the container.
6. Open the app at its domain. Confirm the login screen renders over HTTPS
   with no mixed-content warnings in the browser console — every asset URL
   must be `https://`.
7. **Open question 2 (record the answer):** from the login screen, request a
   login code and inspect the mailed link, or load any page and inspect a
   generated absolute URL. If URLs carry `https://`, Runtipi's Traefik passes
   the forwarded scheme through and the M19.27 trusted-proxy path is
   sufficient; if any carries `http://`, record it — the proxied Caddyfile
   would then need to set the FastCGI `HTTPS` parameter explicitly, and that
   is a finding against M19.27, not a documentation nit.
8. Sign in with the mailed code. This proves the queue worker is running —
   login mail is queued, so a missing worker means the mail never sends.
9. Complete first-run node setup for the role chosen on the install form, and
   confirm the God Mode health surfaces show the scheduler firing (the
   diagnostics heartbeat runs every minute).
10. Restart the app from the Runtipi dashboard. Confirm the node comes back
    with sessions and encrypted configuration intact — the seed-derived key
    survived the container recreation.
11. Update or reinstall the app at the same version (Runtipi's update path),
    and confirm the same: data intact, key intact, migrations idempotent.
12. Record every place the documentation was insufficient, every step that
    needed knowledge the documents did not supply, and file a documentation
    issue for each.
13. Close the loop in the repository: update the "Open questions" section of
    `deploy/runtipi/README.md` with both observed answers and the Runtipi
    version they were observed on.

## Expected results

- The store adds, the app lists, and the install form enforces exposure and
  the required fields (steps 1–3).
- The install pulls the published GHCR images at the release version and
  boots to a serving node with no manual container work (step 5).
- The site is fully HTTPS: no mixed content, and generated absolute URLs —
  including the mailed login link — carry `https://` (steps 6–7).
- Login mail arrives and sign-in works, proving worker and mail configuration
  (step 8); the scheduler heartbeat is visible (step 9).
- A restart and an update both preserve sessions and encrypted values,
  proving the derived key is stable (steps 10–11).
- Both open questions in `deploy/runtipi/README.md` are closed with observed
  behavior and the Runtipi version recorded (steps 4, 7, 13).

## Evidence to capture

- Who installed, on what date, on what hardware, and against which Runtipi
  version and which Meridian release.
- The per-step log with stuck points and how each was resolved, in the same
  spirit as [`QA-RC-02`](QA-RC-02-install-deployment-dry-run.md): what is
  under test is the documentation, not the person.
- A screenshot of the app store listing, the install form, and the login
  screen served over HTTPS.
- The app log excerpt showing the pulled image references, the derived-key
  line, the backup warning, and the migration run — with secrets redacted.
- The observed answers to both open questions, and the commit that records
  them in `deploy/runtipi/README.md`.
- The documentation issues filed.

## Failure notes

- The app not appearing in the store on an amd64 host is a store or
  `config.json` problem; the app *correctly* not appearing on an ARM host is
  the amd64-only declaration working as designed — do not file it.
- An install reachable at `http://<ip>:<port>` that answers 503 to everything
  means `force_expose` was lost from the app definition; that is a generator
  regression, not a Runtipi fault.
- Mixed content or an `http://` mailed link is the open-question-2 failure
  path: record exactly which URL was wrong and stop short of hand-editing the
  container — the fix belongs in M19.27's Caddyfile or trusted-proxy
  configuration, applied through a release.
- Login mail never arriving with a healthy-looking node usually means the
  worker container is not running or SMTP credentials are wrong; the
  distinction is visible in the worker container's logs.
- A failed migration on update is the back-up-before-updating warning made
  real: restore from the pre-update backup and file the failure against the
  release, not against this script.
