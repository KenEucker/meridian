# QA-BOOT-01: Fresh Checkout Boots

## Purpose

Verify that a fresh Meridian checkout can run the current process checks and, once product scaffolding exists, boot the development surfaces expected by the foundation milestone.

## Requirements covered

- Technical spec: Section 28 Implementation Order
- Technical spec: Section 26.1 Alpha 1 acceptance target

## Environment

- Fresh local checkout.
- Development environment.
- No product services are required until the monorepo and application scaffolds exist.

## Personas

- Developer
- Human reviewer

## Setup data

- No seed data is required for the initial process-only repository.
- After application scaffolding exists, use the documented development seed data.

## Steps

1. Clone the repository.
2. Run `scripts/process/check.sh`.
3. If `composer.json` exists, confirm Composer validation runs.
4. If `package.json` exists, confirm detected Node scripts run.
5. After product scaffolding exists, follow the project boot instructions and confirm the documented local services start.

## Expected results

- Process validators pass.
- Missing Composer or Node project files are reported as skipped, not failures.
- No Meridian product behavior is required before product scaffolding exists.
- Once product scaffolding is added, documented boot commands complete without hidden setup steps.

## Evidence to capture

- Terminal output from `scripts/process/check.sh`.
- Any service URLs or health output once product services exist.
- Screenshots only after UI surfaces exist.

## Failure notes

Record the failed command, exact error text, operating system, and whether Composer or Node project files were present.
