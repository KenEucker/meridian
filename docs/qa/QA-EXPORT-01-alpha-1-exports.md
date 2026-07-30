# QA-EXPORT-01: Alpha 1 Reporting Exports

## Purpose

Verify the five Alpha 1 reporting exports end to end in one sitting: credential
eligibility (M13.1), the shift roster (M13.2), staff contacts (M13.3), actual
hours worked (M13.4), and credits earned (M13.6). A reviewer confirms that an
organizer exports the whole event and a department lead exports only their own
department, that narrowing an export can never widen it, that unauthorized
actors are refused, that every file carries the documented columns, that
sensitive fields follow the scope rules — phone numbers and emergency contacts
off the shift roster, emergency contacts on the staff contact export only for a
caller who leads every exported department, and no dates of birth anywhere — and
that each successful export is audited with who pulled it, how wide the scope
was, and how many rows went with it.

This is the consolidated export script the individual Milestone 13 scripts point
at. `QA-CRED-01` section G, `QA-SHIFT-01`, `QA-STAFF-01`, and `QA-SLB-01`
section H each verify one export beside the domain that produces it; this script
verifies all five together, and is the one that answers the Milestone 13 QA gate.

Exports are server-generated and online-only in Alpha 1. No product UI entry
point is required for this script: reporting surfaces (`REPORT-014`) are their
own requirement and remain deferred, so the checks below use the documented
domain services and the HTTP download endpoints. The short-lived download URL
half of `REPORT-015` now exists for credential eligibility (M16.12) and is
verified with the rest of that pattern rather than here; the export entry points
that use it arrive with M16.22.

## Requirements covered

- `REPORT-001` through `REPORT-005`: the five exports.
- `REPORT-006`, `REPORT-007`: organizer event-wide scope and department-role department scope.
- `REPORT-008`: shift rosters exclude phone numbers and emergency contacts.
- `REPORT-009`, `VOL-012`: department leads and department administration export emergency contacts for their own department.
- `REPORT-010`, `VOL-011`: organizers hold no emergency contact access, including when narrowing to one department.
- `CRED-005`, `HOURS-001`, `HOURS-007`, `HOURS-008`, `CREDIT-005`: the state each export reports and the basis it must reproduce.
- Requirements sections 3.15, 5.12, 7.10, and 7.14.
- Technical spec section 22.2 (CSV import/export).
- Data/API spec sections 8, 10.10, 10.11, and 10.12.
- Development process section 7.10: export changes state actor permissions, scope rules, included columns, excluded sensitive fields, sample file, and test fixture.
- Meridian Alpha 1 tasks M13.1 through M13.6 and M13.9.

## Environment

- A dedicated development/QA database. The setup below runs `migrate:fresh --seed` and must not be used against shared or valuable data.
- Repository dependencies installed, with shell access from `apps/server`.
- Laravel available at `http://127.0.0.1:8000` for the optional HTTP section.
- No reporting UI is required; commands below use `php artisan tinker`.
- Files are written to `storage/app/` so they can be opened in a spreadsheet and attached as evidence.

## Personas

- Olive Organizer (`olive.organizer@idaho-burners.test`): organizer, exports the whole event.
- Dana Departmentlead (`dana.departmentlead@idaho-burners.test`): Rangers department lead, exports Rangers only.
- Sam Shiftlead (`sam.shiftlead@idaho-burners.test`): Rangers department administration/logistics, holds department-scoped export authority for Rangers.
- Omar ICOperator (`omar.icoperator@idaho-burners.test`) and Ivy ICViewer (`ivy.icviewer@idaho-burners.test`): incident authority only, must be refused.
- Ira Ineligible (`ira.ineligible@idaho-burners.test`) and Gwen Godmode (`gwen.godmode@idaho-burners.test`): no export role at all, must be refused.
- Vera Staff (`vera.staff@idaho-burners.test`): Rangers / Dirt member who appears in the exported rows.

Do not use Vera as the "plain staff member is refused" case. Roles are granted to
teams, and the seeded scenario grants Dana's `department_lead` role to the Dirt
team, so every Dirt member — Vera and Sam included — resolves a Rangers-scoped
export authority. That is the seed's shape, not an export defect; Ira and Gwen
are the personas that carry no export role.

## Setup data

- Organization: `Idaho Burners` with slug `idaho-burners`.
- Event: `Idaho Decompression 2026` with slug `idaho-decompression-2026`.
- Departments: `Rangers` (`RANGERS`) and `Gate` (`GATE`), both assigned to the event.
- Teams: `Dirt` (`DIRT`) in Rangers, and the Gate default team.
- QA records created below are titled or named with a `QA EXPORT` prefix so cleanup is obvious.
- Committed sample files, which pin the header row of each export:
  - `apps/server/tests/Fixtures/credential-eligibility-export-sample.csv`
  - `apps/server/tests/Fixtures/shift-roster-export-sample.csv`
  - `apps/server/tests/Fixtures/staff-contact-export-sample.csv` (organizer)
  - `apps/server/tests/Fixtures/staff-contact-export-department-sample.csv` (department lead)
  - `apps/server/tests/Fixtures/hours-worked-export-sample.csv`
  - `apps/server/tests/Fixtures/credits-earned-export-sample.csv`

