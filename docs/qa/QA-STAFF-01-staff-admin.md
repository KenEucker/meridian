# QA-STAFF-01 Staff Admin

## Purpose

Verify that an authorized admin can manage staff profile records and view organization-level staff status records in Orchid without exercising department or team membership workflows.

Section B additionally verifies the Milestone 13 department staff contact export (M13.3), which is where the staff contact fields maintained in section A are read back out: a department lead exports their own department with phone numbers and emergency contacts, an organizer exports the whole event without emergency contacts, narrowing an organizer export to one department does not change that, unauthorized actors are refused, and each successful export is audited with whether emergency contacts went with it.

## Requirements covered

- `VOL-001`
- `VOL-002`
- `VOL-004`
- `VOL-005`
- `VOL-009`
- `VOL-011`
- `VOL-012`
- `REPORT-003`
- `REPORT-006`
- `REPORT-007`
- `REPORT-009`
- `REPORT-010`
- Requirements sections 4.2, 4.4, 5.1, and 7.14
- Data/API spec section 10.4
- Technical spec section 22.2 (CSV export)
- Meridian Alpha 1 tasks M4.5 and M13.3

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated.
- Orchid admin reachable at the configured development admin route.
- For section B, the app is also seeded from the development scenario:
  ```bash
  cd apps/server
  php artisan migrate:fresh --seed
  ```
- For section B, shell access from `apps/server` for tinker verification commands.
- No product UI entry point is required for the export; it is server-generated and online-only in Alpha 1.

## Personas

- Staff admin: Orchid user with `platform.index` and `platform.staff` permissions.
- Restricted admin: Orchid user with `platform.index` but without `platform.staff`.
- Department lead: seeded Dana Departmentlead (`dana.departmentlead@idaho-burners.test`), department lead for Rangers.
- Organizer: seeded Olive Organizer (`olive.organizer@idaho-burners.test`).
- Contact subject: seeded Vera Staff (`vera.staff@idaho-burners.test`), active Rangers / Dirt member.
- Department-ineligible member: seeded Ira Ineligible (`ira.ineligible@idaho-burners.test`), Gate member with department status `ineligible`.
- Unauthorized exporters: seeded Vera Staff and Omar ICOperator (`omar.icoperator@idaho-burners.test`).

## Setup data

- At least one organization exists.
- Create or use a staff admin account with `platform.staff` permission.
- Create or use a restricted admin account without `platform.staff` permission.
- For section B: organization `Idaho Burners` (slug `idaho-burners`), event `Idaho Decompression 2026` (slug `idaho-decompression-2026`), departments `Rangers` (code `RANGERS`) and `Gate` (code `GATE`), both assigned to the event by the development scenario seeder.

## Steps

### A. Staff profile administration

1. Sign in to Orchid as the staff admin.
2. Open Operations, then Staff.
3. Create a staff profile with only legal name and email.
4. Confirm the staff profile appears in the Staff list.
5. Open the staff profile detail.
6. Confirm organization status records appear inside the Staff detail screen with organization and readable status labels.
7. Confirm there is no separate Organization Staff navigation item or separate Organization Staff screen.
8. Edit the staff profile and confirm legal name and email are required while preferred name, handle, phone, city, state, date of birth, and emergency contact fields may be blank.
9. Archive and restore the staff profile.
10. Sign in as the restricted admin and attempt to open Staff.

### B. Department staff contact export (M13.3)

