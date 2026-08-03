# QA-CLIENT-01: Client Session and API Wiring

## Purpose

Verify that the shared Meridian client is a real client of its node: it signs in
for a device-bound token, establishes its session from `GET /api/me`, renders
navigation from the capability codes that response carries and from nothing
else, keeps working from a durable copy of it while the event window is open,
switches organization and event only where the node allows it, sends every
write through one durable command outbox, reaches every authenticated file
through a short-lived scoped URL, and holds no fixture anywhere in that path.

This is the consolidated Milestone 16 client script. It is the one that answers
the milestone QA gate for the client half of the work: `GET /api/me` and the
permission-aware shell (M16.4 through M16.7), the command outbox (M16.10), the
short-lived download URLs (M16.12), and the surfaces M16.14 through M16.22 bound
to their endpoints.

The credential half of Milestone 16 — how a token is issued, bound to a device,
revoked, and how a shared workstation signs somebody in with a typed code
(M16.1 through M16.3, M16.8, M16.9) — is `QA-AUTH-01`, which this script signs
in through rather than repeating. The one credential behavior repeated here is
revocation seen from the client, because what a client does when its token stops
working is a client behavior.

The single most important setup step in this script is turning the development
session fixture off. `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION` installs a
labeled development session document when a client boots holding nothing, so a
developer running `pnpm run client:dev` sees a populated shell without signing
in. It is the thing this script exists to look past: with it on, a shell full of
navigation proves nothing about session wiring. Every section below assumes it is
`false`.

## Requirements covered

- `CLIENT-001` through `CLIENT-003`: the session response, its contents, and the
  navigation structure it must not carry.
- `CLIENT-004` through `CLIENT-006`, `CLIENT-024`: capability-derived
  navigation, nothing rendered without a permitting code, and the server as the
  enforcement boundary.
- `CLIENT-007` through `CLIENT-010`: the durable session cache, event-window
  staleness, cached-permission disclosure, and permission reduction on refresh.
- `CLIENT-011` through `CLIENT-014`: node-lock-first context resolution,
  association-bounded switching, the offline lock, and what a switch replaces.
- `CLIENT-015` through `CLIENT-018`: the one command outbox, client-generated
  idempotency keys, the four command states, and connected-only refusal.
- `CLIENT-019`, `CLIENT-020`: short-lived scoped download URLs from a surface.
- `CLIENT-021`, `CLIENT-022`: permission-scoped replication (automated evidence;
  see section J).
- `CLIENT-023`: every bound surface reads and writes the node rather than a
  fixture.
- `AUTH-018`, `AUTH-021`, `AUTH-023`: token authentication from the client, the
  device it is bound to, and what a revoked token does to a running client.
- Requirements sections 7.16 and 7.22.
- Technical spec sections 11.4, 11A.2 through 11A.7.
- Data/API spec sections 5.3 through 5.7, 7.3.
- UI implementation contract sections 12, 12.2, 12.6, 16.3, 19A.1 through 19A.3.
- UI operating guide sections 8.3A, 18.1, 18.2, 18.2A.
- Meridian Alpha 1 tasks M16.4 through M16.7, M16.10, M16.12, M16.14 through
  M16.23.

## Environment

- A dedicated development/QA database. The setup below runs `migrate:fresh --seed`
  and must not be used against shared or valuable data.
- Repository dependencies installed; shell access from the repository root and
  from `apps/server`.
- The node at `http://127.0.0.1:8000` (`corepack pnpm run server:dev`).
- The shared client in Admin mode at its Vite dev server
  (`corepack pnpm run client:dev:admin`), which is the mode that carries the
  organizer and department administration surfaces this script walks. Field mode
  (`client:dev:field`) is used once, in section E, for the offline boot.
- `MAIL_MAILER=log` in `apps/server/.env`, so login codes are readable with
  `corepack pnpm run server:mailtail`.
