# Meridian Server

The Laravel application that hosts the Meridian API, serves Meridian Admin, and
provides Orchid God Mode / repair tooling plus node sync surfaces (Technical
spec sections 3.1 and 5.1).

This slice (`M1.3`) installs the Orchid admin platform and exposes a
development admin route (technical spec sections 5.1 and 22.1). Orchid provides
God Mode / repair tooling; normal Meridian Admin and operational workflows live
in the shared Vue product client.
PostgreSQL remains the canonical Meridian server database (data/API
specification section 3.1). There are still no Meridian product models,
workflows, permissions, sync behavior, or domain admin screens yet — only
Orchid's stock account-administration screens (users, roles, profile) ship in
this slice. The following arrive in later Alpha 1 tasks:

- Health/version endpoint for Electron — `M1.4`.
- Server boot QA update — `M1.5`.

## Technology baseline

This app follows `docs/meridian-technology-baseline.md`:

- PHP `>=8.5 <8.6` target (PHP 8.4.x is the documented temporary local fallback).
- `laravel/framework` `^13.0`.
- `orchid/platform` `^14.0` for God Mode / repair tooling.
- PostgreSQL `18.x` as the canonical server database.
- Composer-managed dependencies with a committed `composer.lock`.

## Prerequisites

- PHP 8.5.x (8.4.x is an accepted temporary local fallback).
- The PHP `pdo_pgsql` extension (bundled with most PHP builds; required for the
  development database).
- The PHP `gd` extension (bundled with most PHP builds; required by Orchid for
  image attachment handling).
- Composer 2.x.
- A reachable PostgreSQL 18.x instance for local development.
- The SQLite PHP extension (bundled with most PHP builds), used by the automated
  test suite only.

## PostgreSQL development database

The development connection defaults match the committed `.env.example`:

| Setting | Default | `.env` key |
|---|---|---|
| Host | `127.0.0.1` | `DB_HOST` |
| Port | `5432` | `DB_PORT` |
| Database | `meridian` | `DB_DATABASE` |
| Username | `meridian` | `DB_USERNAME` |
| Password | `meridian` | `DB_PASSWORD` |
| SSL mode | `prefer` | `DB_SSLMODE` |

Provide a PostgreSQL server that matches these values. The recommended option is
the managed database service under [`deploy/docker`](../../deploy/docker/README.md),
which runs PostgreSQL 18 with logical replication enabled:

```bash
# From the repository root:
corepack pnpm run db:setup
```

`db:setup` copies `deploy/docker/.env` from the example (if needed) and starts
the database, waiting until it reports healthy. See
[`deploy/docker/README.md`](../../deploy/docker/README.md) for the full `db:*`
script list and the equivalent raw Docker Compose commands.

Alternatively, run a local PostgreSQL 18.x service or start a disposable
container:

```bash
docker run --name meridian-postgres \
  -e POSTGRES_DB=meridian \
  -e POSTGRES_USER=meridian \
  -e POSTGRES_PASSWORD=meridian \
  -p 5432:5432 \
  -d postgres:18
```

> The `docker run` command above is a bare-database convenience that does not set
> up logical replication. Use the
> [`deploy/docker`](../../deploy/docker/README.md) service when node-to-node sync
> needs it. The complete multi-service deployment bundle remains a separate,
> later task.

If you already run PostgreSQL natively, create a matching role and database:

```sql
CREATE ROLE meridian WITH LOGIN PASSWORD 'meridian';
CREATE DATABASE meridian OWNER meridian;
```

## Boot path

From the repository root, configure the ignored local env files for Laravel and
all shared-client Vite modes:

```bash
corepack pnpm run env:local
```

When PostgreSQL is reachable and Composer dependencies are installed, run the
idempotent server setup:

```bash
corepack pnpm run server:setup
```

`server:setup` ensures `apps/server/.env` exists, generates `APP_KEY` only when
it is missing, clears cached configuration, runs migrations, and seeds the
well-known local Field fixture used by Field Report command upload QA. The older
manual sequence is still:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

`php artisan db:seed` (or `migrate:fresh --seed`) loads the permission catalog and
the development scenario from development process section 13.3:

- Organization: **Northwood Collective** (`northwood-collective`)
- Event: **Emberfall 2026** (`emberfall-2026`,
  `America/Los_Angeles`)
- Departments: Organizers, Rangers, Gate, DPW
- Named teams: Rangers Dirt, Rangers Command, Rangers IC Operators, Rangers IC
  Viewers, Gate Operator, DPW Logistics — plus a `Default` team created for any
  department a persona joins without a named team (Organizers and Gate)

