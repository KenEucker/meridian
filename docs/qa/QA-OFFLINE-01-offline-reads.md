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
   and refuses to serve one past its event window. PowerSync — the replicating
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

This script does not cover offline *writes* in general — QA-FR-01 covers the
Field Report queue and QA-SLB-01 covers attendance and the M18.54 Logistics
addition. What is covered here is reading with no node, and the one refusal
outcome that M18.54's addition made reachable.

## Requirements covered

- `CLIENT-001`
- `CLIENT-007` through `CLIENT-010`
- `CLIENT-015`, `CLIENT-016`, `CLIENT-017`, `CLIENT-017A`, `CLIENT-018`
- `CLIENT-021`, `CLIENT-022`
- `MOD-016`, `MOD-017`
- `SYS-033`
- `TEAM-009`
- `UI-020`
- Requirements section 2.4 (attribution)
- Technical spec sections 8.6, 9.1 through 9.5, 10, 11A.4, 11A.5, 11A.7, 22A.8,
  26.2
- Data/API spec sections 5.3, 5.6, 5.10, 7.1, 7.2, 7.3
- UI Implementation Contract sections 11.13, 16, 16.1, 16.1A, 16.2, 16.3
- ADR-0003 (PowerSync retirement)
- Meridian Alpha 1 tasks M18.46 through M18.53 and M18.55

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
     absent;
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

### H. PowerSync is gone

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
- No PowerSync configuration or application code remains, and event mode gates on
  the read set check.

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
- Output of the PowerSync reference grep and the `docker compose config` check.

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
