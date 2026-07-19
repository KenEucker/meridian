# QA-SLB-01: Check-In, Check-Out, and Hours

## Purpose

Verify the Milestone 10 staff-mediated attendance workflow: Department
Logistics can mark assigned department staff on-site, check them into a selected
shift, check them out with actual start/end times, create canonical hours, and
review the resulting attendance/hour records. The script also verifies no-show,
offline queued attendance writes, authorized hours correction before freeze, and
freeze blocking after the correction grace period.

This script covers the Alpha 1 human QA gate for check-in/check-out/hours. It
does not add or require staff self-service check-in, credit calculation, hours
exports, PowerSync conflict repair UI, signed node-operation envelopes, or new
product behavior beyond existing Department Operations surfaces and domain
commands.

## Requirements covered

- `SLB-003`
- `SLB-004`
- `SLB-005`
- `SLB-006`
- `SLB-007`
- `HOURS-001` through `HOURS-008`
- Requirements sections 3.13 and 3.14
- Technical spec sections 20.1 through 20.6 and 23
- Data/API spec sections 7.2 and 10.10
- UI Implementation Contract sections 12.5 and 16.2
- Kiosk and Field Hardware UX Guide section 5
- Meridian Alpha 1 tasks M10.2 through M10.6 and M10.11

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
  (`sam.shiftlead@idaho-burners.test`) or another actor with Department
  Logistics permission for Rangers / Dirt.
- Department lead reviewer: seeded Dana Departmentlead
  (`dana.departmentlead@idaho-burners.test`), permitted to review and correct
  department attendance/hours where authorized.
- Staff subject: seeded Vera Staff (`vera.staff@idaho-burners.test`), active in
  Rangers / Dirt and assigned or assignable to Ranger Dirt shifts.
- Unauthorized/default staff comparison actor: Vera Staff when confirming that
  default staff do not self check-in/out or see Logistics controls.
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
6. Open `http://127.0.0.1:5173`, then choose **Logistics desk** from the home
   surface. The direct route pattern is
   `/events/:eventId/departments/:departmentId/logistics`.
7. Clear site data first if an earlier local attendance/offline run is present.
8. Confirm the seeded event/department context is Idaho Decompression 2026 /
   Rangers and that Logistics search uses the department-scoped offline cache.

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
     tests/Feature/HoursCorrectionTest.php
   ```
2. From the repository root, run the shared client attendance/offline suites:
   ```bash
   corepack pnpm --filter @meridian/client run test -- \
     src/department-ops/logistics.spec.ts \
     src/views/DepartmentOpsViews.spec.ts \
     src/shift-board/offlineAttendanceOperation.spec.ts \
     src/shift-board/pendingAttendanceQueue.spec.ts \
     src/shift-board/syncAttendanceOutbox.spec.ts \
     src/shift-board/attendanceOperationLocalStore.spec.ts
   ```
3. Confirm all suites pass. Retain output proving:
   - check-in requires authorized Department Logistics/lead access and on-site
     department presence;
   - duplicate check-in is idempotent;
   - check-out creates one `hours_worked` record with actual start/end, minutes,
     event, department, shift, staff, and attendance record;
   - no-show is constrained to started shifts and is idempotent;
   - offline command transport preserves operation UUID, device/node provenance,
     and retry-safe acceptance for check-in, check-out, and no-show;
   - hours correction writes before/after audit, rejects unauthorized actors and
     invalid ranges, and frozen hours reject later correction.

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
15. Confirm the check-out dialog defaults the end timestamp to now and allows
    Department Logistics to edit actual start/end time during check-out.
16. Set actual start to `2026-07-01 08:00` and actual end to
    `2026-07-01 12:07`, then submit check-out.
17. Confirm Vera's attendance state changes to checked out and no longer blocks
    off-site status because of shift attendance. If Vera holds equipment, the
    equipment rule may still block off-site until equipment is returned, marked
    Missing, or marked Damaged.
18. From `apps/server`, inspect the resulting hours:
    ```bash
    php artisan tinker --execute='$staff = App\Models\Staff::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail(); App\Models\HoursWorked::query()->with(["event", "department", "shift", "attendanceRecord"])->where("staff_id", $staff->id)->latest("created_at")->limit(5)->get()->each(fn ($hours) => print(json_encode(["hours_worked_id" => $hours->id, "event" => $hours->event?->slug, "department" => $hours->department?->code, "shift" => $hours->shift?->title, "attendance_record_id" => $hours->attendance_record_id, "actual_started_at" => (string) $hours->actual_started_at, "actual_ended_at" => (string) $hours->actual_ended_at, "minutes_worked" => $hours->minutes_worked, "status" => $hours->status, "frozen_at" => (string) $hours->frozen_at], JSON_PRETTY_PRINT).PHP_EOL));'
    ```
19. Confirm the latest row belongs to the event, department, selected shift, and
    Vera; includes actual start/end; has computed minutes; and is separate from
    the scheduled shift duration.

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
28. Record the queued operation UUID from the UI, local pending queue, or
    browser storage evidence before reconnecting.
29. Reload the page while still offline.
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

36. Identify the latest `hours_worked_id` from section C.
37. As an authorized attendance manager, correct the actual start/end while the
    record is unfrozen:
    ```bash
    php artisan tinker --execute='$hours = App\Models\HoursWorked::query()->latest("created_at")->firstOrFail(); $actor = App\Models\User::query()->where("email", "dana.departmentlead@idaho-burners.test")->firstOrFail(); $result = app(App\Services\Attendance\HoursCorrectionService::class)->correctHours($hours, $actor, (string) Illuminate\Support\Str::uuid(), Illuminate\Support\Carbon::parse("2026-07-01 08:15:00"), Illuminate\Support\Carbon::parse("2026-07-01 12:45:00")); print(json_encode(["hours_worked_id" => $result->hoursWorked->id, "operation_type" => $result->operation->operation_type, "minutes_worked" => $result->hoursWorked->minutes_worked, "corrected_by_user_id" => $result->hoursWorked->corrected_by_user_id, "corrected_at" => (string) $result->record->corrected_at], JSON_PRETTY_PRINT).PHP_EOL);'
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
    php artisan tinker --execute='$hours = App\Models\HoursWorked::query()->latest("created_at")->firstOrFail(); $actor = App\Models\User::query()->where("email", "dana.departmentlead@idaho-burners.test")->firstOrFail(); $frozen = app(App\Services\Attendance\HoursCorrectionService::class)->freezeHours($hours, $actor, Illuminate\Support\Carbon::parse("2026-07-08 00:00:00")); print(json_encode(["hours_worked_id" => $frozen->id, "frozen_at" => (string) $frozen->frozen_at], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
