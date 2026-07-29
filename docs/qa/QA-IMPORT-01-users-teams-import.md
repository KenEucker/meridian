# QA-IMPORT-01: Users, Teams, Shifts, and Assignments CSV Import

## Purpose

Verify that a God Mode operator can bulk-create and bulk-correct user accounts, department teams, event shifts, and shift assignments from a CSV file in the Orchid console, that a preview shows exactly what the file would do before anything is written, that one bad row never aborts the file, and that a spreadsheet cannot grant console access, put someone on a shift they may not work, or remove anything.

Section A covers the users and teams imports (M13.7). Section B covers the shifts and assignments imports (M13.8).

## Requirements covered

- Technical spec section 22.2: Alpha 1 Orchid / God Mode includes CSV import/export, focused first on Users, Teams, Shifts, and Assignments.
- Technical spec section 22.1: Orchid is God Mode and repair tooling, not the normal Admin product shell.
- `SHIFT-001` through `SHIFT-009`: shift structure, schedule, capacity, and signup-window rules an imported shift must still satisfy.
- `SHIFT-012`, `SHIFT-014`, `SHIFT-015`, `SHIFT-016`, `TRAIN-008`, `WAIVER-005`: assignment eligibility an imported assignment must still satisfy.
- Development process section 3.6: audit-sensitive changes record audit history.
- Development process section 7.10: export/import changes state actor permissions, included columns, and round-trip behavior.
- Meridian Alpha 1 tasks M13.7 and M13.8.

## Documented import rules (enforced by `UserImportService`, `TeamImportService`, `ShiftImportService`, and `AssignmentImportService`)

- Header names are normalized, so `Email`, `email`, and `EMAIL` all resolve to `email`. Column order does not matter and unknown columns are ignored.
- Users require `email` and `name`. The file cannot carry passwords, console permissions, roles, or the disabled flag.
- An imported account has no console access and no verified email address; the first magic-link sign-in still establishes the address.
- Teams require `organization_slug`, `department_code`, `name`, and `code`; `description` is optional. Department codes are unique per organization, so the slug/code pair resolves to exactly one department.
- Rows match existing records — users by normalized email, teams by department and team code — so re-running a corrected file updates instead of duplicating.
- A row that matches an existing record with no differences is skipped as already up to date.
- Nothing is deleted, archived, or disabled by an import. A record missing from the file is left alone.
- Team writes go through the same domain service the team screen uses, so an imported team is created and audited identically to a hand-created one.
- A missing required header column rejects the whole file; a bad row is skipped with a reason and the rest of the file still imports.
- Shifts require `organization_slug`, `event_slug`, `department_code`, `team_code`, `title`, `starts_at`, and `ends_at`; `capacity`, `signup_opens_at`, `signup_closes_at`, and `schedule_lock_at` are optional.
- A time written without a timezone is read in the event's timezone; a value carrying `Z` or an offset is taken as written.
- A shift is matched by event, department, eligible team, title, and start together. Correcting anything else updates the shift; changing a title or a start creates a second shift instead of renaming one.
- An optional column the shift file omits leaves the existing value alone; the same column present with an empty cell clears it. Training and waiver requirements are never changed by an import, and no import cancels a shift.
- Assignments require `organization_slug`, `event_slug`, `department_code`, `shift_title`, `shift_starts_at`, and `staff_email`; `team_code` is optional and needed only when a title and start match more than one shift in the department, which is otherwise skipped as ambiguous.
- Assignment rows are eligibility-checked exactly like a lead assignment: Do Not Staff, department membership, eligible team membership, Ineligible status, and required trainings and waivers all refuse the row with the domain's own reason.
- An imported assignment is recorded as lead-assigned by the operator running the import; the file cannot claim a staff member signed themselves up. A closed signup window does not refuse an import, because a roster usually arrives as a spreadsheet after signup closed.
- Nobody is removed from a shift by an import, and a row for an existing active assignment is reported as already assigned rather than duplicated.

## Environment

