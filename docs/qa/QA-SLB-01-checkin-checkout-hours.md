# QA-SLB-01: Check-In, Check-Out, and Hours

## Purpose

Verify the Milestone 10 staff-mediated attendance workflow: Department
Logistics can mark assigned department staff on-site, check them into a selected
shift, check them out with actual start/end times, create canonical hours, and
review the resulting attendance/hour records. The script also verifies no-show,
offline queued attendance writes, authorized hours correction before freeze, and
freeze blocking after the correction grace period. Since M18.4 the correction is
performed from the Logistics Desk staff workspace as well as from the domain, so
section F now walks both.

Since M18.8 the desk's department index is durable, so section E also walks
SLB-021's offline half: a desk that has read its department once still searches
staff, equipment, and shifts with the node unreachable, says which copy it is
searching, and holds nothing for a department this device has not read.

This script covers the Alpha 1 human QA gate for check-in/check-out/hours. It
does not add or require staff self-service check-in, credit calculation,
PowerSync conflict repair UI, signed node-operation envelopes, or new product
behavior beyond existing Department Operations surfaces and domain commands.

Section H additionally verifies the Milestone 13 actual hours worked export
(M13.4), which reads back out the same records sections C and F create and
correct: a department lead exports their own department, an organizer exports
the whole event, scheduled and actual minutes appear side by side, correction
and freeze state are reported on every row, staff who worked no hours produce no
rows, unauthorized actors are refused, and each successful export is audited.

## Requirements covered

- `SLB-003`
- `SLB-004`
- `SLB-005`
- `SLB-006`
- `SLB-007`
- `SLB-008`
- `SLB-015` through `SLB-018`
- `SLB-019`
- `SLB-021`
- `SLB-031`
- `SLB-032`
- `CLIENT-014`, `CLIENT-015`, `CLIENT-018`, `CLIENT-023`
- `HOURS-001` through `HOURS-008`
- `REPORT-004`
- `REPORT-006`
- `REPORT-007`
- `REPORT-010`
- Requirements sections 3.13, 3.14, and 7.14
- Technical spec sections 20.1 through 20.6, 22.2 (CSV export), and 23
- Data/API spec sections 7.2 and 10.10
- UI Implementation Contract sections 12.5 and 16.2
- Kiosk and Field Hardware UX Guide section 5
- Meridian Alpha 1 tasks M10.2 through M10.6, M10.11, M13.4, M16.21, M18.3,
  M18.4, and M18.8

## Environment

- A dedicated development/QA database. The setup below runs
  `migrate:fresh --seed` and must not be used against shared or valuable data.
- Repository dependencies installed with approved PHP, Composer, Node.js 24 LTS,
  and pnpm 11.x versions.
- Laravel available at `http://127.0.0.1:8000`.
- The shared Vue client available at `http://127.0.0.1:5173` in a secure browser
  context (`localhost` / `127.0.0.1` is sufficient).
- Browser DevTools capable of switching the page network condition to Offline.
- At least three terminals: Laravel server, shared client dev server, and QA
  commands.

## Personas

- Department Logistics operator: seeded Sam Shiftlead
  (`sam.shiftlead@northwood-collective.test`) or another actor with Department
  Logistics permission for Rangers / Dirt.
- Department lead reviewer: seeded Dana Departmentlead
  (`dana.departmentlead@northwood-collective.test`), permitted to review and correct
  department attendance/hours where authorized.
- Staff subject: seeded Vera Staff (`vera.staff@northwood-collective.test`), active in
  Rangers / Dirt and assigned or assignable to Ranger Dirt shifts.
- Unauthorized/default staff comparison actor: Vera Staff when confirming that
  default staff do not self check-in/out or see Logistics controls.
- Organizer exporter (section H): seeded Olive Organizer
  (`olive.organizer@northwood-collective.test`).
- Human reviewer observing the UI and retaining command/test evidence.

## Setup data

1. From the repository root, install dependencies if needed:
   ```bash
   corepack pnpm install
   composer --working-dir=apps/server install
   ```
2. Configure local Laravel and shared-client Vite env files:
   ```bash
   corepack pnpm run setup:local
   ```
3. Configure a dedicated local server database, then reset and seed it:
   ```bash
   php apps/server/artisan migrate:fresh --seed
   ```