## Steps

### A. Build exportable state

1. Seed a clean scenario:
   ```bash
   cd apps/server
   php artisan migrate:fresh --seed
   ```
2. Give Vera the contact details the exports must handle differently, and create a Rangers shift with her on it:
   ```bash
   php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $rangers = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $dirt = App\Models\Team::query()->where("department_id", $rangers->id)->where("code", "DIRT")->firstOrFail(); $vera = App\Models\User::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail()->staffProfiles()->firstOrFail(); $vera->forceFill(["phone" => "+1-208-555-0100", "emergency_contact_name" => "Quinn Contact", "emergency_contact_phone" => "+1-208-555-0199", "date_of_birth" => "1990-01-15"])->save(); $starts = now()->subDays(2)->setTime(9, 0); $shift = App\Models\Shift::factory()->create(["event_id" => $event->id, "department_id" => $rangers->id, "eligible_team_id" => $dirt->id, "title" => "QA EXPORT Dirt Day", "starts_at" => $starts, "ends_at" => $starts->copy()->addHours(8), "capacity" => 4]); $dana = App\Models\User::query()->where("email", "dana.departmentlead@idaho-burners.test")->firstOrFail(); App\Models\ShiftAssignment::factory()->create(["shift_id" => $shift->id, "staff_id" => $vera->id, "assignment_status" => App\Models\ShiftAssignment::STATUS_ASSIGNED, "assigned_by_user_id" => $dana->id]); app(App\Services\Credential\CredentialEligibilityService::class)->recalculate($event, $vera); print(json_encode(["shift_id" => (string) $shift->id, "staff_id" => (string) $vera->id], JSON_PRETTY_PRINT).PHP_EOL);'
   ```
3. Record frozen hours for that shift and calculate credits from them:
   ```bash
   php artisan tinker --execute='$shift = App\Models\Shift::query()->where("title", "QA EXPORT Dirt Day")->firstOrFail(); $vera = App\Models\User::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail()->staffProfiles()->firstOrFail(); $hours = App\Models\HoursWorked::factory()->create(["shift_id" => $shift->id, "staff_id" => $vera->id, "actual_started_at" => $shift->starts_at, "actual_ended_at" => $shift->starts_at->copy()->addHours(7), "minutes_worked" => 420, "frozen_at" => now()->subDay()]); $organization = App\Models\Organization::query()->where("slug", "idaho-burners")->firstOrFail(); $policy = App\Models\CreditPolicy::factory()->create(["organization_id" => $organization->id, "name" => "QA EXPORT Standard", "credit_multiplier" => "1.500"]); $organization->forceFill(["default_credit_policy_id" => $policy->id])->save(); $olive = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $result = app(App\Services\Credits\CreditCalculationService::class)->calculateForEvent($shift->event, $olive); print(json_encode(["hours_id" => (string) $hours->id, "credit_entries_created" => $result->createdCount()], JSON_PRETTY_PRINT).PHP_EOL);'
   ```
4. Confirm the command reports at least one credit entry created. If it reports an open hours record instead, freeze the remaining record and re-run — credits refuse an event that still holds unfrozen hours.

### B. Credential eligibility export (M13.1)

5. Export as Olive and save the file:
   ```bash
   php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT); $export = app(App\Services\Reporting\CredentialEligibilityExportService::class)->export($event, $scope, $olive); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["event_wide" => $scope->isEventWide(), "filename" => $export->filename, "row_count" => $export->rowCount], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
   ```
6. Compare the header row with `credential-eligibility-export-sample.csv` and search the file for Vera's phone number, emergency contact, and date of birth.

### C. Shift roster export (M13.2)

7. Export as Olive and save the file:
   ```bash
   php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT); $export = app(App\Services\Reporting\ShiftRosterExportService::class)->export($event, $scope, $olive); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["filename" => $export->filename, "row_count" => $export->rowCount], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
   ```
8. Read the `QA EXPORT Dirt Day` row for Vera and note `assignment_status`, `assignment_source`, `shift_capacity`, and `assigned_staff_count`. Search the whole file for her phone number and emergency contact.

### D. Staff contact export (M13.3)