- Development server environment with a migrated database
- Orchid console at `/admin`
- Screens under the God Mode section of the sidebar: **Import Users** (`/admin/imports/users`), **Import Teams** (`/admin/imports/teams`), **Import Shifts** (`/admin/imports/shifts`), and **Import Assignments** (`/admin/imports/assignments`)

## Personas

- Gwen Godmode: console user holding `platform.imports`
- A console user holding `platform.systems.users` and `platform.teams` but **not** `platform.imports` (must fail closed)
- Vera Staff: an active `RANGERS` member of the `DIRT` team, eligible for Rangers shifts
- Ira Ineligible: an active staff record who is **not** a member of `RANGERS` (must be refused)

## Setup data

- An organization with slug `idaho-burners`
- Departments `RANGERS` and `GATE` in that organization
- An event with slug `idaho-decompression-2026` in that organization, in a timezone that is not UTC (the development scenario uses `America/Boise`)
- A users CSV such as:

  ```text
  email,name
  vera.staff@example.org,Vera Staff
  sam.shiftlead@example.org,Sam Shiftlead
  not-an-email,Broken Row
  ,Missing Email
  VERA.STAFF@example.org,Vera Again
  ```

- A teams CSV such as:

  ```text
  organization_slug,department_code,name,code,description
  idaho-burners,RANGERS,Dirt,DIRT,Field rangers walking the city
  idaho-burners,RANGERS,Command,COMMAND,
  idaho-burners,GATE,Greeters,GREETERS,"Gate greeters, perimeter"
  no-such-org,RANGERS,Ghost,GHOST,
  ```

- A shifts CSV such as:

  ```text
  organization_slug,event_slug,department_code,team_code,title,starts_at,ends_at,capacity
  idaho-burners,idaho-decompression-2026,RANGERS,DIRT,Dirt Patrol Day,2026-08-28 09:00,2026-08-28 17:00,6
  idaho-burners,idaho-decompression-2026,RANGERS,DIRT,Dirt Patrol Night,2026-08-28 17:00,2026-08-29 01:00,4
  idaho-burners,idaho-decompression-2026,GATE,GREETERS,Gate Opening,2026-08-28 06:00,2026-08-28 14:00,
  idaho-burners,idaho-decompression-2026,RANGERS,NOPE,Ghost Shift,2026-08-28 09:00,2026-08-28 17:00,
  idaho-burners,idaho-decompression-2026,RANGERS,DIRT,Backwards Shift,2026-08-28 17:00,2026-08-28 09:00,
  ```

- An assignments CSV such as:

  ```text
  organization_slug,event_slug,department_code,shift_title,shift_starts_at,staff_email
  idaho-burners,idaho-decompression-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00,vera.staff@idaho-burners.test
  idaho-burners,idaho-decompression-2026,RANGERS,Dirt Patrol Day,2026-08-28 09:00,ira.ineligible@idaho-burners.test
  idaho-burners,idaho-decompression-2026,RANGERS,No Such Shift,2026-08-28 09:00,vera.staff@idaho-burners.test
  ```

- The committed samples `apps/server/tests/Fixtures/users-import-sample.csv`, `apps/server/tests/Fixtures/teams-import-sample.csv`, `apps/server/tests/Fixtures/shifts-import-sample.csv`, and `apps/server/tests/Fixtures/assignments-import-sample.csv` may be used instead. Use dates that are still in the future when the script is run; a shift that has already started is deliberately not editable.

## Steps

### A. Users and teams (M13.7)

