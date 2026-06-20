# Codex Alpha 1 Task Prompt

Use this prompt when asking Codex to implement one Meridian Alpha 1 task from `docs/process/meridian-alpha-1-development-plan.md`.

## Mission

Implement exactly the selected Alpha 1 task as one PR-sized change.

Do not implement adjacent Alpha 1 tasks, deferred scope, speculative improvements, product requirement changes, milestone-plan changes, or dependency changes outside the approved technology baseline.

If the task cannot be implemented from the governing documents without guessing, stop and report the missing or conflicting requirements instead of inventing behavior.

## Required Inputs

The user request must name:

- task ID from `docs/process/meridian-alpha-1-development-plan.md`;
- any course-correction notes from prior review, or `None`.

## Governing Documents

Before creating a task branch or making changes:

- Start from the `production` branch.
- Pull the latest `production` from `origin` with a fast-forward-only update.
- Confirm the worktree is clean.
- Create a fresh task branch from the updated `production` branch.
- If the branch switch, pull, or clean-worktree check cannot be completed safely, stop and report the blocker before editing files.

Before implementation, read the selected task and all relevant governing docs.

Always read:

- `docs/process/meridian-alpha-1-development-plan.md`
- `docs/process/meridian-development-process.md`
- `docs/process/traceability-matrix.md`
- `docs/process/conventional-commits.md`
- `docs/process/github-branch-protection.md`
- `docs/qa/README.md`
- `docs/meridian-technology-baseline.md`

Read the relevant source documents for the task:

- requirements: `docs/meridian-requirements-document.md`
- technical spec: `docs/meridian-technical-spec.md`
- data/API spec: `docs/db/meridian-data-model-and-api-specification.md`
- UI docs under `docs/ui/` when screens, components, accessibility, kiosk, IMS, field, or dashboard behavior changes
- QA docs under `docs/qa/`
- issue docs under `docs/issues/` when referenced by the task

Stay inside the requirements, technical spec, data/API spec, UI docs, QA docs, issue docs, and process docs. When these documents conflict, report the conflict and stop unless one document clearly governs the other.

## Pre-Implementation Checklist

Before writing code or docs, produce a short implementation note that includes:

- selected task ID and task title;
- source references: requirement IDs, technical spec sections, data/API sections, UI sections, QA docs, issue docs, and process docs that govern the task;
- explicit out-of-scope and deferred items;
- dependency and technology-baseline impact, or `None`;
- data, API, permission, audit, offline/sync, UI, and QA impact, or `None`;
- acceptance criteria written before implementation.

Acceptance criteria must be specific, testable, source-linked, role-aware where relevant, and explicit about state changes, authorization, validation, audit/history, offline/sync behavior, UI behavior, exports, and non-goals when applicable.

## Implementation Rules

- Implement only the selected task.
- Treat the task as one PR-sized change.
- Use the smallest complete path that satisfies the task.
- Respect all explicit deferred scope and Alpha 1 exclusions.
- Do not broaden scope silently.
- Do not modify product requirements.
- Do not modify the milestone plan except when the task explicitly asks for a process/reference update.
- Do not add, replace, or upgrade runtimes, package managers, libraries, services, wrappers, auth systems, UI frameworks, component libraries, or test runners unless the technology baseline already allows it or a human-approved baseline update is included.
- Preserve operational history; do not add destructive behavior unless the governing docs require it.
- Add or update tests where code exists.
- Add or update human QA instructions when user-visible behavior changes.
- Update OpenAPI/generated clients when API contracts change.
- Update `docs/process/traceability-matrix.md` when a requirement or spec section is closed or materially advanced.

If requirements are missing, contradictory, or too vague to implement safely, stop with:

- what is blocked;
- which documents were checked;
- the exact question or decision needed;
- any safe documentation-only discovery output that can still be provided.

## Verification Rules

Run the smallest useful checks first, then broader checks when available and relevant.

Use existing repository commands and scripts. Prefer:

- targeted automated tests for changed code;
- `scripts/process/check.sh` from Git Bash on Windows, or any POSIX shell on Linux/macOS, for process/doc-only changes when available. On Windows, open Git Bash in the repository; do not use PowerShell, `cmd.exe`, or the WSL `bash.exe` shim for this check;
- lint/type/build checks for touched frontend code;
- migration, API, sync, or export checks when those surfaces change.

Record commands run and results. If a check cannot run, explain why and what residual risk remains.

## PR-Ready Output

At the end, provide PR-ready output with:

- summary of changes;
- source references used;
- acceptance criteria and status for each criterion;
- automated test evidence;
- human QA steps or updated QA doc references;
- traceability notes, including whether `docs/process/traceability-matrix.md` changed;
- suggested branch name;
- suggested Conventional Commit message;
- suggested PR title;
- suggested PR body.

The PR body must follow `.github/pull_request_template.md` and include all required sections:

- Summary
- Traceability
- Implementation Notes
- Technology Baseline
- Data Model / Migration Notes
- Permission Notes
- Offline / Sync Notes
- Audit Notes
- Automated Tests
- Acceptance Criteria Checklist
- Human QA Plan
- Risks
- Follow-up Issues

## Final Steps

If I say "send it", that means I want you to push the changes and create the PR. It should be a review ready PR, not a draft, and I expect you to the use the github connector. I may have to ask you to make reviwsions before this point, so do not automatically push changes and create the PR before I ask.

You may have to make changes to the PR title or PR body, in order to pass validation, as this has been happening a lot. If you have to do this, you will need to also submit a chore commit in order for the updated title/body to be included in a new run of the actions.