4. Start Laravel in one terminal:
   ```bash
   php apps/server/artisan serve --host=127.0.0.1 --port=8000
   ```
5. Start the shared Kiosk client in another terminal:
   ```bash
   corepack pnpm run client:dev:kiosk -- --host 127.0.0.1
   ```
6. Open `http://127.0.0.1:5173/login` and sign in, reading the login code out of
   the mail log with
   `grep -A 2 "Enter this code" apps/server/storage/logs/laravel.log | tail -1`.
   Attendance commands carry the token this issues (AUTH-018; M16.11), so a run
   that skips this step queues operations and sends none. A workstation that has
   been through kiosk code entry (QA-AUTH-01) is signed in already and needs
   nothing here.
7. Choose **Logistics desk** from the home surface. The direct route pattern is
   `/events/:eventId/departments/:departmentId/logistics`.
8. Clear site data first if an earlier local attendance/offline run is present,
   signing in again afterwards.
9. Confirm the seeded event/department context is Emberfall 2026 /
   Rangers, and that the desk names the same event and department under **Search
   scope** with the time it read them. Since M16.21 the desk reads its staff,
   equipment, and shifts from the node rather than from data bundled into the
   client, so a desk showing nobody is a signed-in operator with no department
   roster rather than a fixture that failed to load.

## Steps

### A. Automated attendance evidence

1. From `apps/server`, run the attendance and hours server suites:
   ```bash
   php artisan test \
     tests/Feature/DepartmentPresenceTest.php \
     tests/Feature/AttendanceCheckInTest.php \
     tests/Feature/AttendanceCheckOutTest.php \
     tests/Feature/AttendanceMarkNoShowTest.php \
     tests/Feature/AttendanceCommandHttpTest.php \
     tests/Feature/DepartmentOperationsReadHttpTest.php \
     tests/Feature/DepartmentOperationsCommandHttpTest.php \
     tests/Feature/HoursCorrectionTest.php \
     tests/Feature/HoursWorkedExportTest.php
   ```
2. From the repository root, run the shared client attendance/offline suites:
   ```bash
   corepack pnpm --filter @meridian/client run test -- \
     src/department-ops/departmentOpsReadModel.spec.ts \
     src/views/DepartmentOpsViews.spec.ts \
     src/shift-board/offlineAttendanceOperation.spec.ts \
     src/outbox/commandOutbox.spec.ts \
     src/outbox/submitCommand.spec.ts \
     src/outbox/syncCommandOutbox.spec.ts
   ```
   Attendance queueing, durability, and transport moved onto the shared command
   outbox in M16.10, so the attendance evidence is now in the outbox suites:
   attendance is a caller of that queue rather than the owner of one.
3. Confirm all suites pass. Retain output proving:
   - check-in requires authorized Department Logistics/lead access and on-site
     department presence;
   - duplicate check-in is idempotent;
   - check-out creates one `hours_worked` record with actual start/end, minutes,
     event, department, shift, staff, and attendance record;
   - no-show is constrained to started shifts and is idempotent;
   - offline command transport preserves operation UUID, device/node provenance,
     and retry-safe acceptance for check-in, check-out, and no-show;
   - the four department operations reads answer only callers with standing in
     the department, carry the same authority the commands enforce, and keep
     every identity off a Planning Table row;
   - presence refuses an off-site move while somebody is checked in, and an
     unscheduled shift addition refuses somebody who is not on-site, each in the
     domain service's own words;
   - presence refuses an off-site move while somebody still holds checked-out
     department equipment, accepts it once that equipment is returned or written
     off as Missing or Damaged, and the Logistics Desk read answers the same way
     the command does in both cases;
   - hours correction writes before/after audit, rejects unauthorized actors and
     invalid ranges, and frozen hours reject later correction;
   - the hours worked export matches its committed sample file, reports
     scheduled minutes beside actual minutes, reports correction and freeze
     state, produces rows only from recorded hours, is scoped to the caller's
     own authority, and is audited.

### B. Logistics Desk check-in

4. In the Kiosk client, open Logistics Desk as a Department Logistics operator.
5. Search for `Vera Staff` and open Vera's staff workspace.
6. Confirm the workspace shows Vera's identity/context, on-site/off-site
   controls, active/upcoming/outgoing shift context, future signups, and
   check-in/check-out affordances in one continuous workspace.
