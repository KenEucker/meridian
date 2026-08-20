# QA-OFFLINE-01: Offline Reads, Connectivity Tiers, and Refusal Resolution

## Purpose

Verify by hand what Milestone 18 Part F built: a device holds the whole read set
its user is authorized to read, refreshes it when there is something to refresh
it from, says truthfully which of the two connectivity tiers it has, and holds
every read-only surface to one of exactly two outcomes — it renders from stored
data, or it says plainly that it needs a connection.

Three things are being checked, and they are separable failures:

1. **The read set** (M18.46 through M18.51). One authenticated endpoint composes
   the technical spec 9.3 cache set for the caller, bounded by their effective
   roles and the organization's active modules. The client stores it whole,
   refreshes it on sign-in, on regaining connectivity, and on a context switch,
   and refuses to serve one past its event window once the six-week fallback
   from the last successful refresh (`CLIENT-008A`) has also lapsed — the same
   widening the cached session already has, applied to the cached data it
   covers. PowerSync — the replicating
   service the design originally called for — is gone, and event mode now gates
   on the thing offline readiness actually needs.
2. **The two connectivity tiers** (M18.52). A device working against a reachable
   on-site node with central unreachable is a normal, expected state and has to
   be reported as itself. Before this it reported plain `online`, which is a
   claim about central that no device had checked.
3. **The surface audit** (M18.53). Every routed surface is on a written list with
   one of two outcomes. A surface that cannot work offline is not a defect; a
   spinner that never resolves, a blank panel, or a fetch error rendered as
   though the screen were broken is.

Section G additionally walks the resolution path M18.55 added: a refused command
now has a third answer besides "try again" and "dismiss", where the reader holds
the authority and the node's refusal reason is one the specification lists as
overridable.

Section H walks the one M19.17 added, which is not the reader's at all: a write
queued against a module that was switched off while the device was away is
refused on arrival and lands in the God Mode conflict queue (MOD-017). It is here
rather than in a script of its own because it is the offline queue's behaviour
under a condition the queue cannot see; `QA-MOD-01` covers the module system as a
whole.

This script does not cover offline *writes* in general — QA-FR-01 covers the
Field Report queue and QA-SLB-01 covers attendance and the M18.54 Logistics
addition. What is covered here is reading with no node, and the one refusal
outcome that M18.54's addition made reachable.

## Requirements covered

- `CLIENT-001`
- `CLIENT-007` through `CLIENT-010`, including `CLIENT-008A` as it applies to
  the offline read set
- `CLIENT-015`, `CLIENT-016`, `CLIENT-017`, `CLIENT-017A`, `CLIENT-018`
- `CLIENT-021`, `CLIENT-022`
- `CLIENT-025`, `CLIENT-026`, `CLIENT-027`
- `INC-017`, `INC-018`
- `MOD-016`, `MOD-017`
- `SYS-033`
- `TEAM-009`
- `UI-020`
- Requirements section 2.4 (attribution)
- Technical spec sections 8.6, 9.1 through 9.5, 9.7, 10, 11A.4, 11A.5, 11A.7,
  15A.8, 19.2, 22A.8, 26.2
- Data/API spec sections 5.3, 5.6, 5.10, 7.1, 7.2, 7.3, 7.5, 7.6
- UI Implementation Contract sections 11.13, 11.14, 16, 16.1, 16.1A, 16.2,
  16.3, 16.4, 19A.2
- ADR-0003 (PowerSync retirement)
- Meridian Alpha 1 tasks M18.46 through M18.53, M18.55, and M19.17

## Environment

- A dedicated development/QA database. The setup below runs
  `migrate:fresh --seed` and must not be used against shared or valuable data.
- Repository dependencies installed with approved PHP, Composer, Node.js 24 LTS,
  and pnpm 11.x versions.
