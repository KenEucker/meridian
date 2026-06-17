# Meridian Server

The Laravel application that will host the Meridian API, Orchid admin, and node
sync surfaces (Technical spec sections 3.1 and 5.1).

This slice (`M1.1`) adds the Laravel framework scaffold only. There are no
Meridian product models, workflows, permissions, sync behavior, or admin
screens yet. The following arrive in later Alpha 1 tasks:

- PostgreSQL development configuration — `M1.2`.
- Orchid install and development admin route — `M1.3`.
- Health/version endpoint for Electron — `M1.4`.

The scaffold uses Laravel's default SQLite connection so a fresh checkout can
run migrations and tests without a database server. PostgreSQL becomes the
configured development database in `M1.2`.

## Technology baseline

This app follows `docs/meridian-technology-baseline.md`:

- PHP `>=8.5 <8.6` target (PHP 8.4.x is the documented temporary local fallback).
- `laravel/framework` `^13.0`.
- Composer-managed dependencies with a committed `composer.lock`.

## Prerequisites

- PHP 8.5.x (8.4.x is an accepted temporary local fallback).
- Composer 2.x.
- The SQLite PHP extension (bundled with most PHP builds).

## Boot path

From this directory:

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

`php artisan serve` exposes the scaffold welcome page at the printed local URL.

## Tests

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
```

The default test suite runs against an in-memory SQLite database, so no
external database is required.

> If you are running the temporary PHP 8.4.x local fallback, install
> dependencies with `composer install --ignore-platform-req=php` because the
> project targets PHP 8.5.

## Notes

`vendor/`, `.env`, and the local SQLite database file are intentionally not
committed. The committed `.env.example` documents the default configuration.
