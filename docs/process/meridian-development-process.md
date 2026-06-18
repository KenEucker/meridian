# Meridian Development Process Plan

**Project:** Meridian  
**Document type:** Development process / contribution guide  
**Draft:** 0.1  
**Source documents:**

- `docs/meridian-requirements-document.md`
- `docs/meridian-technical-spec.md`
- `docs/meridian-technology-baseline.md`

---

## 1. Purpose

This document turns the Meridian requirements document and technical specification into an iterative development process.

It defines how Meridian work should move from requirement to implementation, review, testing, human verification, and release.

The goal is to make every meaningful change traceable to:

1. a source requirement or technical slice;
2. explicit acceptance criteria;
3. implementation evidence;
4. automated test coverage;
5. human QA steps that can be followed by a non-author reviewer.

This process is designed for human developers, AI-assisted development, and human reviewers working together.

---

## 2. Source of Truth

Meridian development should be grounded in the project source documents.

### 2.1 Requirements document

The requirements document defines the product behavior and operational truth. It is the source of truth for:

- volunteer operations concepts;
- MVP scope and non-goals;
- user roles and authority boundaries;
- workflow expectations;
- requirement IDs such as `ORG-001`, `SHIFT-016`, `FR-012`, and `INC-014`.

When implementation behavior conflicts with the requirements document, the requirements document wins unless a formal requirements change is accepted.

### 2.2 Technical specification

The technical specification defines the Alpha 1 architecture and technical direction. It is the source of truth for:

- monorepo topology;
- Laravel, Orchid, PostgreSQL, Vue, Capacitor, Electron, PowerSync, and Docker Compose choices;
- device trust, local encryption, and signatures;
- central/on-site node pairing and sync;
- offline write scope;
- implementation order;
- Alpha 1 acceptance target.

When implementation structure conflicts with the technical specification, the technical specification wins unless a formal technical decision change is accepted.

### 2.3 Development process document

This document does not replace the requirements document, technical specification, or technology baseline.

It answers:

- How do we turn requirements into work?
- How do we write acceptance criteria?
- How do we implement changes safely?
- How do we formulate a PR?
- How do we verify that the work meets the acceptance criteria?
- How does a human reviewer check the result?

### 2.4 Technology baseline

The technology baseline defines the approved runtimes, frameworks, package managers, libraries, services, version constraints, and dependency-change policy.

Use:

- `docs/meridian-technology-baseline.md`

When an implementation decision conflicts with the technology baseline, the technology baseline wins unless a human-approved baseline update is made before or alongside the implementation.

AI coding agents and human contributors must not introduce, replace, or upgrade runtimes, package managers, libraries, services, desktop/mobile wrappers, authentication systems, UI frameworks, component libraries, or test runners outside this baseline without first asking for a decision and recording the approved change in the baseline.

---

## 3. Development Principles

Every Meridian change should follow these principles.

### 3.1 Requirement traceability

Every user-visible, permission-related, data-model, sync, export, or operational workflow change should reference at least one requirement ID or technical spec section.

Examples:

- `SHIFT-016`, `TRAIN-008`, `WAIVER-005`
- `FR-007`, `FR-009`, `FR-012`
- `INC-008`, `INC-014`, `INC-015`
- Technical spec section `9.4 Offline write scope`
- Technical spec section `26.1 Alpha 1 acceptance target`

### 3.2 Small vertical slices

Prefer small, reviewable slices that move one workflow forward end-to-end.

A good slice usually includes:

- database migration or schema change;
- domain action/service;
- authorization policy;
- API endpoint or Orchid screen;
- UI path where relevant;
- tests;
- human QA script.

Avoid large PRs that implement many unrelated requirements at once.

### 3.3 Operational truth first

Meridian should preserve operational truth before optimizing convenience.

For example:

- scheduled shifts and actual hours are different records;
- field reports are immutable but appendable;
- incidents are not destroyed;
- credit calculations freeze after the correction period;
- historical labels should remain readable even after names change.

### 3.4 Permissions are product behavior

Permissions are not just implementation details.

Every permissions-related change should specify:

- actor;
- role/scope;
- allowed action;
- denied action;
- denial reason when practical;
- audit behavior.

### 3.5 Offline and sync behavior must be explicit

Any change touching field reports, attendance, mobile state, device trust, node sync, or event-mode behavior must define:

- whether the action works offline;
- where the write is first created;
- what UUID/signature/timestamp is used;
- what happens before sync;
- what happens after sync;
- what happens if sync fails;
- how the human user sees the state.

### 3.6 Auditability is part of the feature

A feature is not done if important state changes happen without audit records.

At minimum, audit-sensitive changes include:

- permission changes;
- organization and department status changes;
- credential revocations;
- hour corrections;
- finalized credit calculation;
- field report attachment/removal from incidents;
- incident state/title/link changes;
- sync conflict resolution;
- dangerous God mode changes.

### 3.7 Human QA must be possible

Every PR should include a human-readable QA path that lets someone who did not write the code verify the behavior.

Human QA should use realistic role names and scenarios, not only technical endpoints.

---

## 4. Work Item Types

Meridian work should be organized into a few predictable work item types.

### 4.1 Requirement implementation

Implements product behavior from one or more requirement IDs.

Examples:

- implement DNS auto-rejection;
- implement shift eligibility checks;
- implement credential blocked state;
- implement field report append behavior.

### 4.2 Technical foundation

Implements architectural capabilities required by the technical specification.

Examples:

- monorepo scaffold;
- Docker Compose environment;
- node setup flow;
- device key registration;
- PowerSync integration;
- Electron health shell.

