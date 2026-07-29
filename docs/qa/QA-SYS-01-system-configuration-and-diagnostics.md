# QA-SYS-01: System Configuration and Diagnostics

## Purpose

Prove that a God Mode operator can trace any catalogued environment variable
to its truthful source, apply and remove node-local database overrides safely,
never see a secret, read honest node diagnostics including expected-offline
behavior, export a sanitized support bundle, gate deployments on CLI
diagnostics, and watch sanitized node health reports arrive on central.

## Requirements covered

- SYS-001 through SYS-041 (requirements section 7.26)
- Technical spec section 22A
- Data/API sections 13.7 through 13.9

## Environment

- Development or standalone for steps 1–10.
- A paired central + on-site pair for steps 11–13 (same setup as `QA-SYNC-01`).

## Personas

- Gwen Godmode — console user holding all `platform.system.*` permissions.
- Vera Viewer — console user holding only `platform.index` and
  `platform.system.configuration` (create by editing a console user's
  permissions).

## Setup data

- A booted server with migrations run and a configured node
  (`QA-BOOT-01` state).
- No existing rows in `system_config_overrides`.

## Steps

1. As Gwen, open **Infrastructure → System Configuration**. Search for
   `BCRYPT_ROUNDS`; filter by section *Database*; filter state *Bootstrap-locked*.
2. Open `DB_PASSWORD`. Confirm it is bootstrap-locked, read-only, masked, and
   the explanation names the environment file as the change path.
3. Open `MERIDIAN_NODE_NAME`. Confirm it is managed and points at Node
   Configuration.
4. Open `APP_DEBUG`. Save an override of `false` with a reason. Confirm the
   list now shows source **Database Override** and the audit history records
   the change with your name.
5. Open `MAIL_PASSWORD`. Enter a new secret value; confirm a change reason is
   required, the saved value shows only as a mask, and the audit history shows
   `[redacted]`.
6. As Vera, open the same screens. Confirm the list renders, the edit screen
   offers no save form, and a hand-crafted POST to the save URL is refused.
7. As Gwen, remove the `APP_DEBUG` override. Confirm the source returns to
   **Environment / .env** (or **Laravel Default**).
8. Open **Infrastructure → System Diagnostics**. Confirm an overall status,
   per-category cards, required/optional labels, and that no configuration
   values appear anywhere on the page. Filter by status and category.
9. Select **Export sanitized bundle**. Open the JSON and search it for the
   secret you set in step 5 and for `DB_PASSWORD`'s value — neither may
   appear; configuration entries carry only source/status metadata.
10. Run `php artisan meridian:diagnostics`; note exit code 0. Stop the
    database service, run it again, and confirm a non-zero exit and that the
    server still answers `GET /api/health`. Restart the database.
11. On the paired pair: on the on-site node run
    `php artisan meridian:health-report`. On central, open
    **Infrastructure → Node Health** and confirm the on-site node's report:
    overall status, category badges, version, and sync counts, with no
    configuration values or secrets.
12. Disconnect the on-site node from the internet, queue an attendance or
    field-report operation, and open its own diagnostics. Confirm node sync
    reports *expected offline* rather than failure.
13. Wait past the staleness window (or adjust the report's `generated_at` in
    the database) and confirm central labels the report **stale**.

## Expected results

- Sources are truthful, including the combined **Environment / .env** badge.
- Overrides apply through `config()`, show activation requirements, and
  removal restores the underlying value.
- Bootstrap-locked and managed variables cannot be overridden from the screen
  or the database.
- Secrets are never displayed anywhere; replace-only editing works.
- Vera can view but not manage; the server refuses her writes.
- Diagnostics distinguishes required from optional checks and expected offline
  from failure; the export and CLI output are sanitized.
- CLI exit codes gate on required-critical.
- Central verifies, displays, and staleness-labels node health reports.

## Evidence to capture

- Screenshots: configuration list with filters, `DB_PASSWORD` edit screen,
  secret edit screen after save, diagnostics overall view, Node Health screen
  with a fresh and a stale report.
- The exported JSON bundle.
- Terminal output of both `meridian:diagnostics` runs with exit codes.

## Failure notes

Record any place a secret or configuration value appeared where it should not
have as a critical finding, with the surface and the value's variable name
(never the value itself).
