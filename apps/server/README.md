# Meridian Server

The Laravel application that will host the Meridian API, Orchid admin, and node
sync surfaces (Technical spec sections 3.1 and 5.1).

This slice (`M1.3`) installs the Orchid admin platform and exposes a
development admin route (technical spec sections 5.1 and 22.1). Orchid provides
the trusted admin/god-mode data administration interface; the volunteer-facing
operational workflows are separate from Orchid and arrive in later milestones.
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
- `orchid/platform` `^14.0` for the admin/god-mode interface.
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

Provide a PostgreSQL server that matches these values. Either run a local
PostgreSQL 18.x service or start a disposable container:

```bash
docker run --name meridian-postgres \
  -e POSTGRES_DB=meridian \
  -e POSTGRES_USER=meridian \
  -e POSTGRES_PASSWORD=meridian \
  -p 5432:5432 \
  -d postgres:18
```

> The `docker run` command above is a local-development convenience only. The
> production/on-site Docker Compose deployment bundle is a separate, later task.

If you already run PostgreSQL natively, create a matching role and database:

```sql
CREATE ROLE meridian WITH LOGIN PASSWORD 'meridian';
CREATE DATABASE meridian OWNER meridian;
```

## Boot path

From this directory:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

`composer install` republishes Orchid's front-end assets to
`public/vendor/orchid` (via the `orchid:publish` post-autoload step), so the
admin UI is styled after a fresh checkout. `php artisan migrate` runs the
default Laravel migrations plus the published Orchid migrations against
PostgreSQL and should report each migration as `DONE`. `php artisan serve`
exposes the scaffold welcome page at the printed local URL.

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

> Orchid is the admin/god-mode surface only. Real Meridian authentication
> (external providers, magic link) and the volunteer-facing operational UI are
> implemented in later milestones; this slice only proves the admin route is
> reachable.

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
