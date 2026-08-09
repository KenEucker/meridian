# QA-READY-01: Device Readiness and Event-Mode Fail-Closed

## Purpose

Verify Milestone 8 device readiness and offline foundations for a human
reviewer: the shared client shows an honest advisory readiness checklist, shared
offline/sync status appears only when the device is offline, and event mode
fails closed when required HTTPS, offline read set availability, local
encryption, or device signing capabilities are unavailable. This script closes
the Milestone 8 QA gate. It does not exercise offline field writes, device
upload paths, or later readiness signals (login, device trust, event selection,
cache, sync).

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
11. Confirm no item reports `Not available yet in this build.` Every one of the
    eight has a signal behind it since M18.54, and a checklist saying otherwise
    is reporting a wiring failure rather than a device state.
11a. On a device signed in normally, confirm **Logged in**, **Event selected**,
     and **Trusted server known** are **Ready**, and that **Device trusted**
     names this device and the date its trust runs to (AUTH-024). Signing in is
     what establishes trust, so a device that just signed in is trusted.
11b. Confirm **Local cache complete** is **Ready** and names the moment this
     device stored its copy. This is the set the node composed *for this
     caller*: a staff member holding no Logistics index holds a complete cache,
     and the item must not report otherwise.
11c. Confirm **Last sync completed** is **Ready** and says everything recorded
     here has been sent.
11d. Queue an offline write without sending it — from the Logistics desk, mark
     somebody on-site with DevTools set to Offline — then return to
     `/readiness` and confirm **Last sync completed** is **Not ready** and says
     how many actions have not reached the node. Restore the network, let the
     outbox drain, and confirm it returns to **Ready**.
11e. Clear the stored read set (sign out and back in with the network off, or
     clear site data) and confirm **Local cache complete** reports **Not ready**
     with `holds no offline copy yet` rather than a false pass.
11f. Revoke this device from God Mode, refresh the session, and confirm
     **Device trusted** reports **Not ready** and says an administrator has to
     restore it — the one trust state signing in again does not fix.
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
17. Confirm the shell stays silent on a development node even though the two
    connectivity tiers are now live (M18.52). A development node reports that
    it has no central beyond itself, so reaching it *is* `Online` and there is
    nothing to say. `Local node reachable` and `Central unreachable` need a
    paired on-site node and are checked in
    [QA-SYNC-01](QA-SYNC-01-onsite-central-sync.md) section C, against a real
    outage rather than a simulated one. `Sync conflict` is not a device state at
    all — it belongs to the God-mode conflict queue — and the `Queued` and
    `Sync failed` states are covered by the automated read-set refresh tests
    from step 2.

### D. Server event-mode fail-closed (HTTPS and the offline read set) (M8.7, M18.51)

M18.51 replaced the PowerSync liveness probe with a probe on whether this node
can serve `GET /api/offline-read-set`. Nothing else about the gate changed: two
server-owned checks, either of which fails event mode closed. The unservable
case is forced by binding a stub probe, because there is no external service to
switch off any more.

18. From `apps/server`, confirm development mode never blocks even without
    HTTPS or a servable read set:
    ```bash
    php artisan tinker --execute='config(["app.url" => "http://localhost", "meridian.event_mode.enabled" => null, "meridian.event_mode.require_https" => true, "meridian.event_mode.require_offline_read_set" => true]); app()->instance(App\Services\Offline\OfflineReadSetProbe::class, new class extends App\Services\Offline\OfflineReadSetProbe { public function __construct() {} public function isAvailable(): bool { return false; } }); $r = app(App\Services\EventMode\EventModeGuard::class)->evaluate(App\Models\Node::ROLE_DEVELOPMENT); print(json_encode(["event_mode" => $r->eventMode, "blocked" => $r->blocked(), "reasons" => $r->reasons()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
19. Confirm HTTPS validation fails closed for an event role when `APP_URL` is
    plain HTTP, with the read set servable:
    ```bash
    php artisan tinker --execute='config(["app.url" => "http://onsite.example.org", "meridian.event_mode.enabled" => null, "meridian.event_mode.require_https" => true, "meridian.event_mode.require_offline_read_set" => true]); $r = app(App\Services\EventMode\EventModeGuard::class)->evaluate(App\Models\Node::ROLE_ONSITE); print(json_encode(["event_mode" => $r->eventMode, "blocked" => $r->blocked(), "reasons" => $r->reasons()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