### 4.3 Workflow slice

Implements a user workflow that crosses several requirements.

Examples:

- Staff applies to an event and becomes Prospective after approval;
- Shift lead checks a staff member in/out and creates actual hours;
- Offline field report syncs from device to on-site to central.

### 4.4 Admin/God mode support

Implements Orchid or God mode features that support configuration, repair, visibility, imports, exports, and troubleshooting.

Examples:

- manage teams;
- view audit log;
- resolve sync conflicts;
- import shifts;
- export credential eligibility.

### 4.5 Hardening and QA

Improves test coverage, error handling, setup validation, observability, or release safety.

Examples:

- fail closed when event-mode HTTPS validation fails;
- add conflict queue test coverage;
- add seed scenarios;
- improve Electron health warnings.

### 4.6 Documentation/change control

Updates requirements, technical decisions, user-facing documentation, or developer process.

Examples:

- add an accepted technical decision record;
- clarify acceptance criteria for attendance reconciliation;
- update the QA matrix after implementation.

---

## 5. Traceability Model

Every issue and PR should include a traceability block.

### 5.1 Traceability fields

```md
## Traceability

Requirements:
- SHIFT-001
- SHIFT-002
- SHIFT-004
- SHIFT-016

Technical spec:
- Section 5.2 Modular monolith boundaries
- Section 20.4 Shift lead workflows

Work item type:
- Workflow slice

MVP / Alpha 1 relevance:
- Required for October MVP
- Required for Alpha 1 attendance proof
```

### 5.2 Traceability matrix

Maintain a lightweight traceability matrix in the repo.

Suggested path:

```text
docs/process/traceability-matrix.md
```

Suggested columns:

| Requirement | Status | Issue | PR | Automated tests | Human QA scenario | Notes |
|---|---|---|---|---|---|---|
| `SHIFT-016` | In progress | `#42` | `#51` | `ShiftEligibilityTest` | `QA-SHIFT-02` | Enforce training/waiver for scheduled and unscheduled additions |
| `FR-012` | Not started |  |  |  |  | Copy field report content into incident notes when linked |
| `INC-014` | Partial | `#63` | `#80` | `IncidentTimelineTest` | `QA-INC-03` | Needs attachment strike coverage |

The matrix does not need to be perfect on day one, but every merged PR should improve it.

---

## 6. Iteration Loop

Meridian should be developed through repeated implementation loops.

### Step 1: Select a slice

Choose one coherent slice from the requirements or Alpha 1 implementation order.

A good slice can be described in one sentence:

> A shift lead can check out a staff member and create an actual hours record.

Poor slice:

> Implement shifts, attendance, hours, credits, and exports.

### Step 2: Identify source requirements

List all source requirement IDs and technical sections.

Do not rely on memory. Read the source documents and quote or paraphrase the relevant behavior into the issue.

### Step 3: Define acceptance criteria

Write acceptance criteria before implementation.

Use role-based scenarios in `Given / When / Then` form where possible.

### Step 4: Define data and permission impact

Before coding, answer:

- What records are created, changed, appended, stricken, frozen, or audited?
- Which actor can perform the action?
- Which actor cannot?
- Does the behavior differ online/offline?
- Does this touch central/on-site sync?
- Does this touch PowerSync?
- Does this affect exports?
- Does this require migration or seed data?

### Step 4a: Check the technology baseline

Before coding or changing dependency manifests, read `docs/meridian-technology-baseline.md`.

If the slice needs a runtime, package manager, framework, library, service, wrapper, authentication system, component library, or test runner that is not already approved there:

- stop before implementation;
- document the proposed dependency or version change;
- answer the dependency approval checklist from the technology baseline;
- ask the project owner to decide;
- update the technology baseline with the accepted decision before using the dependency.

Do not make implicit LLM-chosen dependency decisions inside feature work.

### Step 5: Implement the smallest complete path

Implement from the domain inward:

1. migration/schema;
2. model relationships;
3. action/service class;
4. policy/authorization;
5. API/Orchid/UI;
6. tests;
7. documentation/traceability.

### Step 6: Run automated checks

Run the smallest useful local check first, then the full suite before PR.

Suggested order:

```bash
# server
composer test
php artisan test
php artisan migrate:fresh --seed

# frontend
npm run lint
npm run test
npm run build

# generated contracts
npm run openapi:generate
npm run typecheck

# full repo check, once available
npm run check
```

Exact commands should be updated once the repo scripts exist.

### Step 7: Self-check against acceptance criteria

Before opening the PR, the implementer must mark each acceptance criterion as:

- met;
- not met;
- changed;
- intentionally deferred.

Any deferred item must reference a follow-up issue.

### Step 8: Open the PR

Use the PR template in this document.

Attach screenshots, logs, exported CSVs, or test output where helpful.

### Step 9: Human review and QA

A reviewer should verify:

- the PR matches the requirement;
- the acceptance criteria are specific and complete;
- automated tests cover the important paths;
- the manual QA script works;
- source documents do not contradict the implementation.

### Step 10: Merge and update traceability

After approval:

- merge;
- update the traceability matrix;
- update docs or release notes;
- file follow-up issues for known gaps.

---

## 7. How to Make Development Changes

This section describes the expected implementation flow for a change.

### 7.1 Branch naming

Use branch names that identify the slice.

```text
feature/shift-checkout-hours
feature/offline-field-report-text
feature/node-pairing-token
fix/credential-blocked-after-waiver-expiry
test/fr-incident-link-coverage
docs/development-process
```