11. From `apps/server`, give Vera the contact details the export is supposed to carry:
    ```bash
    php artisan tinker --execute='$staff = App\Models\Staff::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail(); $staff->forceFill(["phone" => "+1-208-555-0101", "emergency_contact_name" => "Quinn Contact", "emergency_contact_phone" => "+1-208-555-0199"])->save(); print(json_encode(["staff_id" => $staff->id, "phone" => $staff->phone, "emergency_contact_name" => $staff->emergency_contact_name, "emergency_contact_phone" => $staff->emergency_contact_phone], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
12. Export as Dana Departmentlead and save the file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $dana = App\Models\User::query()->where("email", "dana.departmentlead@idaho-burners.test")->firstOrFail(); $rangers = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($dana, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT); $export = app(App\Services\Reporting\StaffContactExportService::class)->export($event, $scope, $dana); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["event_wide" => $scope->organizationWide, "department_ids" => $scope->departmentFilter(), "rangers_id" => (string) $rangers->id, "emergency_contacts_included" => $scope->coversOnlyOwnDepartments(), "filename" => $export->filename, "row_count" => $export->rowCount, "saved_to" => storage_path("app/".$export->filename)], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
13. Confirm `event_wide` is false, `department_ids` contains only the Rangers id, `emergency_contacts_included` is true, and the filename carries `rangers`.
14. Confirm the header row is `event_name,department,department_code,teams,staff_legal_name,staff_preferred_name,staff_handle,staff_email,staff_phone,department_membership_status,organization_status,emergency_contact_name,emergency_contact_phone`, and that every exported row belongs to Rangers.
15. Confirm Vera's row carries `+1-208-555-0101`, `Quinn Contact`, and `+1-208-555-0199` (REPORT-009, VOL-012), and that her `teams` cell names her Rangers teams.
16. Export as Olive Organizer and save the file:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT); $export = app(App\Services\Reporting\StaffContactExportService::class)->export($event, $scope, $olive); file_put_contents(storage_path("app/".$export->filename), $export->contents); print(json_encode(["event_wide" => $scope->organizationWide, "emergency_contacts_included" => $scope->coversOnlyOwnDepartments(), "filename" => $export->filename, "row_count" => $export->rowCount, "saved_to" => storage_path("app/".$export->filename)], JSON_PRETTY_PRINT).PHP_EOL); print($export->contents);'
    ```
17. Confirm `event_wide` is true, `emergency_contacts_included` is false, the header ends at `organization_status` with no emergency contact columns at all, and both Rangers and Gate rows appear.
18. Confirm the organizer file still carries Vera's phone number and does not carry her emergency contact anywhere:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $staff = App\Models\Staff::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT); $csv = app(App\Services\Reporting\StaffContactExportService::class)->export($event, $scope, $olive)->contents; print(json_encode(["phone_present" => str_contains($csv, (string) $staff->phone), "emergency_name_present" => str_contains($csv, (string) $staff->emergency_contact_name), "emergency_phone_present" => str_contains($csv, (string) $staff->emergency_contact_phone), "emergency_columns_present" => str_contains($csv, "emergency_contact_name")], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
19. Confirm `phone_present` is true and the other three are false. A staff contact list without phone numbers would not be a contact list; only emergency contacts are withheld from organizers (REPORT-010, VOL-011).
20. Confirm narrowing an organizer export to one department does not turn the organizer into that department's lead:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $rangers = App\Models\Department::query()->where("code", "RANGERS")->firstOrFail(); $olive = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $scope = app(App\Services\Reporting\ReportingExportAccess::class)->resolve($olive, $event, App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT)->restrictedToDepartment((string) $rangers->id); $export = app(App\Services\Reporting\StaffContactExportService::class)->export($event, $scope, $olive); print(json_encode(["department_filter" => $scope->departmentFilter(), "emergency_contacts_included" => $scope->coversOnlyOwnDepartments(), "header" => explode("\n", $export->contents)[0], "row_count" => $export->rowCount], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
21. Confirm `department_filter` contains only the Rangers id, `emergency_contacts_included` is false, and the header still has no emergency contact columns.
22. Confirm the contact list reports status instead of hiding people. In Dana's Rangers file and Olive's event file, find Ira Ineligible on the Gate rows and confirm `department_membership_status` reads `ineligible` rather than the person being absent.
23. Confirm a department lead cannot reach another department, and that unauthorized actors resolve no export scope at all:
    ```bash
    php artisan tinker --execute='$event = App\Models\Event::query()->where("slug", "idaho-decompression-2026")->firstOrFail(); $gate = App\Models\Department::query()->where("code", "GATE")->firstOrFail(); $access = app(App\Services\Reporting\ReportingExportAccess::class); $permission = App\Domain\Permissions\PermissionCatalog::PERMISSION_REPORTS_STAFF_CONTACT_EXPORT; $dana = App\Models\User::query()->where("email", "dana.departmentlead@idaho-burners.test")->firstOrFail(); print(json_encode(["dana_includes_gate" => $access->resolve($dana, $event, $permission)->includesDepartment((string) $gate->id)]).PHP_EOL); foreach (["vera.staff@idaho-burners.test", "omar.icoperator@idaho-burners.test"] as $email) { $user = App\Models\User::query()->where("email", $email)->firstOrFail(); print(json_encode([$email => $access->resolve($user, $event, $permission) === null ? "denied" : "unexpected_scope"]).PHP_EOL); }'
    ```