20. Confirm an unservable offline read set fails closed for an event role:
    ```bash
    php artisan tinker --execute='config(["app.url" => "https://onsite.example.org", "meridian.event_mode.enabled" => null, "meridian.event_mode.require_https" => true, "meridian.event_mode.require_offline_read_set" => true]); app()->instance(App\Services\Offline\OfflineReadSetProbe::class, new class extends App\Services\Offline\OfflineReadSetProbe { public function __construct() {} public function isAvailable(): bool { return false; } }); $r = app(App\Services\EventMode\EventModeGuard::class)->evaluate(App\Models\Node::ROLE_ONSITE); print(json_encode(["event_mode" => $r->eventMode, "blocked" => $r->blocked(), "reasons" => $r->reasons()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
21. Confirm a normally configured event node passes both checks, so the read-set
    check is something a real node satisfies rather than a rule only a stub can
    fail:
    ```bash
    php artisan tinker --execute='config(["app.url" => "https://onsite.example.org", "meridian.event_mode.enabled" => null, "meridian.event_mode.require_https" => true, "meridian.event_mode.require_offline_read_set" => true]); $r = app(App\Services\EventMode\EventModeGuard::class)->evaluate(App\Models\Node::ROLE_ONSITE); print(json_encode(["event_mode" => $r->eventMode, "blocked" => $r->blocked(), "reasons" => $r->reasons()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
22. Optional (destructive to an empty install only): on a database with no
    active node, open `/setup`, submit an `onsite` (or `central` /
    `standalone`) role while `APP_URL` is plain HTTP, and confirm setup shows an
    event-mode safeguard error and creates no node. Prefer a dedicated empty
    database; do not wipe a shared QA database unless that is intentional.
    Automated coverage for this path is `EventModeSetupFailClosedTest` from
    step 3.

### E. Client event-mode fail-closed (encryption and signing) (M8.7)

23. Confirm the client event-mode gate automated tests passed in step 2
    (`apps/client/src/readiness/eventMode.spec.ts`): event mode is ready only
    when both local encryption and device signing are available, and each
    missing capability is reported as a blocker with a human-readable reason.
24. Confirm the readiness surface from section B already exposes the same
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
- On localhost with a signed-in device, all eight items report a real answer and
  none reports `Not available yet in this build.`
- **Device trusted** follows the node's own `device_trusts` verdict, names the
  device and the date its trust runs to, and tells a lapsed trust from a revoked
  one — the first is renewed by signing in and the second is not.
- **Local cache complete** is Ready for a device holding the set composed for its
  own caller, and Not ready when it holds nothing or holds a set past the event
  window it may be served in.
- **Last sync completed** covers both directions: it is Not ready while the
  outbox holds unsent work, names how many actions are waiting, reports a
  refusal ahead of unsent work, and returns to Ready once the queue drains and
  the read set has refreshed.
- The checklist copy states readiness is advisory, user-only, not
  organizer-visible, and non-expiring.
- OfflineBanner is silent while online and shows **Offline but usable** /
  **Local work can continue.** when the device is offline, without blocking
  the page.
- Development-role event-mode evaluation is not blocked without HTTPS or a
  servable offline read set.
- Onsite (event) evaluation is blocked with an HTTPS reason when `APP_URL` is
  HTTP, and blocked with an offline read set reason when the probe reports the
  set is unservable.
- A normally configured onsite node passes both checks with no reasons.
- Client event mode fails closed when encryption or signing is unavailable
  (automated evidence); readiness UI surfaces those capability states honestly.
- Non-goals for this script: offline field-report/attendance writes, device
  upload paths, organizer-visible readiness, M15 packaging/secret safeguards,
  and Electron health-panel sync fields.

## Evidence to capture

- Terminal output from the mobile Vitest run and `php artisan test --filter=EventMode`.
- Screenshot of `/readiness` showing all eight checklist items and the advisory
  lede.
- Screenshot of the app shell while online (no offline banner) and while
  offline (Offline but usable banner).
- Terminal JSON output from the four tinker event-mode evaluations in
  section D.

## Failure notes

Record the failed step, exact error text or unexpected UI label, operating
system, Node.js and pnpm versions, PHP version, whether the shared client was
served from a secure context, the `APP_URL` used for
server checks, and whether automated EventMode or mobile readiness tests also
failed. If encryption or device signing shows Not ready on localhost, capture
the checklist detail reason before continuing. If `/setup` creates a node while
an event-role safeguard fails, stop and file a blocking event-mode issue.