### 7.2 Commit style

Use Conventional Commit messages that name the changed behavior.

Examples:

```text
feat(attendance): add shift checkout action
fix(shifts): enforce waiver eligibility for unscheduled additions
docs(qa): add human QA scenario for offline field reports
ci(process): validate PR body sections
```

See `docs/process/conventional-commits.md` for the accepted commit types and validation commands.

### 7.3 Server-side changes

For Laravel modules, prefer this order:

1. Create or update migrations.
2. Create or update Eloquent models.
3. Add explicit action/service class for domain behavior.
4. Add policy authorization.
5. Add validation request or DTO.
6. Add API endpoint or Orchid screen.
7. Add audit entry generation where required.
8. Add tests.
9. Update OpenAPI if the API changed.

Each domain module may own its migrations, models, policies, actions, API endpoints, Orchid screens, and tests.

Avoid hiding cross-domain behavior in model observers unless there is a strong reason. Prefer explicit action classes such as:

```text
ApproveApplicationAction
AssignStaffToTeamAction
CheckStaffOutAction
CalculateCreditsAction
AttachFieldReportToIncidentAction
RevokeCredentialAction
```

### 7.4 API changes

Any API change should update the OpenAPI description and generated TypeScript client.

API PRs should include:

- endpoint purpose;
- request validation;
- response shape;
- authorization rules;
- error states;
- OpenAPI diff;
- generated client update;
- tests for success and denial cases.

### 7.5 Database changes

Database changes should include:

- migration;
- rollback consideration;
- seed/update path when needed;
- indexes for expected lookup patterns;
- foreign key behavior;
- preservation of historical records;
- audit impact.

Before adding destructive behavior, ask whether the domain expects:

- deletion;
- soft deletion;
- stricken record;
- append-only correction;
- frozen record.

Meridian generally should not destroy operational history.

### 7.6 Permission changes

Permission changes should include:

- policy or gate implementation;
- tests for allowed actors;
- tests for denied actors;
- UI explanation where practical;
- audit entry when permission state changes.

Permission PRs should answer:

```md
Who can do this?
Why can they do it?
Who cannot do this?
What denial reason is shown?
Is the permission organization-, event-, department-, team-, shift-, or node-scoped?
```

### 7.7 Offline-capable changes

Offline-capable changes must define the complete operation lifecycle.

Required checklist:

```md
- [ ] Device creates UUID before sync.
- [ ] Device records device timestamp.
- [ ] Local state updates immediately where required.
- [ ] Operation is signed by device where required.
- [ ] Server validates operation on receipt.
- [ ] Accepting node countersigns where required.
- [ ] Sync retry behavior is defined.
- [ ] Failed sync is recoverable or visible.
- [ ] Tests cover duplicate/idempotent operation handling.
```

### 7.8 Sync changes

Node sync changes should be operation-based, idempotent, and auditable.

A sync PR should specify:

- operation type;
- entity type;
- payload shape;
- idempotency key;
- ordering requirements;
- conflict behavior;
- default conflict resolution;
- audit entries;
- Electron health impact.

### 7.9 UI changes

UI changes should include:

- role-specific entry point;
- empty state;
- loading state;
- error state;
- permission-denied state;
- success confirmation;
- mobile/offline behavior where relevant;
- screenshots or screen recording in PR.

### 7.10 Export/import changes

Export/import PRs should include:

- actor permissions;
- scope rules;
- included columns;
- excluded sensitive fields;
- sample file;
- round-trip behavior where applicable;
- test fixture;
- human QA export sample.

For example, organizer exports must not include emergency contacts, while department staff contact exports may include emergency contacts for that department.

---

## 8. Acceptance Criteria

Acceptance criteria define what must be true for a slice to be accepted.

They should be written before implementation.

### 8.1 Acceptance criteria rules

Good acceptance criteria are:

- specific;
- testable;
- role-aware;
- tied to source requirements;
- explicit about data state;
- explicit about audit behavior;
- explicit about offline/sync behavior if relevant;
- explicit about out-of-scope behavior.

### 8.2 Acceptance criteria template

```md
## Acceptance Criteria

Source:
- Requirements: `SHIFT-016`, `TRAIN-008`, `WAIVER-005`
- Technical spec: Section 20.4 Shift lead workflows

Actors:
- Shift Lead
- Department Lead
- Staff

Preconditions:
- Event exists.
- Department exists.
- Team exists.
- Staff is assigned to the department and eligible team.
- Shift requires Training A and Waiver B.

Scenarios:

1. Eligible scheduled staff may be checked in.
   - Given the staff member has completed Training A
   - And the staff member has completed Waiver B
   - And the staff member is assigned to the eligible team
   - When the Shift Lead checks the staff member in
   - Then an attendance operation is recorded
   - And the staff member appears as checked in on the Shift Lead Board

2. Ineligible unscheduled staff may not be added.
   - Given the staff member has not completed Training A
   - When the Shift Lead attempts to add the staff member to the shift
   - Then the action is denied
   - And the denial explains that required training is incomplete
   - And no attendance record is created

Audit:
- Successful check-in/check-out creates audit or operation history as required.
- Denied eligibility attempts are logged only if the implementation defines denial logging for this action.

Automated tests:
- `ShiftEligibilityTest::test_requires_training_for_unscheduled_addition`
- `ShiftEligibilityTest::test_requires_waiver_for_unscheduled_addition`

Human QA:
- Run `QA-SHIFT-02`.
```

### 8.3 Acceptance criteria categories

Use these categories when relevant.

#### Functional

What the user can do.

#### Authorization