1. Sign in to the Orchid console as Gwen Godmode and open **Import Users** from the God Mode section of the sidebar.
2. Paste the users CSV into **Or paste CSV** and choose **Preview**.
3. Open **Users** in the console and confirm none of the pasted accounts exist yet.
4. Return to **Import Users**, paste the same CSV, and choose **Import**, confirming the prompt.
5. Open the created account `vera.staff@example.org` on the user screen and check its console permissions.
6. Change `Vera Staff` to `Vera Ranger` in the CSV, remove the invalid rows, and import the file again.
7. Import the same file a third time without changing it.
8. Import a users CSV whose header row is `username,name`.
9. Open **Import Teams**, choose the teams CSV as a **CSV file** upload rather than pasting it, and choose **Import**.
10. Open **Teams** in the console and confirm where each imported team landed.
11. Re-import the teams CSV with `DIRT` renamed to `Dirt Rangers` and the department code written in lower case as `rangers`.
12. Sign out, sign in as the console user without `platform.imports`, and try to open `/admin/imports/users` and `/admin/imports/teams`.
13. Open the audit log and review the entries produced by the runs above.

### B. Shifts and assignments (M13.8)

Run this section after section A, so the `DIRT` and `GREETERS` teams exist.

14. Sign back in as Gwen Godmode, open **Import Shifts**, paste the shifts CSV, and choose **Preview**.
15. Choose **Import** on the same file, confirming the prompt.
16. Open **Shifts** in the console and read the scheduled start of `Dirt Patrol Day` against the event timezone.
17. Re-import the shifts CSV with `Dirt Patrol Day` capacity changed to `8` and its end time changed to `2026-08-28 21:00`, and with the two invalid rows removed.
18. Re-import that same corrected file again without changing it.
19. Re-import it once more with `Dirt Patrol Day` retitled to `Dirt Patrol Daylight`.
20. Set a required training on `Dirt Patrol Night` from the shift screen, then re-import the shifts file with that shift's capacity changed, and re-open the shift.
21. Cancel `Gate Opening` from the shift screen, then import a file containing that row again.
22. Open **Import Assignments**, paste the assignments CSV, choose **Preview**, then **Import**.
23. Open `Dirt Patrol Day` and check how Vera's assignment is recorded and who assigned it.
24. Import the same assignments file a second time.
25. Set a signup window on `Dirt Patrol Night` that has already closed, then import an assignment row for Vera on that shift.
26. Create a second `RANGERS` shift with the same title and start as `Dirt Patrol Day` for a different team, then import an assignment row that names only the department, and then one that adds a `team_code` column.
27. Sign out, sign in as the console user without `platform.imports`, and try to open `/admin/imports/shifts` and `/admin/imports/assignments`.
28. Open the audit log and review the entries produced by section B.

## Expected results