7. If Vera is not already on-site for Rangers, mark her on-site.
8. Open Check in for `Ranger Dirt Day Shift`.
9. Confirm the dialog defaults the timestamp to now, keeps the selected shift
   visible, and does not present staff self-service language.
10. Submit check-in.
11. Confirm Vera's workspace now shows her checked into the selected shift and
    the Department Overview checked-in count/currently-working section updates
    for the same shift.
12. Attempt to mark Vera off-site while checked in.
13. Confirm the action is blocked or unavailable with a clear explanation.

### C. Check-out and hours creation

14. Return to Vera's Logistics workspace and open Check out for the checked-in
    shift.
15. Confirm the check-out dialog defaults **Actual end** to now, offers an
    empty **Actual start**, and says that leaving the start empty keeps the
    recorded check-in.
16. Set actual start to `2026-07-01 08:00` and actual end to
    `2026-07-01 12:07`, then submit check-out.
17. Confirm Vera's attendance state changes to checked out and no longer blocks
    off-site status because of shift attendance. If Vera holds equipment, the
    equipment rule may still block off-site until equipment is returned, marked
    Missing, or marked Damaged.
18. From `apps/server`, inspect the resulting hours:
    ```bash
    php artisan tinker --execute='$staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); App\Models\HoursWorked::query()->with(["event", "department", "shift", "attendanceRecord"])->where("staff_id", $staff->id)->latest("created_at")->limit(5)->get()->each(fn ($hours) => print(json_encode(["hours_worked_id" => $hours->id, "event" => $hours->event?->slug, "department" => $hours->department?->code, "shift" => $hours->shift?->title, "attendance_record_id" => $hours->attendance_record_id, "actual_started_at" => (string) $hours->actual_started_at, "actual_ended_at" => (string) $hours->actual_ended_at, "minutes_worked" => $hours->minutes_worked, "status" => $hours->status, "frozen_at" => (string) $hours->frozen_at], JSON_PRETTY_PRINT).PHP_EOL));'
    ```
19. Confirm the latest row belongs to the event, department, selected shift, and
    Vera; includes actual start/end; has computed minutes; and is separate from
    the scheduled shift duration.

### C2. Presence and unscheduled shift addition (M16.21, M18.3)

19a. Pick an on-site Rangers staff member who holds no assignment on the running
     shift, open their workspace, and confirm the shift card offers **Add to
     shift**.
19b. Add them, and confirm the desk re-reads: the card now shows an assignment
     and offers check-in, and the Department Overview assignment count for that
     shift goes up by one.
19c. From `apps/server`, confirm the addition is a real assignment audited as an
     unscheduled one:
     ```bash
     php artisan tinker --execute='App\Models\AuditEvent::query()->where("action", "shift_assignment.unscheduled_added")->latest("created_at")->limit(3)->get(["actor_user_id", "entity_id", "after_json"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
     ```
19d. Open the workspace of somebody who is off-site and confirm the desk offers
     them no check-in and no shift addition at all — the node did not offer the
     actions, rather than the client disabling buttons it drew anyway. Mark them
     on-site and confirm the addition appears, which is the order requirements
     5.8 asks for: presence first, then the shift.
19d1. With that person still on-site, look at a running shift belonging to a team
     they are not on. Confirm its card is on screen, offers no **Add to shift**,
     and reads "This shift is for the *team* team, and they are not a member of
     it." A card that cannot be acted on still has to say why.
19d2. Archive the team behind one of those shifts from the department's team
     administration, reload the desk, and confirm the card now reads "The *team*
     team has been archived, so this shift takes no additions" and still offers
     nothing. The desk and `UnscheduledShiftAdditionService` decide this on one
     rule, so a button here would be one the node refuses.
19e. Confirm presence writes reach the node:
     ```bash
     php artisan tinker --execute='App\Models\EventDepartmentPresence::query()->latest("updated_at")->limit(5)->get(["staff_id", "current_state", "marked_on_site_at", "marked_off_site_at", "last_marked_by_user_id"])->each(fn ($presence) => print($presence->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
     ```
19f. Hand a radio to an on-site staff member who is not checked into anything,
     then open their workspace. Confirm **Mark off-site** is closed, the desk
     states "Staff must return or resolve checked-out department equipment
     before being marked off-site," and the radio is named in the workspace's
     equipment list, so the operator reads what to do about it (SLB-018).