- A browser with developer tools: the Network panel is used to prove which
  request a surface made, and Application → Local Storage to read and clear the
  client's durable state.
- A way to take the browser offline. The developer tools "Offline" throttling
  preset is enough for most of this; section E's boot case needs the node
  stopped instead, because the cache is about a device that cannot reach its
  node rather than one with no network at all.

## Personas

Seeded by `php artisan migrate:fresh --seed`; every account signs in by emailed
login code, so no password is needed for the client.

- Olive Organizer (`olive.organizer@northwood-collective.test`): `organizer`. Reaches
  the organization surfaces and the credential eligibility export event-wide.
- Dana Departmentlead (`dana.departmentlead@northwood-collective.test`):
  `department_lead` on Rangers / Dirt. Reaches Rangers and exports Rangers only.
- Sam Shiftlead (`sam.shiftlead@northwood-collective.test`): every department
  capability at once — the persona that reaches all of the department
  administration and logistics surfaces from one sign-in.
- Vera Staff (`vera.staff@northwood-collective.test`): plain staff, no roles. The
  persona whose shell must be nearly empty.
- Ingrid ICLead (`ingrid.iclead@northwood-collective.test`) and Ivy ICViewer
  (`ivy.icviewer@northwood-collective.test`): incident authority, event-scoped. Used
  for the IMS surfaces and the incident PDF download.
- Ira Ineligible (`ira.ineligible@northwood-collective.test`): Gate membership with
  status `ineligible`. Signs in and reaches nothing; a surface that opens for
  Ira is a finding.

Vera and Ira are the two personas this script leans on hardest. Both hold a
valid token and a valid session, so anything they can reach was reached from
capabilities rather than from authentication.

## Setup data

- Organization `Northwood Collective` (`northwood-collective`); event `Emberfall 2026`
  (`emberfall-2026`, `America/Los_Angeles`); departments Organizers, Rangers,
  Gate, DPW.
- `apps/client/.env.meridian-admin.local` and `.env.meridian-field.local` with
  `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION=false`. Both files are ignored and
  are written by `corepack pnpm run env:local` with the flag on, so this is an
  edit the reviewer makes and then reverts.
- The client's durable state, all of it device-local, all readable and clearable
  from Application → Local Storage:
  - `meridian.api-token.v1` — the bearer token, its user, its device, its expiry.
  - `meridian.session.v1` — the cached `GET /api/me` document and when it was
    written.
  - `meridian.node.url` — the node this device was pointed at, when one was set.
- A second event for section F. The seeded scenario has one, so create it:

  ```bash
  php apps/server/artisan tinker --execute='$organization = App\Models\Organization::query()->where("slug", "northwood-collective")->firstOrFail(); $event = App\Models\Event::factory()->create(["organization_id" => $organization->id, "name" => "QA CLIENT Second Event", "slug" => "qa-client-second-event", "timezone" => "America/Los_Angeles"]); print(json_encode(["event_id" => (string) $event->id], JSON_PRETTY_PRINT).PHP_EOL);'
  ```

  Then assign Rangers to it and confirm Dana resolves an association with both
  events. If the second event does not appear in Dana's session response in
  section F, that is a setup gap rather than a switcher defect — check the
  department assignment before recording a finding.

## Steps

### A. A client that holds nothing

1. Seed the node and start it:

   ```bash
   php apps/server/artisan migrate:fresh --seed
   ```

2. Confirm `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION=false` in
   `apps/client/.env.meridian-admin.local` — it is the default since M18.9, but a
   file generated before then still says `true`. Then start the client in Admin
   mode with `corepack pnpm run client:dev:admin`.
3. In the browser, clear `meridian.api-token.v1` and `meridian.session.v1` from
   Local Storage, then open the client at `/`.
4. Note where the client lands and what the shell shows.
5. Type a protected URL directly — `/events/{event id}/departments/{Rangers id}/overview` —
   and note where it lands.

### B. Sign in and read the session