Who can and cannot do it.

#### Validation

What data is required or rejected.

#### State transition

What changes from one state to another.

#### Historical behavior

What must remain preserved.

#### Audit

What must be recorded.

#### Offline/sync

What happens without connectivity and after reconnection.

#### UI

What the human sees.

#### Export/reporting

What appears in generated files.

#### Non-goals

What this slice intentionally does not do.

---

## 9. Example Acceptance Criteria

### 9.1 Example: Field report creation offline

```md
## Acceptance Criteria: Offline Field Report Text

Source:
- Requirements: `FR-001`, `FR-002`, `FR-003`, `FR-004`, `FR-007`, `FR-010`
- Technical spec: Sections 9.4, 17.2, 17.3, 26.1

Actors:
- Authorized staff member
- IC role
- God mode user

Preconditions:
- User has authenticated before the event.
- Device is trusted.
- Local encryption is active.
- Device signing is available.
- Event data is cached.
- Network connection is disabled.

Scenarios:

1. Authorized staff member submits a field report offline.
   - Given the user is authorized to create field reports
   - And the device is offline
   - When the user submits field report body text
   - Then the report receives a device-generated UUID
   - And the report is locally stored encrypted
   - And the report appears submitted immediately to the user
   - And the report is marked pending sync

2. Original report body cannot be edited.
   - Given the report has been submitted
   - When the user views the report
   - Then no edit action is available for the original body

3. Report syncs after reconnect.
   - Given the device reconnects to the on-site node
   - When sync runs
   - Then the operation is submitted to the server
   - And the server accepts the report if valid
   - And the report receives a server-visible field report number
   - And the temporary local number is replaced

4. IC role visibility.
   - Given the report has reached the server
   - When an IC role views field reports for the event
   - Then the report is visible
   - And a non-IC department lead cannot view it unless they are the author

Automated tests:
- Device/local unit tests for pending report state
- Server feature test for field report acceptance
- Policy test for author and IC visibility
- Sync/idempotency test for duplicate operation submission

Human QA:
- Run `QA-FR-01`.
```

### 9.2 Example: Check-out creates hours

```md
## Acceptance Criteria: Check-out Creates Actual Hours

Source:
- Requirements: `SLB-004`, `SLB-005`, `SLB-006`, `HOURS-001`, `HOURS-002`, `HOURS-003`, `HOURS-004`
- Technical spec: Section 20 Attendance

Actors:
- Shift Lead
- Department Lead
- Staff

Preconditions:
- Staff is assigned to a shift.
- Shift Lead has authority for the relevant team.
- Staff has been checked in.

Scenarios:

1. Shift Lead checks out staff.
   - Given the staff member is checked in
   - When the Shift Lead checks the staff member out
   - Then an attendance operation is recorded
   - And an hours worked record is created
   - And the hours record references event, department, shift, staff, actual start time, and actual end time

2. Shift Lead edits actual checkout time during checkout.
   - Given the staff member forgot to check out on time
   - When the Shift Lead enters a corrected actual end time
   - Then the hours record uses the corrected actual end time
   - And the correction is visible in history/audit according to the implementation design

3. Hours cannot be free-floating.
   - Given no shift is selected or inferable
   - When the Shift Lead attempts to create hours
   - Then the action is rejected
   - And no hours record exists without shift and department

Automated tests:
- `CheckOutCreatesHoursTest`
- `HoursRequireShiftAndDepartmentTest`
- `ShiftLeadPolicyTest`

Human QA:
- Run `QA-SLB-01`.
```

### 9.3 Example: Credential blocked after all shifts removed

```md
## Acceptance Criteria: Credential Blocks When All Shifts Removed

Source:
- Requirements: `CRED-003`, `CRED-004`, `CRED-009`, `CRED-010`, `SHIFT-013`

Actors:
- Staff
- Department Lead
- Organizer

Preconditions:
- Staff has one event credential in Eligible state.
- Staff has exactly one signed-up future shift.

Scenarios:

1. Removing the final shift blocks credential.
   - Given the Department Lead removes the staff member from the final future shift
   - When credential eligibility recalculates
   - Then the credential state becomes Blocked
   - And the blocked reason includes no signed-up shifts

2. Completed hours are preserved.
   - Given the staff member has completed shifts and recorded hours
   - When future shifts are removed
   - Then completed shifts and recorded hours remain visible and unchanged

Automated tests:
- `CredentialEligibilityTest::test_blocks_when_all_shifts_removed`
- `CredentialRevocationTest::test_preserves_completed_hours`

Human QA:
- Run `QA-CRED-01`.
```

---

## 10. Issue Template

Use this template for implementation issues.

```md
# [Slice name]

## Summary

One or two sentences describing the user-visible or technical outcome.

## Source

Requirements:
- `REQ-ID`

Technical spec:
- Section name or heading

## Work item type

- Requirement implementation
- Technical foundation
- Workflow slice
- Admin/God mode support
- Hardening and QA
- Documentation/change control

## User / actor

Who is this for?

## Current behavior

What happens now?

## Desired behavior

What should happen after this work?

## Acceptance criteria

Use Given / When / Then scenarios.

## Data model impact

- New tables:
- Changed tables:
- Historical preservation:
- Audit entries:
- Sync impact:

## Permission impact

- Allowed:
- Denied:
- Scope:
- Denial reason:

## Offline/sync impact

- Offline supported?
- Device operation?
- Node operation?
- Conflict behavior?

## Testing expectations

- Unit:
- Feature/API:
- Policy:
- UI:
- Sync/offline:
- Export/import:
- Human QA:

## Out of scope

What this issue does not include.
```