19g. Take the radio back through the desk's own return control, choosing
     **Returned**. Confirm **Mark off-site** opens, the sentence is gone, and
     marking them off-site is accepted.
19h. Repeat 19f, then write the radio off from God Mode instead of returning it:
     in Orchid, open the equipment item and set its status to **Missing**. Reload
     the desk and confirm the radio is still listed in the workspace's equipment,
     because the department is still owed it, and that **Mark off-site** is now
     open and accepted. SLB-018 blocks on equipment somebody is still holding,
     not on equipment already written off, and the desk and the command have to
     read that the same way.

### D. No-show path

20. Select another assigned staff member/started Rangers shift that is not
    checked in. If needed, use the seeded scenario or create a temporary QA
    shift/assignment from `apps/server`.
21. From Logistics, mark the staff member no-show after the shift start.
22. Confirm the workspace and Department Overview/Planning Table reflect a
    no-show state/count for that shift.
23. Repeat the same no-show action or retry the same operation after a simulated
    submit uncertainty.
24. Confirm no duplicate current-state record is created and the UI remains in
    no-show state.

### E. Offline queued attendance writes

25. In DevTools, set the browser network condition to Offline.
26. From Logistics, perform one attendance action that has required synced local
    data available, such as check-in, check-out with actual times, or mark
    no-show.
27. Confirm the action remains locally represented as queued/pending sync and
    the UI does not imply current central truth while offline.
27a. Still offline, try to mark somebody on-site, add somebody to a shift, or
     hand out a piece of equipment. Confirm each is refused where it stands with
     a sentence naming the work — "Marking someone on-site needs a connection to
     the node" and its siblings — and that nothing is queued for it. Attendance
     queues and the rest does not, which is the line data/API 7.2 draws
     (CLIENT-018).
28. Record the queued operation UUID from the UI, local pending queue, or
    browser storage evidence before reconnecting.
29. Reload the page while still offline.
29a. Confirm the Logistics Desk still opens on this department rather than on the
     unreachable-node message, and that the **Search scope** panel says the node
     could not be reached and names the moment this device stored the copy it is
     searching (M18.8, SLB-021).
29b. Still offline, search the reloaded desk for a staff member, a piece of
     equipment by asset tag, and a shift by title. Confirm each is found and that
     opening the staff member's workspace shows their shift cards, presence, and
     open equipment as of the stored read.
29c. Switch the department in the shell to one this device has not opened while
     online, and confirm that desk states the unreachable node rather than
     showing the first department's staff under the second department's heading
     (CLIENT-014).
30. Confirm the queued attendance action remains visible enough for the
    Department Logistics operator to trust that it was captured.
31. Restore the browser network condition to Online.
32. Trigger retry/sync if the UI exposes a manual retry, or wait for automatic
    sync.
33. Confirm the queued action is accepted once, clears from pending state, and
    updates the server-side attendance/hours state.