- Laravel available at `http://127.0.0.1:8000`.
- The shared Vue client available at `http://127.0.0.1:5173` in a secure browser
  context (`localhost` / `127.0.0.1` is sufficient). The client is pinned to that
  origin by CORS; serving it elsewhere fails for reasons unrelated to this
  script.
- Browser DevTools with the Application (or Storage) panel, for reading
  IndexedDB, and the Network panel, for switching the page to Offline.
- At least three terminals: Laravel server, shared client dev server, and QA
  commands.

## Personas

- **Ordinary staff member**: seeded Vera Staff
  (`vera.staff@northwood-collective.test`). The regular-staff read set (technical
  spec 9.3) and the surfaces it feeds.
- **Department Logistics operator**: seeded Sam Shiftlead
  (`sam.shiftlead@northwood-collective.test`). The largest role-additive scope,
  and the persona for the Logistics Desk's offline reads and the refused
  addition in section G.
- **Department lead**: seeded Dana Departmentlead
  (`dana.departmentlead@northwood-collective.test`). The lead-scoped sections of
  the read set, and the only persona in this script who holds
  `department.shift_additions.override`.
- **Organizer**: seeded Olive Organizer
  (`olive.organizer@northwood-collective.test`). Used to confirm the
  connection-required surfaces say so rather than rendering empty.
- Human reviewer observing the UI and retaining command/test evidence.

## Setup data

1. From the repository root, install dependencies if needed:
   ```bash
   corepack pnpm install
   ```
2. Configure local Laravel and shared-client Vite env files:
   ```bash
   corepack pnpm run setup:local
   ```
3. Reset and seed a dedicated local server database:
   ```bash
   php apps/server/artisan migrate:fresh --seed
   ```
4. Start Laravel in one terminal:
   ```bash
   php apps/server/artisan serve --host=127.0.0.1 --port=8000
   ```
5. Start the shared Field client in another terminal:
   ```bash
   corepack pnpm run client:dev:field -- --host 127.0.0.1
   ```
6. Open `http://127.0.0.1:5173/login` and sign in as the persona a section names,
   reading the login code out of the mail log with:
   ```bash
   grep -A 2 "Enter this code" apps/server/storage/logs/laravel.log | tail -1
   ```
7. Clear site data between personas. The read set is context-scoped and dropped
   on sign-out; a section that starts with a previous persona's set still in
   storage is testing the wrong thing, and section C is where that is checked
   deliberately rather than by accident.

## Steps

### A. Automated evidence

1. From `apps/server`, run the read set, retirement, and refusal suites:
   ```bash
   php artisan test \
     tests/Feature/OfflineReadSetTest.php \
     tests/Feature/OfflineReadSetRoleScopesTest.php \
     tests/Feature/ModuleScopedReplicationTest.php \
     tests/Feature/ModuleInactiveOfflineWriteTest.php \
     tests/Feature/PowerSyncRetirementTest.php \
     tests/Feature/ShiftAdditionOverrideTest.php \
     tests/Feature/DepartmentOperationsCommandHttpTest.php
   ```
2. From the repository root, run the shared client offline and outbox suites:
   ```bash
   corepack pnpm --filter @meridian/client run test -- \
     src/offline/offlineReadSetStore.spec.ts \
     src/offline/offlineReadSetStorage.spec.ts \
     src/offline/offlineReadSetRuntime.spec.ts \
     src/offline/offlineReadSetRefresh.spec.ts \
     src/offline/offlineReadSetStaleness.spec.ts \
     src/offline/offlineSurfaceInventory.spec.ts \
     src/offline/useConnectivity.spec.ts \
     src/offline/readModelMigration.spec.ts \
     src/outbox/overrideCommand.spec.ts \
     src/outbox/syncCommandOutbox.spec.ts \
     src/outbox/CommandOutboxNotice.spec.ts
   ```