---

## 11. Pull Request Template

Use this template for PRs.

```md
# Summary

Describe what changed and why.

## Traceability

Requirements:
- `REQ-ID`

Technical spec:
- Section name or heading

Issue:
- Closes #

## Implementation notes

Explain the design in reviewer-friendly terms.

## Data model / migration notes

- Tables changed:
- Backfill needed:
- Rollback consideration:
- Historical preservation:

## Permission notes

- Allowed actors:
- Denied actors:
- Scope:
- Denial reason shown:

## Offline / sync notes

- Offline behavior:
- Sync behavior:
- Idempotency:
- Conflict behavior:

## Audit notes

- Audit entries created:
- Before/after values captured:
- Reason/comment required?

## Screenshots / artifacts

Attach screenshots, screen recordings, exported files, logs, or OpenAPI diffs.

## Automated tests

Paste commands and results.

```bash
php artisan test --filter=...
npm run test -- ...
npm run typecheck
```

## Acceptance criteria checklist

- [ ] AC 1
- [ ] AC 2
- [ ] AC 3

## Human QA plan

1. Seed or create required data.
2. Log in as...
3. Navigate to...
4. Perform...
5. Confirm...
6. Confirm denied behavior by logging in as...

## Risks

Known risks, edge cases, or areas needing follow-up.

## Follow-up issues

- #
```

---

## 12. Checking Against Acceptance Criteria

### 12.1 Implementer self-check

Before requesting review, the implementer should complete this checklist.

```md
- [ ] I linked the PR to source requirements or technical spec sections.
- [ ] I copied the acceptance criteria into the PR.
- [ ] I checked each acceptance criterion manually or with tests.
- [ ] I added tests for success paths.
- [ ] I added tests for denial/failure paths.
- [ ] I added policy tests for permission-sensitive behavior.
- [ ] I updated OpenAPI/generated client if API changed.
- [ ] I added or updated audit behavior if important state changes.
- [ ] I added human QA steps.
- [ ] I updated docs or traceability matrix.
- [ ] I filed follow-up issues for known gaps.
```

### 12.2 Reviewer check

Reviewers should read in this order:

1. Source requirement(s).
2. Acceptance criteria.
3. PR summary.
4. Tests.
5. Implementation.
6. Human QA results.

Reviewer questions:

- Does this satisfy the requirement, or only part of it?
- Are denied users tested?
- Are historical/audit expectations met?
- Is offline/sync behavior defined where needed?
- Does the implementation introduce hidden policy decisions?
- Would a human event operator understand the UI state?
- Does the QA script prove the behavior?

### 12.3 QA check

QA should verify the acceptance criteria without depending on implementation details.

QA should report:

```md
## QA Result

Scenario:
- `QA-SLB-01`

Environment:
- development / standalone / central / onsite

Build:
- commit SHA
- server version
- mobile version
- Electron version, if relevant

Result:
- Pass / Fail / Blocked

Evidence:
- screenshots
- exported CSV
- logs
- observed behavior

Notes:
- Any confusion, mismatch, or follow-up needed
```

---

## 13. Testing Strategy

Meridian needs layered testing because the product crosses server, mobile, sync, permissions, offline behavior, and human field workflows.

### 13.1 Test pyramid

#### Unit tests

Use for pure domain behavior.

Examples:

- status transition rules;
- credit calculation;
- credential eligibility;
- IMS/FRA number formatting;
- conflict default selection;
- timestamp sanity interpretation.

#### Feature/API tests

Use for Laravel endpoint behavior.

Examples:

- application approval creates Prospective staff records;
- DNS application auto-rejection;
- shift signup eligibility;
- check-out creates hours;
- incident creation restricted to IC roles.

#### Policy tests

Use for every role-sensitive rule.

Examples:

- organizer cannot view emergency contacts by default;
- department lead can view emergency contacts for own department;
- non-IC department lead cannot view field reports authored by their staff;
- `ic_viewer` can view but not modify incidents;
- only organizer/IC lead can revoke credential.

#### Contract tests

Use for API/OpenAPI and client generation.

Examples:

- OpenAPI schema matches Laravel responses;
- generated TypeScript client compiles;
- mobile app uses generated client without type errors.

#### Migration tests

Use for database changes that preserve operational history.

Examples:

- archived teams remain visible;
- historical team labels are preserved;
- frozen credit records are not recalculated by later policy changes.

#### Sync tests

Use for PowerSync and node sync behavior.

Examples:

- duplicate attendance operation is idempotent;
- field report created offline syncs once;
- node operation signature verification fails closed;
- unresolved conflicts do not block unrelated sync.

#### UI/component tests

Use for Vue and operational screens.

Examples:

- Shift Lead Board action visibility;
- field report submitted/pending/synced states;
- readiness checklist;
- incident status transitions.

#### End-to-end tests

Use sparingly for critical flows.

Examples:

- approve applicant → assign team → sign up for shift → credential eligible;
- shift lead check-in/out → hours record → credit calculation after freeze;
- offline field report → reconnect → on-site accepts → central receives;
- IC operator creates incident and links field report.

### 13.2 Required test classes by slice type

| Slice type | Minimum automated tests |
|---|---|
| Domain rule | Unit test |
| Permission change | Policy test + feature denial test |
| API endpoint | Feature/API test + OpenAPI update |
| UI workflow | Component or E2E test + human QA |
| Offline write | Local state test + sync acceptance/idempotency test |
| Node sync | Operation apply test + conflict/idempotency test |
| Export | Feature test + generated sample fixture |
| Data freeze/history | Migration/domain test + human QA |

