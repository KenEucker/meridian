# QA-IMPORT-01: Users and Teams CSV Import

## Purpose

Verify that a God Mode operator can bulk-create and bulk-correct user accounts and department teams from a CSV file in the Orchid console, that a preview shows exactly what the file would do before anything is written, that one bad row never aborts the file, and that a spreadsheet cannot grant console access or remove anything.

## Requirements covered

- Technical spec section 22.2: Alpha 1 Orchid / God Mode includes CSV import/export, focused first on Users, Teams, Shifts, and Assignments.
- Technical spec section 22.1: Orchid is God Mode and repair tooling, not the normal Admin product shell.
- Development process section 3.6: audit-sensitive changes record audit history.
- Development process section 7.10: export/import changes state actor permissions, included columns, and round-trip behavior.

## Documented import rules (enforced by `UserImportService` and `TeamImportService`)

- Header names are normalized, so `Email`, `email`, and `EMAIL` all resolve to `email`. Column order does not matter and unknown columns are ignored.
- Users require `email` and `name`. The file cannot carry passwords, console permissions, roles, or the disabled flag.
- An imported account has no console access and no verified email address; the first magic-link sign-in still establishes the address.
- Teams require `organization_slug`, `department_code`, `name`, and `code`; `description` is optional. Department codes are unique per organization, so the slug/code pair resolves to exactly one department.
- Rows match existing records — users by normalized email, teams by department and team code — so re-running a corrected file updates instead of duplicating.
- A row that matches an existing record with no differences is skipped as already up to date.
- Nothing is deleted, archived, or disabled by an import. A record missing from the file is left alone.
- Team writes go through the same domain service the team screen uses, so an imported team is created and audited identically to a hand-created one.
- A missing required header column rejects the whole file; a bad row is skipped with a reason and the rest of the file still imports.

## Environment

- Development server environment with a migrated database
- Orchid console at `/admin`
- Screens: **Import Users** (`/admin/imports/users`) and **Import Teams** (`/admin/imports/teams`) under the God Mode section of the sidebar

## Personas

- Gwen Godmode: console user holding `platform.imports`
- A console user holding `platform.systems.users` and `platform.teams` but **not** `platform.imports` (must fail closed)

## Setup data

- An organization with slug `idaho-burners`
- Departments `RANGERS` and `GATE` in that organization
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

- The committed samples `apps/server/tests/Fixtures/users-import-sample.csv` and `apps/server/tests/Fixtures/teams-import-sample.csv` may be used instead.

## Steps

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

## Evidence to capture

- Screenshot of a preview result table showing created/updated/skipped rows with reasons
- Screenshot of the import result after step 4 and after the re-run in step 6
- Screenshot or record of the imported user's empty console permissions
- Screenshot of the team list showing imported teams under the correct departments
- Audit log entries for `user.imported`, `user.updated`, `team.created`, `users.imported`, and `teams.imported`
- The 403 response or denied screen for the console user without `platform.imports`

## Failure notes

- Record any row whose reported outcome does not match what the database shows afterwards.
- Record any case where a preview writes data, or where a real import writes something the preview did not report.
- Record any case where an import removes, archives, or disables a record.
- Record any case where an imported account holds console permissions, a role, or a usable password.
- Record any case where a single bad row aborts the file instead of being skipped.