Persona sign-in accounts are listed under
[Development sign-in accounts](#development-sign-in-accounts) below.

In local development (`APP_ENV=local`), product routes served by Laravel load
the shared Vue Vite dev server from `MERIDIAN_CLIENT_DEV_SERVER_URL`
(`http://localhost:5173` by default). Start it from the repository root with
`corepack pnpm run client:dev` when you want Vue changes to hot-update through
the server app. Set `MERIDIAN_CLIENT_USE_DEV_SERVER=false` to test the
production-style `apps/client/dist` path locally.

The health endpoint version comes from the root `package.json` Meridian version.

`composer install` republishes Orchid's front-end assets to
`public/vendor/orchid` (via the `orchid:publish` post-autoload step), so the
admin UI is styled after a fresh checkout. `php artisan migrate` runs the
default Laravel migrations plus the published Orchid migrations against
PostgreSQL and should report each migration as `DONE`. `php artisan serve`
exposes the server at the printed local URL.

## Development sign-in accounts

Every seeded persona has a linked user and staff record, and all of them share
the password `password`. Seeding matches on email (`updateOrCreate`), so
re-running it does not duplicate these accounts or reset a password you changed.

| Email | Persona | Department / team | Roles granted |
|---|---|---|---|
| `vera.staff@northwood-collective.test` | Vera Staff | Rangers / Dirt | none — plain staff |
| `sam.shiftlead@northwood-collective.test` | Sam Shiftlead | Rangers / Dirt | `shift_lead`, `department_logistics`, `department_operations`, `department_administration`, `department_planning` |
| `dana.departmentlead@northwood-collective.test` | Dana Departmentlead | Rangers / Dirt | `department_lead` |
| `olive.organizer@northwood-collective.test` | Olive Organizer | Organizers / Default | `organizer` |
| `ingrid.iclead@northwood-collective.test` | Ingrid ICLead | Rangers / Command | `ic_lead` (event-scoped) |
| `omar.icoperator@northwood-collective.test` | Omar ICOperator | Rangers / IC Operators | `ic_operator` (event-scoped) |
| `ivy.icviewer@northwood-collective.test` | Ivy ICViewer | Rangers / IC Viewers | `ic_viewer` (event-scoped) |
| `gwen.godmode@northwood-collective.test` | Gwen Godmode | none | none — see below |
| `debbie.dns@northwood-collective.test` | Debbie DNS | none | none — organization status `do_not_staff` |
| `pat.prospective@northwood-collective.test` | Pat Prospective | none | none — organization status `prospective` |
| `ira.ineligible@northwood-collective.test` | Ira Ineligible | Gate / Default | none — department status `ineligible` |

Sam carries every department capability at once, so one sign-in reaches all of
the department administration surfaces. The last three personas exist to
exercise refusals rather than access: Debbie is barred from staffing, Pat has
not been accepted into the organization, and Ira's department membership is
ineligible. Signing in as any of them and finding a surface open is a finding.

Gwen Godmode is seeded without a `god_mode` grant because node-scoped direct
user roles remain deferred to a later task. Today she signs in as an ordinary
active staff member with no department, so she is not yet a way to reach God
Mode; use Orchid for that.

`database/seeders/Support/DevelopmentScenarioCatalog.php` is the source of truth
for this table. `DevelopmentScenarioSeedTest` asserts the shared password along
with the grant and status cases above, so a change to the seeder that this
table no longer describes will fail there first.

## Orchid admin

Orchid is mounted under the `/admin` route prefix (configurable with
`PLATFORM_PREFIX`). After booting the server:

- visit `/admin/login` to load the admin login screen;
- visit `/admin` to reach the dashboard (unauthenticated visitors are redirected
  to the login screen).

Create a development admin user with Orchid's command:

```bash
php artisan orchid:admin "Admin" admin@example.com password
```

> Orchid is God Mode / repair tooling only. Real Meridian authentication
> (external providers, magic link) and the shared Vue product UI are implemented
> in later milestones; this slice only proves the Orchid route is reachable.

## Tests

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

The automated test suite runs against an in-memory SQLite database (configured
in `phpunit.xml`), so it does not require a running PostgreSQL server. Client
SQLite is used only for the test runner and, later, offline client state;
PostgreSQL remains the canonical development and server database.

> If you are running the temporary PHP 8.4.x local fallback, install
> dependencies with `composer install --ignore-platform-req=php` because the
> project targets PHP 8.5.

## Notes

`vendor/` and `.env` are intentionally not committed. The committed
`.env.example` documents the default development configuration. Orchid's
published front-end assets under `public/vendor/` are also not committed; they
are regenerated from the locked `orchid/platform` package on every
`composer install`.
