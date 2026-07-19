# QA-READY-01: Device Readiness and Event-Mode Fail-Closed

## Purpose

Verify Milestone 8 device readiness and offline foundations for a human
reviewer: the shared client shows an honest advisory readiness checklist, shared
offline/sync status appears only when the device is offline, and event mode
fails closed when required HTTPS, PowerSync, local encryption, or device
signing capabilities are unavailable. This script closes the Milestone 8 QA
gate. It does not exercise offline field writes, PowerSync client uploads, or
later readiness signals (login, device trust, event selection, cache, sync).

## Requirements covered

- Technical spec: Section 8.2 Secure connection policy
- Technical spec: Section 8.6 HTTPS validation
- Technical spec: Section 12.3 Local encryption
- Technical spec: Section 12.4 Device signatures
- Technical spec: Section 14 Readiness
- Technical spec: Section 26.2 Production/event safeguards
- UI Implementation Contract: Section 11.13 OfflineBanner
- UI Implementation Contract: Section 16 Offline and Sync Contract
- Meridian Alpha 1 tasks M8.3 through M8.8
- Milestone 8 QA gate: a trusted device shows readiness state and event mode
  honestly fails closed when required capabilities are unavailable

## Environment

- Fresh checkout or task branch with workspace and server dependencies
  installed.
- Node.js 24 LTS and pnpm 11.x available via Corepack.
- PHP and Composer available for the Laravel server app under `apps/server`.
- A secure-context browser session for the shared client (localhost Vite/dev URL
  is sufficient; `http://localhost` is a secure context).
- Optional: a migrated local Laravel database if repeating the `/setup`
  fail-closed path from section D; tinker-based checks do not require a fresh
  database.

## Personas

- Field staff member reviewing their own device readiness (user-only checklist).
- Setup / node operator configuring an event-role node.
- Human reviewer confirming fail-closed behavior.

## Setup data

- No organization, event, staff, or device-trust seed data is required for the
  readiness checklist or offline banner.
- For section D server checks, use config overrides in tinker (no permanent
  `.env` edits). Restore nothing if you only call `evaluate()` / `ensureReady()`
  without writing nodes.
- Do not expect organizer-visible readiness anywhere; readiness is user-only
  per technical spec section 14.

## Steps

### A. Automated readiness and offline foundations

1. From the repository root, install workspace dependencies if needed:
   `corepack pnpm install`.
2. Run the shared client readiness and offline automated suite:
   `corepack pnpm --filter @meridian/client run test`.
3. From `apps/server`, run the server event-mode fail-closed feature tests:
   ```bash
   cd apps/server
   php artisan test --filter=EventMode
   ```

### B. Field app readiness checklist (M8.3–M8.5)

4. Start the Field dev server: `corepack pnpm run client:dev:field`.
5. Open the home route (for example `http://localhost:5173/`) and confirm the
   home placeholder includes a link labeled **Check device readiness**.
6. Follow the link (or open `/readiness` directly) and confirm the heading
   **Device readiness**.
7. Confirm the advisory lede states that the checklist is only for the user,
   is advisory, is not shared with organizers, does not expire, and that
   preparing a device is encouraged but not required.
8. Confirm the summary line reports a count in the form
   `N of 8 checks ready.`
9. Confirm the checklist lists exactly these eight items in this order, each
   with a textual status of **Ready**, **Not ready**, or **Pending** (state
   must not rely on color alone):
   1. Logged in
   2. Device trusted
   3. Event selected
   4. Local cache complete
   5. Encryption active
   6. Last sync completed
   7. Trusted server known
   8. Device signing available
10. On a normal localhost secure context, confirm **Encryption active** and
    **Device signing available** show **Ready** (local encryption and device
    signing capability probes succeed).
11. Confirm the other six items show **Pending** with detail
    `Not available yet in this build.` (honest pending, not a false pass or
    nagging failure).
12. Confirm there is no organizer, admin, or shared-team surface that displays
    this checklist in this build.

### C. Shared offline / sync status display (M8.6)

13. With the shared client still open and the device online, confirm no
    OfflineBanner is visible in the app shell (online is silent).
14. In browser DevTools, set the network condition to Offline (or otherwise
    make `navigator.onLine` false) and confirm an Offline banner appears with:
    - label **Offline but usable**
    - meaning **Local work can continue.**
15. Confirm the banner is a non-blocking status region (page content remains
    usable; no interruptive dialog).
16. Restore network / Online and confirm the banner disappears again.
17. Confirm richer sync labels (`Local node reachable`, `Central unreachable`,
    `Queued`, `Sync conflict`, `Sync failed`) are not invented by the live
    shell today; those states are covered by the automated Offline banner /
    connectivity model tests from step 2 and are wired to real PowerSync /
    node-sync signals in later milestones.

### D. Server event-mode fail-closed (HTTPS and PowerSync) (M8.7)