24. Confirm `dana_includes_gate` is false and both personas are `denied`; being on a contact list is not authority to export it, and incident authority is not export authority.
25. Confirm each successful export was audited:
    ```bash
    php artisan tinker --execute='App\Models\AuditEvent::query()->where("action", "event_staff_contact.exported")->latest("created_at")->take(5)->get(["actor_user_id", "event_id", "department_id", "after_json"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
    ```
26. Confirm one audit event per export with the acting user, the event, a `scope` of `event` or `department`, a `row_count` matching the file, and an `emergency_contacts_included` flag that matches what the file actually carried.
27. Optional HTTP check when a browser session is available for Olive or Dana (log in with `QA-AUTH-01`): download `/api/events/{event id}/exports/staff-contact` and confirm the browser saves a `.csv` attachment. Add `?department_id={department id}` to narrow an organizer export to one department, and confirm a department id from another organization returns 404.
28. Confirm this export is server-generated and online-only, that no product UI entry point is required for it yet, and that the remaining Alpha 1 exports and their consolidated script belong to M13.4 through M13.9 (`QA-EXPORT-01`).

## Expected results

- Staff profile creation requires legal name and email, allows other profile fields to be completed later, and rejects invalid email/date values.
- Organization-level status is shown as a detail of the staff profile, not as a separate kind of staff.
- A staff profile can have an organization status without department or team membership.
- Staff profile archive/restore preserves the record.
- Restricted admin receives a denied response for Staff.
- The staff contact export produces a CSV with the documented header and one row per active department membership in the event's participating departments.
- A department lead's file carries phone numbers and emergency contacts for their own department.
- An organizer's file carries phone numbers and has no emergency contact columns at all, whether the export covers the event or is narrowed to one department.
- Membership and organization statuses are reported rather than filtered, so an ineligible or inactive member is visible as such.
- Department leads export only their own department; staff on a contact list and IC roles resolve no export scope.
- Every successful export writes one `event_staff_contact.exported` audit event naming the actor, event, scope, row count, and whether emergency contacts were included.

## Evidence to capture

- Screenshot of the Staff list showing the created profile.
- Screenshot of the Staff detail screen showing organization status details.
- Note the status label used during testing.
- Note the denied response observed for the restricted admin.
- The saved department-scoped export file showing the emergency contact columns, and the saved organizer export file showing they are absent.
- Sensitive-field check output showing `phone_present` true and the emergency values false.
- Narrowed organizer export output showing the header still has no emergency contact columns.
- Denied export-scope output for the department lead reaching Gate and for the staff and IC personas.
- Staff contact export audit event output including `emergency_contacts_included`.

## Failure notes

- If the Staff menu item is missing for the staff admin, verify the account has `platform.staff`.
- If a separate Organization Staff screen appears, stop and report terminology regression because organizational staff is not a product concept.
- If department or team membership controls appear in this flow, stop and report scope leakage because those belong to M4.6.
- If an organizer export carries an emergency contact in any form, including a blank emergency contact column, stop and file a blocking REPORT-010 / VOL-011 issue.
- If a department lead cannot see emergency contacts for their own department, stop and file a blocking REPORT-009 / VOL-012 issue.
- If a department lead export returns another department's staff, or a plain staff member resolves an export scope, stop and file a blocking REPORT-006 / REPORT-007 issue.
- If the export lists staff of a department that is not assigned to the event, stop and file a blocking REPORT-003 scope issue.
- If a successful export writes no audit event, or the audit does not record whether emergency contacts were included, stop and file a blocking data/API section 8 issue.