### 13.3 Test data strategy

Create seed scenarios that mirror real operations.

Suggested seed personas:

| Persona | Role |
|---|---|
| Vera Staff | regular staff |
| Sam Shiftlead | shift lead for Dirt team |
| Dana Departmentlead | department lead for Rangers |
| Olive Organizer | organizer |
| Ingrid ICLead | IC department lead |
| Omar ICOperator | IC operator |
| Ivy ICViewer | IC viewer |
| Gwen Godmode | God mode user |
| Debbie DNS | Do Not Staff person |
| Pat Prospective | prospective staff |
| Ira Ineligible | department-ineligible staff |

Suggested seed domains:

- Organization: Idaho Burners
- Event: Idaho Decompression 2026
- Departments: Rangers, Gate, DPW
- Teams: Dirt, Operator, Command, Logistics
- Shifts: day shift, overnight shift, command shift
- Trainings: Basic Ranger Training, Radio Training
- Waivers: Event Waiver, Ranger Waiver
- Credit policies: organization default, overnight multiplier
- Equipment: radio, vest, flag

### 13.4 Human QA scenario IDs

Maintain human QA scripts under:

```text
docs/qa/
```

Suggested files:

```text
docs/qa/QA-APP-01-event-application-approval.md
docs/qa/QA-DEPT-01-team-assignment.md
docs/qa/QA-SHIFT-01-shift-signup-eligibility.md
docs/qa/QA-SLB-01-checkin-checkout-hours.md
docs/qa/QA-CRED-01-credential-eligibility.md
docs/qa/QA-FR-01-offline-field-report.md
docs/qa/QA-INC-01-incident-management.md
docs/qa/QA-EXPORT-01-mvp-exports.md
docs/qa/QA-SYNC-01-onsite-central-sync.md
docs/qa/QA-ELECTRON-01-health-panel.md
```

Each QA script should include:

```md
# QA-ID: Name

## Purpose

## Requirements covered

## Environment

## Personas

## Setup data

## Steps

## Expected results

## Evidence to capture

## Failure notes
```

---

## 14. Human QA Plan

The human QA plan should prove that a real event operator can perform the workflow.

### 14.1 QA environments

Test across these environments as they become available.

| Environment | Purpose |
|---|---|
| Development | fast feedback for developers |
| Standalone | single-node local event simulation |
| Central | pre-event admin/config source |
| On-site | event-mode authority and local sync |
| Mobile installed app | offline-capable field use |
| Electron wrapper | command center / on-site laptop |

### 14.2 QA phases

#### Phase 1: Development smoke test

Goal: prove the app boots and core screens are reachable.

Checks:

- server starts;
- migrations run;
- Orchid loads;
- seeded users can log in;
- Vue app loads;
- Electron wrapper opens local UI;
- no default secrets in non-development mode.

#### Phase 2: Product workflow QA

Goal: prove MVP workflows.

Checks:

- event application and approval;
- department/team assignment;
- shift eligibility and signup;
- credential eligibility;
- Shift Lead Board;
- field report creation;
- incident management;
- exports.

#### Phase 3: Offline/device QA

Goal: prove Alpha 1 offline behavior.

Checks:

- device trust;
- local encryption active;
- device signing active;
- readiness checklist;
- offline field report with photos;
- offline check-in/check-out/no-show;
- reconnect and sync.

#### Phase 4: On-site/central sync QA

Goal: prove field reliability.

Checks:

- central configures event;
- on-site pairs with central;
- event data syncs to on-site;
- on-site is authoritative during active event window;
- central rejects inappropriate event-scoped edits during active event;
- on-site queues operations when disconnected;
- central receives operations after reconnect;
- conflict queue displays unresolved conflicts;
- unrelated sync continues.

#### Phase 5: Release candidate QA

Goal: decide whether a build is acceptable for field testing.

Checks:

- all critical QA scripts pass;
- no open critical bugs;
- version metadata correct;
- Electron health panel accurate;
- deployment bundle reproducible;
- install docs tested by someone other than the author.

---

## 15. Definition of Ready

A work item is ready for implementation when it has:

```md
- [ ] Clear summary.
- [ ] Source requirement IDs or technical spec sections.
- [ ] Technology baseline reviewed.
- [ ] Dependency impact documented as "none" or proposed baseline update.
- [ ] Work item type.
- [ ] Acceptance criteria.
- [ ] Actor/role definitions.
- [ ] Data model impact.
- [ ] Permission impact.
- [ ] Offline/sync impact, or explicit "none."
- [ ] Testing expectations.
- [ ] Human QA expectation.
- [ ] Out-of-scope list.
```

Do not start ambiguous work unless the first task is explicitly a discovery/design task.

---

## 16. Definition of Done

A work item is done when:

```md
- [ ] Acceptance criteria are met.
- [ ] Source requirements are still accurately represented.
- [ ] Technology baseline was followed, or a human-approved baseline update is included.
- [ ] Automated tests cover the main success path.
- [ ] Automated tests cover important denial/failure paths.
- [ ] Permission-sensitive behavior has policy tests.
- [ ] Audit-sensitive behavior records audit/history.
- [ ] Offline/sync behavior is implemented or explicitly out of scope.
- [ ] API changes update OpenAPI and generated clients.
- [ ] UI changes include screenshots or equivalent review evidence.
- [ ] Human QA script exists or is updated.
- [ ] Human QA has passed, or any failure is documented and accepted.
- [ ] Traceability matrix is updated.
- [ ] Follow-up gaps are filed as issues.
```