42. Attempt another correction:
    ```bash
    php artisan tinker --execute='try { $hours = App\Models\HoursWorked::query()->latest("created_at")->firstOrFail(); $actor = App\Models\User::query()->where("email", "dana.departmentlead@idaho-burners.test")->firstOrFail(); app(App\Services\Attendance\HoursCorrectionService::class)->correctHours($hours, $actor, (string) Illuminate\Support\Str::uuid(), Illuminate\Support\Carbon::parse("2026-07-01 08:00:00"), Illuminate\Support\Carbon::parse("2026-07-01 11:00:00")); print("UNEXPECTED_SUCCESS\n"); } catch (Throwable $e) { print($e->getMessage().PHP_EOL); }'
    ```
43. Confirm the denial message is `Hours are frozen after the correction grace
    period.` and the frozen hours values are unchanged.

### G. Role and non-goal checks

44. Sign in or simulate the UI as default Vera Staff without Department
    Logistics/lead authority.
45. Confirm Vera cannot open Logistics mutation controls for herself, cannot
    self check-in/out as default staff, and cannot correct hours.
46. Confirm organizers do not automatically see all department attendance unless
    they also hold the documented department capability.
47. Confirm this script did not require credit calculation, hours export,
    unscheduled shift addition, equipment offline sync, incident creation,
    Field Report review, PowerSync conflict repair UI, or direct God Mode
    attendance editing.

## Expected results

- Department Logistics can mark eligible department staff on-site and then
  check scheduled staff into a selected department shift.
- Check-in creates an append-only attendance operation, derives checked-in
  current state, and audits accepted state changes.
- Staff cannot be marked off-site while checked into a shift for that
  department/event.
- Department Logistics can check staff out from the selected staff workspace and
  supply actual start/end times during check-out.
- Check-out creates one canonical `hours_worked` record tied to event,
  department, shift, staff, and attendance record, with computed minutes from
  actual times rather than scheduled duration.
- No-show can be recorded by authorized attendance managers after shift start
  and remains idempotent on retry.
- Check-in, check-out, and no-show can queue offline when the surface and synced
  data are available, remain visible as queued/pending, and sync once with
  operation UUID/device/node provenance.
- Authorized attendance managers can correct unfrozen hours with before/after
  audit evidence.
- Frozen hours reject later correction and preserve recorded values.
- Default staff self check-in/out, free-floating hours, staff self-reported
  hours, credits, exports, conflict repair UI, and signed node-operation
  envelopes are not required for this Alpha 1 QA gate.

## Evidence to capture

- Server attendance/hour test output and client attendance/offline test output.
- Screenshot or screen recording of Vera's Logistics workspace before check-in,
  after on-site/check-in, and after check-out.
- Screenshot showing off-site blocked while checked in.
- Tinker output showing the `hours_worked` row with event, department, shift,
  staff, attendance record, actual times, minutes, and status.
- No-show UI evidence and/or server output proving idempotent no-show state.
- Offline queued action screenshot before reload, after reload, and after sync.
- Tinker output showing accepted offline operation provenance.
- Tinker output for hours correction, `hours.corrected` before/after audit, and
  frozen-hours rejection.
- Screenshot or notes confirming default staff cannot self check-in/out.

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
- If correction lacks authorization, before/after audit, or updated minutes, stop
  and file an SLB-007 / HOURS-007 issue.
- If frozen hours can be corrected, stop and file a HOURS-008 issue.
- If queued offline attendance disappears after reload or syncs multiple times
  for the same operation UUID, stop and file an offline attendance queue issue.
- If this script appears to require credits, exports, PowerSync conflict repair
  UI, signed node-operation envelopes, or direct God Mode edits, report scope
  leakage; those are deferred to their owning tasks.
