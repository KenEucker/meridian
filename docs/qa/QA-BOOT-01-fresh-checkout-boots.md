# QA-BOOT-01: Fresh Checkout Boots

## Purpose

Verify that a fresh Meridian checkout can run the current process checks and boot the server/admin foundation: Laravel starts, PostgreSQL migrations apply, the Orchid admin login is reachable, and the server health/version endpoint responds.

## Requirements covered

- Technical spec: Section 29 Implementation Order
- Technical spec: Section 27.1 Alpha 1 acceptance target
- Technical spec: Section 25.3 Health panel (server version source)
- Technical spec: Section 26.3 Versioning (server and config schema version)
- Technical spec: Section 5.1 Core server
- Technical spec: Section 22.1 Orchid purpose
- Data/API spec: Section 3.1 Canonical Source of Truth

## Environment

- Fresh local checkout.
- Development environment.
- PHP 8.5.x (8.4.x acceptable as a documented temporary local fallback) and Composer 2.10.x available.
- PostgreSQL 18.x available locally and reachable on `127.0.0.1:5432`.

## Personas

- Developer
- Human reviewer

## Setup data

- No seed data is required to boot the server foundation.
- A PostgreSQL database and role matching `apps/server/.env.example` defaults (`meridian` / `meridian` / `meridian`) are required for migrations to apply.
- Domain seed data arrives with later milestones.

## Steps

1. Clone the repository.
2. On Windows, open Git Bash in the repository and run `scripts/process/check.sh`. On Linux/macOS, run `scripts/process/check.sh` from any POSIX shell.
3. Confirm Composer validation runs (`composer.json` exists under `apps/server`).
4. Create the development database and role if they do not yet exist, for example:
   - `createuser meridian --pwprompt` (set the password to `meridian` to match the defaults), then
   - `createdb meridian --owner=meridian`.
5. From `apps/server`, install PHP dependencies: `composer install`.
6. From `apps/server`, copy the environment file and generate the app key:
   - `cp .env.example .env`
   - `php artisan key:generate`
7. From `apps/server`, run database migrations against PostgreSQL: `php artisan migrate`.
8. From `apps/server`, start the development server: `php artisan serve`.
9. In a browser, open `http://127.0.0.1:8000/admin/login` and confirm the Orchid admin login screen loads.
10. Request the server health endpoint: `curl http://127.0.0.1:8000/api/health` and confirm a JSON payload is returned.

## Expected results

- Process validators pass.
- Missing Composer or Node project files are reported as skipped, not failures.
- `composer install` completes and `php artisan migrate` applies migrations against PostgreSQL without errors.
- `php artisan serve` starts the server without hidden setup steps.
- `http://127.0.0.1:8000/admin/login` returns the Orchid admin login screen.
- `GET /api/health` returns HTTP 200 with a JSON body that includes `status` (`ok`), `environment`, `server_version`, `config_schema_version`, and `timestamp`.

## Evidence to capture

- Terminal output from `scripts/process/check.sh`.
- Terminal output from `php artisan migrate` and `php artisan serve`.
- The JSON response body from `GET /api/health`.
- A screenshot of the Orchid admin login screen.

## Failure notes

Record the failed command, exact error text, operating system, whether Composer or Node project files were present, and whether PostgreSQL was reachable with the expected database, role, and password.