34. From `apps/server`, inspect accepted operation provenance:
    ```bash
    php artisan tinker --execute='App\Models\AttendanceOperation::query()->latest("created_at")->limit(10)->get(["operation_uuid", "operation_type", "staff_id", "shift_id", "source_context", "origin_device_id", "origin_node_id", "device_created_at", "server_received_at"])->each(fn ($operation) => print($operation->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
35. Confirm the accepted server row has the same operation UUID recorded before
    reconnecting and retains device-created timestamp, server receipt timestamp,
    source context, and device/node provenance when available.

### F. Hours correction and freeze

#### F1. Correction from the Logistics Desk (M18.4, SLB-031, SLB-032)

35a. As Dana Department Lead or another authorized attendance manager, open the
     Logistics Desk, search for the staff member you checked out in section C,
     and open their workspace. Confirm the card for that shift now sits under
     **Outgoing shifts** and reads its recorded hours — actual start, actual
     end, and minutes — beside the shift window.
35b. Press **Correct hours** on that card. Confirm the dialog is titled "Correct
     hours", states that the previous values stay in attendance history, and
     opens with **both** actual times already filled with the recorded ones
     rather than blank or set to now.
35c. Without touching either field, note the two times the dialog shows and
     confirm they read as the same wall-clock times the card does. A dialog
     pre-filled in UTC and re-read as local would silently move the number the
     operator came to correct.
35d. Change only the actual start — move it fifteen minutes earlier — and
     confirm. Confirm the desk re-reads, the card's recorded hours and minutes
     change accordingly, and the actual end is exactly what it was before.
35e. Confirm the correction went to the node rather than into the outbox:
     ```bash
     php artisan tinker --execute='App\Models\AttendanceOperation::query()->where("operation_type", "correct")->latest("created_at")->limit(3)->get(["operation_uuid", "operation_type", "staff_id", "shift_id", "source_context", "origin_device_id", "created_by_user_id"])->each(fn ($operation) => print($operation->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
     ```
35f. Confirm the check-out operation for that shift and staff member is still
     present alongside the correction. A correction appends to attendance
     history; it does not rewrite it (SLB-032).
35g. Set the browser network condition to Offline and press **Correct hours**
     again. Confirm it is refused where it stands with "Correcting hours needs a
     connection to the node," that nothing is queued for it, and that the
     recorded hours are unchanged. Restore the connection before continuing.

#### F2. Correction and freeze from the domain

36. Identify the latest `hours_worked_id` from section C.
37. As an authorized attendance manager, correct the actual start/end while the
    record is unfrozen:
    ```bash
    php artisan tinker --execute='$hours = App\Models\HoursWorked::query()->latest("created_at")->firstOrFail(); $actor = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $result = app(App\Services\Attendance\HoursCorrectionService::class)->correctHours($hours, $actor, (string) Illuminate\Support\Str::uuid(), Illuminate\Support\Carbon::parse("2026-07-01 08:15:00"), Illuminate\Support\Carbon::parse("2026-07-01 12:45:00")); print(json_encode(["hours_worked_id" => $result->hoursWorked->id, "operation_type" => $result->operation->operation_type, "minutes_worked" => $result->hoursWorked->minutes_worked, "corrected_by_user_id" => $result->hoursWorked->corrected_by_user_id, "corrected_at" => (string) $result->record->corrected_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
38. Confirm the correction updates actual times/minutes, records the correcting
    actor, creates a `correct` attendance operation, and leaves the UI showing
    corrected attendance as normal.