6. Sign in as Vera Staff: enter her address on the login screen, read the code
   with `corepack pnpm run server:mailtail`, and enter it on the code screen.
7. With the Network panel open, watch the requests the client makes immediately
   after the code is accepted. Note which one establishes the session, whether
   it carries a bearer token, and what came back.
7a. Confirm that `GET /api/me` carries **no** `event_id` parameter and answers
    200 rather than 409 (M18.9). Without reloading, confirm the shell, Home, and
    Me name Vera and the event she holds, and that every workflow link points at
    that event's id. A sign-in that resolves at the previous occupant's event is
    the defect this step exists for: it leaves the client rendering somebody
    else's event until the page is reloaded.
7b. Repeat step 6 in the same tab as a different persona — Dana Departmentlead —
    without signing out first if the client offers it, and confirm the session,
    the navigation, and the department selection are hers and carry nothing of
    Vera's.
8. Read `meridian.api-token.v1` and `meridian.session.v1` out of Local Storage.
9. Fetch the same document from the command line for a closer read — the token
   is the `token` field of the storage entry:

   ```bash
   curl -s http://127.0.0.1:8000/api/me -H "Accept: application/json" -H "Authorization: Bearer PASTE-TOKEN" | python -m json.tool
   ```

10. Read the payload's top-level keys, then search the whole document for
    anything shaped like a screen, route, menu, or navigation entry.

### C. Navigation follows capabilities

11. Still signed in as Vera, record what the shell offers: navigation entries,
    workflow links on the home surface, and the action controls on any surface
    she can open.
12. Sign out, then sign in as Sam Shiftlead and record the same three things.
13. Sign in as Olive Organizer and confirm the organization surfaces are present;
    sign in as Dana Departmentlead and confirm the Rangers surfaces are present
    and the organization-wide ones are not.
14. Sign in as Ira Ineligible and record what the shell offers.
15. As Vera, type the URL of a surface Sam reached in step 12 — the department
    equipment inventory is a good one — and note what renders.
16. As Vera, call an endpoint behind one of those surfaces directly, with her own
    token:

    ```bash
    curl -i http://127.0.0.1:8000/api/departments/PASTE-RANGERS-ID/equipment -H "Accept: application/json" -H "Authorization: Bearer PASTE-VERA-TOKEN"
    ```

### D. Cached permissions and reduction

17. Sign in as Sam and let the session resolve. Open **Settings** and confirm the
    Permissions section reports current permissions and names when the node last
    answered. Then navigate to two other surfaces and confirm no permissions
    notice appears anywhere outside Settings.
18. Take the browser offline in developer tools and navigate between two
    surfaces Sam can reach. Confirm those surfaces still say nothing about
    permissions — the offline banner is a different indicator and is expected.
19. Open Settings and read the Permissions section: what it says about where the
    permissions came from, and what moment it names.
20. Back online, revoke the `department_logistics` grant on the node. Grants hang
    off teams rather than people, so this revokes it for every team that holds
    it — which in the seeded scenario is Sam's, and is why the seed is reset at
    the start of this script rather than shared with another run:

    ```bash
    php apps/server/artisan tinker --execute='$role = App\Models\PermissionRole::query()->where("code", "department_logistics")->firstOrFail(); $grants = App\Models\TeamGrant::query()->where("permission_role_id", $role->id)->get(); $grants->each(fn ($grant) => $grant->forceFill(["revoked_at" => now()])->save()); print(json_encode(["revoked" => $grants->count()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```

21. Reload the client and record which navigation entries and actions are gone.
22. Reload once more and confirm the removed capability does not come back from
    the cache.
23. End the event window and reload again:

    ```bash
    php apps/server/artisan tinker --execute='App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail()->forceFill(["active_event_window_ends_at" => now()->subHour()])->save();'
    ```

24. Take the browser offline, reload, and record what the client does with the
    cached document now that the window it was cached for has closed.
