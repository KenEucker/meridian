# 004: Branch Protection Setup Note

## Type

Documentation/change control

## Traceability

- Technical spec: Section 29 Implementation Order
- docs/process/github-branch-protection.md
- docs/process/meridian-development-process.md

## Summary

Document the GitHub branch protection settings required after the CI process baseline exists so maintainers can turn advisory checks into merge requirements.

## Acceptance criteria

- The protected branch is identified as the repository default integration branch.
- Required pull request settings are documented.
- The required PR status check emitted by the `pr-process-checks` workflow is documented.
- The post-merge `main-process-checks` workflow is described as a monitor rather than a PR-blocking check.
- Human confirmation steps are documented for repository maintainers.
- No Meridian product workflows, runtime dependencies, CI behavior changes, data models, API contracts, UI surfaces, permissions, audit behavior, or offline/sync behavior are implemented.

## Automated tests

- `scripts/process/check.sh`

## Human QA

- A repository maintainer confirms the protected-branch settings in GitHub using `docs/process/github-branch-protection.md`.

## Notes

This task is intentionally documentation-only. GitHub branch protection settings are applied by a maintainer in repository administration, not by repository code.