- Step 2: the preview reports 2 created, 0 updated, 3 skipped, and lists each row with its outcome — `Email address is not valid.`, `Missing email address.`, and `Duplicate of row 2 in this file.`. A notice states nothing was saved.
- Step 3: no accounts from the file exist; the preview wrote nothing.
- Step 4: the same per-row outcomes are reported, this time as an import result, and `vera.staff@example.org` and `sam.shiftlead@example.org` now exist.
- Step 5: the imported account has no console permissions, no role, and no verified email address, and cannot sign in to the console.
- Step 6: the run reports 1 updated (not created), the account's name reads `Vera Ranger`, and no second account exists for that address.
- Step 7: every row is skipped as `Already up to date.` and nothing is written.
- Step 8: the file is refused whole with a message naming the missing `email` column, and no accounts are created.
- Step 9: the upload imports 3 teams and skips the `no-such-org` row with `No department "RANGERS" in organization "no-such-org".`.
- Step 10: `Dirt` and `Command` are in Rangers, `Greeters` is in Gate, each department's existing default team is untouched, and the `Greeters` description reads `Gate greeters, perimeter` with its comma intact.
- Step 11: the run reports 1 updated, 2 already up to date, and the `no-such-org` row skipped again; the team is renamed rather than duplicated, and the lower-case department code still resolves to Rangers.
- Step 12: both screens are denied (HTTP 403), and neither import screen appears in that user's sidebar.
- Step 13: each created user has a `user.imported` entry and each renamed user a `user.updated` entry with before/after values; imported teams have the ordinary `team.created` and `team.updated` entries; each run has a `users.imported` or `teams.imported` summary entry with its counts; the actor on every entry is Gwen Godmode and the source context is `orchid`. The previewed run in step 2 left no audit entries at all.
- Step 14: the preview reports 3 created and 2 skipped, with `No team "NOPE" in department "RANGERS".` and `Shift end must be after shift start.`, and no shifts exist yet.
- Step 15: the same three shifts are created with the same per-row outcomes, and `Gate Opening` has no capacity limit.
- Step 16: `Dirt Patrol Day` starts at 09:00 in the event timezone — stored as 15:00 UTC for `America/Boise` in August — rather than at 09:00 UTC.
- Step 17: the run reports 1 updated and 2 already up to date; `Dirt Patrol Day` shows capacity 8 and the new end time, and no second `Dirt Patrol Day` exists.
- Step 18: every row is skipped as `Already up to date.` and nothing is written.
- Step 19: the run reports 1 created, not 1 updated: the title is part of the row's identity, so a fourth shift now exists and the original is untouched. Delete or cancel the extra shift before continuing.
- Step 20: the run reports 1 updated, the new capacity is saved, and the required training is still on the shift — an import never removes a requirement the file cannot express.
- Step 21: the row is skipped with a message saying the shift is cancelled and to restore it on the shift screen; the shift stays cancelled and its values are unchanged.
- Step 22: the preview reports 1 created and 2 skipped, with `Staff must belong to the shift department before assignment.` for Ira and `No shift "No Such Shift" starting 2026-08-28 09:00 in department "RANGERS".` for the third row; no assignment exists until **Import** is chosen, after which Vera is on the shift.
- Step 23: Vera's assignment status is `assigned` (not `signed_up`) and it is recorded as assigned by Gwen Godmode.
- Step 24: every row is skipped, Vera's with `This staff member is already assigned to the shift.`, and no second assignment is created.
- Step 25: the row imports despite the closed signup window, which is the one gate an import passes.
- Step 26: the department-only row is skipped with `That title and start matches 2 shifts in this department. Add a team_code column to name one.`, and the row carrying `team_code` imports against the named team.
- Step 27: both screens are denied (HTTP 403) and neither appears in that user's sidebar.
- Step 28: created shifts have the ordinary `shift.created` entries and updated shifts `shift.updated` with before/after values; assignments have the ordinary `shift_assignment.assigned` entries naming Gwen Godmode as the actor; each run has a `shifts.imported` or `shift_assignments.imported` summary entry with its counts; the source context on every entry is `orchid`, and the previews in steps 14 and 22 left no audit entries at all.

## Evidence to capture

- Screenshot of a preview result table showing created/updated/skipped rows with reasons
- Screenshot of the import result after step 4 and after the re-run in step 6
- Screenshot or record of the imported user's empty console permissions
- Screenshot of the team list showing imported teams under the correct departments
- Screenshot of the shift list after step 15 and of `Dirt Patrol Day` after the correction in step 17
- Record of the stored `starts_at` for `Dirt Patrol Day` alongside the event timezone
- Screenshot of the assignment import result showing the refused ineligible row with its reason
- Record of Vera's assignment status and assigning user after step 23
- Audit log entries for `user.imported`, `user.updated`, `team.created`, `users.imported`, `teams.imported`, `shift.created`, `shift.updated`, `shifts.imported`, `shift_assignment.assigned`, and `shift_assignments.imported`
- The 403 response or denied screen for the console user without `platform.imports`

## Failure notes

- Record any row whose reported outcome does not match what the database shows afterwards.
- Record any case where a preview writes data, or where a real import writes something the preview did not report.
- Record any case where an import removes, archives, or disables a record.
- Record any case where an imported account holds console permissions, a role, or a usable password.
- Record any case where a single bad row aborts the file instead of being skipped.
- Record any case where an imported shift time lands at the wrong moment for the event timezone.
- Record any case where an import cancels a shift, removes an assignment, or drops a shift's training or waiver requirements.
- Record any case where an imported assignment puts a staff member on a shift they are not eligible to work, or is recorded as a self-signup.