25. Put the window back (`active_event_window_ends_at` to `now()->addDays(7)`),
    come back online, and reload.

### E. Boot from the cache with the node down

26. In Field mode (`corepack pnpm run client:dev:field`, with the same flag set
    to `false` in `.env.meridian-field.local`), sign in as Sam and open a
    surface so the session is cached.
27. Stop the node (`Ctrl-C` on `server:dev`) and reload the client.
28. Record how long the client takes to render navigation and what that
    navigation is, then open Settings and confirm the Permissions section states
    the same cached story as step 19.
29. Start the node again and reload.

### F. Organization and event switching

30. Sign in as Dana, who holds an association with both events, and open the
    user menu in the shell.
31. Follow **Switch organization** to `organizations.index`, then into that
    organization's events at `organizations.events.index`.
32. Record which organizations and events are listed.
33. Switch to `QA CLIENT Second Event`. With the Network panel open, note which
    request the switch makes and what the shell, navigation, and branding do
    afterwards.
34. Before switching back, note something on screen that belongs to the previous
    event — a department overview, a Field Report list — then switch back and
    confirm what happened to it.
35. Take the browser offline and open the user menu again. Record whether the
    switching entries are present, absent, or disabled, and what the client says
    about it.
36. Come back online. Lock the active node to one event — the lock is
    `nodes.event_id` on the node this server is, which is what
    `SessionResolver` reads — and reload:

    ```bash
    php apps/server/artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $node = App\Models\Node::query()->active()->local()->latest("id")->firstOrFail(); $node->forceFill(["event_id" => $event->id])->save(); print(json_encode(["node" => (string) $node->getKey(), "event_id" => (string) $node->event_id], JSON_PRETTY_PRINT).PHP_EOL);'
    ```

    A fresh seed may hold no local node yet, in which case run the node
    first-run setup (`QA-NODE-01`) before this step; a node that does not exist
    cannot be locked, and that is a setup state rather than a finding.

    Reload the client and record what the context surfaces say. Clear
    `event_id` back to `null` afterwards so the rest of the suite runs against
    an unlocked node.

### G. The command outbox

37. Sign in as Sam and open the Logistics Desk for Rangers.
38. Take the browser offline. Check a rostered staff member in.
39. Record what the outbox notice says, and read the queued command out of Local
    Storage — note its idempotency key, which is the `operation_uuid` the
    attendance command carries on the wire.
40. Still offline, try to mark somebody on-site and try an equipment checkout.
    Record what happens to each.
41. Come back online and let the queue drain. Record what the notice says and
    what happened to the staff member's attendance on the node.
42. Confirm the command was applied once, not twice:

    ```bash
    php apps/server/artisan tinker --execute='App\Models\AttendanceOperation::query()->latest("created_at")->take(10)->get(["operation_uuid", "operation_type", "created_at"])->each(fn ($operation) => print($operation->toJson().PHP_EOL));'
    ```

    Then read the same answer from the attendance records themselves: one
    check-in for that staff member and that shift, not two.