9. Export as Olive (organizer, event-wide), then as Olive narrowed to Rangers, then as Dana (Rangers lead):
   ```bash
   php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $rangers = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $access = app(App\Services\Reporting\ReportingExportAccess::class); $service = app(App\Services\Reporting\StaffContactExportService::class); $permission = App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT; foreach (["olive.organizer@idaho-burners.test", "dana.departmentlead@idaho-burners.test"] as $email) { $user = App\Models\User::query()->where("email", $email)->firstOrFail(); $scope = $access->resolve($user, $event, $permission); $export = $service->export($event, $scope, $user); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode([$email => ["event_wide" => $scope->isEventWide(), "own_departments_only" => $scope->coversOnlyOwnDepartments(), "filename" => $export->filename, "row_count" => $export->rowCount, "header" => strtok($export->contents, "\n")]], JSON_PRETTY_PRINT).PHP_EOL); $narrowed = $scope->restrictedToDepartment((string) $rangers->id); $narrowedExport = $service->export($event, $narrowed, $user); print(json_encode([$email." (narrowed to RANGERS)" => ["own_departments_only" => $narrowed->coversOnlyOwnDepartments(), "filename" => $narrowedExport->filename, "row_count" => $narrowedExport->rowCount, "header" => strtok($narrowedExport->contents, "\n")]], JSON_PRETTY_PRINT).PHP_EOL); }'
   ```
10. Compare the four header rows against `staff-contact-export-sample.csv` and `staff-contact-export-department-sample.csv`, and check which files carry Vera's emergency contact and which carry her phone number.

### E. Hours worked export (M13.4)

11. Export as Dana and read the row for the QA shift:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@idaho-burners.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($dana, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT); $export = app(App\Services\Reporting\HoursWorkedExportService::class)->export($event, $scope, $dana); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["department_ids" => $scope->departmentFilter(), "filename" => $export->filename, "row_count" => $export->rowCount], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
12. Compare `scheduled_minutes` with `minutes_worked` on Vera's row, and read `hours_status`, `correction_state`, `corrected_at`, and `frozen_at`.

### F. Credits earned export (M13.6)

13. Export as Olive, then rename and re-rate the credit policy and export again:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $access = app(App\Services\Reporting\ReportingExportAccess::class); $service = app(App\Services\Reporting\CreditsEarnedExportService::class); $permission = App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT; $export = $service->export($event, $access->resolve($olive, $event, $permission), $olive); file_put_contents(storage_path("app/".$export->filename), $export->contents); print($export->contents); App\Models\CreditPolicy::query()->where("name", "QA EXPORT Standard")->firstOrFail()->forceFill(["name" => "QA EXPORT Renamed", "credit_multiplier" => "3.000"])->save(); $after = $service->export($event, $access->resolve($olive, $event, $permission), $olive); print($after->contents);'
    ```
14. Compare `credit_policy_name`, `credit_multiplier`, and `credits` in the two files, and check that `minutes_worked`, `hours`, `credit_multiplier`, and `credits` on a row are arithmetically consistent.

### G. Scope, narrowing, and refusals

15. Confirm a department lead cannot reach another department, and that an unauthorized actor resolves no scope at all:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $gate = App\Models\Department::query()->where("code", "GATE")->firstOrFail(); $access = app(App\Services\Reporting\ReportingExportAccess::class); $permissions = [App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDENTIAL_ELIGIBILITY_EXPORT, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_SHIFT_ROSTER_EXPORT, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_HOURS_WORKED_EXPORT, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_CREDITS_EARNED_EXPORT]; foreach (["dana.departmentlead@idaho-burners.test", "sam.shiftlead@idaho-burners.test", "omar.icoperator@idaho-burners.test", "ivy.icviewer@idaho-burners.test", "ira.ineligible@idaho-burners.test", "gwen.godmode@idaho-burners.test"] as $email) { $user = App\Models\User::query()->where("email", $email)->firstOrFail(); $report = []; foreach ($permissions as $permission) { $scope = $access->resolve($user, $event, $permission); $report[$permission] = $scope === null ? "denied" : ["event_wide" => $scope->isEventWide(), "departments" => $scope->departmentFilter(), "covers_gate" => $scope->includesDepartment((string) $gate->id)]; } print(json_encode([$email => $report], JSON_PRETTY_PRINT).PHP_EOL); }'
    ```
16. Optional HTTP check, with a browser session signed in as Olive (use `QA-AUTH-01`): download each of
    `/api/events/{event id}/exports/credential-eligibility`,
    `/api/events/{event id}/exports/shift-roster`,
    `/api/events/{event id}/exports/staff-contact`,
    `/api/events/{event id}/exports/hours-worked`, and
    `/api/events/{event id}/exports/credits-earned`.
17. Add `?department_id={Rangers id}` to one of those downloads, then repeat with a department id belonging to another organization, then repeat the plain download signed in as Omar ICOperator.

### H. Audit

