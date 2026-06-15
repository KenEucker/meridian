# GitHub Branch Protection

GitHub Actions report whether Meridian process checks pass, but Actions alone do not enforce PR discipline. The `main` branch must require the relevant status checks before a merge can complete.

Recommended `main` branch protection settings:

- Require a pull request before merging.
- Require approvals before merging.
- Require conversation resolution before merging.
- Require status checks to pass before merging.
- Require branches to be up to date before merging when practical.
- Require the `pr-process-checks` workflow check on pull requests.
- Require the `main-process-checks` workflow to pass after merge and monitor failures.
- Restrict bypass permissions to trusted maintainers only.
- Do not allow force pushes to `main`.
- Do not allow deletions of `main`.

Required PR checks should include:

- PR body validation.
- Conventional Commit PR title validation.
- Traceability matrix validation.
- QA documentation validation.
- Repository process scaffold validation.
- Composer validation when `composer.json` exists.
- Node lint/test/typecheck/build scripts when `package.json` exists.

The process validators ensure PRs include traceability, acceptance criteria, automated tests, and human QA plans. Branch protection is what turns those checks into a merge requirement.
