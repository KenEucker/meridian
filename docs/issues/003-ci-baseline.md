# 003: CI Baseline

## Type

Technical foundation

## Traceability

- Technical spec: Section 29 Implementation Order
- docs/process/meridian-development-process.md

## Summary

Establish a lightweight CI baseline that enforces Meridian process discipline before product code exists and expands automatically when Composer or Node project files are added.

## Acceptance criteria

- Pull requests run process validators.
- Pull requests validate the PR body for required sections and traceability.
- Pull requests validate Composer when `composer.json` exists.
- Pull requests install Node dependencies and run detected `lint`, `test`, `typecheck`, and `build` scripts when `package.json` exists.
- Pushes to `main` run the same process validators and lightweight Composer/Node checks.
- Pushes to `main` upload traceability, QA docs, and process summary artifacts.
- Workflows do not use secrets or `pull_request_target`.

## Automated tests

- `scripts/process/check.sh`
- GitHub Actions workflow execution

## Human QA

- Confirm branch protection requires the PR process check before merging.

## Notes

GitHub Actions are advisory until `main` branch protection requires passing checks.
