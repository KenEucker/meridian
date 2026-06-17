# GitHub Branch Protection

GitHub Actions report whether Meridian process checks pass, but Actions alone do not enforce PR discipline. Branch protection turns those checks into a merge requirement.

## Protected Branch

Protect the repository default integration branch.

For this repository, that branch is currently:

```text
production
```

If the default branch is renamed later, apply the same settings to the new default branch and keep the workflow branch filters aligned with it.

## Required Pull Request Settings

Configure the protected branch with these settings:

- Require a pull request before merging.
- Require at least one approval before merging.
- Require conversation resolution before merging.
- Require status checks to pass before merging.
- Require branches to be up to date before merging when practical.
- Restrict bypass permissions to trusted maintainers only.
- Do not allow force pushes.
- Do not allow branch deletion.

## Required Status Checks

After the `pr-process-checks` workflow has run at least once on a pull request, require its `process` job as a protected-branch status check.

GitHub may display this check as either:

```text
process
```

or:

```text
pr-process-checks / process
```

Use GitHub's status-check picker after a successful pull request run so the required check exactly matches the emitted check name.

The required PR check covers:

- PR body validation.
- Conventional Commit PR title validation.
- Traceability matrix validation.
- QA documentation validation.
- Repository process scaffold validation.
- Composer validation when `composer.json` exists.
- Node `lint`, `test`, `typecheck`, and `build` scripts when `package.json` exists and those scripts are defined.

Do not require the `main-process-checks` workflow as a PR merge check. It runs after changes land on the protected branch and should be monitored for post-merge process failures.

Before relying on the post-merge monitor, confirm that the workflow branch filter targets the protected branch. In this repository, the protected branch is `production`; if a workflow name still says `main-process-checks`, treat that as a historical name and verify the trigger branch separately.

## Human Confirmation Checklist

A maintainer should confirm:

- The protected branch is the current default branch.
- Pull requests are required before merging.
- Required status checks include the PR workflow `process` job.
- The required status check was selected from an actual recent workflow run.
- Branch deletion and force pushes are disabled.
- Bypass access is limited to trusted maintainers.
- The post-merge process workflow runs on the protected branch and failures are monitored.

Record confirmation in the pull request or repository administration notes.
