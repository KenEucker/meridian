# Meridian Server

This directory contains the Laravel server/admin application scaffold for Meridian.

M1.1 intentionally provides only the framework boot path. PostgreSQL development
configuration, Orchid, Meridian domain workflows, API contracts, permissions,
audit behavior, sync behavior, and product seed data are deferred to later
Alpha 1 tasks.

## Local Checks

From this directory, run Composer validation:

```bash
composer validate --no-check-publish
```

Run the default Laravel test suite:

```bash
php artisan test
```

The repository process check also validates this Composer project once Composer
is available:

```bash
scripts/process/check.sh
```
