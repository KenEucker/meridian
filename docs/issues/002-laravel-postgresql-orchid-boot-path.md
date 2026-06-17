# 002: Laravel + PostgreSQL + Orchid Boot Path

## Type

Technical foundation

## Traceability

- Technical spec: Section 5 Server Stack
- Technical spec: Section 21 Admin, Orchid, and God Mode
- Technical spec: Section 27.1 Alpha 1 acceptance target
- Technical spec: Section 29 Implementation Order

## Summary

Add the first server boot path for Laravel, PostgreSQL, and Orchid without implementing Meridian product behavior.

## Acceptance criteria

- Laravel application scaffold exists under the agreed monorepo location.
- PostgreSQL is the configured development database.
- Orchid is installed and reachable in development.
- A fresh checkout has documented boot commands.
- Composer validation and relevant tests run in CI when `composer.json` exists.
- No Meridian domain workflows, permissions, sync behavior, or product data models are implemented in this slice.

## Automated tests

- `composer validate --no-check-publish`
- Initial Laravel test command once scaffolded
- `scripts/process/check.sh` from Git Bash on Windows, or any POSIX shell on Linux/macOS.

## Human QA

- `docs/qa/QA-BOOT-01-fresh-checkout-boots.md`

## Notes

This issue proves the server/admin foundation only. Product model work should follow in later requirement implementation issues.