43. Replay the same command by hand and confirm the node treats it as the same
    command rather than a new one. Copy the request body the client sent out of
    the Network panel — it already carries the `operation_uuid` from step 39 —
    and post it again:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/commands/check-in-staff -H "Content-Type: application/json" -H "Accept: application/json" -H "Authorization: Bearer PASTE-SAM-TOKEN" -d 'PASTE-THE-BODY-FROM-THE-NETWORK-PANEL'
    ```

44. Produce a refusal: offline, check in a staff member who is already checked
    in, then come back online and let it drain. Record what the outbox does with
    a command the node refused, and whether it goes away on its own.

### H. Downloads through short-lived URLs

45. Sign in as Olive and open Credentials from the organization pages
    (`organizer.credentials`).
46. With the Network panel open, run the credential eligibility export. Record
    the two requests it makes, in order, and whether either carries the bearer
    token.
47. Copy the issued URL out of the Network panel and open it in a private window
    holding no Meridian session at all.
48. Wait for the URL to expire — the lifetime is
    `meridian.downloads.signed_url_expires_minutes` on the node; shorten it if
    the wait is impractical — and open it again.
49. Take the issued URL and change the resource id in its path, leaving the
    signature alone. Open that.
50. Sign in as Ivy ICViewer, open an incident, and download its PDF. Then sign
    in as Vera and ask for the same PDF's URL directly:

    ```bash
    curl -i -X POST http://127.0.0.1:8000/api/events/PASTE-EVENT-ID/incidents/PASTE-INCIDENT-ID/pdf/download-url -H "Accept: application/json" -H "Authorization: Bearer PASTE-VERA-TOKEN"
    ```

51. As Dana, run the credential eligibility export from the same surface Olive
    used and compare the rows with Olive's file.

### I. A revoked token, from the client's side

52. Signed in as Sam with a surface open, revoke his token in the God Mode
    console at `platform.api-tokens` (`QA-AUTH-01` covers the console screen
    itself).
53. Without reloading, do something in the client that talks to the node — open
    another surface, or let a refresh run.
54. Record what the client does, and confirm `meridian.api-token.v1` and
    `meridian.session.v1` are gone from Local Storage afterwards.

### J. Bound surfaces read the node

55. Sign in as Sam, and with the Network panel filtered to the node's origin,
    open each of these and record the request each one made:
    organizer departments and staff (`organizer.departments.index`,
    `organizer.staff.index`, as Olive), department detail and teams, trainings,
    equipment inventory, shift administration, the document library, the
    incident list, and the Logistics Desk.
56. On any two of them, make a change and confirm it reaches the database rather
    than only the screen — reload after the change and confirm it survived.
57. Search the client for fixture routes still in the path of a bound surface:

    ```bash
    grep -rn "fixtureDepartmentAccess\|localFieldFixture" apps/client/src --include=*.ts --include=*.vue | grep -v spec
    ```

58. Permission-scoped replication (`CLIENT-021`, `CLIENT-022`) is verified by
    `PowerSyncPermissionScopedReplicationTest` and
    `PowerSyncDeviceCacheProjectionTest` rather than by hand: the check is what a
    device is permitted to replicate, which is a property of the sync rules and
    not of a screen. Run them as this script's evidence for those two:

    ```bash
    cd apps/server && php artisan test --filter=PowerSync
    ```

## Expected results

- Step 4: the client lands on the sign-in screen with no navigation at all —
  not a reduced menu, not an empty shell (`CLIENT-005`). If it shows a populated
  shell, `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION` is still `true`; fix that
  and start again, because nothing after this step means anything until it is.
- Step 5: the typed URL lands on sign-in too, by replacement rather than by push
  — the back button does not return to the surface nobody could see.
- Step 7: exactly one request establishes the session, `GET /api/me`, carrying
  the bearer token in an `Authorization` header. Navigation appears only after
  it answers.
- Step 8: `meridian.api-token.v1` carries the token, Vera as its user, a device
  id, and an expiry (`AUTH-021`, `AUTH-024`). `meridian.session.v1` carries the
  session document and the moment this client wrote it. Neither carries a
  password, and the session entry carries no token.
- Step 10: the top-level keys are `user`, `roles`, `capabilities`,
  `organizations`, `events`, `departments`, `teams`, `context`, and
  `refreshed_at` — and nothing else (`CLIENT-002`). Nothing at any depth names a
  screen, route, menu, or surface (`CLIENT-003`). Vera's `capabilities` is empty
  or near it; her `organizations`, `events`, and `departments` are her own.
- Step 11: Vera reaches her own staff surfaces and nothing else. No department
  administration, no organization pages, no IMS, no logistics.
- Step 12: Sam's shell carries the department administration and logistics
  entries his capabilities permit, and still carries no organization-wide
  surfaces — he holds department authority, not organizer authority.
- Step 13: Olive reaches the organization surfaces; Dana reaches Rangers and not
  the organization-wide pages. Every difference between the four shells traces to
  a capability code in the session response and to nothing else (`CLIENT-004`).
- Step 14: Ira's shell is as bare as Vera's. An ineligible department membership
  is not a reduced menu; it is no menu.
- Step 15: the typed surface renders no content Vera may not see. A denied
  surface names the required role only for an elevated user (`CLIENT-024`); Vera
  is not one, so she gets a plain refusal rather than an inventory of what she
  lacks.
- Step 16: the node refuses with `403` regardless of what the client rendered.
  This is the check that matters most in section C: the client hiding an action
  is a courtesy, and the node refusing it is the boundary (`CLIENT-006`).
- Step 17: no permissions notice on any surface outside Settings, in any session
  state. Permission state is a standing property of the session rather than
  news, and it is read on Settings with the rest of this device's standing
  (contract 19A.2). Settings itself reports the live state rather than going
  quiet: a page opened to ask the question answers it.
- Step 19: the Settings Permissions section states that the client is working
  from cached permissions and names when the node last answered, printed on the
  event's own clock
  (`CLIENT-009`). It is stated as information, not as an error — a device
  offline during its event is working normally. It is a separate indicator from
  the offline banner and both may be visible at once.
- Step 21: the removed capability's navigation entries and actions are gone
  immediately after the refresh (`CLIENT-010`).
- Step 22: they stay gone. A capability the node withdrew must not be
  resurrected by a restart, which is what a merged cache would do.
- Step 24: the client refuses to act on the cached document — it renders no
  navigation and says the event window has ended, naming the event and offering
  a refresh, rather than presenting the same blank state as a device that has
  never signed in (`CLIENT-008`).
- Step 28: navigation renders immediately, from the durable copy, before any
  network call resolves and while the node is unreachable (`CLIENT-007`). A
  client that renders nothing until the node answers is the failure this cache
  exists to prevent.
- Step 32: exactly the organizations and events Dana holds an association with
  (`CLIENT-012`). No event she has no association with appears, including any
  belonging to another organization.
- Step 33: the switch re-resolves the session against the new event —
  `GET /api/me` with the new `event_id` — and permissions, navigation, and
  branding all follow the document that came back (`CLIENT-014`).
- Step 34: nothing from the previous event survives the switch. Records, lists,
  and counts belonging to the old context are gone rather than left on screen
  under a new heading.
- Step 35: no switcher at all — absent, not disabled — with the reason stated
  (`CLIENT-013`). A disabled control invites somebody to keep trying something
  that cannot work here.
- Step 36: the locked node's event is the context, the switcher is absent, and
  the surface says the node is locked to that event and names it (`CLIENT-011`).
- Step 39: the check-in is queued, the notice says so, and the queued entry
  carries a client-generated idempotency key — the device's own operation UUID
  (`CLIENT-015`, `CLIENT-016`).
- Step 40: both are refused where they stand, each with a sentence saying it
  needs a connection, and neither is queued (`CLIENT-018`). A connected-only
  command that quietly queues is a finding: it would be recorded against an
  answer that may already be wrong by the time it drains.
- Step 41: the queue drains, the command settles as accepted, and the check-in
  is on the node.
- Steps 42 and 43: the command took effect exactly once. The replayed key
  produces no second check-in and no error — the same key is the same command,
  which is what makes replay after an interrupted sync safe (`CLIENT-016`).
- Step 44: the refused command stays in the outbox as rejected, carrying the
  node's reason, with **Try again** and **Dismiss** beside it, until a person
  acts on it (`CLIENT-017`). A rejected check-in that vanished would leave
  somebody believing they recorded attendance they did not.
- Step 46: two requests, in order — a `POST` to the export's `download-url`
  sibling carrying the bearer token, then a navigation to the URL it returned,
  carrying no credential of its own beyond the signature (`CLIENT-019`). No
  request anywhere in that pair puts a token in a link.
- Step 47: the issued URL serves the file to a session-less window, because the
  signature is the authorization the node already decided.
- Step 48: the expired URL is refused (`CLIENT-020`).
- Step 49: the altered path is refused. The signature covers the resource, so a
  URL issued for one file does not retrieve another.
- Step 50: Ivy's PDF downloads by the same two-step path. Vera's request for the
  same URL is refused at the point of issue with `403`, so the refusal is an
  answer the surface can show rather than a tab opening onto an error document.
- Step 51: Dana's export runs from the same entry point and contains Rangers
  rows only, while Olive's covers every department (`REPORT-006`, `REPORT-007`).
  The surface itself does not narrow anything — the node resolves the scope from
  the caller's own authority when it issues the URL and again when it serves the
  file.
- Step 54: the client moves to sign-in where it stands, without waiting for the
  next click, and both storage entries are cleared (`AUTH-023`). A device whose
  token was revoked while somebody was looking at a surface must not be left
  standing on it.
- Step 55: every surface listed made an HTTP request to the node. A surface that
  rendered content while making no request is reading a fixture (`CLIENT-023`).
- Step 56: the change survives a reload, so it reached the database.
- Step 57: no bound surface's path reaches a fixture. `fixtureDepartmentAccess`
  is gone entirely; remaining `localFieldFixture` references belong to the
  development session module and the Field Report development seed, both of
  which are inert with the environment flag off.
- Step 58: the PowerSync tests pass.

## Evidence to capture

- Screenshot of the signed-out shell from step 4 beside Vera's, Sam's, Olive's,
  and Ira's from steps 11 through 14 — five shells, one session response each.
- The `GET /api/me` payload from step 9, with its top-level keys visible.
- Network panel transcript from step 7 showing the session request and its
  `Authorization` header, with the token value redacted.
- The `403` from step 16.
- Screenshot of the Settings Permissions section in its current state (step 17),
  its cached state (step 19), and its expired state (step 24), plus one of an
  ordinary surface while offline showing no permissions notice on it.
- Screen recording or timed screenshot of step 28, showing navigation rendered
  with the node stopped.
- Screenshots of the context surfaces from steps 32, 35, and 36 — the switcher
  present, absent offline, and absent under a node lock.
- The queued outbox entry from step 39 with its idempotency key, the refusals
  from step 40, and the rejected entry with the node's reason from step 44.
- Network panel transcript of step 46 showing the two requests in order.
- The refusals from steps 48, 49, and 50.
- Olive's and Dana's credential eligibility files from steps 46 and 51.
- The `php artisan test --filter=PowerSync` output.

## Failure notes

- Record any populated shell on a client holding no session — and check the
  environment flag before recording it as a defect.
- Record any navigation entry, action, or surface that renders for a user whose
  session response carries no permitting capability code.
- Record any endpoint that answers a caller the client would have hidden the
  action from. A client-side omission that the server does not also refuse is
  the most serious finding this script can produce.
- Record anything navigation-shaped in the `GET /api/me` payload: a screen list,
  a menu structure, a route name, a precomputed surface availability flag.
- Record any capability that survives its removal on the node, whether across a
  refresh or across a restart.
- Record any client that renders nothing until the node answers, or that sends a
  user to a sign-in screen they cannot complete while holding a usable cached
  session.
- Record any cached session that grants access after its event window has ended.
- Record any switcher offered to an offline client, any organization or event
  offered that the user holds no association with, and any data from a previous
  context left visible after a switch.
- Record any command that queues when it should have been refused, any queued
  command that takes effect twice, and any rejected command that disappears
  without a person dismissing it.
- Record any credential in a download link, any short-lived URL that outlives
  its expiry or retrieves a resource it was not issued for, and any download
  that refuses in a new tab rather than on the surface that asked for it.
- Record any surface that renders content while making no request to the node.