3. Confirm all suites pass. Retain output proving:
   - the read set is composed through the caller's effective roles, and a login
     with no staff profile receives nothing;
   - an unpublished document reaches nobody and an inactive module's records are
     absent from a device whose user is permitted to read them, and reactivating
     the module replicates them back unchanged;
   - every section that replicates declares the synced table it projects, and no
     section is owned more widely than that table;
   - a write queued offline against a module that went inactive becomes an open
     sync conflict rather than being applied or dropped, one conflict per queued
     write however many times it is delivered;
   - an unchanged set returns `304` rather than a payload;
   - a role-additive section is absent for a caller without the role rather than
     present and empty;
   - no application code references PowerSync configuration;
   - every one of the seven connectivity states is produced, including a
     reachable local node with central unreachable;
   - every surface in the inventory renders offline without a transport failure
     or a stuck spinner;
   - an override is refused without the capability, `do_not_staff` is refused at
     any authority, and the override is a distinct command from the original.

### B. The read set reaches the device

Sign in as **Vera Staff**.

1. With the Network panel open, sign in and confirm one request to
   `/api/offline-read-set` is made as part of signing in — not on opening a
   screen that happens to need it. The set is fetched because the user signed in,
   not because they browsed somewhere (M18.49).
2. In the Application panel, open IndexedDB and confirm one record holding the
   whole set. Record its approximate size.
3. Confirm the `localStorage` key the old read cache used
   (`meridian.readCache.*`) is **absent**. `readCache.ts` was deleted in M18.50,
   and a stray key means something is still writing domain data where it no
   longer belongs.
4. Reload the page with the network still available and confirm the refresh
   request returns `304 Not Modified` rather than a payload. The device asking is
   the one on a weak connection, and an unchanged set should cost nothing.

### C. The set is dropped when it should be

1. Still signed in as Vera Staff, switch to another event or organization from
   the context surface. Confirm a new `/api/offline-read-set` request is made and
   the stored record is replaced — not merged. A section whose grant was
   withdrawn must not survive a switch.
2. Sign out. Confirm the IndexedDB record is **gone**. The next person to sign in
   on this device has no business holding the previous one's roster.
3. Sign in as **Sam Shiftlead** and confirm the set that arrives carries the
   department Logistics sections Vera's did not. Record the approximate size
   difference; the Logistics indexes are the largest scope.

### D. Reading with no node

Signed in as **Sam Shiftlead**, with the read set fetched.

1. Set the Network panel to Offline.
2. Open the **Logistics desk**. Confirm it renders the department's staff,
   equipment, and shifts from the stored copy, and that it says which copy it is
   showing and when it was read. A freshness disclosure that is absent is as much
   a failure as a screen that does not render.
3. Search the staff index. Confirm results appear without a round trip.
4. Open **Staff → my shifts**, **my documents**, and **Field Reports**. Each must
   render from the stored set with its own freshness disclosure.
5. Open a **reporting export** surface and the **Event Horizon**. Each must state
   plainly that it needs a connection and why. Confirm specifically that neither
   shows a spinner that never resolves, a blank panel, or a raw fetch error.
6. Restore the network. Confirm the surfaces from step 2 refresh and their
   freshness disclosures update.

### E. The surface audit is a list, not a hope

1. Open `apps/client/src/offline/offlineSurfaceInventory.ts` and read the list.
   Confirm every routed surface has an entry, each with one of exactly two
   outcomes and a stated basis.
2. Pick three surfaces recorded as `renders-offline` and three recorded as
   `connection-required` that section D did not walk. With the network Offline,
   open each and confirm it behaves as its entry says.
3. Confirm the connection-required ones name **what** is unavailable and **why**,
   rather than showing a generic error. An export is a file the node generates;
   an audit trail is history this device was never sent. Those are honest answers
   and should read as answers.

### F. The two connectivity tiers

1. With Laravel running and reachable, open the device diagnostics surface and
   the shell's user button. Confirm the connectivity state is reported and that
   its sentence matches what the device has actually confirmed.