39. Inspect correction audit:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->where("action", "hours.corrected")->latest("created_at")->limit(3)->get(["action", "actor_user_id", "entity_id", "before_json", "after_json"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
40. Confirm before/after values include the prior and corrected minutes/times.
41. Freeze the latest hours record:
    ```bash
    php artisan tinker --execute='$hours = App\Models\HoursWorked::query()->latest("created_at")->firstOrFail(); $actor = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $frozen = app(App\Services\Attendance\HoursCorrectionService::class)->freezeHours($hours, $actor, Illuminate\Support\Carbon::parse("2026-07-08 00:00:00")); print(json_encode(["hours_worked_id" => $frozen->id, "frozen_at" => (string) $frozen->frozen_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
42. Attempt another correction:
    ```bash
    php artisan tinker --execute='try { $hours = App\Models\HoursWorked::query()->latest("created_at")->firstOrFail(); $actor = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); app(App\Services\Attendance\HoursCorrectionService::class)->correctHours($hours, $actor, (string) Illuminate\Support\Str::uuid(), Illuminate\Support\Carbon::parse("2026-07-01 08:00:00"), Illuminate\Support\Carbon::parse("2026-07-01 11:00:00")); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
43. Confirm the denial names the moment the grace period closed rather than
    stating that one exists — `The correction grace period closed on 7 Jul 2026
    17:00 PDT, so these hours are frozen and can no longer be corrected.`, with
    the date and zone matching the `frozen_at` you set in step 41 read in the
    event's time zone — and that the frozen hours values are unchanged.
43a. Reload the Logistics Desk and open that staff member's workspace. Confirm
     the card for the frozen shift no longer offers **Correct hours** and prints
     the same sentence the command refused with, word for word. The desk and the
     node have to say one thing; a button here would be one the node refuses.
43b. Find a staff member assigned to a shift that has ended and was never
     checked out. Confirm their card offers no correction and reads "This shift
     has no recorded hours yet. Check this staff member out to create them.",
     which names the action that is actually available.

### G. Role and non-goal checks

44. Sign in or simulate the UI as default Vera Staff without Department
    Logistics/lead authority.
45. Confirm Vera cannot open Logistics mutation controls for herself, cannot
    self check-in/out as default staff, and cannot correct hours.
46. Confirm organizers do not automatically see all department attendance unless
    they also hold the documented department capability.
47. Open the Planning Table and confirm the shift detail beside the chart shows
    counts and hours only — no name, no roster, and no signup list (SLB-019).
48. Confirm this script did not require credit calculation, equipment offline
    sync, incident creation, Field Report review, PowerSync conflict repair UI,
    or direct God Mode attendance editing.

### H. Actual hours worked export (M13.4)

This section reads back out the records sections C and F created, corrected, and
froze, so run it after those sections rather than on a freshly seeded database.

48. From `apps/server`, export as Dana Departmentlead and save the file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $rangers = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($dana, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT); $export = app(App\Services\Reporting\HoursWorkedExportService::class)->export($event, $scope, $dana); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["event_wide" => $scope->organizationWide, "department_ids" => $scope->departmentFilter(), "rangers_id" => (string) $rangers->id, "filename" => $export->filename, "row_count" => $export->rowCount, "saved_to" => storage_path("app/".$export->filename)], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
49. Confirm `event_wide` is false, `department_ids` contains only the Rangers id,
    the filename carries `rangers`, and every exported row belongs to Rangers.
50. Confirm the header row is `event_name,department,team,shift_title,shift_starts_at,shift_ends_at,scheduled_minutes,staff_legal_name,staff_preferred_name,staff_handle,staff_email,actual_started_at,actual_ended_at,minutes_worked,hours_worked,hours_status,correction_state,corrected_at,frozen_at`.
51. Find Vera's row for the shift used in section C. Confirm `scheduled_minutes`
    is the scheduled length of that shift and `minutes_worked` is the corrected
    actual length from step 37, that the two differ, and that `hours_worked`
    equals `minutes_worked` divided by 60 to two decimal places (HOURS-001,
    HOURS-002).
52. Confirm the same row reports `corrected_at` with the correction moment from
    step 37 (HOURS-007) and `correction_state` of `frozen` with `frozen_at`
    matching the freeze from step 41 (HOURS-008).
53. Confirm rows come from recorded hours alone:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($dana, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT); $export = app(App\Services\Reporting\HoursWorkedExportService::class)->export($event, $scope, $dana); $recorded = App\Models\HoursWorked::query()->where("event_id", $event->id)->whereIn("department_id", $scope->departmentFilter())->count(); print(json_encode(["recorded_hours_rows" => $recorded, "exported_rows" => $export->rowCount], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
54. Confirm the two counts match, and that the no-show staff member from section
    D and any staff member still checked in have no row in the file. Hours
    cannot exist without a worked shift (HOURS-005, HOURS-006); who was expected
    on a shift is the shift roster export's answer, not this one.
55. Export as Olive Organizer and save the file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT); $export = app(App\Services\Reporting\HoursWorkedExportService::class)->export($event, $scope, $olive); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["event_wide" => $scope->organizationWide, "filename" => $export->filename, "row_count" => $export->rowCount, "saved_to" => storage_path("app/".$export->filename)], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
56. Confirm `event_wide` is true and the organizer file contains at least every
    row the Rangers file contained (REPORT-006).
57. Confirm the file carries no contact or identity fields it has no business
    carrying:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@northwood-collective.test")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@northwood-collective.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT); $csv = app(App\Services\Reporting\HoursWorkedExportService::class)->export($event, $scope, $olive)->contents; print(json_encode(["phone_present" => $staff->phone !== null && str_contains($csv, (string) $staff->phone), "emergency_name_present" => $staff->emergency_contact_name !== null && str_contains($csv, (string) $staff->emergency_contact_name), "emergency_columns_present" => str_contains($csv, "emergency_contact"), "date_of_birth_present" => $staff->date_of_birth !== null && str_contains($csv, $staff->date_of_birth->format("Y-m-d"))], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
58. Confirm all four values are false (REPORT-010). A timesheet needs none of
    those fields to be a timesheet.
59. Confirm a department lead cannot reach another department, and that
    unauthorized actors resolve no export scope at all:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "emberfall-2026")->firstOrFail(); $gate = App\Models\Department::query()->where("code", "GATE")->firstOrFail(); $access = app(App\Services\Reporting\ReportingExportAccess::class); $permission = App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT; $dana = App\Models\User::query()->where("email", "dana.departmentlead@northwood-collective.test")->firstOrFail(); print(json_encode(["dana_includes_gate" => $access->resolve($dana, $event, $permission)->includesDepartment((string) $gate->id)]).PHP_EOL); foreach (["vera.staff@northwood-collective.test", "sam.shiftlead@northwood-collective.test"] as $email) { $user = App\Models\User::query()->where("email", $email)->firstOrFail(); print(json_encode([$email => $access->resolve($user, $event, $permission) === null ? "denied" : "unexpected_scope"]).PHP_EOL); }'
    ```
60. Confirm `dana_includes_gate` is false and both personas are `denied`. Having
    worked the hours is not authority to export them, and the Department
    Logistics authority to record and correct hours is not authority to export
    the department's timesheet (HOURS-007).
61. Confirm each successful export was audited:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->where("action", "event_hours_worked.exported")->latest("created_at")->take(5)->get(["actor_user_id", "event_id", "department_id", "after_json"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
62. Confirm one audit event per export with the acting user, the event, a `scope`
    of `event` or `department`, a `row_count` matching the file, and a
    `total_minutes_worked` matching the sum of the file's `minutes_worked`
    column.
63. Optional HTTP check when a browser session is available for Olive or Dana
    (log in with `QA-AUTH-01`): download
    `/api/events/{event id}/exports/hours-worked` and confirm the browser saves a
    `.csv` attachment. Add `?department_id={department id}` to narrow an
    organizer export to one department, and confirm a department id from another
    organization returns 404.
64. Confirm this export is server-generated and online-only, that no product UI
    entry point is required for it yet, and that credit calculation from these
    hours belongs to M13.5 and M13.6.

## Expected results

- Department Logistics can mark eligible department staff on-site and then
  check scheduled staff into a selected department shift.
- Check-in creates an append-only attendance operation, derives checked-in
  current state, and audits accepted state changes.
- Staff cannot be marked off-site while checked into a shift for that
  department/event.
- Staff cannot be marked off-site while holding checked-out equipment for that
  department/event, and can be once that equipment is returned or marked Missing
  or Damaged. The Logistics Desk states the block in the same words the command
  refuses with, and offers the off-site control in exactly the cases the command
  would accept.
- Department Logistics can check staff out from the selected staff workspace and
  supply actual start/end times during check-out.
- Department Logistics can mark eligible staff on-site and off-site, and add an
  on-site staff member to a started shift they were not assigned to, both through
  the node rather than in the browser.
- Presence, shift addition, equipment handoff, and hours correction are refused
  where they stand when the node is unreachable, while check-in, check-out, and
  no-show queue.
- The Planning Table shows aggregates only, with no staff identity anywhere on
  it.
- Check-out creates one canonical `hours_worked` record tied to event,
  department, shift, staff, and attendance record, with computed minutes from
  actual times rather than scheduled duration.
- No-show can be recorded by authorized attendance managers after shift start
  and remains idempotent on retry.
- Check-in, check-out, and no-show can queue offline when the surface and synced
  data are available, remain visible as queued/pending, and sync once with
  operation UUID/device/node provenance.
- A Logistics Desk that has read its department once still searches staff,
  equipment, and shifts with the node unreachable, states that it is searching a
  stored copy and when that copy was taken, and offers no index at all for a
  department this device has not read.
- Authorized attendance managers can correct unfrozen hours with before/after
  audit evidence, from the Logistics Desk staff workspace as well as from the
  domain, and the dialog opens on the recorded times rather than on now or on
  nothing.
- A correction appends a `correct` attendance operation beside the check-out
  that produced the record rather than replacing it, and the prior values remain
  readable in attendance history and in the audit entry.
- Correction is refused where it stands when the node is unreachable, unlike the
  check-out that created the record.
- Frozen hours reject later correction and preserve recorded values, and the
  refusal names the moment the grace period closed in the event's time zone. The
  Logistics Desk withdraws the control and prints the same sentence.
- The hours worked export produces a CSV with the documented header and one row
  per recorded `hours_worked` record in scope, reporting the scheduled window and
  scheduled minutes beside the actual window, minutes, and decimal hours.
- Every exported row reports its correction state, and a corrected record names
  the moment it was corrected.
- A no-show, a staff member still checked in, and a shift nobody worked produce
  no rows.
- The export carries no phone number, emergency contact, or date of birth.
- Department leads export only their own department; staff who worked the hours
  and Department Logistics operators who recorded them resolve no export scope.
- Every successful export writes one `event_hours_worked.exported` audit event
  naming the actor, event, scope, row count, and total minutes worked.
- Default staff self check-in/out, free-floating hours, staff self-reported
  hours, credits, conflict repair UI, and signed node-operation envelopes are not
  required for this Alpha 1 QA gate.

## Evidence to capture

- Server attendance/hour test output and client attendance/offline test output.
- Screenshot or screen recording of Vera's Logistics workspace before check-in,
  after on-site/check-in, and after check-out.
- Screenshot showing off-site blocked while checked in.
- Screenshot of the Correct hours dialog opened on a completed shift, showing
  both actual times pre-filled, and a second one of the same card after the
  correction with the changed recorded hours.
- Screenshot of a frozen shift card showing no correction control and the
  sentence naming the date the grace period closed.
- Screenshot showing off-site blocked while equipment is still out, with the
  equipment named, and a second one showing the same workspace once the item is
  returned or written off.
- Tinker output showing the `hours_worked` row with event, department, shift,
  staff, attendance record, actual times, minutes, and status.
- No-show UI evidence and/or server output proving idempotent no-show state.
- Offline queued action screenshot before reload, after reload, and after sync.
- Tinker output showing accepted offline operation provenance.
- Tinker output for hours correction, `hours.corrected` before/after audit, and
  frozen-hours rejection.
- Screenshot or notes confirming default staff cannot self check-in/out.
- The saved department-scoped and organizer hours worked export files.
- Recorded-hours versus exported-row count output.
- Sensitive-field check output showing all four values false.
- Denied export-scope output for the department lead reaching Gate and for the
  staff and Department Logistics personas.
- Hours worked export audit output including `total_minutes_worked`.

## Failure notes

- If check-in is possible before department on-site presence, stop and file a
  blocking SLB-003 / SLB-015 issue.
- If default staff can self check-in/out, stop and file a blocking Alpha 1
  self-service scope issue.
- If check-out does not create `hours_worked`, or creates hours without event,
  department, shift, staff, attendance record, actual start, or actual end, stop
  and file a blocking SLB-005 / HOURS-001 through HOURS-006 issue.
- If edited actual times during check-out are ignored, stop and file an SLB-006
  issue.
- If a desk action changes what is on screen without the node recording it, stop
  and file a blocking CLIENT-023 issue: a surface that reports work it did not
  send is the failure this milestone exists to end.
- If presence, shift addition, or equipment handoff is queued rather than refused
  while offline, stop and file a blocking CLIENT-018 issue.
- If any staff name appears on the Planning Table, stop and file a blocking
  SLB-019 issue.
- If correction lacks authorization, before/after audit, or updated minutes, stop
  and file an SLB-007 / HOURS-007 issue.
- If frozen hours can be corrected, stop and file a HOURS-008 issue.
- If queued offline attendance disappears after reload or syncs multiple times
  for the same operation UUID, stop and file an offline attendance queue issue.
- If the export reports scheduled minutes as actual minutes, or omits either,
  stop and file a blocking HOURS-001 / HOURS-002 issue.
- If a frozen record exports as `open`, or a corrected record exports without a
  correction moment, stop and file a blocking HOURS-007 / HOURS-008 issue,
  because credits calculated from this file would rest on a total the reader was
  told was final.
- If the export produces a row for a no-show or an open check-in, stop and file
  a blocking HOURS-005 / HOURS-006 issue.
- If the export carries a phone number, emergency contact, or date of birth,
  stop and file a blocking REPORT-010 issue.
- If a department lead export returns another department's hours, or a
  Department Logistics operator or plain staff member resolves an export scope,
  stop and file a blocking REPORT-006 / REPORT-007 issue.
- If a successful export writes no audit event, or the audit does not record the
  scope, row count, and total minutes, stop and file a blocking data/API section
  8 issue.
- If this script appears to require credits, PowerSync conflict repair UI,
  signed node-operation envelopes, or direct God Mode edits, report scope
  leakage; those are deferred to their owning tasks.