18. From `apps/server`, confirm development mode never blocks even without
    HTTPS or PowerSync:
    ```bash
    php artisan tinker --execute='config(["app.url" => "http://localhost", "meridian.event_mode.enabled" => null, "meridian.event_mode.require_https" => true, "meridian.event_mode.require_powersync" => true, "powersync.endpoint" => "http://powersync.test", "powersync.liveness_path" => "/probes/liveness"]); Illuminate\Support\Facades\Http::fake(["http://powersync.test/probes/liveness" => Illuminate\Support\Facades\Http::response([], 503)]); $r = app(App\Services\EventMode\EventModeGuard::class)->evaluate(App\Models\Node::ROLE_DEVELOPMENT); print(json_encode(["event_mode" => $r->eventMode, "blocked" => $r->blocked(), "reasons" => $r->reasons()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
19. Confirm HTTPS validation fails closed for an event role when `APP_URL` is
    plain HTTP:
    ```bash
    php artisan tinker --execute='config(["app.url" => "http://onsite.example.org", "meridian.event_mode.enabled" => null, "meridian.event_mode.require_https" => true, "meridian.event_mode.require_powersync" => true, "powersync.endpoint" => "http://powersync.test", "powersync.liveness_path" => "/probes/liveness"]); Illuminate\Support\Facades\Http::fake(["http://powersync.test/probes/liveness" => Illuminate\Support\Facades\Http::response(["status" => "ok"])]); $r = app(App\Services\EventMode\EventModeGuard::class)->evaluate(App\Models\Node::ROLE_ONSITE); print(json_encode(["event_mode" => $r->eventMode, "blocked" => $r->blocked(), "reasons" => $r->reasons()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
20. Confirm PowerSync unavailability fails closed for an event role when the
    liveness probe fails:
    ```bash
    php artisan tinker --execute='config(["app.url" => "https://onsite.example.org", "meridian.event_mode.enabled" => null, "meridian.event_mode.require_https" => true, "meridian.event_mode.require_powersync" => true, "powersync.endpoint" => "http://powersync.test", "powersync.liveness_path" => "/probes/liveness"]); Illuminate\Support\Facades\Http::fake(["http://powersync.test/probes/liveness" => Illuminate\Support\Facades\Http::response([], 503)]); $r = app(App\Services\EventMode\EventModeGuard::class)->evaluate(App\Models\Node::ROLE_ONSITE); print(json_encode(["event_mode" => $r->eventMode, "blocked" => $r->blocked(), "reasons" => $r->reasons()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
21. Optional (destructive to an empty install only): on a database with no
    active node, open `/setup`, submit an `onsite` (or `central` /
    `standalone`) role while `APP_URL` is plain HTTP or PowerSync is down, and
    confirm setup shows an event-mode safeguard error and creates no node.
    Prefer a dedicated empty database; do not wipe a shared QA database unless
    that is intentional. Automated coverage for this path is
    `EventModeSetupFailClosedTest` from step 3.

### E. Client event-mode fail-closed (encryption and signing) (M8.7)

22. Confirm the client event-mode gate automated tests passed in step 2
    (`apps/client/src/readiness/eventMode.spec.ts`): event mode is ready only
    when both local encryption and device signing are available, and each
    missing capability is reported as a blocker with a human-readable reason.
23. Confirm the readiness surface from section B already exposes the same
    capability signals as checklist items **Encryption active** and
    **Device signing available** (Ready / Not ready with reason text). There is
    no separate event-mode blocking UI for offline writes in this milestone;
    later tasks own consuming the gate.

## Expected results

- `corepack pnpm --filter @meridian/client run test` passes, including
  local-encryption, device-signing, readiness checklist/UI, offline banner /
  connectivity, and client event-mode gate tests.
- `php artisan test --filter=EventMode` passes
  (`EventModeFailClosedTest`, `EventModeSetupFailClosedTest`).
- `/readiness` shows the eight technical-spec section 14 items in order with
  textual Ready / Not ready / Pending status.
- On localhost, encryption and device signing are Ready; the other six items
  are Pending with `Not available yet in this build.`
- The checklist copy states readiness is advisory, user-only, not
  organizer-visible, and non-expiring.
- OfflineBanner is silent while online and shows **Offline but usable** /
  **Local work can continue.** when the device is offline, without blocking
  the page.
- Development-role event-mode evaluation is not blocked without HTTPS/PowerSync.
- Onsite (event) evaluation is blocked with an HTTPS reason when `APP_URL` is
  HTTP, and blocked with a PowerSync reason when liveness fails.
- Client event mode fails closed when encryption or signing is unavailable
  (automated evidence); readiness UI surfaces those capability states honestly.
- Non-goals for this script: offline field-report/attendance writes, PowerSync
  client upload, wiring pending readiness items, organizer-visible readiness,
  M15 packaging/secret safeguards, and Electron health-panel PowerSync fields.

## Evidence to capture

- Terminal output from the mobile Vitest run and `php artisan test --filter=EventMode`.
- Screenshot of `/readiness` showing all eight checklist items and the advisory
  lede.
- Screenshot of the app shell while online (no offline banner) and while
  offline (Offline but usable banner).
- Terminal JSON output from the three tinker event-mode evaluations in
  section D.

## Failure notes

Record the failed step, exact error text or unexpected UI label, operating
system, Node.js and pnpm versions, PHP version, whether the shared client was
served from a secure context, the `APP_URL` / PowerSync endpoint used for
server checks, and whether automated EventMode or mobile readiness tests also
failed. If encryption or device signing shows Not ready on localhost, capture
the checklist detail reason before continuing. If `/setup` creates a node while
an event-role safeguard fails, stop and file a blocking event-mode issue.