2. Stop the Laravel server. Within the client's watch interval, confirm the state
   becomes `offline_usable` and the banner says the device is working offline.
   Confirm a connected-only write — creating an incident — is refused **because
   no node is reachable**, in those words.
3. Restart Laravel. Confirm the state returns.
4. Simulate an on-site node that cannot reach central. From `apps/server`:
   ```bash
   php artisan tinker
   ```
   and set the node's central reachability to unreachable for the current node
   record, then reload the client.
5. Confirm the client reports **`central_unreachable`** and **not** `online`. This
   is the M18.52 correction: a device working happily against an on-site node
   with the internet down used to claim "Central or expected sync target
   reachable", which it had not checked.
6. In that state, confirm an incident **can** still be created. The local tier is
   what gates connected-only work; refusing it because central is unreachable
   would take away the exact capability an on-site node exists to provide.
7. Restore the node's central reachability.

### G. Resolving a refused command (M18.55)

This section needs a refused Logistics addition, which is what M18.54's offline
write made ordinary. Set it up so the refusal is a real one rather than a forced
error.

1. Sign in as **Sam Shiftlead** (Department Logistics) and open the Logistics
   desk for Emberfall 2026 / Rangers.
2. Choose a staff member who is **not** marked on-site, and add them to a running
   shift. With the network available, confirm the desk reports the node's
   refusal: *"Staff must be marked on-site with this department before
   unscheduled shift addition."*
3. In the command outbox notice, confirm the refusal is listed with that reason
   and offers **Try again** and **Dismiss** — and **no override control**. Sam
   holds `department.attendance.manage` and issues additions; the role that
   issues the addition is deliberately not the role that decides its refusal was
   wrong.
4. Sign out and sign in as **Dana Departmentlead**, then repeat steps 1 and 2.
5. Confirm the outbox notice now offers a third control, **Add anyway**.
6. Click it. Confirm:
   - the refusal moves into the overridden list and states that it was overridden
     on your authority and that the override is waiting to reach the node;
   - it is **not** offered for a second override;
   - **Try again** no longer applies to it.
7. Confirm in the Network panel that the request went to
   `/api/commands/override-shift-addition` and carried a **different**
   `operation_uuid` from the refused command, with the refused command's key in
   `overridden_operation_uuid` and `staff_not_on_site` in
   `overridden_reason_code`. Two commands, not one retried — that is what makes
   the record hold both facts.
8. Confirm the staff member is now on the shift, and that the assignment records
   the override. From `apps/server`:
   ```bash
   php artisan tinker --execute="echo App\Models\ShiftAssignment::query()->whereNotNull('overridden_reason_code')->get(['staff_id','overridden_reason_code','override_of_operation_uuid'])->toJson();"
   ```
9. Confirm the audit trail carries the override under its own action:
   ```bash
   php artisan tinker --execute="echo App\Models\AuditEvent::query()->where('action','shift_assignment.unscheduled_added_by_override')->latest()->first(['actor_user_id','after_json'])->toJson();"
   ```
   The entry must name the acting user, the overridden reason code, and the
   refused command's key (requirements 2.4).
10. Confirm the allowlist holds. Set a staff member's organization status to Do
    Not Staff, attempt the same addition as Dana, and confirm the outbox notice
    shows the refusal with **no override control at all**. An organization's
    exclusion decision is not reversed from a desk, at any authority.
11. Confirm one reason is waived and not the rest. Add a required waiver the
    staff member has not completed, override the on-site refusal, and confirm the
    override comes back refused **for the waiver**. The override is a recorded
    exception to one rule, not a way past all of them.
12. Confirm the override survives no signal. With the network Offline, issue an
    override as Dana; confirm it is held on the device, and that it sends and is
    decided when the network returns.

### H. A queued write that lands on a switched-off module (MOD-017)