---

## 17. Suggested Alpha 1 Implementation Plan

The technical specification already defines an implementation order. This process plan turns that order into reviewable development slices.

### 17.1 Foundation milestone

Goal: establish repo, server, admin, and local development path.

Slices:

1. Monorepo scaffold.
2. Laravel + PostgreSQL + Orchid.
3. Docker Compose development environment.
4. Basic module folder structure.
5. Initial seed data.
6. CI baseline.
7. Developer setup documentation.

Acceptance evidence:

- fresh checkout can boot;
- migrations run;
- Orchid loads;
- tests run in CI;
- seed organization/event/users exist.

### 17.2 On-site shell milestone

Goal: establish the on-site command-center surface early.

Slices:

1. Electron wrapper shell.
2. Local UI launch.
3. Health panel placeholder.
4. Version display.
5. Crash recovery behavior.

Acceptance evidence:

- Electron opens the local Meridian UI;
- health panel shows node name, role, server version, and placeholder sync state.

### 17.3 Authentication and node setup milestone

Goal: establish real identity and node identity.

Slices:

1. Email magic link auth.
2. Google OAuth.
3. Discord OAuth.
4. Node setup flow.
5. Node keys.
6. Node roles.
7. Central pairing token.
8. Basic audit for setup/config changes.

Acceptance evidence:

- verified email resolves to one user;
- no password login exists;
- fresh standalone node generates required keys;
- central/on-site pairing succeeds in a test environment.

### 17.4 Core organization model milestone

Goal: represent the minimum product model needed for operations.

Slices:

1. Organizations.
2. Events.
3. Departments.
4. Teams and default teams.
5. Staff profiles.
6. Memberships.
7. Organization and department statuses.
8. Role/permission scaffolding.
9. Audit service.

Acceptance evidence:

- staff can belong to multiple departments and teams;
- a department membership cannot exist without team membership;
- organization blocking status supersedes department status;
- permission decisions are explainable in tests or UI.

### 17.5 Application and onboarding milestone

Goal: support staff intake and approval.

Slices:

1. Event application.
2. DNS auto-rejection.
3. Application status transitions.
4. Organization approval.
5. Prospective staff record creation.
6. Department assignment.
7. Team assignment.
8. Rescind before team assignment.

Acceptance evidence:

- applicant can submit;
- DNS email is auto-rejected without automatic notice;
- approved applicant becomes Prospective;
- application cannot be rescinded after team assignment.

### 17.6 Shift and credential milestone

Goal: support planned staffing and credential eligibility.

Slices:

1. Shift CRUD.
2. Team-based eligibility.
3. Training completion.
4. Waiver completion.
5. Shift signup.
6. Capacity rules.
7. Overlap warnings.
8. Schedule lock/cutoff.
9. Credential eligibility calculation.
10. Credential blocked/revoked states.

Acceptance evidence:

- eligible staff members can sign up immediately;
- missing training/waiver blocks signup;
- full shift blocks self-signup;
- overlap warns by default;
- removing all shifts blocks credential;
- credential revocation removes future shifts where possible and preserves completed hours.

### 17.7 Device trust and offline readiness milestone

Goal: enable offline-capable field operations safely.

Slices:

1. Vue + Capacitor app shell.
2. Device identity.
3. Device trust.
4. Device signing.
5. Local encryption.
6. Readiness checklist.
7. PowerSync service integration.

Acceptance evidence:

- trusted session lasts for defined window;
- local encryption is active;
- device signing is active;
- event mode fails closed if required trust/encryption/signing is unavailable.

### 17.8 Field report milestone

Goal: support immutable offline field reports with photos.

Slices:

1. Field report text creation.
2. Offline local submitted state.
3. Server acceptance.
4. FRA numbering.
5. Author visibility.
6. IC visibility.
7. Append-only additions.
8. Photo attachment limits and processing.
9. Photo sync.
10. Incident link preparation.

Acceptance evidence:

- offline field report appears submitted immediately;
- original body is immutable;
- author can append;
- only added append content syncs to associated incidents later;
- IC roles can view all event field reports;
- non-IC department leads cannot view other users' field reports by default.

### 17.9 Attendance and Shift Lead Board milestone

Goal: support field check-in/out/no-show and actual hours.

Slices:

1. Shift Lead Board roster.
2. Check-in.
3. Check-out.
4. No-show.
5. Actual hours creation.
6. Correct hours during grace period.
7. Add eligible unscheduled staff.
8. Deployment/location current assignment.
9. Equipment checkout/check-in.
10. Offline attendance operations.

Acceptance evidence:

- shift lead can check in/out assigned staff members;
- check-out creates actual hours;
- no free-floating hours exist;
- missing required training/waiver blocks unscheduled addition;
- duplicate check-in is idempotent;
- overlapping check-ins warn.

### 17.10 Incident milestone

Goal: support online-only incident management for IC roles.

Slices:

1. IC department selection.
2. IC role grants to teams.
3. Incident list.
4. Incident creation.
5. Incident timeline notes.
6. Status transitions.
7. Title edits with timeline entries.
8. Field report link/unlink.
9. PDF print by IC leads.
10. Incident visibility policies.

Acceptance evidence:

- only IC roles can view incidents;
- `ic_viewer` cannot modify incidents;
- `ic_operator` can modify but cannot download field report photos;
- `ic_lead` can print/export PDF and download permitted photos;
- incident changes preserve history.

### 17.11 Node sync milestone

Goal: prove central/on-site operation.

Slices:

1. Operation schema.
2. Operation signing.
3. Operation receive/store/apply path.
4. Bidirectional sync.
5. Event authority rules.
6. Conflict queue.
7. Conflict resolver.
8. Electron health sync warnings.

Acceptance evidence:

- on-site primary is authoritative during active event window;
- central refuses inappropriate event-scoped writes;
- node operations are idempotent;
- unresolved conflicts do not block unrelated sync;
- severe conflicts appear in Electron health.

### 17.12 Reporting and release milestone

Goal: produce usable outputs and distributable Alpha 1 builds.

Slices:

1. Credential eligibility export.
2. Shift roster export.
3. Staff contact export.
4. Hours worked export.
5. Credits earned export.
6. CSV/spreadsheet import for users/teams/shifts/assignments.
7. Versioned builds.
8. Deployment config bundle.
9. Release candidate QA.

Acceptance evidence:

- organizer exports exclude emergency contacts;
- department contact exports may include emergency contacts for department staff;
- credits export includes calculation basis;
- build versions are visible;
- install/deployment can be followed by a human tester.

---

## 18. AI-Assisted Development Rules

Meridian can use AI-assisted implementation, but AI output must be reviewed as untrusted work.

### 18.1 Prompting rule

Every AI implementation prompt should include:

- instruction to read `docs/meridian-technology-baseline.md` before coding;
- source requirement IDs;
- relevant technical spec sections;
- existing file paths;
- acceptance criteria;
- out-of-scope list;
- expected tests;
- instruction not to silently broaden scope.
- instruction to stop and ask before adding, replacing, or upgrading dependencies or approved technology choices outside the baseline.

### 18.2 AI output review

AI-generated code must be checked for:

- invented requirements;
- skipped authorization;
- missing audit entries;
- destructive deletes;
- overbroad permissions;
- non-idempotent sync behavior;
- hardcoded organization assumptions;
- missing tests;
- mismatched OpenAPI/client types.

### 18.3 AI change limit

Prefer asking AI to implement one slice at a time.

Do not ask AI to implement "the whole MVP" or "all sync" in one pass.

### 18.4 Evidence requirement

AI-assisted PRs still require:

- human-readable implementation notes;
- tests;
- manual QA steps;
- reviewer verification.

---

## 19. Change Control

### 19.1 Requirements changes

A requirements change is needed when product behavior changes.

Examples:

- allowing organizers to see all emergency contacts;
- allowing staff self-reported hours;
- making incidents available outside IC;
- changing field reports from immutable to editable.

Requirements changes should update the requirements document and traceability matrix before or alongside implementation.

### 19.2 Technical decision changes

A technical decision change is needed when architecture changes.

Examples:

- replacing PowerSync;
- switching away from Laravel/Orchid;
- adding a plugin platform to Alpha 1;
- changing node sync from operation sync to raw database replication.

Create an Architecture Decision Record under:

```text
docs/adr/
```

Suggested format:

```md
# ADR-0001: Title

## Status

Proposed / Accepted / Rejected / Superseded

## Context

## Decision

## Consequences

## Requirements affected

## Technical spec sections affected
```

### 19.3 Scope control

Every issue and PR should distinguish:

- MVP/Alpha 1 required;
- useful but deferred;
- explicit non-goal.

Do not implement deferred features accidentally.

---

## 20. Release Readiness Checklist

A release candidate should not be considered ready until this checklist is reviewed.

```md
## Release Candidate Checklist

Build/version:
- [ ] Server version set.
- [ ] Mobile version set.
- [ ] Electron version set.
- [ ] Config schema version set.
- [ ] Docker image tags set.

Security/event-mode:
- [ ] Dev auth disabled outside development.
- [ ] Default secrets refused or regenerated.
- [ ] HTTPS validation passes in event mode.
- [ ] PowerSync availability validation passes in event mode.
- [ ] Local encryption validation passes.
- [ ] Device signing validation passes.

Core workflows:
- [ ] Application approval works.
- [ ] Department/team assignment works.
- [ ] Shift signup eligibility works.
- [ ] Credential eligibility export works.
- [ ] Shift Lead Board works.
- [ ] Field report offline flow works.
- [ ] Incident online flow works.
- [ ] Attendance creates hours.
- [ ] Credits calculate after freeze.
- [ ] Required exports work.

Sync:
- [ ] Central/on-site pairing works.
- [ ] Event data syncs to on-site.
- [ ] On-site operations sync back to central.
- [ ] Conflict queue works.
- [ ] Electron health shows sync state.

Human QA:
- [ ] Critical QA scripts passed.
- [ ] Failed QA scripts documented.
- [ ] Known critical issues triaged.
- [ ] Install/deployment docs tested by a second person.
```

---

## 21. After the Process Baseline

The milestone-0 process baseline is represented by:

- repository topology placeholders under `apps/`, `packages/`, and `deploy/`;
- process validators under `scripts/process/`;
- issue and PR templates under `.github/`;
- CI workflows under `.github/workflows/`;
- `docs/process/traceability-matrix.md`;
- `docs/qa/QA-BOOT-01-fresh-checkout-boots.md`;
- `docs/process/meridian-alpha-1-development-plan.md`;
- `docs/process/codex-alpha-task-prompt.md`.

After milestone 0, select the next PR-sized task from `docs/process/meridian-alpha-1-development-plan.md`. For Codex or another LLM, use `docs/process/codex-alpha-task-prompt.md` and provide exactly one task ID plus any course-correction notes.

Do not ask an implementation agent to infer the next slice from this document alone. The Alpha 1 development plan is the current task sequence.