18. Review the audit entries every export above produced:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->whereIn("action", ["event_credential_eligibility.exported", "event_shift_roster.exported", "event_staff_contact.exported", "event_hours_worked.exported", "event_credits_earned.exported"])->latest("created_at")->take(20)->get(["action", "actor_user_id", "event_id", "department_id", "after_json"])->each(fn ($entry) => print($entry->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```

## Expected results

- Step 4: the credit calculation reports at least one entry created; an event holding an unfrozen hours record is refused outright rather than partially credited.
- Step 5: `event_wide` is true and the file carries every department's credentialed staff, including Vera with status `eligible` and a credential shift count of at least 1.
- Step 6: the header row is exactly `event_name,staff_legal_name,staff_preferred_name,staff_handle,staff_email,departments,credential_status,status_reason,status_reason_label,credential_shift_count,credential_updated_at,revoked_at`, and the file contains no phone number, no emergency contact, and no date of birth.
- Step 7: the header row is exactly `event_name,department,team,shift_title,shift_status,shift_starts_at,shift_ends_at,shift_capacity,assigned_staff_count,staff_legal_name,staff_preferred_name,staff_handle,staff_email,assignment_status,assignment_source`.
- Step 8: Vera's row reads `assigned` / `lead_assigned` with capacity 4 and a roster size of 1, and the file contains neither her phone number nor her emergency contact (`REPORT-008`).
- Step 9: Olive resolves `event_wide` true and `own_departments_only` false, both event-wide and narrowed to Rangers; Dana resolves `event_wide` false with Rangers only and `own_departments_only` true. Dana's filename carries `rangers`; Olive's event-wide filename does not.
- Step 10: Dana's two files carry the `emergency_contact_name` and `emergency_contact_phone` columns and Vera's contact in them. Neither of Olive's files carries those columns at all — including the one narrowed to Rangers, because narrowing changes which rows are exported and not the authority the caller came by (`REPORT-010`). All four files carry `staff_phone`, which is the one export permitted to (`REPORT-009`).
- Step 11: Dana's export is limited to Rangers `hours_worked` records and the header matches `hours-worked-export-sample.csv`.
- Step 12: Vera's row reports `scheduled_minutes` 480 beside `minutes_worked` 420 and `hours_worked` 7.00 — the short shift shows as a difference rather than being absorbed — with `correction_state` `frozen`, a `frozen_at` moment, and an empty `corrected_at`.
- Step 13: both credits files report the same `credit_policy_name`, `credit_multiplier`, and `credits`; renaming and re-rating the policy afterwards does not restate what the event already paid, because the row is read from the entry's frozen calculation basis (`CREDIT-005`).
- Step 14: `hours` × `credit_multiplier` equals `credits` on each row, and `minutes_worked` ÷ 60 equals `hours`.
- Step 15: Dana and Sam resolve a Rangers-only scope for all five permissions with `covers_gate` false; Omar, Ivy, Ira, and Gwen are `denied` for all five — incident authority, department membership alone, and console access are not export authority.
- Step 16: each download saves a `.csv` attachment whose `Content-Disposition` filename matches the report, event, and timestamp pattern.
- Step 17: the `department_id` narrowing returns only Rangers rows; a department id from another organization returns HTTP 404; the download as Omar returns HTTP 403 with a message naming the report.
- Step 18: there is one audit entry per successful export, each carrying the acting user, the event, a `scope` of `event` or `department`, the `department_ids` it covered, and a `row_count` matching the file. Staff contact entries additionally carry `emergency_contacts_included`, true for Dana and false for Olive. No audit entry exists for a refused export.

## Evidence to capture

- The five saved CSV files from `storage/app/`, one per export, plus Dana's staff contact file and Olive's for comparison.
- The header row of each file beside the matching committed sample fixture.
- Search output showing no phone number, emergency contact, or date of birth in the credential eligibility, shift roster, hours worked, and credits earned files.
- Screenshot or transcript of step 9 showing the resolved scope for Olive and Dana, including the narrowed cases.
- Transcript of step 15 showing the refusals for Omar, Ivy, Ira, and Gwen.
- Transcript of the two credits files from step 13, before and after the policy rename.
- Audit entries for `event_credential_eligibility.exported`, `event_shift_roster.exported`, `event_staff_contact.exported`, `event_hours_worked.exported`, and `event_credits_earned.exported`.

## Failure notes

- Record any export whose row count does not match the rows in the saved file.
- Record any file carrying a column the committed sample fixture does not have, or missing one it does.
- Record any emergency contact reaching an organizer's file, by any route including narrowing.
- Record any phone number, emergency contact, or date of birth reaching the credential eligibility, shift roster, hours worked, or credits earned files.
- Record any case where a department-scoped caller reaches another department's rows, or where an unauthorized actor resolves a scope at all.
- Record any credits row whose policy name, multiplier, or credit value changes after the policy is renamed or re-rated.
- Record any successful export that produced no audit entry, or any refused export that produced one.