The one case where a queued write is refused for a reason nobody at the desk
could have known about: an organizer narrowed the product while the device was
out of coverage. The requirement forbids two outcomes — the write must not be
silently applied, and it must not be silently dropped — so this section checks
both, from the device that queued it and from the console that has to answer for
it.

Sign in as **Sam Shiftlead** on the Logistics Desk.

1. With the node reachable, note a shift in the desk's window and a department
   staff member marked on-site.
2. Stop the Laravel server. Confirm the connectivity indicator reports no node
   reachable.
3. Add the on-site staff member to the shift. Confirm the outbox holds it as
   queued rather than refusing it — the Logistics addition is an offline write
   (data/API 7.2).
4. With the queue still holding it, start Laravel again and switch Scheduling off
   for Northwood Collective from the God Mode Organization Modules screen, or
   with:
   ```bash
   php apps/server/artisan tinker --execute="App\Models\OrganizationModule::query()->updateOrCreate(['organization_id' => App\Models\Organization::query()->where('slug','northwood-collective')->value('id'), 'module_key' => 'scheduling'], ['entitled' => true, 'enabled' => false]);"
   ```
5. Let the outbox drain, or press retry. Confirm the queued addition settles as
   **rejected** with the node's own sentence naming Scheduling — not as accepted,
   and not silently removed from the queue.
6. Confirm no assignment was created:
   ```bash
   php apps/server/artisan tinker --execute="echo App\Models\ShiftAssignment::query()->count();"
   ```
7. Sign in to the God Mode console as an operator holding
   `platform.sync-conflicts` and open **Sync Conflicts**. Confirm one open
   conflict of type `module_inactive` naming the shift, and open it.
8. On the review screen, confirm: the operation UUID is the key the device queued
   the command under; the operation type reads as a queued device write with no
   origin node; the local value shows the command and the fields the device sent;
   the remote value shows the module with `entitled` and `enabled` separately and
   `active` false; and **Accept on-site is not offered** — there is nothing to
   accept from the device while the module is off.
9. Choose **Accept central**. Confirm the conflict closes as resolved with the
   reviewer named, no assignment is created, and an audit entry records the
   decision.
10. Drain the outbox once more with the same command still in it, or repeat step
    5. Confirm no second conflict row appears: the device's key makes a repeated
    delivery the same refusal.

### I. PowerSync is gone

1. Confirm no PowerSync service, role, bucket-storage database, or publication
   provisioning remains:
   ```bash
   grep -ri "powersync" apps/server/app apps/server/config apps/client/src deploy || echo "no references"
   ```
   Documentation and the retirement test may still name it historically; running
   configuration and application code must not.
2. Open the device diagnostics surface and confirm the sync check is
   `sync.offline_read_set` rather than `sync.powersync`, and that event mode
   gates on it.
3. Confirm `docker compose config` still parses:
   ```bash
   docker compose -f deploy/docker/docker-compose.yml config > /dev/null && echo ok
   ```

### J. Download status on Settings and the completion notice

The per-artifact download status (`CLIENT-025` through `CLIENT-027`; technical
spec 9.7; UI contract 16.4). The denominator has to come from what the node
named for this device, the readout has to live on Settings rather than in the
shell, and the completion notice has to be a transient toast that fires on the
transition to holding everything and never again for a refresh that downloads
nothing.

1. Clear site data and open the client signed out. Open **Settings** and read
   the **Offline downloads** section. Confirm it says the node has named
   nothing for a device holding no session — not "0 of N" against a built-in
   list.
2. Sign in as **Vera Staff** and watch the shell as the sign-in resolves.
   Confirm that when the last named artifact lands, one toast appears —
   "All available event data is downloaded." — and dismisses itself within a
   few seconds without being clicked. Confirm nothing persistent joins the
   shell.
3. Open **Settings** and read the Offline downloads section beside the
   Permissions section. Confirm a progress summary in the form
   "N of N downloaded", with each artifact named (the offline read set; the
   branding assets where the context has an organization), its state, and its
   last successful download.
4. Reload the page twice with the network available. The refresh answers `304`
   and downloads nothing: confirm the toast does **not** reappear on either
   reload.
5. Navigate two or three surfaces and confirm the download status appears
   nowhere in the shell — no banner, no badge. The readout is Settings' and the
   toast is transient (contract 16.4: not in the shell; no nagging).
6. Stop the Laravel server and reload. Confirm the read set's row reads as
   **pending** — an unreachable node is the situation the cache exists for,
   never dressed as a failure — and that restarting Laravel and reloading
   returns it to downloaded. The failed state (the node answered and refused,
   with a **Retry download** control beside the node's own words) is
   impractical to stage by hand; take it from the automated evidence:
   ```bash
   corepack pnpm --filter @meridian/client run test -- \
     src/offline/downloadStatus.spec.ts \
     src/views/SettingsDownloadStatus.spec.ts
   ```
7. Switch context (as a persona holding two events, per `QA-CLIENT-01` section
   F) or clear site data and sign in again. The device is incomplete again and
   then completes: confirm the toast fires once more — the only condition under
   which it repeats.

### K. The viewed-incident cache

The device incident cache (`INC-017`, `INC-018`; technical spec 19.2, 9.3).
Populated only by the user's own views, no last-five cap, readable offline,
flushed at logout, and reported on Settings as a count rather than a fraction.

1. Sign in as an IC persona (`ingrid.iclead@northwood-collective.test`) and
   open six different incidents from the incident list, one after another.
2. Set the Network panel to Offline. Re-open each of the six from the list you
   still have in history or by URL. Confirm **all six** render from the cache —
   the sixth did not push out the first (the last-five cap is gone).
3. Still offline, open an incident you never viewed. Confirm it fails plainly
   as needing a connection rather than rendering empty — the cache holds the
   user's own views, never the event's incident log.
4. Restore the network. Open **Settings** and confirm the Offline downloads
   section reports "6 viewed incidents cached" as a count, and that the
   "N of N downloaded" summary above it did not change — viewed incidents never
   enter the fraction.
5. Sign out and sign back in as the same persona. Confirm Settings reports no
   viewed incidents and an incident is no longer readable offline until viewed
   again: the cache flushes at logout.
6. Sign in as **Vera Staff** and confirm Settings shows no viewed-incident line
   at all. Regular staff see no incident UI, and their device holds no
   incident.
7. The six-week expiry and the per-user boundary are automated evidence:
   ```bash
   corepack pnpm --filter @meridian/client run test -- \
     src/ims/viewedIncidentCache.spec.ts
   ```

## Expected results

- One `/api/offline-read-set` request per sign-in, reconnect, and context switch,
  and none driven by opening a screen.
- The whole set held in one IndexedDB record, replaced whole on refresh, dropped
  on sign-out and on context switch.
- No `localStorage` key holding domain data.
- An unchanged set answered with `304`.
- Every surface recorded as `renders-offline` renders with no node in reach, with
  its own freshness disclosure.
- Every surface recorded as `connection-required` says what is unavailable and
  why — never a spinner, a blank panel, or a raw fetch error.
- A reachable on-site node with central unreachable reports `central_unreachable`
  and not `online`, and an incident is still creatable in that state.
- No node reachable reports `offline_usable`, and a connected-only write is
  refused for that reason in those words.
- A Logistics operator is offered dismissal and retry on a refusal and no
  override; a department lead is offered all three.
- An override is a distinct command naming the refused one, waives exactly one
  reason, and is recorded on the assignment and in the audit trail with the
  acting user and the overridden reason code.
- `do_not_staff` offers no override control at any authority.
- A write queued against a module that went inactive settles as rejected with the
  node's sentence naming the module, writes no record, and appears once in the
  God Mode conflict queue as `module_inactive` however many times it is
  delivered.
- That conflict offers Accept central and not Accept on-site, closes with the
  reviewer named, and creates no record when it closes.
- No PowerSync configuration or application code remains, and event mode gates on
  the read set check.
- The Settings Offline downloads section reports "N of N downloaded" with every
  named artifact's state and last successful download, where N is what the
  node's responses named for this caller — a device with no session reads as
  nothing named, never "0 of N" against a built-in list.
- The completion toast fires once on the transition to holding every named
  artifact, dismisses itself, never persists, and does not reappear for `304`
  refreshes; it fires again only after the device has been incomplete again.
- No download-status readout anywhere in the shell.
- Every incident an IC user viewed renders offline — all six, no last-five cap —
  while an unviewed incident is a plain connection failure.
- Settings reports viewed incidents as a count ("6 viewed incidents cached"),
  never inside the downloaded fraction; the count is absent for a user whose
  views cached nothing, regular staff included.
- The incident cache flushes at logout.

## Evidence to capture

- The Network panel showing one read-set request per trigger, and a `304` on an
  unchanged refresh.
- The IndexedDB record before and after sign-out.
- Screenshots of the Logistics desk rendering offline with its freshness
  disclosure, beside a connection-required surface stating what is unavailable.
- The connectivity indicator reading `central_unreachable`, beside the successful
  incident creation in that state.
- The outbox notice for the same refusal as Sam (two controls) and as Dana
  (three).
- The override request body showing two different operation UUIDs.
- The `shift_assignments` row and the audit entry for the override.
- The refusal with no override control for `do_not_staff`.
- The waiver refusal that survived the on-site override.
- The outbox entry rejected for an inactive module, beside the God Mode
  `module_inactive` conflict it filed, showing the same operation UUID.
- The conflict review screen before resolution, showing no Accept on-site
  control, and the audit entry after.
- Output of the PowerSync reference grep and the `docker compose config` check.
- The Settings Offline downloads section signed out (nothing named), incomplete
  ("0 of 2 downloaded"), and complete ("2 of 2 downloaded"), plus a capture of
  the completion toast.
- The incident list offline with a cached incident rendering beside the refusal
  for one never viewed, and the Settings viewed-incident count before and after
  sign-out.
- Output of the download-status and viewed-incident-cache suites from steps J.6
  and K.7.

## Failure notes

Record the failed step, the exact error text or unexpected UI label, the
operating system, Node.js and pnpm versions, PHP version, whether the shared
client was served from a secure context, and which persona was signed in.

For a read-set failure, capture the response status and body of the
`/api/offline-read-set` request and the contents of the IndexedDB record.

For a connectivity failure, capture both tiers separately: what the node
answered, and what the node said about central. Reporting `online` for a state
the device has not confirmed is a blocking defect, because every sentence the
shell renders about connectivity is built on it.

For a surface-audit failure, name the route and whether the inventory records it
as `renders-offline` or `connection-required`. A surface that stopped rendering
offline and a surface that was never expected to are different defects with the
same symptom, and the inventory entry is what tells them apart.

For an override failure, capture whether the client offered a control it should
not have (a poor experience, since the node refuses it) or the node applied one
it should not have (a blocking authority defect). An override applied for
`do_not_staff`, or one that waived more than the reason it named, is blocking.

For a download-status failure, capture the Settings section and which node
response should have named the artifact in question. A denominator that counts
an artifact no node response named for this caller — a staff member stuck at
"2 of 3" against a map package they are not entitled to — is the defect
`CLIENT-025` exists to prevent. A completion toast that persists, requires
dismissal, or repeats on a refresh that downloaded nothing is a `CLIENT-027`
defect.

For an incident-cache failure, record which incident was viewed, by whom, and
when. A cached incident served to a different user, one surviving logout, or
one populated by anything other than the user's own view is a blocking
`INC-018` defect; a viewed incident that fell out of the cache while others
remained (a resurrected cap) is an `INC-017` defect.
