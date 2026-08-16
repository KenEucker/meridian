# Meridian Alpha 1 Development Plan

**Project:** Meridian  
**Document type:** Milestone and task plan  
**Draft:** 0.1  
**Planning scope:** Alpha 1 / October MVP scope already defined in repository documents  

---

## 1. Purpose

This plan divides Meridian Alpha 1 development into linear milestones and PR-sized tasks.

The plan is designed so that:

- each task can become one small pull request;
- each task is narrow enough to implement and test within a day;
- QA can move through the product in a human-readable order;
- every task references source documents in `docs/`;
- no task intentionally adds scope beyond the existing requirements, technical, data/API, UI, QA, issue, and process documents.

This plan does not replace `docs/process/meridian-development-process.md`. It applies that process to a concrete Alpha 1 build sequence.

---

## 2. Governing Documents

Work must stay inside the scope described by these documents.

### Product, Technical, and Data/API

- `docs/meridian-requirements-document.md`
- `docs/meridian-technical-spec.md`
- `docs/meridian-technology-baseline.md`
- `docs/db/meridian-data-model-and-api-specification.md`

### UI and UX

- `docs/ui/meridian-ui-operating-guide.md`
- `docs/ui/meridian-style-guide.md`
- `docs/ui/meridian-ui-implementation-contract.md`
- `docs/ui/meridian-screen-surface-specification.md`
- `docs/ui/meridian-component-library-specification.md`
- `docs/ui/meridian-dashboard-widget-specification.md`
- `docs/ui/meridian-ims-surface-specification.md`
- `docs/ui/meridian-briefing-surface-specification.md`
- `docs/ui/meridian-kiosk-and-field-hardware-ux-guide.md`
- `docs/ui/meridian-accessibility-checklist.md`

### Process, QA, and Existing Work Items

- `docs/process/meridian-development-process.md`
- `docs/process/traceability-matrix.md`
- `docs/process/github-branch-protection.md`
- `docs/process/conventional-commits.md`
- `docs/qa/README.md`
- `docs/qa/QA-BOOT-01-fresh-checkout-boots.md`
- `docs/issues/001-monorepo-scaffold.md`
- `docs/issues/002-laravel-postgresql-orchid-boot-path.md`
- `docs/issues/003-ci-baseline.md`

---

## 3. Planning Rules

1. One task should normally become one PR.
2. A task should be testable within one day by a developer and reviewer.
3. A task must include traceability in the issue and PR body.
4. A task must include automated tests where code exists.
5. A task must include or update human QA steps when it changes a user-visible workflow.
6. A task must update `docs/process/traceability-matrix.md` when it closes or materially advances a requirement/spec section.
7. A task must not implement deferred or explicit non-goal scope from the requirements, technical spec, or data/API spec.
8. A task that discovers missing requirements must stop at discovery/design unless the source document is updated through the normal process.
9. A task must follow `docs/meridian-technology-baseline.md`; proposed dependencies or technology/version changes outside that baseline must be decided by a human and added to the baseline before implementation.

---

## 4. PR and QA Flow

Each implementation PR should follow `docs/process/meridian-development-process.md` and include:

- traceability to requirement IDs and technical/data/UI sections;
- acceptance criteria;
- implementation summary;
- automated test evidence;
- human QA instructions;
- screenshots or screen recordings for UI changes;
- traceability matrix update when applicable.

Each milestone has a QA gate. QA gates should be run in order because later milestones depend on earlier product surfaces, seed data, permissions, and infrastructure.

---

## 5. Milestones

### Milestone 0: Repository and Process Baseline

**Goal:** Establish the repository, process checks, and PR discipline before product behavior.

**Primary source docs:** `docs/issues/001-monorepo-scaffold.md`, `docs/issues/003-ci-baseline.md`, `docs/process/meridian-development-process.md`, `docs/process/github-branch-protection.md`, `docs/process/conventional-commits.md`, `docs/qa/QA-BOOT-01-fresh-checkout-boots.md`, Technical spec sections 4, 26, and 29.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M0.1 Monorepo scaffold | Create documented `apps/`, `packages/`, and `deploy/` topology without product behavior. | Issue 001; Technical spec section 4 | Process checks; `QA-BOOT-01` |
| M0.2 CI process checks | Add PR/main checks for process validators and adaptive Composer/Node checks. | Issue 003; branch protection doc | GitHub Actions run; PR body validator |
| M0.3 Branch protection setup note | Document required GitHub branch protection status checks after CI exists. | Branch protection doc | Human confirmation only |
| M0.4 Developer boot README update | Update README with current boot/check commands for the empty scaffold. | README; process doc | Fresh checkout follows README |

**QA gate:** A human can clone the repo, run process checks, and understand how future product services will be added.

---

### Milestone 1: Server/Admin Boot Path

**Goal:** Prove Laravel, PostgreSQL, Orchid, and local development boot before domain behavior.

**Primary source docs:** `docs/issues/002-laravel-postgresql-orchid-boot-path.md`, Technical spec sections 3.1, 5, 22, 26, and 29; data/API spec sections 3 and 17.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M1.1 Laravel scaffold | Add Laravel app under the monorepo location with no Meridian workflows. | Issue 002; Technical spec sections 3.1, 5.1 | Laravel default tests; Composer validate |
| M1.2 PostgreSQL development config | Configure PostgreSQL for local development and document connection setup. | Technical spec section 5.1; data/API section 3.1 | Migration command succeeds |
| M1.3 Orchid install | Install Orchid and expose a development admin route. | Technical spec sections 5.1, 22.1 | Orchid route loads |
| M1.4 Server health endpoint | Add minimal health/version endpoint for later Electron health use. | Technical spec sections 25.3, 26.3 | Feature test for health payload |
| M1.5 Server boot QA update | Update `QA-BOOT-01` with actual server boot steps. | QA README; QA-BOOT-01 | Human follows fresh checkout boot |

**QA gate:** A reviewer can start the server stack, run migrations, and reach Orchid.

---

### Milestone 2: Shared Client and Platform Shells

**Goal:** Establish the shared Vue client plus Capacitor and Electron packaging wrappers before field workflows.

**Primary source docs:** Technical spec sections 3.2, 3.3, 9, 12, 13, 25, 26, and 29; UI implementation contract sections 3, 5, 6, 12, and 18; kiosk guide.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M2.1 Vue app shell | Add shared client app shell with routing placeholder and no domain workflows. | Technical spec section 3.2; UI implementation contract section 3 | App builds; smoke test loads |
| M2.2 Capacitor baseline | Add Capacitor packaging configuration and documented local run path. | Technical spec section 3.3 | Build/config validation |
| M2.3 Electron wrapper shell | Add Electron app that opens the packaged shared client UI. | Technical spec sections 3.4, 25.1, 25.2 | Desktop app opens configured URL |
| M2.4 Electron health placeholder | Display local node/server version placeholders from server health. | Technical spec section 25.3; kiosk guide section 12 | Manual desktop QA |
| M2.5 Shared UI tokens baseline | Add semantic token skeleton shared by field/admin surfaces. | Style guide; UI implementation contract section 10; component spec section 3 | Visual smoke test |
| M2.6 Fixed UI modes | Build Admin, Field, and Kiosk artifacts from the shared Vue client; lock Server/Mobile/Desktop packaging to their fixed UI modes; document the mode-by-surface matrix. | Requirements 7.18; Technical spec section 3.5; UI implementation contract sections 5 and 5A | Client mode config tests; mobile/desktop/server artifact path tests |

**QA gate:** A reviewer can open Meridian Admin through the web server, Meridian
Field through the mobile shell, and Meridian Kiosk through the Electron shell,
even though many product workflows are still placeholders.

---

### Milestone 3: Authentication, Users, Devices, and Node Setup

**Goal:** Establish real identity, node identity, trusted devices, and setup safeguards.

**Primary source docs:** Technical spec sections 7, 8, 11, 12, 13, 14, 22, 23, 26, and 29; data/API sections 10.3, 12, 13, and 14; UI implementation contract sections 12 and 18.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M3.1 User/auth identity schema | Add `users` and `auth_identities` models/migrations. | Data/API section 10.3 | Migration/model tests |
| M3.2 Email magic-link auth | Implement verified-email magic-link login path. | Technical spec section 11.1 | Auth feature tests |
| M3.3 Google OAuth login | Add Google OAuth with verified email requirement. | Technical spec section 11.1 | Auth provider test/fake |
| M3.4 Discord OAuth login | Add Discord OAuth with verified email requirement. | Technical spec section 11.1 | Auth provider test/fake |
| M3.5 Node model and setup screen | Add node records, first-run setup, and node role selection. | Technical spec section 7; data/API section 13.1 | Setup feature test |
| M3.6 Node keys and config values | Generate node keys and expose config source in God mode placeholder. | Technical spec sections 7.3, 7.4, 22.4 | Config tests; audit expectation |
| M3.7 Device identity and trust records | Add devices/device trusts with six-week trust duration. | Technical spec section 12; data/API section 12 | Model/policy tests |
| M3.8 Shared workstation records | Add shared workstation and login code records without full kiosk workflow. | Technical spec section 13; data/API section 12.3 | Model tests |

**QA gate:** A reviewer can create a development node, log in through at least one configured provider path/fake, and see trusted device/node state.

---

### Milestone 4: Core Organization, Event, Department, Team, and Permission Model

**Goal:** Build the canonical organizational model that later workflows depend on.

**Primary source docs:** Requirements sections 2, 3.1-3.7, 4, 5.1, 7.1-7.5; Technical spec sections 5.2, 15, 22, 23; data/API sections 6, 8, 10.1-10.7, 15.1, 17; UI docs for status, component, screen, and accessibility rules.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M4.1 Organizations | Add organization model, migration, Orchid list/detail scaffold, including default IC and organizers department settings. | ORG-001, ORG-002, ORG-005, ORG-007; data/API 10.1 | Model/feature tests |
| M4.2 Events | Add event model with timezone and active window fields. | Requirements 3.2; data/API 10.2 | Model tests |
| M4.3 Departments | Add department model and organization relationship. | ORG-002; requirements 3.3 | Model tests |
| M4.4 Teams and default team | Add teams, default team creation, archive behavior. | TEAM-001 through TEAM-006; data/API 10.6 | Team domain tests |
| M4.5 Staff | Add staff profile and staff organization status records. | VOL-001 through VOL-005; data/API 10.4 | Model/validation tests |
| M4.6 Department/team memberships | Enforce department membership requiring at least one team. | VOL-006; TEAM-002; data/API 10.6 | Domain tests |
| M4.7 Status model | Add organization and department status transitions. | STAT-001 through STAT-011 | Status tests |
| M4.8 Permission catalog | Add permission roles, permissions, role permissions, and team grants, including organizer grants through the configured organizers department. | TEAM-009, TEAM-010; ORG-015, ORG-016; data/API 10.7 | Policy tests |
| M4.9 Audit service baseline | Add reusable audit event write path. | Requirements 2.4; technical spec section 23; data/API 8, 14.1 | Audit unit tests |
| M4.10 Seed personas | Add development seed organization, event, departments, teams, and personas. | Development process section 13.3 | Seed smoke test |

**QA gate:** Seeded users, organization, event, departments, teams,
memberships, statuses, and permission scaffolds are visible in admin surfaces
and covered by tests.

---

### Milestone 5: Application and Onboarding

**Goal:** Support event application, approval, organization status, department assignment, and team assignment.

**Primary source docs:** Requirements sections 3.10, 5.2-5.4, 6.3, 7.4; Technical spec sections 15, 22, 23; data/API sections 5.2, 10.5, 10.6; UI implementation contract sections 12.1, 12.6, 20.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M5.1 Public application form | Add event application form and submitted state. | APP-001 through APP-004 | Feature/UI tests |
| M5.2 Application review list | Add organizer application review list/detail. | APP-003, APP-005 | Feature tests |
| M5.3 Department interest | Add optional department interest on application submit and review surfaces. | APP-011; requirements 3.10, 5.2; data/API 10.5; UI contract 12.10 | Feature/domain/UI tests: submit with no interest; submit with multiple unordered interests; reject invalid/archived/non-event/non-org departments; hide field when no eligible departments; organizer/Staff Coordinator display and list filter; department lead read-only visibility for interested applications; verify no membership/assignment/access side effects |
| M5.4 DNS auto-rejection | Reject DNS email applications without automatic notice. | APP-003; requirements 3.5 | Domain tests |
| M5.5 Approve application | Approval creates Prospective staff organization status. | APP-005, APP-006 | Feature/domain tests |
| M5.6 Reject/defer/withdraw | Implement remaining application status transitions. | APP-003, APP-004 | Status tests |
| M5.7 Assign to department | Organizer/lead assigns approved staff to department. | APP-007; requirements 5.3 | Policy/domain tests |
| M5.8 Assign to team | Department/team lead assigns staff to team. | Requirements 5.4; TEAM-008, TEAM-009 | Policy/domain tests |
| M5.9 Rescind before team assignment | Allow rescind before team assignment and block after team assignment. | APP-008 through APP-010 | Domain tests |
| M5.10 Onboarding QA script | Add `QA-APP-01-event-application-approval.md`. | QA README; development process | Human QA script validation |

**QA gate:** A human can apply, approve, assign department/team, and verify rescind rules in order.

---

### Milestone 6: Policies, Procedures, Fragments, and Acknowledgments

**Goal:** Deliver the policy/procedure vertical slice early because it affects signup, training, sync, exports, and UI.

**Primary source docs:** Requirements sections 2.11, 3.24-3.27, 5.13-5.16, 7.15; Technical spec section 21; data/API section 11; UI implementation contract sections 11.15-11.17, 12, 17, 20; component/accessibility docs.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M6.1 Policy document model | Add policy documents with state, scope, versions, Markdown source. | POL-001, POL-003, POL-004, POL-032, POL-034; data/API 11.2 | Model tests |
| M6.2 Procedure document model | Add procedure documents mirroring policy rules. | POL-002, POL-033; data/API 11.3 | Model tests |
| M6.3 Fragment model | Add document fragments and auto-incrementing version. | POL-014 through POL-018, POL-038, POL-039; data/API 11.5 | Model/domain tests |
| M6.4 Fragment reference parser | Store and validate fragment references; block broken references. | POL-019 through POL-022, POL-036, POL-037; data/API 11.6 | Parser/domain tests |
| M6.5 Rendered document viewer | Render sanitized Markdown with fragments inline. | POL-022, POL-034, POL-035, POL-040, POL-041 | UI/component tests |
| M6.6 Fragment-driven version bump | Bump published referencing document fragment revision when fragment changes. | POL-039 through POL-042; data/API 11.7 | Domain/job tests |
| M6.7 Orchid document admin | Add Orchid screens for policies, procedures, fragments, and preview. | Technical spec 21.11, 22.2 | Feature/UI tests |
| M6.8 Reference impact warning | Warn before fragment edits that affect published documents. | Technical spec 21.11; UI contract 17.3 | UI test/manual QA |
| M6.9 Acknowledgment requirements | Add organization/department scoped signup/training requirements. | POL-023 through POL-027, POL-046, POL-047 | Domain tests |
| M6.10 Online acknowledgment action | Record document acknowledgment with document version while connected. | POL-043 through POL-045; technical spec 21.9 | Feature tests |
| M6.11 Markdown/PDF document export | Export document as Markdown/PDF with fragments inline. | POL-028 through POL-031; data/API 11.11 | Export tests/sample |
| M6.12 Policy QA script | Add `QA-POL-01-policy-procedure-fragment-acknowledgment.md`. | QA README; UI accessibility checklist | Human QA script |

**QA gate:** A lead can create a fragment, create policy/procedure documents, publish, view rendered content, acknowledge during signup/training, and export Markdown/PDF.

---

### Milestone 7: Shifts, Training, Waivers, and Credentials

**Goal:** Build planned staffing, eligibility, and event credential state before field operations.

**Primary source docs:** Requirements sections 3.8-3.16, 5.5-5.6, 7.6-7.8; Technical spec sections 15, 20, 22, 23; data/API sections 10.8-10.11, 15.2; UI implementation contract sections 9, 12, 13, 20.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M7.1 Training records | Add trainings, prerequisites, completions, expiration fields. | TRAIN-001 through TRAIN-006 | Model/domain tests |
| M7.2 Waiver records | Add waivers and completion tracking without storing signed contents. | WAIVER-001 through WAIVER-004 | Model/domain tests |
| M7.3 Shift model | Add shifts with event, department, time, title/function, team eligibility, capacity. | SHIFT-001 through SHIFT-004, SHIFT-007 | Model tests |
| M7.4 Shift requirements | Add shift training/waiver requirements and signup date fields. | SHIFT-005, SHIFT-006, SHIFT-008 | Domain tests |
| M7.5 Shift signup command | Eligible staff can sign up immediately. | SHIFT-011; requirements 3.12 | Feature/domain tests |
| M7.6 Eligibility denials | Block missing training/waiver, ineligible department status, and full capacity. | TRAIN-008, WAIVER-005, SHIFT-012, SHIFT-016 | Domain/policy tests |
| M7.7 Overlap warning | Warn on overlap by default and allow elevated assignment. | SHIFT-014, SHIFT-015 | Domain/UI tests |
| M7.8 Schedule lock rules | Add configurable cutoff/lock behavior. | SHIFT-009, SHIFT-013 | Domain tests |
| M7.9 Credential eligibility | Calculate eligible/blocked/revoked credential state. | CRED-001 through CRED-010 | Domain tests |
| M7.10 Credential revocation | Restrict revocation and preserve completed shifts/hours. | CRED-011 through CRED-014 | Policy/domain tests |
| M7.11 Shift/credential QA scripts | Add `QA-SHIFT-01-shift-signup-eligibility.md` and `QA-CRED-01-credential-eligibility.md`. | QA README | Human QA scripts |

**QA gate:** A reviewer can configure training/waiver/shift eligibility, sign up an eligible staff member, observe denials, and verify credential state.

---

### Milestone 8: PowerSync, Device Readiness, and Offline Foundations

**Goal:** Make offline-capable field use safe before enabling offline writes.

**Primary source docs:** Technical spec sections 9, 12, 14, 26.2, 27.1, 29; data/API sections 7, 12, 15.4; UI implementation contract sections 16 and 18; kiosk guide section 10.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M8.1 PowerSync service config | Add PowerSync service/deploy configuration and server integration stub. | Technical spec 9.1, 9.2 | Config/test smoke |
| M8.2 Device cache projections | Define initial authorized device cache projections. | Technical spec 9.3; data/API 7.1 | Projection tests |
| M8.3 Local encryption readiness | Add readiness check for local encryption availability. | Technical spec 12.3, 26.2 | Readiness tests |
| M8.4 Device signing readiness | Add readiness check for device signing availability. | Technical spec 12.4, 26.2 | Readiness tests |
| M8.5 Readiness UI | Add shared client readiness checklist surface. | Technical spec 14; UI implementation contract 16 | Component/UI tests |
| M8.6 Offline state UI | Add shared offline/sync status display for shared client surfaces. | UI implementation contract 11.13, 16 | UI tests/manual QA |
| M8.7 Event-mode fail-closed checks | Block event mode when HTTPS, PowerSync, encryption, or signing fails. | Technical spec 8.6, 26.2 | Feature tests |
| M8.8 Offline readiness QA script | Add `QA-READY-01-device-readiness.md`. | QA README | Human QA script |

**QA gate:** A trusted device shows readiness state and event mode honestly fails closed when required capabilities are unavailable.

---

### Milestone 9: Field Reports and Attachments

**Goal:** Support immutable offline field reports with photo attachments and restricted visibility.

**Primary source docs:** Requirements sections 3.20, 3.21A, 5.10, 7.11, 7.12A; Technical spec sections 17, 18, 19.9, 19.10, 23, 27.1, 28; data/API sections 10.15, 10.16, 10.17, 15.3, 15.4A; IMS/UI docs for field report surfaces.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M9.1 Field report model | Add immutable field report records and author visibility. | FR-001 through FR-007; data/API 10.15 | Model/policy tests |
| M9.2 Offline create operation | Device creates UUID and submitted local state offline. | Technical spec 17.2; data/API 7.2 | Local/sync tests |
| M9.3 Server acceptance and FRA numbering | Server accepts field reports and assigns FRA numbers. | Technical spec 17.5; data/API 4.5 | Feature/idempotency tests |
| M9.4 Field report author list/detail | Author can view own submitted reports. | FR-004; UI implementation contract 12.3 | UI tests |
| M9.5 IC field report visibility | IC roles can view event field reports; non-IC leads cannot by default. | FR-005, FR-006; technical spec 17.6 | Policy tests |
| M9.6 Append-only additions | Original author can append; original body remains immutable. | FR-007 through FR-009, FR-013 | Domain tests |
| M9.6A Field report Name References | Parse submitted Field Report text and appends into a rebuildable derived Name Reference index without autocomplete, suggestions, notifications, or extra visibility. | NR-001 through NR-007, NR-011 through NR-014; technical spec 17.7 | Parser/search/policy tests |
| M9.7 Photo capture limits | Enforce max 2 images, dimensions, size, no GIFs, EXIF strip. | Technical spec 18.3; data/API 10.17 | Processing tests |
| M9.7A Immutable Field Report titles | Add required immutable Field Report titles end-to-end: schema, offline create/sync payload, server acceptance, author and permitted-reviewer UI, and incident-copy contract support. | FR-003, FR-007, FR-012; technical spec 17.3, 17.4, 24.1; data/API 10.15; UI contract 14 | Schema/domain/offline/UI tests |
| M9.8 Photo sync and storage | Sync photos up, store server-side, restrict downloads. | Technical spec 18.5, 18.6 | Feature/security tests |
| M9.9 Field report QA script | Add `QA-FR-01-offline-field-report.md`. | QA README | Human QA script |

**M9.7A acceptance criteria:**

- required trimmed title of 1–200 characters; duplicate titles allowed within an event;
- title and original body become immutable at submission;
- appends cannot add or alter a title;
- title works in offline-created and server-accepted reports;
- author and IC list/detail surfaces show the title;
- Name Reference parsing remains body/append-body only;
- incident copies use `Field Report: <title>` followed by body;
- schema/domain/offline/UI tests cover validation, immutability, and display.

**QA gate:** A reviewer can submit a field report offline with a title, reconnect, see FRA assignment, verify title and body immutability, confirm IC-only visibility, and verify Name References remain plain text for authors while parsing into permitted derived search/display behavior.

---

### Milestone 10: Department Operations, Attendance, Hours, Deployments, and Equipment

**Goal:** Support staff-mediated field operations and actual work records.

**Primary source docs:** Requirements sections 3.13-3.15, 3.18-3.19, 5.7-5.9, 7.9-7.10, 7.13; Technical spec sections 20, 23, 27.1; data/API sections 10.10, 10.12-10.14, 15.2; UI implementation contract sections 12.5, 13.2-13.3, 16.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M10.1 Department overview | Lead situational-awareness surface with switchable shift, exceptions-first ordering, checked-in staff, assignments, and compact equipment/deployment summaries. | SLB-001, SLB-002, SLB-020 | UI/feature tests |
| M10.1A Department presence | Track department-specific on-site/off-site status for eligible department staff. | SLB-015 through SLB-018; data/API 10.10A | Domain/policy tests |
| M10.1B Department ops UX contract reset | Align requirements, UI/API contracts, milestone language, and QA with Department Overview, Logistics Window, Operations Center, and Planning Table workflows. | SLB-001 through SLB-022; UI contract 12.4-12.5 | Doc/traceability checks |
| M10.2 Check-in operation | Department Logistics checks staff into a shift with operation record after the staff member is on-site. | SLB-003; technical spec 20.2 | Domain/policy tests |
| M10.3 Check-out and hours | Check-out creates actual hours with actual start/end. | SLB-004 through SLB-006; HOURS-001 through HOURS-006 | Domain tests |
| M10.4 No-show operation | Authorized attendance manager marks no-show idempotently. | Technical spec 20.2 | Domain tests |
| M10.5 Offline attendance queue | Check-in/check-out/no-show work offline and sync later. | Technical spec 20.1; data/API 7.2 | Sync/idempotency tests |
| M10.6 Hours correction grace period | Allow corrections during grace period and freeze later. | HOURS-007, HOURS-008 | Domain/audit tests |
| M10.7 On-site staff shift addition | Logistics adds an on-site eligible unscheduled staff member during operations from the staff workspace. | SLB-008; SHIFT-016 | Domain/UI tests |
| M10.8 Operations Center | Capability-composed Operations Center with mandatory deployments module and capability-gated incident/equipment overview slots. | SLB-009, SLB-010, SLB-014, SLB-022; data/API 10.14 | Domain/UI/policy tests |
| M10.9 Logistics equipment checkout/check-in | Manual equipment workflows and states from the staff workspace, including equipment issued outside a shift. | SLB-011, SLB-012; EQUIP-001 through EQUIP-005 | Domain/UI tests |
| M10.9A Planning table | Identity-free Planning Table comparing plan versus actual aggregates by shift/team window. | SLB-019, SLB-020; UI contract 12.5 | UI/aggregate tests |
| M10.9B Logistics search workspace | Department-scoped offline Logistics search and staff operational workspace shell. | SLB-003, SLB-021; UI contract 12.5 | UI/search/offline tests |
| M10.10 Field report/incident modules | Add Field Report and incident modules/shortcuts only where the actor already has permission; Operations Center shell grants nothing. | SLB-013, SLB-014, SLB-022 | UI/policy tests |
| M10.11 Attendance QA script | Add `QA-SLB-01-checkin-checkout-hours.md`. | QA README | Human QA script |

**QA gate:** A reviewer can use Department Overview to switch shifts and see
exceptions/checked-in/assignments, use Logistics Window search to mark a staff
member on-site/off-site, check them in/out with equipment handoff, add an
on-site eligible staff member to a shift, assign deployments from the Operations
Center, and review identity-free Planning Table aggregates without seeing
individual signup or team-member identities.

---

### Milestone 11: Incident Management and MVP Product UI Gap Closure

**Goal:** Support online-only incident management for the configured Incident Command Department, and close MVP-critical product UI gaps discovered after the foundational organization/department/team/document/training milestones.

The MVP product UI gap-closure tasks in this milestone are forward-scheduled work from the current development point; they do not reopen earlier completed milestone scopes.

**Primary source docs:** Requirements sections 3.1-3.12, 3.20-3.23, 3.21A, 5, 7.6, 7.8-7.13, 7.12A; Technical spec sections 15, 16, 19, 21, 22, 23, 24, 27.1, 28; data/API sections 6.5, 10.5-10.7, 10.9, 10.15-10.17, 11, 15.3, 15.4, 15.4A; IMS surface specification; UI implementation contract sections 12, 15, and 17.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M11.1 IC department selection | Configure event IC department. | INC-002; requirements 3.23 | Model/feature tests |
| M11.2 IC team grants | Grant `ic_viewer`, `ic_operator`, `ic_lead` through teams. | Technical spec 16; data/API 6.5 | Policy tests |
| M11.3 Incident model and numbers | Add incidents with IMS number assignment. | INC-001, INC-003, INC-004 | Model/domain tests |
| M11.4 Online incident create | IC operator/lead creates incident only while connected. | Technical spec 19.2, 19.4 | Feature/policy tests |
| M11.5 Incident list/detail | Build restricted incident list/detail surfaces. | IMS spec sections 5-7 | UI/policy tests |
| M11.6 Incident timeline notes | Add append-only incident timeline entries. | INC-007, INC-014; data/API 10.16 | Domain tests |
| M11.6A Incident Name References | Extract Name References from incident notes and attached Field Reports, render chips near tags, and wire chip clicks to normal permission-filtered search. | NR-001 through NR-014; technical spec 19.9, 19.10 | Parser/search/UI/policy tests |
| M11.7 Incident create/edit autosave screen | Add the unified online-only incident create/edit form: opening create starts with a blank edit form, the first persisted field change creates the incident and assigns its IMS number, and permitted IC operators/leads can continue editing current incident fields regardless of status. Status affects filtering only. | INC-001, INC-003, INC-004, INC-010 through INC-012; technical spec 19.2; IMS spec section 8; UI operating guide 19.3; data/API 10.16 | Domain/UI/autosave/policy tests |
| M11.7A Incident priority, types, and responders | Add the missing IMS create/edit fields for priority label, incident types multi-select, and involved Rangers/responders multi-select, including storage, current-field autosave, detail/list display where appropriate, concise timeline/history entries, and IC-only policy enforcement. Priority, state, and type labels must remain visually and textually distinct. | INC-006, INC-007, INC-010 through INC-014; IMS spec section 8; UI contract sections 9.8, 9.9, 15.1; UI operating guide 19.1-19.4; data/API 10.16 | Schema/domain/UI/autosave/policy tests |
| M11.7B Linked incidents | Add linked-incident create/edit support with same-event link/unlink commands, duplicate/self/cross-event protections, detail/edit display, concise timeline/history entries, and IC-only policy enforcement. Linked incidents remain preserved history and are not incident merges. | INC-007 through INC-009, INC-014; IMS spec section 8; UI contract 15.1; UI operating guide 19.2-19.4; data/API 10.16 | Schema/domain/UI/policy tests |
| M11.8 Link/unlink field report | Copy field report content into incident notes as `Field Report: <title>`, author, and body; strike relationship on removal; expose the IC Field Reports list and list cross-links needed to manage attachments from Incident screens. | FR-011 through FR-014; INC-014 | Domain/UI tests |
| M11.9 Incident attachments strike | Allow incident attachments to be stricken, not deleted. | INC-013; data/API 10.17 | Domain/security tests |
| M11.10 Incident PDF print | IC leads print incidents to PDF. | INC-015 | Export test/sample |
| M11.11 Incident QA script | Add `QA-INC-01-incident-management.md`. | QA README | Human QA script |
| M11.12 Admin department management | Add normal Meridian Admin product UI and API/domain actions for organizers to create, edit, archive/restore, and list organization departments outside Orchid/God Mode. This is a Milestone 11 product-admin backfill, not a retroactive Milestone 4 task. | ORG-002; Technical spec sections 15.2, 22.1; data/API 10.6; UI contract 12.6 `organizer.departments` | API/domain/UI tests; organizer QA |
| M11.13 Department self-administration | Add normal Meridian Admin product UI and API/domain actions for department administration/department leads to maintain permitted department details and manage teams, including default-team rename and non-default team archive/restore, outside Orchid/God Mode. The Admin page must also render a scoped team-lead view with only led teams and assigned staff, while staff-only members have no Admin entry point. This is a Milestone 11 product-admin backfill, not a retroactive Milestone 4 task. | TEAM-001 through TEAM-006, TEAM-009; Technical spec sections 15.2, 22.1; data/API 10.6, 10.7; UI contract 12.4 `department.teams` | API/domain/policy/UI tests; department/team lead QA |
| M11.14 Staff intake and lead selection UI | Add MVP Meridian Admin flows for organization-interest/organizer signup intake, organizer add/invite staff without requiring the public application path, and organizer selection of department leads from existing staff. | Requirements sections 3.1, 3.3, 5.1-5.4; ORG-001, ORG-015, ORG-016, VOL-001 through VOL-006, TEAM-009; UI contract 12.6 `organizer.staff`, `organizer.departments` | API/domain/policy/UI tests; organizer staff-intake QA |
| M11.15 Product document authoring and sharing UI | Move MVP policy/procedure/fragment creation, preview, publish/archive, internal visibility review, and permitted external share/export entry points into normal Meridian Admin and department/team product surfaces instead of relying on Orchid for maintainer work. | Requirements sections 3.8, 3.15, 7.10; POL-001 through POL-047; Technical spec section 21; UI contract 12.3, 12.4, 12.6, 17.3 | API/domain/policy/UI tests; document authoring/sharing QA |
| M11.16 Product training management UI | Add department/organizer training creation, prerequisite/expiration setup, staff signup/roster where the MVP workflow requires scheduled training attendance, manual completion recording by authorized trainers/leads, and completion spreadsheet import entry points. | TRAIN-001 through TRAIN-006; Requirements 3.8, 5.6, 7.6; Technical spec sections 15.2, 22.2; UI contract 12.4 `department.trainings` | API/domain/policy/UI/import tests; training management QA |
| M11.17 Product team and shift administration UI | Ensure department leads can designate team leads, team leads can assign permitted staff to their teams, and team/department leads can create and maintain shifts from the normal product UI with the documented eligibility and time-window rules. | TEAM-008 through TEAM-010; SHIFT-001 through SHIFT-016; Requirements 5.4, 5.7, 7.7; UI contract 12.4 `department.teams`, `department.shifts`, `department.shift-create`, `department.shift-edit` | API/domain/policy/UI tests; team and shift administration QA |
| M11.18 Equipment inventory setup and import UI | Add normal product UI for department/event equipment inventory creation and bulk CSV import before operations, feeding the existing Logistics checkout/check-in workflow. Do not add department-to-department allotments unless EQUIP-006 is changed, because they are explicitly out of MVP. | EQUIP-001 through EQUIP-007; Requirements 3.13, 7.13; Technical spec sections 20.2, 22.2; UI contract 12.4 `department.equipment` | API/domain/policy/UI/import tests; equipment inventory QA |
| M11.19 Incident search and list filters | Add explicit IMS incident search/filter behavior beyond list/detail and Name Reference chip navigation, restricted by existing IC permissions. | INC-001 through INC-015; NR-001 through NR-014; Technical spec 19.9, 19.10; UI contract 12.7, 15 | Search/UI/policy tests; incident search QA |
| M11.20 Staff Me and event information | Finish Staff Me role-aware event routing, add the team overview handoff for team leads, and replace the interim Event Info placeholders with visible published document content for directions, arrival requirements, packing, food, housing, and event requirements. The Event Info placeholder must not become the final source of truth; it should resolve documents created in the system once document selection and assembly rules are defined. | Requirements 3.8, 5.7, 7.10; Technical spec section 21; UI contract 12.3 `staff.me`, `event.info` | UI/routing/document-visibility tests; staff event-info QA |

**QA gate:** A reviewer can verify IC-only incident access, create/edit incidents online with the full IMS current-field set, link incidents, link field reports, review history, search/filter incidents, and confirm Name Reference chips/search do not expose unauthorized incidents or Field Reports. A reviewer can also complete the MVP product-admin path in normal Meridian Admin without Orchid/God Mode: organizer-interest intake, staff invite/add, department creation, department lead selection, department/team administration, document authoring/sharing, training creation/signup/completion where enabled, team/shift administration, and equipment inventory CSV setup feeding Logistics checkout/check-in.

---

### Milestone 12: Node Sync, Central/On-site Authority, and Conflicts

**Goal:** Prove central/on-site sync and event authority behavior.

**Primary source docs:** Technical spec sections 3.4, 7, 8, 10, 21.10, 23, 25.3, 27.1; data/API sections 7.4, 7.5, 13, 14.2; kiosk guide section 12; process traceability matrix.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M12.1 Node pairing token | Pair on-site node with central using one-time token. | Technical spec 7.4 | Feature tests |
| M12.2 Node operation schema | Add append-only node operation storage. | Technical spec 10.4; data/API 13.3 | Model tests |
| M12.3 Operation signing | Sign node operations and verify signatures. | Technical spec 10.4 | Crypto/unit tests |
| M12.4 Receive/store/apply path | Store remote operations before applying. | Technical spec 10.1 | Idempotency tests |
| M12.5 Bidirectional sync loop | Sync operations central to on-site and on-site to central. | Technical spec 10.1 | Integration tests |
| M12.6 Active event authority | Enforce on-site authority during active event window. | Technical spec 10.2 | Feature tests |
| M12.7 Policy edit active-event block | Block policy/procedure and fragment edits during active event. | Technical spec 10.2, 21.10 | Feature tests |
| M12.8 Conflict queue | Create God-mode sync conflict queue. | Technical spec 10.3; data/API 14.2 | Feature tests |
| M12.9 Conflict resolver | Resolve by accepting on-site or central, with audit. | Technical spec 10.3 | UI/domain tests |
| M12.10 Electron sync health | Show node sync failures and severe conflicts in Electron health. | Technical spec 25.3 | Desktop/manual QA |
| M12.11 Node sync QA script | Add `QA-SYNC-01-onsite-central-sync.md`. | QA README | Human QA script |

**QA gate:** A reviewer can pair central/on-site, sync event data, perform on-site authoritative operations, and resolve conflicts.

---

### Milestone 13: Reporting, Imports, Credits, and Exports

**Goal:** Produce required operational exports and import paths.

**Primary source docs:** Requirements sections 3.15, 5.12, 7.10, 7.14; Technical spec sections 22.2, 26, 27.1; data/API sections 10.12, 11.11, 15.2; UI implementation contract sections 12.6, 17.5.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M13.1 Credential eligibility export | Export event credential eligibility. | REPORT-001; CRED requirements | Export test/sample |
| M13.2 Shift roster export | Export shift roster excluding phone/emergency contacts. | REPORT-002, REPORT-008 | Export test/sample |
| M13.3 Staff contact export | Department contact export may include permitted emergency contacts. | REPORT-003, REPORT-006 through REPORT-010 | Policy/export tests |
| M13.4 Hours worked export | Export actual hours worked. | REPORT-004; HOURS requirements | Export test/sample |
| M13.5 Credit calculation | Calculate credits after correction grace period. | CREDIT-001 through CREDIT-004 | Domain tests |
| M13.6 Credits earned export | Export credits with calculation basis. | REPORT-005; CREDIT-005 | Export test/sample |
| M13.7 Users/teams import | CSV/spreadsheet import for users and teams. | Technical spec 22.2 | Import tests/fixture |
| M13.8 Shifts/assignments import | CSV/spreadsheet import for shifts and assignments. | Technical spec 22.2 | Import tests/fixture |
| M13.9 Export QA script | Add `QA-EXPORT-01-alpha-1-exports.md`. | QA README | Human QA script |

**QA gate:** A reviewer can generate all required Alpha 1 exports and verify sensitive fields follow scope rules.

---

### Milestone 13A: System Configuration and Diagnostics

**Goal:** Give operators a first-party system administration surface: an environment and runtime configuration dashboard backed by node-local database overrides, a system diagnostics and health dashboard, sanitized diagnostics exports, CLI diagnostics for deployment tooling, and sanitized node health reporting from paired nodes to central.

This milestone is deployment/technician tooling rather than product behavior. It is placed after Milestone 13 because it builds on the node model and pairing (Milestone 3, Milestone 12), the signed node sync channel (Milestone 12), the audit service (Milestone 4), and the God Mode console groundwork (Milestones 15B/15C precede it in number only; 13A depends on none of their tasks). It extends — and does not replace — the existing `node_config_values` store and the Node Configuration screen: node identity and pairing state remain administered there, and the new catalogue links to that surface as managed, read-only entries.

**Primary source docs:** Requirements section 7.26 (SYS-001 through SYS-041); Technical spec sections 22A, 22.4, 23, 26.2; data/API sections 13.2, 13.7 through 13.9, 14.1; `docs/technician/configuration.md`; `docs/technician/system-diagnostics.md`.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M13A.1 Catalogue metadata | Annotate `apps/server/.env.example` with `@tag` metadata (label, type, config mapping, secret, required, bootstrap, managed, readonly, restart) and section headings, keeping it the single catalogue source. | SYS-001 through SYS-004; technical spec 22A.2 | Catalogue parsing tests; a test asserting every mapped config key exists |
| M13A.2 Override schema and store | Add `system_config_overrides` with typed JSON values, encrypted secrets, active flags, change reasons, and the audited write path refusing bootstrap-locked/managed/unmapped variables. | SYS-005 through SYS-015; data/API 13.7 | Schema/store/audit tests; secret encryption-at-rest test |
| M13A.3 Boot-time application | Apply valid, active node-local overrides into `config()` at boot with database → environment/.env → default precedence, config-cache compatibility, and soft failure to environment configuration. | SYS-005, SYS-008 through SYS-010, SYS-022 | Precedence tests; invalid/disabled/bootstrap-locked skip tests; unreadable-table fallback test |
| M13A.4 Configuration screens | Add System → Configuration list and per-variable edit screens with search/filters, truthful source badges, activation warnings, secret masking and replace-only editing, and redacted audit history. | SYS-016 through SYS-021; technical spec 22A.7 | Screen/permission/masking tests |
| M13A.5 Diagnostics framework | Add the `DiagnosticCheck` contract, runner, statuses, overall-health aggregation, and the application/security/configuration/database/cache/queue/scheduler/storage/wiring/PowerSync/node-sync/node-identity/integrations checks with a scheduler heartbeat. | SYS-029 through SYS-033, SYS-036; technical spec 22A.8 | Aggregation/status tests; expected-offline test; throwing-check isolation test |
| M13A.6 Diagnostics screen and export | Add System → Diagnostics with filters and manual refresh, plus the permission-gated sanitized JSON export. | SYS-028, SYS-034, SYS-035; technical spec 22A.9, 22A.10 | Screen/permission tests; export redaction tests seeding known secrets |
| M13A.7 CLI commands | Add `meridian:diagnostics [--json]` (non-zero exit on required critical), `meridian:config:list`, and `meridian:config:validate`. | SYS-041; technical spec 22A.12 | Exit-code tests; masking tests |
| M13A.8 Node health reporting | Add `node_health_reports`, the sanitized report builder, node-key signing and verification mirroring the sync exchange, the report endpoint, the scheduled `meridian:health-report` run, and the central Node Health screen with staleness labels. | SYS-037 through SYS-040; data/API 13.8, 13.9; technical spec 22A.11 | Signature/tamper/stale/unknown-node refusal tests; sanitization tests; screen test |
| M13A.9 Permissions | Register the granular `platform.system.*` console permissions (view/manage configuration, manage secrets, audit history, view diagnostics, export), defaulting to God Mode operators only. | SYS-024 through SYS-027 | Permission enforcement tests including view-does-not-grant-manage |
| M13A.10 Technician docs and QA | Extend `docs/technician/configuration.md`, add `docs/technician/system-diagnostics.md`, and add `QA-SYS-01-system-configuration-and-diagnostics.md`. | SYS requirements; QA README | Human QA script |

**Acceptance criteria and human QA checks:**

- `.env.example` defines the discoverable variable scope, and the configuration page shows truthful effective sources including the combined Environment / `.env` badge;
- an authorized operator creates, disables, and removes a safe override; the change applies through `config()` and removal restores the underlying value;
- bootstrap-locked values (application key, database credentials, cache/session stores, node signing key) render read-only and are skipped at load even if a row exists;
- activation requirements are visible, and an override that has not reached every process shows as pending rather than active;
- secrets are encrypted at rest, masked everywhere, replaceable without being readable, excluded from exports and audit records, and require a change reason;
- overrides are node-local, keep working offline, and never travel through PowerSync or node sync;
- the diagnostics page reports the required categories, distinguishes required from optional checks, and presents an intentionally offline on-site node as expected offline rather than failed;
- the diagnostics export is sanitized, with automated proof that known secrets are redacted;
- `meridian:diagnostics` exits non-zero when a required check is critical;
- paired nodes deliver signed sanitized health reports every ten minutes; central refuses and audits tampered, stale, or unknown-node reports; the Node Health screen labels stale reports;
- all capabilities are granular `platform.system.*` permissions defaulting to God Mode, and organization roles gain nothing;
- failure to read the override table leaves the node bootable on environment configuration and surfaces as a critical diagnostics result.

**QA gate:** A reviewer can open System → Configuration, trace a value to its source, apply and remove an override with its activation requirement stated, confirm a secret can be replaced but never read, open System → Diagnostics and export the sanitized bundle, run the CLI diagnostics and see the exit code gate, and — with a paired pair of nodes — watch a sanitized health report arrive on central and go stale when the peer stops reporting. Run `QA-SYS-01`.

---

### Milestone 14: Event Geography & Maps

**Goal:** Deliver the MVP Event Geography & Maps feature (event maps, camps, map locations, the event-level Placement department designation, operations-window locking, optional IMS references, and offline map sync) before pilot/release readiness. This is a required MVP feature and intentionally simple; it must not become a full GIS, dispatch, or live-tracking system.

This milestone is placed after IMS (Milestone 11), offline foundations (Milestone 8), and core org/event/department/permission work (Milestone 4) because it depends on those surfaces. It is a dedicated milestone, not folded into an unrelated milestone, and uses a new milestone number rather than reusing the existing M5.3 identifier.

**Primary source docs:** Requirements sections 3.28-3.31, 4.11, 5.17, 6.3 (Event Maps and Geography), 7.17; Technical spec sections 15.3, 19.5, 21A, 23, 27, 28; data/API sections 5.2, 6.6, 7.1, 7.3, 8, 10.1, 10.2, 10.9, 10.14, 10.16, 10.18, 15.6; UI screen surface section 13A; UI operating guide sections 8.6, 9.2/9.5/9.6, 10.3, 11.1; UI implementation contract sections 12.11, 13, 15, 16; IMS surface specification sections 7-9; kiosk guide sections 3, 10; dashboard widget spec sections 6.2, 9; component library section 6A; accessibility checklist section 17A.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M14.1 Placement department designation | Add organization `default_placement_department_id` and event `placement_department_id`, with validation that the designated department is assigned to the event, and event-settings/Orchid selection with org default. | PLACE-001 through PLACE-008; data/API 10.1, 10.2; technical spec 15.3 | Model/validation/feature tests |
| M14.2 Map and asset model | Add `event_maps` (type, draft/published/archived state), `map_assets`/packages, and optional `map_layers`. Maps enabled by default for events. | MAP-001 through MAP-011; data/API 10.18 | Model/domain tests |
| M14.3 Camp and map-location model | Add `camps` (name + location only) and `map_locations` with lightweight types, plus `map_geometries` (point/line/polygon, local + GeoJSON). | CAMP-001 through CAMP-007; LOC-001 through LOC-003; MAP-016; data/API 10.18 | Model/domain tests |
| M14.4 Map import and metadata | Support uploaded/imported map asset or prepared package with lightweight metadata; no GIS editor/geocoding/public builder. | MAP-009, MAP-010; technical spec 21A.4 | Feature/import tests |
| M14.5 Map permissions | Add map capabilities and authorization derived from organizers/admins and the designated Placement department (leads + map-management grants); non-lead members view by default. | PLACE-009 through PLACE-012; data/API 6.6; technical spec 15.3 | Policy tests |
| M14.6 Publish/archive commands | Add `publish-event-map`/`archive-event-map` command writes; only published maps visible to operational users. | MAP-006, MAP-008, MAP-015; data/API 5.2 | Domain/policy/audit tests |
| M14.7 Operations-window locking | Lock published map geometry and camp/location records when the operations window begins; add organizer/admin `override-locked-map-data` with reason. | MAP-012 through MAP-014; MAPCORR-001; technical spec 21A.5 | Domain/feature/audit tests |
| M14.8 Map view surface | Add Event Map screen with map selector, scoped search/filter, layer toggles, camp/location detail drawer, and locked/offline states; no global command palette entries; no dropped pins. | MAP-017 through MAP-019; UI screen surface 13A; UI contract 12.11 | UI/policy tests |
| M14.9 Kiosk and dashboard map | Add kiosk dashboard map by default when published/permitted, and a department-lead map widget/link. | MAPKIOSK-001; kiosk guide 3, 10; dashboard widget 6.2, 9 | UI/policy tests |
| M14.10 IMS optional camp/location | Add optional incident camp/location reference (create/edit selector and detail display) without requiring it and without replacing free-text location. | MAPIMS-001 through MAPIMS-006; data/API 10.16; IMS spec 7-8 | Domain/UI/policy tests |
| M14.11 Operational location references | Add optional shift meeting and deployment map-location references; do not require them; do not add Field Report or equipment-location fields. | MAPOPS-001, MAPOPS-002, MAPFR-001; data/API 10.9, 10.14 | Domain tests |
| M14.12 Map offline sync | Sync published map packages and permitted camp/location data to permitted devices read-only by default; block sensitive layers; keep locked data stable offline. | MAPSYNC-001 through MAPSYNC-003; data/API 7.1, 7.3; technical spec 21A.8 | Sync/policy tests |
| M14.13 Map QA script | Add `QA-MAP-01-event-geography-and-maps.md`. | QA README; development process | Human QA script |

**Acceptance criteria and human QA checks:**

- event has maps enabled by default;
- event can designate zero or one Placement department, and the Placement department must be a department assigned to the event;
- Placement department leads have documented map-management authority before the operations window begins (manage drafts/camps/locations/assets and publish/archive), per resolved permission boundaries;
- an authorized user can create/import a simple placement map before the operations window;
- an authorized user can create camp records with name/location;
- an authorized user can publish a map (publishing authority confirmed for Placement leads and organizers/admins);
- the published map is visible to permitted lead/IC/kiosk users;
- camp names are not public to all volunteers;
- the kiosk dashboard includes the map by default when published and permitted;
- an IMS incident can optionally reference a camp/location and can also be created without a map location;
- Field Reports remain single-text-body only and do not gain map selector requirements;
- the map package/data syncs offline to permitted devices by default, and non-permitted users do not receive sensitive camp/location data;
- map editing locks when the event operations window begins, with an organizer/admin override path only;
- no arbitrary dropped pin workflow exists;
- the global command palette does not expose camps/map places;
- volunteers cannot submit map corrections.

**QA gate:** A human can designate a Placement department, create/import a placement map, add camps/locations, publish, view it as a permitted lead/IC/kiosk user, optionally reference a camp from an incident, confirm Field Reports stay single-body, verify offline sync and sensitive-data exclusion, and confirm locking once the operations window begins.

---

### Milestone 15: Notes and The Briefing (Alpha 1 slice)

**Goal:** Ship standalone immutable Notes (author+Command visibility), Command add-to-Briefing by reference or link, Briefing hub display of added Notes for approved event staff, and empty shells for AARs, Directions, Action Plan, and Notices. Full AAR/Directions/Action Plan/Notice workflows remain post–Alpha 1 but are specified in source docs.

**Primary source docs:** Requirements 3.36–3.41, 5.18, 7.19 (BRF-001–BRF-030); technical spec 21B; data/API 11A; UI Briefing surface spec; UI contract 12.7A.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M15.1 Note model | Add `notes` table/model/factory with immutability (no update path). | BRF-004–BRF-006; data/API 11A.2 | Model/domain tests |
| M15.2 Notes/Briefing permissions | Catalog `notes.*` / `briefing.*` capabilities: create, author view, Command view, add-to-briefing, hub view. | BRF-003–BRF-008C; technical spec 21B.2 | PermissionCatalog / policy tests |
| M15.3 Create Note command | Add online-only `POST /api/commands/create-note` with authz and audit. | BRF-004–BRF-006, BRF-029–BRF-030; data/API 11A.9 | Feature/policy/audit tests |
| M15.4 Note read APIs | Add Notes list/detail for author and Command only (pre-inclusion). | BRF-007 | HTTP/policy tests |
| M15.5 Add-to-Briefing command | Add online-only `POST /api/commands/add-note-to-briefing` for reference/link modes with credit and audience (`event_staff` \| `department_leads_only`). | BRF-008–BRF-008C2; data/API 11A.3 | Feature/policy/audit tests |
| M15.6 Briefing hub UI | Add `briefing.hub` showing Command-added Notes plus shells for AAR/Directions/Action Plan/Notices. | BRF-001, BRF-027; Briefing surface spec 4 | UI tests |
| M15.7 Notes + add UI | Add `notes.create` / `notes.index` / `notes.detail` and `briefing.add-note` (reference/link + audience). | BRF-005–BRF-008C2; UI contract 12.7A | UI/policy tests |
| M15.8 Orchid Note scaffold | Add Orchid Note list/detail repair visibility. | Technical spec 21B.7; UI contract `orchid.notes` | Orchid feature tests |
| M15.9 Notes/Briefing PowerSync | Sync Notes to author+Command; sync Briefing inclusions by audience. | Technical spec 21B.8; data/API 11A.10 | Sync/policy tests |
| M15.10 Briefing QA script | Add/update `QA-BRF-01-briefing-notes-hub.md`. | QA README | Human QA script |

**Acceptance criteria and human QA checks:**

- department lead, team lead, and IC can create an immutable Note while connected;
- author and Command can read the Note; ordinary event staff cannot before Briefing add;
- Command can add the Note to The Briefing by reference or link with author credit and event-staff or department-leads-only audience;
- permitted viewers then see the inclusion in the hub; team leads without department-lead/Command/organizer roles do not see department-leads-only inclusions;
- Note body cannot be edited or appended after create;
- hub shows clear shells for AAR, Directions, Action Plan, and Notices;
- Orchid exposes Note list/detail for repair visibility;
- unauthorized users cannot create Notes or add to Briefing.

**QA gate:** A human can create an immutable Note as a lead, confirm ordinary staff cannot read it, have Command add it by reference (event staff) and by link (department leads only), confirm visibility boundaries, and see shells for the remaining Briefing types.

---

### Milestone 15A: Organization and Department Branding

**Goal:** Let an organization present Meridian as its own system, and let departments carry visible identity, without weakening state legibility or accessibility.

This milestone closes a gap between the UI documentation and the product: UI operating guide section 8.3 and the `DepartmentBadge` contract have always described department logos and accent colors, and `tokens.css` carries a `--m-department-accent` placeholder noting that per-department override "arrives with org model," but no requirement, schema, or component ever followed. Organization white-labeling is new scope added alongside it.

**Primary source docs:** Requirements sections 3.1, 3.3, 3.42, 6.3, 7.20 (BRAND-001 through BRAND-024); Technical spec sections 15.2, 21, 22.1; data/API sections 10.1, 10.6; UI style guide sections 3 and 4; UI operating guide sections 6.3 and 8.3; UI implementation contract sections 10 and 11.3; component library specification sections 3 and 5.1; accessibility checklist section 6.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M15A.1 Branding contract reset | Amend the UI style guide, operating guide, implementation contract, component library specification, and accessibility checklist so a customizable organization palette and a bounded department surface override replace the fixed four-color platform palette and the accent-only department rule. Record that department background is permitted and that the system blocks rather than repairs failing contrast. | BRAND-006, BRAND-007, BRAND-009, BRAND-011, BRAND-016; UI style guide 4; operating guide 6.3, 8.3; UI contract 10, 11.3 | Doc/traceability checks |
| M15A.2 Branding schema | Add organization and department branding columns and logo asset references, including the organization switch that disables department overrides. | BRAND-001, BRAND-004, BRAND-006, BRAND-009, BRAND-013; data/API 10.1, 10.6 | Schema/model tests |
| M15A.3 Contrast validation service | Add a server-side WCAG 2.1 AA contrast validator enforcing 4.5:1 normal text, 3:1 large text, and 3:1 non-text indicators, rejecting failing submissions with the failing pair, measured ratio, and required ratio. No auto-correction. | BRAND-014, BRAND-015, BRAND-016 | Domain/validation tests including boundary ratios |
| M15A.4 Token resolution | Resolve `@meridian/ui-tokens` and the server token stylesheet from the active organization palette at runtime, keeping action, status, severity, attention, and chart tokens derived rather than independently settable. | BRAND-006, BRAND-007; UI contract 10 | Token contract tests; client and server token parity tests |
| M15A.5 Logo upload and lettermark | Add organization full-lockup, organization compact-mark, and department logo upload/replace/remove through the existing attachment path with MIME and size constraints, plus the generated lettermark fallback. | BRAND-004, BRAND-005, BRAND-010, BRAND-023 | Upload/policy/rendering tests |
| M15A.6 Organization branding admin | Add the organizer/Lead Organizer branding surface with palette editing, logo management, live preview, and the contrast validation result shown before save. | BRAND-018, BRAND-019 | API/domain/policy/UI tests |
| M15A.7 Department branding admin | Add the department administration/department lead branding surface limited to logo, accent, and surface background, disabled when the organization switch is off. | BRAND-009, BRAND-011, BRAND-013, BRAND-018, BRAND-019 | API/domain/policy/UI tests |
| M15A.8 Meridian identity replacement | Replace the Meridian display name and mark with organization identity across the app header and Home control, document titles, generated PDF exports, and system email, while preserving Meridian identity on login, magic-link landing, node first-run setup, Orchid, and desktop chrome. | BRAND-002, BRAND-003 | Shell/title/export/mail tests; boundary tests asserting Meridian identity survives where required |
| M15A.9 DepartmentBadge component | Build the long-specified `DepartmentBadge` with logo, icon, short label, lettermark fallback, small accent, sizes, and an accessible name including the department name. | BRAND-010; UI contract 11.3; component library 5.1 | Component/accessibility tests |
| M15A.10 Department surface scoping | Apply the department surface background only to department-scoped surfaces, explicitly excluding incident/IMS surfaces, The Briefing, and organization-level or cross-department surfaces. | BRAND-012 | UI scoping tests asserting IMS and Briefing surfaces are unaffected |
| M15A.11 Branding governance and audit | Enforce central-node authority for branding, block branding edits during the active event window under the existing governance edit-freeze rules, and audit branding create/update/asset-removal. | BRAND-020, BRAND-021 | Domain/audit/authority tests reusing the governance edit-freeze suite |
| M15A.12 Branding sync and offline | Sync branding palettes and assets to on-site nodes and permitted offline devices, and render branding from cache when offline. | BRAND-022 | Sync/offline/cache tests |
| M15A.13 State legibility guard | Verify that no branding profile can make canonical status, severity, priority, or restriction unreadable or color-only, including under a department background override. | BRAND-017; accessibility checklist 6 | Accessibility/contrast regression tests |
| M15A.14 Branding QA script | Add `QA-BRAND-01-organization-and-department-branding.md`. | QA README | Human QA script |
| M15A.15 Team logos | Add a team logo slot on the existing branding asset path — schema column, upload/replace/remove, Orchid and product-path administration under the department's branding authority — and render it where a team is identified on its own. Teams get a logo and no other branding value. | BRAND-023, BRAND-025, BRAND-026 | Schema/upload/policy tests; team surface rendering tests |
| M15A.17 Event logos | Add an event logo slot on the existing branding asset path — schema column, upload/replace/remove, Orchid and product-path administration under organization branding authority. Resolve the node's locked event into the branding profile and the offline manifest. | BRAND-023, BRAND-028, BRAND-030 | Schema/upload/policy tests; resolver tests covering an unlocked node and a lock pointing at another organization's event |
| M15A.18 Event mark in product chrome | Prefer the locked event's logo over the organization mark in the application header, the browser tab icon, and the desktop window icon, falling back to the organization mark when the install is not event-locked or the event has no logo. | BRAND-029, BRAND-003, BRAND-003A; UI contract 10.5 | Shell/favicon/desktop icon tests; boundary tests asserting Meridian identity and organization identity survive where required |
| M15A.16 Department marks in the shell header | Render the current department's mark beside the department and event names in the header, and the marks of the user's other departments as context switchers right-adjusted beside the user menu. Reachable department logo editing from department details as well as from the branding surface. | BRAND-010, BRAND-019, BRAND-027; UI contract 10.4, 11.3a | Shell rendering and switch-behavior tests |

**QA gate:** A reviewer can upload an organization logo and palette, see the organization name and mark replace Meridian across the header, document title, a generated PDF, and a system email while login and Orchid still show Meridian, have a failing color combination rejected with the measured ratio rather than silently corrected, set a department logo, accent, and background and see them on department surfaces but not on IMS or Briefing surfaces, confirm a department with no logo renders a lettermark, disable department overrides organization-wide, confirm branding edits are blocked during the active event window, and confirm branding still renders on an offline device.

---

### Milestone 15B: God Mode Console Orientation, Documentation, and Changelog

**Goal:** Make the God Mode console about Meridian rather than about the administrative framework it is built on, and give a God Mode user a landing screen that orients them and points at what needs their attention.

The console currently ships stock framework content: `PlatformScreen` renders the vendor welcome partial and describes itself as an Orchid application, and the navigation links out to Orchid's own documentation and Orchid's release changelog badged with the framework version. None of it is about Meridian.

**Primary source docs:** Requirements sections 6.3, 7.21 (GOD-001 through GOD-028); Technical spec sections 22.1 through 22.4, 25.3, 26.3; data/API configuration sections; development process release checklist.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M15B.1 Console spec alignment | Extend technical spec section 22 with the God Mode landing screen, technician documentation, and changelog behavior so the console has a specification beyond screen inventory. | GOD-001 through GOD-028; technical spec 22.1-22.4 | Doc/traceability checks |
| M15B.2 Meridian landing screen | Replace the framework welcome partial and "Welcome to your Orchid application" description with the Meridian orientation summary, including the God-Mode-is-repair-tooling boundary statement. | GOD-001 through GOD-004 | Screen/content tests |
| M15B.3 Configuration readiness checks | Add the deployment/configuration attention group covering node configuration completeness, node role and pairing state, required secrets, secure connection policy, and PowerSync connectivity. | GOD-005, GOD-006, GOD-009; technical spec 25.3, 26.2 | Domain/check tests including healthy and degraded states |
| M15B.4 Organizational data gap checks | Add the data gap attention group covering missing departments, missing Organizers Department, unresolvable Incident Command Department, absent active Lead Organizer, and events without assigned departments. | GOD-005, GOD-007, GOD-009; ORG-002, ORG-005 through ORG-008 | Domain/check tests against seeded gap fixtures |
| M15B.5 Conflict surfacing and clean state | Add the unresolved sync conflict group with a link to conflict resolution, guarantee checks are read-only, and render an explicit all-clear when nothing needs attention. | GOD-008 through GOD-011 | Domain/UI tests; a test asserting no writes occur on view |
| M15B.6 Technician documentation tree | Add `docs/technician/` covering deployment, node setup and pairing, configuration and config source resolution, data repair, conflict resolution, and break-glass procedures, written for technicians rather than as specification. | GOD-013 | Doc/process checks |
| M15B.7 Documentation page | Add the in-console Documentation page rendering the packaged technician tree with an index, Markdown rendering, title/heading filtering, and documentation-versus-build version display. Serves only `docs/technician/`. | GOD-012, GOD-014 through GOD-017 | Rendering/packaging tests; a test asserting spec, QA, plan, and issue documents are not reachable |
| M15B.8 Changelog generation | Add a release build step that generates a changelog data file from repository history, capturing pull request title, body, number, merge date, and author grouped by shipped version, and package it with the deployment. | GOD-019, GOD-021; versioning strategy | Generator unit tests; build smoke test |
| M15B.9 Changelog page | Add the in-console Changelog page rendering the packaged data grouped by Meridian version with no change-type filtering. | GOD-018 through GOD-021 | Rendering tests; offline rendering test |
| M15B.10 Changelog refresh | Add central-node-only refresh that merges newer source-repository entries into the packaged baseline, degrades to the baseline on missing network, missing credential, or failure, shows last successful refresh time, is skipped during the active event window, and never blocks rendering. | GOD-022 through GOD-026 | Refresh/degradation/authority tests; credential redaction test |
| M15B.11 Framework link and version cleanup | Remove the external Orchid documentation and changelog menu entries and the framework version badge, replacing them with the internal pages and the Meridian build version. | GOD-027, GOD-028; technical spec 26.3 | Navigation tests asserting no external framework links remain |
| M15B.12 Console QA script | Add `QA-GOD-01-console-orientation-docs-changelog.md`. | QA README | Human QA script |

**QA gate:** A reviewer can open God Mode and read a Meridian orientation summary instead of framework welcome content, see attention items for a misconfigured node, an organization missing its Organizers Department, and an outstanding sync conflict, follow each item to the screen that resolves it, watch the list report all-clear once resolved, read technician documentation in-console with no network access and confirm specification and planning documents are not reachable there, read a version-grouped changelog on an offline on-site node, and confirm no menu entry links to Orchid documentation, Orchid's changelog, or the framework version.

---

### Milestone 15C: God Mode Console Visual Identity

**Goal:** Make the God Mode console look like Meridian rather than like a default administrative framework installation.

Milestone 15B makes the console's *content* about Meridian. This milestone makes its *appearance* about Meridian. The two are separable and can land independently.

`PlatformProvider` already registers `css/meridian-tokens.css` as a dashboard stylesheet, so the shared tokens are loaded into the console today but nothing in the framework chrome consumes them. The `resource.stylesheets`, `template.header`, and `template.footer` configuration hooks in `config/platform.php` are all still empty. The current footer also states the MIT license, which is the framework's license and not Meridian's; the repository is published under AGPL-3.0-or-later.

**Primary source docs:** Requirements section 7.21 (GOD-029 through GOD-038); UI style guide sections 3, 4, and 5; UI implementation contract section 10; component library specification section 3; accessibility checklist section 6; technical spec sections 22.1, 26.3.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M15C.1 Token bridge | Map the shared Meridian design tokens onto the framework's own CSS custom properties and component styles so console surface, foreground, border, focus, and action colors resolve from `@meridian/ui-tokens` instead of framework defaults. | GOD-029, GOD-034; UI contract 10 | Token/style tests; visual regression baseline |
| M15C.2 Typography and spacing | Apply the Meridian typography scale and spacing scale to console chrome, tables, forms, and screen layouts. | GOD-034; style guide 5 | Style tests |
| M15C.3 Console logo | Serve the Meridian logo in console navigation and provide the compact mark for the collapsed navigation state, wired through the framework's supported template hooks. | GOD-030, GOD-037 | Rendering tests at both navigation states |
| M15C.4 Favicon | Serve the Meridian favicon across console, authentication, and setup surfaces. | GOD-031 | Rendering test |
| M15C.5 Footer replacement | Replace the framework footer with a Meridian footer stating the repository's actual license, a 2026-to-present copyright range, and the Meridian build version resolved from `config('meridian.version')`. Remove the framework license, copyright range, and version string. | GOD-032, GOD-033, GOD-028 | Content tests asserting the license matches the repository license and no framework version appears |
| M15C.6 Auth and setup surfaces | Bring login, magic-link, logout, and node first-run setup surfaces onto the same visual identity as the console. | GOD-038 | Rendering tests across each surface |
| M15C.7 Meridian palette isolation | Ensure the console renders in Meridian's default palette and never resolves an organization branding profile. | GOD-035; BRAND-003 | Test asserting console appearance is unchanged with a branded organization active |
| M15C.8 Upgrade safety | Confine restyling to supported configuration and template extension points, and document any place a vendor view had to be overridden along with why. | GOD-037 | Documented override inventory; process check |
| M15C.9 Accessibility verification | Verify the restyle holds contrast and focus visibility across console screens, including tables, forms, badges, and disabled states. | GOD-036; accessibility checklist 6 | Contrast/focus regression tests |
| M15C.10 Console identity QA script | Add `QA-GOD-02-console-visual-identity.md`. | QA README | Human QA script |

**QA gate:** A reviewer can open the God Mode console and see Meridian's logo, favicon, palette, typography, and spacing rather than default framework styling, see a footer stating Meridian's actual license, a 2026-to-present copyright range, and the Meridian build version with no framework license or version anywhere, see the collapsed navigation render the compact mark, confirm login and node setup match the console, confirm an organization branding profile does not change console appearance, and confirm contrast and focus visibility hold across console screens.

---

### Milestone 16: Client Session and API Wiring

**Goal:** Make the client applications real. A user logs in, the server tells the client who they are and what they may do, navigation follows from those permissions instead of from bundled fixture data, and every surface reads and writes through the API endpoints that already exist.

The server side of most of this milestone is already built. Departments, teams, trainings, equipment, shifts, documents, incidents, attendance, and the credential eligibility export all have endpoints. What is missing is a session for a real user to hold, a way for the client to learn that user's permissions, and the wiring between the two.

Three parts of the milestone are not binding work and should be understood as new domain work: token authentication with device binding, shared-workstation login codes, and permission-scoped sync rules. The shared-workstation mechanism amends technical spec section 13.2, which previously allowed only God mode to generate login codes.

Until this milestone, every `/api/*` route sat behind `local.field`, a shared-bearer-token middleware that authenticated as a seeded fixture user and was disabled outside local development. That middleware is removed here. The seeded fixture user survives as ordinary development seed data.

**Primary source docs:** Requirements sections 7.16 (AUTH-018 through AUTH-030) and 7.22 (CLIENT-001 through CLIENT-024); technical spec sections 9.5, 11.4, 11A, 13.2, 13.3, 15; data/API sections 5.4 through 5.7, 7.3, 12.4, 12.5; UI implementation contract section 12; UI operating guide sections 18.1, 18.2; kiosk and field hardware UX guide.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M16.1 Sanctum token issuance | Add API login endpoints so a client requests a magic link and completes verification without leaving the application, receiving a bearer token. Add node-configured token expiry with a documented default. | AUTH-018, AUTH-019, AUTH-024; technical spec 11.4; data/API 5.4 | Feature/auth tests; expiry tests |
| M16.2 Device-bound tokens and revocation | Bind every issued token to a `devices` record, refuse issuance without a resolvable device, and add God Mode listing and revocation by token and by device with request-time evaluation. | AUTH-021 through AUTH-023, AUTH-025; technical spec 11.4; data/API 12.5 | Domain/policy/audit tests; a test asserting a revoked token fails its next request; a redaction test asserting raw tokens never reach logs or audit |
| M16.3 Provider handoff | Complete Google and Discord login through a system browser and return the token to the requesting client, mobile, or desktop application. | AUTH-020; technical spec 11.4; data/API 5.4 | Feature tests per client target |
| M16.4 Session resolution endpoint | Add `GET /api/me` returning identity, effective role codes, permission capability codes, and the user's organizations, events, departments, and teams. Return codes only, with no navigation, screen list, or menu structure. | CLIENT-001 through CLIENT-003; technical spec 11A.2; data/API 5.5 | HTTP/policy tests; a test asserting the payload carries no precomputed surface list; a test asserting one user cannot read another's associations |
| M16.5 Offline permission cache | Persist the session response durably, boot from it when the node is unreachable, bound staleness to the locked event window, show cached state and last refresh, and apply permission reductions immediately on reconnect. | CLIENT-007 through CLIENT-010; technical spec 11A.4 | Cache/staleness tests; a test asserting an ended event window forces refresh; a test asserting a removed permission is dropped on refresh |
| M16.6 Permission-aware navigation | Replace `fixtureDepartmentAccess` and its consumers in the router, shell, and workflow links with capabilities from the session response, honoring the existing rules that unavailable actions are hidden and denied surfaces name the required role only for elevated users. | CLIENT-004 through CLIENT-006, CLIENT-024; UI operating guide 18.1, 18.2 | UI/policy tests; a test asserting no navigation entry renders without a permitting capability; client tests still run without a live server |
| M16.7 Organization and event switcher | Resolve context from the node lock first and narrow it through the session response; let a connected user switch to any event or organization they hold an association with; lock an offline client to the node's context; re-resolve permissions, navigation, branding, and cache on switch. | CLIENT-011 through CLIENT-014; technical spec 11A.3; UI contract 12 | UI/context tests; a test asserting an offline client offers no switching; a test asserting no previous-context data survives a switch |
| M16.8 Shared-workstation login codes | Implement login code generation, hashing, rate limiting, and audit for both God Mode issuance and self-service issuance from a device where the user already holds a session. Codes stay scoped to one user, event, and trusted workstation, and remain valid 6 weeks. | AUTH-026 through AUTH-029; technical spec 13.2; data/API 12.4 | Domain/policy/audit tests; a test asserting a user cannot generate a code for someone else; a test asserting self-service issuance needs no internet or central reachability; a raw-code redaction test |
| M16.9 Shared-workstation session semantics | Establish the session a code produces: 5-minute inactivity timeout, explicit end before user switching, lock on Electron restart, session data wiped on end while queued operations survive, active user shown prominently, and no personal device token issued. | AUTH-030; technical spec 13.3; data/API 12.3; kiosk guide | Session/timeout/lock tests; a test asserting code entry issues no API token; a test asserting queued operations survive session end |
| M16.10 Command outbox | Generalize command submission into one durable queue with client-generated idempotency keys, migrate the field report and attendance queues onto it, surface queued/accepted/rejected state, and refuse connected-only commands at queue time. | CLIENT-015 through CLIENT-018; technical spec 11A.5; data/API 5.3, 5.6 | Queue/replay/idempotency tests; a test asserting a repeated key produces no duplicate effect; a test asserting online-only commands are refused rather than queued |
| M16.11 `local.field` removal | Remove the shared-token middleware, its configuration, and the client environment variable, and move the mobile Field application onto token authentication. Keep the fixture seeder as development seed data. | AUTH-018; technical spec 11.4 | Feature tests; a test asserting the shared token no longer authenticates; mobile upload regression tests |
| M16.12 Short-lived download URLs | Extend the existing field report photo pattern to the credential eligibility export, incident PDFs, and document exports: an authenticated client requests a scoped expiring URL and then navigates to it. | CLIENT-019, CLIENT-020; technical spec 11A.6; data/API 5.7 | HTTP/policy tests; expiry test; a test asserting a URL issued for one resource does not retrieve another |
| M16.13 Permission-scoped sync rules | Scope PowerSync replication by the caller's effective roles so a device never holds records its user could not retrieve through the API, and a role change changes what subsequently replicates. | CLIENT-021, CLIENT-022; technical spec 9.5, 11A.7; data/API 7.3 | Sync/policy tests; a test asserting a demoted user stops receiving previously replicated records |
| M16.14 Organization admin binding | Bind the organizer department and staff surfaces to their existing endpoints and remove their fixture dependencies. | CLIENT-023; ORG-002, VOL-001 through VOL-006; data/API 10.6 | UI/API integration tests |
| M16.15 Department and team binding | Bind department detail, team list, team detail, team lead designation, and team staff assignment surfaces to their endpoints. | CLIENT-023; TEAM requirements; data/API 10.6 | UI/API integration tests |
| M16.16 Trainings binding | Bind training list, detail, signup, completion, and prerequisite surfaces to their endpoints. | CLIENT-023; training requirements | UI/API integration tests |
| M16.17 Equipment binding | Bind department equipment inventory and checkout surfaces to their endpoints. | CLIENT-023; EQUIP-001 through EQUIP-005 | UI/API integration tests |
| M16.18 Shifts binding | Bind shift administration and shift board surfaces to their endpoints. | CLIENT-023; SHIFT requirements | UI/API integration tests |
| M16.19 Documents binding | Bind policy, procedure, fragment, and Event Info surfaces to their endpoints, including document export through M16.12. | CLIENT-023; policy/procedure requirements; data/API 11.4A | UI/API integration tests |
| M16.20 IMS binding | Bind incident list, detail, notes, links, attachments, list presets, and PDF surfaces to their endpoints. | CLIENT-023; INC-001 through INC-015; IMS spec | UI/API/policy integration tests |
| M16.21 Attendance and logistics binding | Bind check-in, check-out, no-show, deployment, and logistics workspace surfaces to the attendance commands through the M16.10 outbox. | CLIENT-023, CLIENT-015; SLB-001 through SLB-022; technical spec 20 | UI/offline/idempotency integration tests |
| M16.22 Export entry points | Bind the reporting export entry points, including `organizer.credentials`, to their endpoints through the M16.12 download URL path. | CLIENT-023, CLIENT-019; REPORT-001; UI contract 12.6 | UI/API/policy tests |
| M16.23 Client session QA script | Add `QA-CLIENT-01-session-and-api-wiring.md`. | QA README | Human QA script |

**Acceptance criteria and human QA checks:**

- a user logs in from the web client, the mobile application, and the desktop application using the same token mechanism;
- every issued token is bound to a device, and God Mode can revoke one token or every token on a device, with revocation taking effect on the next request;
- `GET /api/me` returns role codes and capability codes and returns no navigation structure;
- navigation and available actions follow from those capabilities, with no surface reachable for which the user holds no permitting capability;
- a client that fails to hide an action is still refused by the server;
- a client that loses connectivity keeps working from its cached permissions for the duration of the locked event, shows that its permissions are cached, and shows when they were last refreshed;
- a permission removed on the server is gone from the client on its next successful refresh;
- a connected user belonging to multiple events or organizations can switch between them, and an offline user cannot;
- switching context leaves no data from the previous context visible;
- a staff member at a kiosk with no internet generates a login code on their own already-signed-in phone and uses it to sign in to the workstation;
- God Mode can still generate a login code for another user, and a user cannot generate one for anyone but themselves;
- a shared-workstation session times out after 5 minutes of inactivity, locks on restart, requires an explicit end before switching users, and issues no personal device token;
- commands issued offline are queued, survive a session ending, and apply exactly once when connectivity returns;
- a command restricted to connected operation is refused when issued offline rather than queued;
- exports, incident PDFs, and document exports download through a short-lived scoped URL rather than a credentialed link;
- a device does not hold records its user has no capability to read, and demoting a user changes what replicates to their devices;
- the shared `local.field` token no longer authenticates anything;
- every surface group reads and writes through the API, and the client's automated tests still run without a live server.

**QA gate:** A human can log in on web, mobile, and desktop; confirm navigation matches their real roles and changes when a role is granted or revoked; take a device offline and confirm it keeps working within the event window and reports its permissions as cached; switch events and organizations while connected and confirm switching disappears when offline; generate a login code on their phone with no internet and sign in to a kiosk with it; watch that kiosk session time out; queue an attendance command offline and confirm it applies once on reconnect; download the credential eligibility export through a short-lived URL; and confirm God Mode can revoke a device's tokens and that the device stops working on its next request.

---

### Milestone 17: Insights

**Goal:** Ship the Insights framework — registered metric types, organization-owned configurable sheets, permission and privacy enforcement, live and offline compilation, Command sharing, and browser PDF snapshots — plus the three approved initial metrics.

This milestone builds the reusable framework and the first metrics that prove it. It does not design the full catalogue of Insight Sheets or metrics. Future metric and sheet design is separate work that consumes this framework, and no task here should be read as committing to it.

Insights are not Reports. Reports remain the fixed, formal, historical outputs already specified in section 7.14. Nothing in this milestone renames, replaces, or absorbs them.

Two pieces of this milestone are attendance-domain work rather than Insights work. Automatic no-show determination amends Milestone 10's manual `mark-no-show` behavior, and the missed-shift metric depends on it. It is placed here because the metric is what requires it, and it is called out separately so its blast radius is visible.

**Primary source docs:** Requirements sections 3.43, 3.44, 7.9 (SLB-023 through SLB-030), and 7.23 (INSIGHT-001 through INSIGHT-062); technical spec sections 20.2, 21C, 31; data/API sections 5.8, 6.7, 7.3, 8, 10.10, 10.19; UI implementation contract sections 12.7B, 12.9, 19B; UI operating guide section 20A.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M17.1 Automatic no-show determination | Derive no-show from the ±5% sign-in window on the node holding event authority, writing an audited append-only attendance operation. Exclude cancelled and excused shifts. Preserve manual `mark-no-show`. Make determination idempotent per assignment. | SLB-023 through SLB-027, SLB-029; technical spec 20.2; data/API 10.10 | Domain/authority/audit tests; boundary tests at both window edges; idempotency test; a test asserting cancelled and excused shifts are excluded |
| M17.2 Late arrival and supersession | Let a check-in after an automatic no-show supersede it so the derived state becomes checked-in with both operations preserved, and derive late arrival from that superseded case. | SLB-028, SLB-030; technical spec 20.2 | Domain tests; a test asserting both operations survive in history; a test asserting on-time, late, and missed partition with no gap or overlap |
| M17.3 Metric registration and rendering contract | Add `insight_metric_definitions` and the render contract taking event, department scope, filters, and placement configuration and returning values, state, presentation, and action link. Enforce each metric's declared capability at render. | INSIGHT-002, INSIGHT-037; technical spec 21C.2; data/API 10.19 | Contract/registration tests; a test asserting a metric renders nothing when its declared capability is absent |
| M17.4 Sheets and metric placements | Add `insight_sheets` and `insight_metric_placements` with ordering, per-placement configuration validated against registration at write time, sheet-level enabled filters, and create/edit/soft-archive lifecycle. | INSIGHT-003 through INSIGHT-006, INSIGHT-013, INSIGHT-014; technical spec 21C.3; data/API 10.19 | Schema/domain tests; a test asserting a metric type reused on multiple sheets keeps independent configuration; a test asserting unknown configuration keys are refused rather than ignored |
| M17.5 Insights permissions | Add `insights.view`, `insights.sheets.manage`, and `insights.share_with_command` to the permission catalog with the documented role mappings, and enforce them in policies, query handlers, API responses, and Orchid. | INSIGHT-016 through INSIGHT-023; data/API 6.7 | PermissionCatalog/policy tests; a test asserting sheet-management authority does not widen data access; a test asserting a sheet cannot be configured to expose data its author cannot read |
| M17.6 Scope and context resolution | Resolve one event at a time and a department scope from the viewer's authorization and selected department context, so one sheet renders different data for different viewers. Support authorized department switching. | INSIGHT-009 through INSIGHT-012; technical spec 21C.4 | Scope tests; department isolation tests; a test asserting a lead outside the Organizers Department sees only their department; organizer cross-department test |
| M17.7 Restricted-domain and privacy enforcement | Hide sheets and metrics drawing on domains the viewer cannot access, restrict incident- and Field-Report-derived metrics to the IC pool, and suppress aggregates covering fewer than 5 people server-side with an explicit withheld-for-privacy result. | INSIGHT-024 through INSIGHT-030; technical spec 21C.6 | Policy/privacy tests; a test asserting suppression never renders as zero, null, or empty; a test asserting IMS-derived metrics are invisible outside the IC pool; a test asserting no names or PII appear in any rendered payload |
| M17.8 Metric state model | Implement the operational states (healthy, expected, attention, critical) and the data-quality states (incomplete, stale, offline, waiting to sync) as independent axes with fixed thresholds, reusing existing status and connectivity conventions. | INSIGHT-033 through INSIGHT-036, INSIGHT-040, INSIGHT-041; technical spec 21C.7 | State tests; a test asserting a metric can be simultaneously healthy and stale; a test asserting no dismissal, acknowledgement, or resolution path exists |
| M17.9 Live refresh and offline compilation | Recompile after each synchronized change; compile on-device from permission-scoped synced data when offline; report incomplete rather than reaching past the sync boundary. Sync sheet and placement definitions; sync no compiled value. | INSIGHT-038, INSIGHT-039, INSIGHT-042, INSIGHT-043; technical spec 21C.8; data/API 7.3 | Sync/offline tests; a test asserting no compiled result is persisted; a test asserting a metric lacking local data reports incomplete |
| M17.10 Command sharing | Add `insight_sheet_shares` supporting whole-sheet and single-placement sharing, ongoing and temporary modes with expiry evaluated on read against the event window, originating-department labelling, removal, and audit. | INSIGHT-044 through INSIGHT-051; technical spec 21C.9; data/API 10.19 | Domain/policy/audit tests; a test asserting a restricted metric on a shared sheet stays restricted for Command; a test asserting placement-level sharing does not share other uses of the same metric type; expiry test at window close |
| M17.11 Action links | Link each metric to its operational surface, entering that surface under its own authorization. | INSIGHT-037; technical spec 21C.7 | Policy tests; a test asserting a shared metric's link does not admit Command to the sharing department's surfaces |
| M17.12 Favorites and pins | Add `insight_sheet_favorites` as personal view state, surfaced in the product interface and not in Orchid, and not audited. | INSIGHT-058; data/API 10.19 | Domain/UI tests |
| M17.13 Insights surfaces | Build `insights.index`, `insights.sheet`, `insights.sheet-edit`, and `insights.share`, with primary-navigation placement, unauthorized sheets omitted entirely, sheet-level filters, session-scoped filter memory, and department switching. | INSIGHT-030, INSIGHT-056, INSIGHT-057, INSIGHT-015; UI contract 12.7B, 19B; operating guide 20A | UI/policy tests; a test asserting an inaccessible sheet is absent rather than disabled; session filter memory test |
| M17.14 Personal volunteer Insights | Build `insights.me` returning the signed-in volunteer's own completed shifts, missed shifts, late arrivals, hours worked, hours worked by team, and credits, available without `insights.view`. | INSIGHT-031, INSIGHT-032; data/API 5.8 | UI/policy tests; a test asserting no peer comparison, ranking, or other volunteer's data is reachable |
| M17.15 Browser PDF snapshot | Generate the snapshot in the browser and download it, carrying visible metrics, filters, states, freshness disclosures, organization, event, department, sheet name, timestamp, generating user, and originating department. Store nothing and create no snapshot entity. | INSIGHT-052 through INSIGHT-055; technical spec 21C.10 | Output tests asserting the PDF matches the current view; a test asserting nothing is persisted; a test asserting suppression carries into the PDF |
| M17.16 Orchid Insights administration | Add registered metric definition visibility, organization sheet and placement administration, sheet filter configuration, Command sharing configuration, and the sharing audit view. No metric logic authoring. | INSIGHT-002, INSIGHT-021, INSIGHT-049; technical spec 21C.11; UI contract 12.9 | Orchid feature tests; a test asserting no formula or code authoring path exists; a test asserting editing configuration grants no data access |
| M17.17 Missed shifts metric | Register the missed shifts metric on the M17.1 determination. | INSIGHT-060; SLB-023 through SLB-029 | Metric tests over seeded attendance fixtures |
| M17.18 Equipment not returned metric | Register the equipment not returned metric covering explicitly missing equipment, shift-assigned equipment still checked out after its shift ended, and event-assigned equipment still checked out after the event ended. | INSIGHT-061; EQUIP-001 through EQUIP-005 | Metric tests; separate cases for shift-assigned and event-assigned timing |
| M17.19 Extended shift presence metric | Register a metric identifying how many people remain on shift beyond their scheduled time. Its detailed calculation is designed with this task, not inherited from the framework. | INSIGHT-062; technical spec 30 item 33 | Metric tests |
| M17.20 Insights QA script | Add `QA-INSIGHT-01-insights-framework.md`. | QA README | Human QA script |

**Acceptance criteria and human QA checks:**

- a scheduled shift with no check-in by the end of the ±5% window becomes a no-show without anyone marking it, and the operation is audited and syncs;
- a cancelled shift and an excused shift never become no-shows;
- a very late check-in supersedes the no-show, the derived state becomes checked-in, and both operations remain in history;
- an authorized user creates a sheet, places metrics on it, orders them, and configures each placement independently;
- the same metric type placed twice carries different configuration in each place;
- an unauthorized user cannot manage sheets, and a user with sheet-management authority still cannot configure a sheet to show data they cannot read;
- filters are offered at the sheet level only, and selections survive navigation within the session;
- a sheet reads one event and offers no comparison or multi-event view;
- a department lead sees only their department; an organizer sees across departments; Planning and Logistics see only their own;
- Command sees its own department plus what has been explicitly shared with it, and nothing more;
- incident- and Field-Report-derived metrics are invisible outside the IC pool;
- an aggregate covering fewer than 5 people is withheld with a stated privacy reason, never as zero or blank;
- no rendered Insight, and no audit payload, contains a volunteer name or other PII;
- a volunteer sees their own completed shifts, missed shifts, late arrivals, hours, hours by team, and credits, and cannot reach anyone else's;
- metrics report healthy conditions as clearly as they report problems, and operational state and data-quality state remain separate indicators;
- a metric updates after a synchronized change without a manual refresh;
- a device with no node reachable still renders its sheets, discloses staleness accurately, and reports incomplete where it lacks data;
- an action link opens the operational surface under that surface's own authorization;
- a lead shares a sheet with Command and separately shares one metric placement, and both show their originating department;
- a restricted metric on a shared sheet stays restricted for Command;
- a temporary share stops applying when the event's operations window closes;
- sharing and unsharing appear in audit; PDF generation and favorites do not;
- the browser PDF matches what was on screen including filters and freshness warnings, and Meridian stores no copy of it;
- Insights appear in primary navigation, and sheets the user cannot access do not appear at all.

**QA gate:** A human can watch a shift become a no-show automatically at the window boundary and then be superseded by a late check-in; build a sheet from registered metrics and configure two placements of the same metric differently; confirm a department lead, an organizer, Command, and a volunteer each see a different and correct slice of the same sheet; confirm an IMS-derived metric is invisible to a non-IC user; confirm a four-person aggregate is withheld rather than shown; take a device offline and confirm the sheet still renders with accurate staleness; share a sheet and a single metric with Command, confirm the originating department is labelled, and confirm a restricted metric on that sheet stays hidden; and save the view to PDF and confirm it matches the screen and is stored nowhere.

**Explicitly out of scope for this milestone:**

- cross-event comparison, multi-event sheets, cross-organization comparison;
- organization-authored metric formulas or query builders;
- user-authored narrative analysis, AI-generated insights, predictive analytics;
- trend history across an event;
- configurable thresholds;
- metric dismissal, acknowledgement, assignment, or resolution;
- individual staff rankings or leader access to named volunteer records through Insights;
- persistent saved Insight results, stored PDF snapshots, or snapshot history;
- per-metric user filters and role-specific default sheets;
- an analytics warehouse, external business-intelligence system, or event stream;
- design of Insight Sheets or metrics beyond the three approved above.

---

### Milestone 18: Gap Closure

**Goal:** Close the distance between what the source documents specify and what a person can actually do in the product, so that every MVP feature is reachable, wired to real data, and administrable from a normal product surface rather than only from God Mode.

This milestone was specified from a full audit of the requirements, technical, data/API, and UI documents against the implementation as of version 0.0.49. It divides into five parts with different characters, and they are listed in dependency order rather than in importance order.

**Part A** is domain code that exists, is tested, and cannot be reached. Seven services have no controller, no route, and no user interface; four of them have no consumer anywhere in the application. This is the cheapest work in the milestone and the most valuable, because the hard part is already written and reviewed.

**Part B** is scope the source documents did not previously carry. It arrived through the requirements additions listed in the requirements document header: organization configuration, department team designations, document-backed waivers, notifications, the applicant portal, and the public marketing surface. No task in Part B may begin before its requirement IDs are settled, because those IDs did not exist when earlier milestones were planned.

**Part C** is product surfaces the UI implementation contract has always named and no milestone ever built. Most are small once Part A and Milestone 16 exist.

**Part D** is documentation reconciliation with no runtime behavior of its own.

**Part E** is the Event Horizon: one staff-facing surface that compiles what a staff member still has outstanding in preparation for an event out of the records Parts A, B, and C make reachable. It is listed last because it consumes all of them and adds no domain of its own. It is a reporting surface: it enforces nothing, it holds no state beyond one personal preference, and every rule it reports is enforced where it already was.

**Sequencing note.** Milestone 16 is a hard prerequisite for most of Parts A and C. Until the client holds a real session, every surface built here would consume `department-ops/fixtures.ts` and its siblings and would then have to be rewired. Part B tasks M18.10 through M18.13 are the exception and should land *before* Milestone 16, because they change the permission model that Milestone 16 wires into navigation, and rewiring navigation twice is the expensive order.

Two dependencies run against the task numbering rather than with it. M18.20A needs the Staff Coordinator role from M18.11, because that role is half of who reviews a profile change request. M18.20D needs the notification path from M18.21, because a decision the submitter is never told about is not a decision they can act on; M18.20A through M18.20C are complete without it, so M18.20D is the task that waits.

Part E's item tasks each wait on the surface that resolves their item: M18.39 on the acknowledgment path (M18.6) and waiver administration (M18.18), M18.41 on staff shift signup (M18.2), and the Event Horizon surface itself (M18.43) on Milestone 16. M18.38 and M18.38A are the exception and can land first, because a readiness contract with nothing registered against it is still testable.

**Primary source docs:** Requirements sections 3.9, 3.11–3.15, 3.46, 4.4, 4.8A, 4.9, 7.1 (ORG-017–ORG-021), 7.2 (VOL-014–VOL-026), 7.4 (APP-012–APP-015), 7.5 (TEAM-011–TEAM-018), 7.6 (TRAIN-011, WAIVER-007–WAIVER-010), 7.7 (SHIFT-017, SHIFT-018), 7.9 (SLB-031, SLB-032), 7.13 (EQUIP-008–EQUIP-017), 7.14 (REPORT-014, REPORT-015), 7.24 (NOTIFY-001–NOTIFY-010), 7.25 (PUBLIC-001–PUBLIC-006), 7.28 (HORIZON-001–HORIZON-018); Technical spec sections 15, 20, 21, 21D, 22, 23; data/API sections 5.2, 5.8A, 6.4, 10.1, 10.5, 10.6, 10.8, 10.10, 10.12, 10.13, 10.21, 11.9, 11.10, 15.2; UI implementation contract sections 7, 9.6, 12, 13, 17, 19A, 19C.

#### Part A: Unreachable domain

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.1 Department presence commands and UI | Expose `DepartmentPresenceService` as `mark-staff-on-site` / `mark-staff-off-site` commands and wire the Logistics Desk staff workspace controls to them. Enforce the off-site blocks for an open shift and for held equipment. | SLB-015 through SLB-018; technical spec 20.3; data/API 10.10A | Domain/policy/UI tests; a test asserting off-site is refused while checked in; a test asserting off-site is refused while equipment is held |
| M18.2 Staff shift signup | Expose `ShiftSignupService` and `ShiftRemovalService` as `sign-up-for-shift` / `withdraw-from-shift` commands for ordinary shifts, not only through the training-linked path, and build the `staff.shift-board` surface where a staff member browses eligible shifts and signs up. | SHIFT-011 through SHIFT-015, SHIFT-018; requirements 3.12, 5.5 | Domain/policy/UI tests; a test asserting each eligibility denial reason renders; an overlap warning test asserting it warns rather than blocks |
| M18.3 Unscheduled shift addition | Expose `UnscheduledShiftAdditionService` as an `add-staff-to-shift` command and wire the Logistics Desk staff workspace action to it, requiring on-site presence first. | SLB-008, SHIFT-016; requirements 5.8 | Domain/policy/UI tests; a test asserting a staff member not on-site is refused; a test asserting a staff member outside the department/team is refused |
| M18.4 Hours correction | Expose `HoursCorrectionService` as a `correct-hours` command and build the Logistics Desk workspace path: search staff, open a completed shift, edit actual start/end. Refuse once frozen with a message naming the closed grace period. | SLB-007, SLB-031, SLB-032; HOURS-007, HOURS-008 | Domain/policy/audit/UI tests; a test asserting a frozen record refuses correction; a test asserting prior values survive in history |
| M18.5 Credential revocation | Expose `CredentialRevocationService` as a `revoke-credential` command restricted to organizers and IC department leads, and build the `organizer.credentials` surface. Remove future shifts, preserve completed shifts and hours. | CRED-009 through CRED-014 | Domain/policy/audit/UI tests; a test asserting a non-organizer non-IC-lead is refused; a test asserting recorded hours survive revocation |
| M18.6 Document acknowledgment path | Expose `DocumentAcknowledgmentService` and `DocumentAcknowledgmentRequirementService` as an `acknowledge-document` command plus requirement administration, and build `signup.policy-acknowledgment`, `staff.document-acknowledgments`, and `organizer.document-acknowledgments`. Record document and version. | POL-023 through POL-027, POL-043 through POL-047 | Domain/policy/UI tests; a test asserting the acknowledged version is recorded; a test asserting acknowledgment is not presented as a shift-signup or credential gate |
| M18.7 Staff document library | Build `staff.documents` and `staff.document-detail` reading the existing document endpoints under published-document visibility, so staff reach policies and procedures outside a department administration surface. | POL-006, POL-008 through POL-013, POL-055; UI contract 12.3 | UI/policy tests; a test asserting an unpublished document is absent for a non-maintainer |
| M18.8 Department operations API binding | Replace `department-ops/fixtures.ts` in Department Overview, Logistics Desk, Operations Center, and Planning Table with the real endpoints, routing every mutation through the Milestone 16 command outbox. | CLIENT-015, CLIENT-023; SLB-001 through SLB-022 | UI/offline/idempotency integration tests; a test asserting no fixture module is imported by a department operations view |
| M18.9 Remaining fixture removal | Remove the remaining development-session installers and fixture models behind departments, teams, trainings, equipment, shifts, documents, event info, staff administration, and IMS, once Milestone 16 supplies the session. | CLIENT-001, CLIENT-023, CLIENT-024 | Integration tests per surface group; a repository check asserting no production module imports a fixture; client tests still run without a live server |

#### Part B: New scope

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.10 Team designation model | Add department team designations for Logistics, Operations, Planning, Administration, and Operator, and the organization Staff Coordinator designation on a team within the Organizers Department. A designation attaches an existing department operational grant to a named team; it does not become a new authority path. | TEAM-011 through TEAM-014, TEAM-017; data/API 6.4, 10.6 | Schema/domain/audit tests; a test asserting a designation grants exactly the documented capability set; a test asserting removing a designation removes the derived authority |
| M18.10A Department Operator role | Add `department_operator` to the permission catalog carrying the section 4.8A Operator capabilities, and derive event-scoped `ic_operator` for the designated Operator team when its department is the event's Incident Command Department. The elevation comes from the two designations together and is not separately grantable. | TEAM-012, TEAM-012A; requirements 4.8A; technical spec 16.2; data/API 6.4, 6.5 | PermissionCatalog/policy tests; a test asserting the elevation applies only for events where the department is IC; a test asserting the elevation cannot be granted directly |
| M18.11 Staff Coordinator role | Add `staff_coordinator` to the permission catalog as an organization-scoped role carrying application review, approval, rejection, and deferral, and no other organizer governance capability. | TEAM-014; requirements 4.4; data/API 6.4, 10.7 | PermissionCatalog/policy tests; a test asserting a Staff Coordinator cannot manage departments, staff status, or credentials |
| M18.12 Designation administration | Add department team designation management to the department administration surface and organization designation management to the organization configuration surface, with permission explanations naming the designation. | TEAM-016, TEAM-018; UI contract 12.4, 12.6 | API/domain/policy/UI tests; a test asserting the explanation names the designation that granted the capability |
| M18.13 Attendance manager resolution | Resolve "authorized attendance manager" to `department_logistics` holders plus department leads and shift leads for the department, and apply it consistently to check-in, check-out, mark-no-show, and hours correction. | TEAM-015; SLB-007, SLB-029; HOURS-007 | Policy tests across all four operations; a test asserting the four agree on who is authorized |
| M18.14 Organization configuration | Add the hours correction grace period column with a documented default, and build the organization configuration surface covering lifecycle thresholds, grace period, calendar year start, default credit policy, and the three department designations. | ORG-017, ORG-018, ORG-020, ORG-021; data/API 10.1 | Schema/API/domain/policy/UI tests; a governance-freeze test reusing the existing suite; audit tests |
| M18.14A Incident type administration | Make the configurable incident type list configurable. Add the organization configuration surface ORG-018 requires at `organizer.configuration`, carrying the organization's incident types as its first featureset — add, rename, archive, restore — plus a God Mode Orchid screen for support, both audited, and stop `IncidentCreationService::syncIncidentTypes` and its update counterpart creating a type from an unrecognized name: an incident command names a configured type or is refused. Organizations start from `IncidentTypeDefaults` (M16.20) and curate from there. Unlike branding and policy governance, the list is **not** frozen during the active event window: it is read at the moment an IC operator categorizes a live incident, and an organization that meets something its list does not cover during an event needs to add it during the event. The requirements have listed incident types as a configurable area since the first draft and `incident_types` has carried `organization_id` and `archived_at` since M11.7A; what never existed was a way to configure them, so the incident form created them as a side effect of being filled in. | ORG-018, ORG-020, ORG-021; INC-007; requirements "Configurable areas"; data/API 10.16 | Domain/policy/audit/UI tests; a test asserting an unrecognized type name is refused rather than created; a test asserting an archived type stays on the incidents that carry it and off the assignable list; a test asserting a non-organizer cannot edit the list |
| M18.15 Lifecycle threshold evaluation | Add the scheduled evaluator that applies the Prospective and Active inactivity thresholds, idempotently, through the audited status path, respecting STAT-009. | ORG-019; STAT-009 through STAT-011 | Domain/schedule tests; an idempotency test; a test asserting active department work prevents organization inactivity |
| M18.16 Credit policies | Add `credit_policies` and `credit_ledger_entries`, the organization default and shift override resolution, and product-UI credit policy administration. This is the schema Milestone 13.5 and 13.6 depend on and it does not exist. | ORG-009, ORG-010; SHIFT-010; CREDIT-001 through CREDIT-005; data/API 10.12 | Schema/domain/policy/UI tests; a test asserting shift policy wins over organization default; a freeze test |
| M18.17 Document-backed waivers | Add the optional published-document reference on waivers, render document content with fragments inline at completion, and record the acknowledged document version alongside the completion. | WAIVER-007 through WAIVER-009; POL-022, POL-043 | Schema/domain/rendering tests; a test asserting a waiver with no document behaves unchanged; a version-recording test |
| M18.18 Waiver administration | Expose `WaiverService` and build waiver create/scope/expiration administration and completion recording, with authority following waiver scope. | WAIVER-001 through WAIVER-006, WAIVER-010 | API/domain/policy/UI tests; a test asserting an expired waiver blocks credential eligibility; scope-authority tests |
| M18.19 Relative schedule cutoff | Let a schedule lock be expressed as an offset before the active event window start as well as an absolute timestamp, resolving to absolute whenever the window is known. | SHIFT-009, SHIFT-017 | Domain tests; a test asserting a moved event window moves a relative cutoff and leaves an absolute one alone |
| M18.20 Staff profile surface | Build `staff.me` and `staff.profile-edit` so a staff member maintains their own preferred name, phone, and city/state, taking effect without review, and reads the rest of their profile. Refuse self-service edits to legal name, email, and date of birth on the server as well as hiding them, and route the edits through the Milestone 16 command outbox. | VOL-009, VOL-014, VOL-015, VOL-016, VOL-026; data/API 10.4; UI contract 12.3 | API/domain/policy/UI/audit tests; a test asserting one staff member cannot edit another's profile; a test asserting a submitted legal name, email, or date of birth is refused rather than silently dropped; an audit test asserting before and after values are recorded |
| M18.20A Profile change request model | Add `staff_profile_change_requests` and the domain service both request kinds share: pending/approved/rejected/withdrawn states, one outstanding request per kind per staff member, staff-side withdrawal, reviewer resolution from the organizations the staff member holds a status with, and audit of creation, decision, and withdrawal. Add the `staff.profile-change-requests.review` capability to the permission catalog carrying it to organizers and to the M18.11 Staff Coordinator, and to no other role. | VOL-019, VOL-024, VOL-026; data/API 10.4; technical spec 15, 23 | Schema/domain/policy/audit tests; a test asserting a second outstanding request of the same kind is refused; a test asserting a staff member can withdraw their own request and cannot withdraw another's; a test asserting no role outside organizer and Staff Coordinator can review |
| M18.20B Handle changes and the self-service allowance | Record every handle change as a request row, applying the first two for a staff record without review and requiring approval for each one after. Treat setting a first handle as not a change. Count the allowance from applied changes rather than a counter column, so a rejected or withdrawn request restores nothing because it consumed nothing. Show the staff member how many self-service changes remain before they spend one. | VOL-017, VOL-018, VOL-020, VOL-026; data/API 10.4 | Domain/policy/UI tests; a test asserting the third change becomes a request rather than applying; a test asserting a first handle does not consume the allowance; a test asserting a rejected and a withdrawn request leave the allowance unchanged; a test asserting an approved request applies the handle and a rejected one does not |
| M18.20C Profile picture change requests | Make a picture submission a change request carrying its own processed image, keeping the current picture visible until approval and the submitted picture readable only by its submitter and its reviewers. Reuse the section 18A limits and processing — JPEG/PNG/WebP, 10 MB before processing, resize within 1024 x 1024, EXIF stripped — and the existing short-lived scoped URL path for reading either image. Approving promotes the submitted image to the current picture; rejecting or withdrawing discards it. Removing one's own current picture stays immediate and needs no review. Submission and decision are online-only. | VOL-013, VOL-021, VOL-022, VOL-023, VOL-026; technical spec 18A; CLIENT-019 | Upload/policy/visibility/UI tests; a test asserting a non-active staff member cannot submit; a test asserting a pending picture is unreadable to a user who is neither its submitter nor a reviewer; a test asserting the current picture is unchanged while a request is pending; a test asserting rejection leaves no stored image behind; a test asserting removal creates no request |
| M18.20D Change request review and notification | Build `organizer.profile-change-requests` for approving and rejecting with a reason, showing the previous and requested handle side by side and the current and submitted picture side by side, and naming any active staff member in the organization already using a requested handle. Build `staff.profile-requests` for the submitter's own view. Notify the submitter of each decision through the M18.21 notification path, carrying the reason on a rejection. | VOL-019, VOL-020, VOL-021, VOL-024, VOL-025; UI contract 12.3, 12.6; NOTIFY-001 | UI/policy/notification tests; a test asserting a handle collision is named to the reviewer and does not block the decision; a test asserting a rejection reason reaches the submitter; a test asserting a reviewer sees only requests from organizations they hold review authority in; a test asserting no notification is sent for a request the submitter withdrew |
| M18.20E Profile change approval policy | Let an organization configure how handle changes and how profile picture changes are approved, each independently, from four policies: approved by organizers (default), organizer sets the first one, applied without review, and staff set the first one. Make the self-service handle change allowance a configurable number defaulting to two, consulted only while the policy applies changes without review. Show a staff member the state of their most recent submission of each kind including a rejection and its reason, replaceable by a new submission and dismissable without destroying the row. Configure from both the organizer configuration surface and God Mode. | VOL-027, VOL-028, VOL-029; VOL-010; ORG-018, ORG-020; data/API 10.1, 10.4 | Domain/API/policy/UI tests; a test asserting the default reviews every change including the first; a test asserting a zero allowance switches self-service off without changing the policy; a test asserting an unrecognised policy is refused rather than becoming the default; a test asserting a rejection and its reason reach the submitter and can be dismissed without restoring an allowance |
| M18.21 Notification delivery | Add the transactional email path: templates for the NOTIFY-001 set, organization branding, queued delivery, send recording, central-node-only sending, and the suppression switch. | NOTIFY-001 through NOTIFY-010; BRAND-002 | Mail/queue/policy tests; a test asserting DNS auto-rejection sends nothing; a test asserting an unverified address receives nothing; a test asserting a delivery failure does not roll back its operation |
| M18.22 Applicant portal | Add applicant self-service: request a signed link by email, view own applications, withdraw a withdrawable application, with enumeration protection, rate limiting, and audit. | APP-004, APP-012 through APP-015; AUTH-010 | Feature/policy/audit tests; a test asserting a DNS auto-rejected application is absent; a test asserting the request response is identical for known and unknown addresses |
| M18.23 Marketing surface and organization interest | Add the public marketing surface at the deployment root with the organization interest form, the inquiry record, God Mode review, rate limiting, and audit. Not served by an on-site node or an event-locked node. | PUBLIC-001 through PUBLIC-006 | Feature/policy tests; a test asserting Meridian identity is not replaced by an organization profile; a test asserting an on-site node does not serve it |
| M18.24 Equipment assignment scope | Add the shift-or-event assignment scope to equipment checkouts, and derive overdue and unknown as presentation states rather than storing them. | EQUIP-005, EQUIP-009; UI contract 9.6 | Schema/domain tests; separate cases for shift-assigned and event-assigned timing; a test asserting no new stored state was added |
| M18.24A Dictated Field Reports | Give the existing client dictated-Field-Report surface a server side: authorize taking a report on behalf of another staff member for `department_operator`, `ic_operator`, and `ic_lead`; record author and submitter separately; scope the staff selector to staff the creating user may already see; keep append authority with the author. The client surface exists today with no requirement, no server authorization, and no scoping on its staff directory. **Delivered for `ic_operator` and `ic_lead` only.** Granting the capability to `department_operator` means giving the TEAM-012 Operator designation a capability, and the whole section 4.8A Operator capability set is M18.10A's; that half of FR-015 ships with M18.10A. The selector scope is the department the granting team belongs to — requirements 4.8A, "another staff member of the department" — for every holder, so an IC role reaches the department carrying the event's Incident Command designation and no further. | FR-015 through FR-017; requirements 4.8A; technical spec 17.3, 17.4 | Domain/policy/UI tests; a test asserting the submitter gains no append authority; a test asserting the staff selector discloses no staff outside the creating user's scope; a test asserting an unauthorized user cannot take a report for anyone |
| M18.24B Pooled and tracked equipment model | Add `equipment_items.tracking` and `quantity_total` and `equipment_checkouts.quantity` and `quantity_returned`. Default existing rows to `individual` with quantity 1, which is what they already are. Derive pooled availability as total less open checkout quantity, never storing `checked_out` on a pool, and let a pooled checkout return in parts. Record missing and damaged pooled returns as an audited adjustment of the pool's serviceable quantity with a reason. Extend inventory administration and CSV import to carry the tracking kind and pool quantity, matching a pooled row by department, name, and kind rather than by asset tag. | EQUIP-010, EQUIP-011, EQUIP-016, EQUIP-017; data/API 10.13; UI contract 9.6, 9.6A | Schema/domain/audit tests; a migration test asserting every existing item becomes individually tracked with quantity 1; a test asserting pooled availability falls and recovers as units go out and come back; a test asserting a pool is never stored `checked_out`; a partial-return test; a test asserting a damaged pooled return reduces the serviceable quantity and audits the reason; an import test asserting a re-run updates a pool rather than creating a second one |
| M18.24C Equipment lookup | Add equipment lookup for checkout: match name, asset tag, and serial number within the operator's authorized department and event scope, offering only equipment available to hand out and disclosing nothing outside scope. Resolve an exact asset tag or serial match to that item directly so a barcode scanner acting as a keyboard completes a handoff, and report an ambiguous or unmatched value rather than guessing. Resolve lookup against the department inventory the surface already holds so it works with no connectivity. | EQUIP-012, EQUIP-013, EQUIP-015; SLB-011, SLB-012; data/API 10.13 | Domain/policy/offline tests; a test asserting an asset tag from another department returns no match and does not reveal that the item exists; a test asserting an exact tag match resolves without selection; a test asserting an ambiguous value resolves to nothing and says so; a test asserting lookup works against cached inventory with the node unreachable |
| M18.24D Equipment checkout surface | Replace the unit-by-unit checkbox list in the Logistics Desk checkout and hand-off dialogs with the two presentations: a short quantity list for pooled kinds, and a lookup field with autopopulating results for tracked units, each selection adding a line the operator can remove before committing. Carry the same split into the return path, where a pooled return takes a quantity and a condition. Route both through the Milestone 16 command outbox with the existing idempotency keys. | EQUIP-012, EQUIP-014, EQUIP-017; SLB-011, SLB-012; CLIENT-015; UI contract 9.6A, 12.4 | UI/offline/idempotency tests; a test asserting no surface renders one checkbox per tracked unit; a test asserting a department with hundreds of tracked units still renders a bounded checkout dialog; a test asserting a scanned tag adds a line without a pointer event; a test asserting a repeated submission checks nothing out twice |

#### Part C: Missing contract surfaces

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.25 Remaining exports | Add shift roster, staff contact list, hours worked, and credits earned exports with their scope and field-exclusion rules. | REPORT-002 through REPORT-005, REPORT-008 through REPORT-010; CREDIT-005 | Export tests with samples; a test asserting shift rosters exclude phone and emergency contacts; a test asserting organizer exports exclude emergency contacts |
| M18.26 Reporting surfaces | Build the organizer and department reporting surfaces, offering only authorized exports and stating scope and excluded fields before generation, downloading through short-lived scoped URLs. | REPORT-014, REPORT-015; CLIENT-019, CLIENT-020 | UI/policy tests; a test asserting an unauthorized export is absent rather than disabled |
| M18.27 Users, teams, shifts, and assignments import | Add the CSV/spreadsheet import paths for users and teams and for shifts and assignments. **The CSV half landed after this milestone was specified**: M13.7 and M13.8 built all four screens at version 0.0.55, against an audit taken at 0.0.49, so what remained here is the spreadsheet half the technical spec asks for in the same breath. Let the four uploads take the `.xlsx` workbook the roster was built in as well as a CSV exported from it, detecting the format from the file's contents rather than its name, so both land on the same rows. Read the workbook with the zip and XML support PHP already ships rather than adding a spreadsheet library to the baseline. The first sheet is the imported one; an empty cell is absent from a workbook rather than empty and must not shift the columns beside it; a deleted row leaves a gap in the numbering an operator reads outcomes by; and a date is a number wearing a format, which has to reach the event-timezone rule as the moment the spreadsheet displays. Refuse a legacy `.xls` and an OpenDocument file by name rather than reading them as CSV and blaming a missing column. | Technical spec 22.2 | Import tests with fixtures; malformed-row and partial-failure tests; a test asserting a workbook and a CSV of the same table produce identical row outcomes; a test asserting an omitted cell does not shift the columns after it; a test asserting reported row numbers are the spreadsheet's own across a deleted row; a test asserting a date-formatted cell lands on the moment the sheet displays; a test asserting only the first sheet is imported; a test asserting a workbook declaring a document type is refused rather than parsed |
| M18.28 Dashboards | Build the dashboard surfaces and the widget inventory for staff, department lead, department operations, organizer, IC, and kiosk groups, honoring the quiet states and the organizer IMS exclusion. Compile the whole inventory on one event-scoped read behind all five dashboard surfaces — `staff.dashboard`, `department.dashboard`, `organizer.dashboard`, `ims.dashboard`, and `kiosk.home` — because they are five presentations of one question and five endpoints would be five places for the answers to disagree. Resolve every group from the gate its own surface already uses rather than from a new one, so a dashboard discloses nothing the reader could not open the surface behind the widget and see, and leave a group the reader does not hold out of the response rather than empty. Keep the organizer IMS exclusion by construction: the organizer group contains no widget that reads an incident, a Field Report, or a count of either, so an organizer who also holds IC standing reads that data through 13.5 as an IC user and never through organizing. **Five of the thirty-six catalogued widgets do not ship**, because widget spec 4 says a widget that cannot answer its required anatomy should not: `staff.briefing` and `ic.briefing` wait on Milestone 15, `dept.event_map` and `kiosk.event_map` on M14.9, and `org.planning_tasks` on a planning task record no requirement has yet specified. Catalogue them anyway, naming the task that will answer each, so a gap is not mistaken for an omission. Two more — `kiosk.node_status` and `kiosk.switch_user` — are the device's to answer, since a node reporting on its own reachability to a device that reached it can only say yes. | UI contract 13.1 through 13.6 | Widget/policy tests; a test asserting organizer widgets surface no incident data without IC authority; quiet-state tests; a test asserting the catalogue carries every row of the contract's six tables; a test asserting a deferred widget is catalogued and never compiled; a test asserting a department lead and a `department_logistics` holder read different halves of 13.2; a test asserting the kiosk group opens on a trusted workstation and not on a personal device |
| M18.29 Context and organizer surfaces | Build `context.organizations`, `context.events`, `context.departments`, `organizer.dashboard`, `organizer.applications`, `organizer.application-detail`, `organizer.events`, and `organizer.audit` as product surfaces. **Four of the eight already exist**: `context.organizations` and `context.events` from M16.7, `organizer.dashboard` from M18.28, and `organizer.applications` from M18.21A, so what this task builds is the four that do not. Make `context.departments` the one context screen that is not connected-only — the departments in scope are already part of the cached session, so it reads the session document rather than an endpoint and opens on a device with no signal, which is the state a lead standing in a field is most likely to be in. Give `organizer.application-detail` a read of its own rather than a row lifted out of the queue, because the queue is filtered and a link somebody followed usually names an application the last read never contained. Move event administration onto `organizer.events`, keeping the published dates and the active event window as separate fields because they are different facts, offering the ORG-006 Incident Command override only from the event's own participating departments and naming the ORG-005 default an event inherits, and leaving the event row itself writable wherever an organizer is standing — M12.6 exempts it from event authority on purpose, so that the very window making a node read-only for everything else can still be closed — while reporting the phase and the node holding the event's other records as context. Have `organizer.audit` answer the attribution half of requirements 2.4 — who acted, on what, when, from where, and why — and name the fields that changed **without their values**: an audit payload is a verbatim copy of whatever the writing path snapshotted, so serving it would make the surface an unscoped read of every field every audited path happens to record, and repair work needing the values is M18.34's God Mode screen. Keep incident and Field Report history out of it by entity type rather than by filter, because ORG-015 is a rule about what organizing reaches and a row saying who reopened which incident discloses that the incident exists. Add `organization.events.manage` and `organization.audit.review` to the two organizer roles and to neither the Staff Coordinator nor any department role, which is TEAM-014's "no other organizer governance capability" applied to both. | UI contract 12.2, 12.6, 12.10.2; APP-003, APP-005, APP-011, APP-019; ORG-005, ORG-006, ORG-015; TEAM-014; requirements 2.4; technical spec 10.2; data/API 10.2, 14.1 | UI/policy tests; a test asserting application review authority covers organizer and Staff Coordinator only; a test asserting a department lead's decision is refused by the node and the application is left Submitted; a test asserting an Incident Command department that does not participate in the event is refused; a test asserting an omitted `ic_department_id` leaves the designation alone and an explicit null clears it; a test asserting an event inside its active window can still have that window closed from a node that holds authority for none of its other records; a test asserting no incident or Field Report entity type reaches the organizer audit read; a test asserting an audit row carries field names and no field values; a test asserting the department context screen renders from a cached session with the device offline |
| M18.30 Department surfaces | Build `department.dashboard`, `department.roster`, `department.deployments`, and `department.credits`. **`department.dashboard` already exists** from M18.28, so what this task builds is the three that do not, one event-and-department-scoped read each, in the shape the four department operations reads already use. Make `department.roster` the department's staff list rather than a second copy of the Admin page's: that one exists to put people on teams and carries a display name and a handle, and this is the list somebody reads to *reach* their department. Open it to the three standings UI contract 12.4 names — `department.administer` and `department.schedule.manage` for the whole department, a designated team lead for the teams they lead — and let planning in deliberately, because SLB-019's identity-free rule is a property of the Planning Table and SLB-020 says so in as many words, while somebody building next week's coverage has to know who is available to build it from. Serve emergency contacts to the `department.administer` holders and to nobody else (VOL-012), and **omit the fields from the payload rather than blanking them**, because a blank emergency contact has to keep meaning "none recorded" to the lead reading it — the same call M13.3 made for the export file, resolved through the same population so a roster and a file cannot disagree about who may read a next-of-kin phone number. Filter the roster on the client, so a lead standing in a field searching for a number does not need a round trip to find one. Give `department.deployments` the writes `deployments` has never had: the table has existed since M10.8 and the Operations Center has read it since M16.21, but nothing outside a factory ever wrote a row, which left SLB-009's "shall always include a deployment/location module" true and empty at the same time. Archive rather than delete, refuse archiving a deployment somebody is currently standing at and say how many have to be moved first, and keep names unique per event and department whatever their casing, because two spellings of one gate are two rows an operator has to choose between over the radio. Open it to `department.deployments.assign` as well as `department.administer` — the operator moving people at two in the morning is the one who discovers the list is missing a gate. Have `department.credits` answer to `reports.credits_earned.export`, the same capability the file behind it answers to, because reading the ledger and exporting it are the same rows and the same disclosure; read every rate and policy name from the entry's frozen `calculation_basis` rather than the policy row it names, so a re-rate cannot restate a finished event (CREDIT-004); and report the hours this department worked that carry no entry, so a total that looks low says why. Write nothing from it: calculation is an organizer's (ORG-010). Add no permission capability at all — every one of the three resolves through codes the catalog already registers. | UI contract 12.4; VOL-011, VOL-012; SLB-009, SLB-010, SLB-019, SLB-020; REPORT-005 through REPORT-007, REPORT-009, REPORT-010; CREDIT-004, CREDIT-005; ORG-010; data/API 10.12, 10.14 | UI/policy tests; a test asserting department leads reach emergency contacts for their own department only; a test asserting the emergency contact fields are absent from the payload rather than served empty; a test asserting a team lead reads only the teams they lead; a test asserting a deployment holding staff cannot be archived until they are moved; a test asserting a second deployment of the same name is refused whatever its casing; a test asserting a re-rated policy does not move a frozen credit entry; a test asserting the credit read writes no record |
| M18.31 Event department participation | Add management of which departments participate in an event to the event administration surface, with the Placement and Incident Command validation rules applied. `event_department_assignments` has existed since M4 and decides which departments an applicant may name, which departments a session carries, who the Incident Command designation may be, and which staff a contact export reaches — and nothing outside a seeder ever wrote a row, so an organization that created a department mid-season had no way to put it in the event it was created for. Put both changes on `organizer.events` as their own commands rather than as fields of the event form, because adding a department is a different act from correcting a schedule and the form's Incident Command select reads the very list being changed. Send the participating list once and read it for both jobs: ORG-006 admits only a department assigned to *that* event, which is that list exactly, so two lists would be two answers that could drift. Archive on removal rather than delete, because a department that worked an event worked it. Restore an archived row when a department comes back rather than writing a second, which is what the table's uniqueness already says and what "removed by mistake" needs. Enforce both designation rules from the removal side: ORG-006 and PLACE-003 each say a designation may only name a department assigned to the event, and checking that only when the designation is *made* leaves the other door open — the department could leave afterwards and the designation would survive pointing at a department that no longer works the event. **This adds `events.placement_department_id`**, which data/API 10.2 has specified since the Placement requirements were drafted and no milestone had created, exactly as M18.14 added the organization half of the same pair when the surface it built named it: a rule that cannot be checked is not a rule. Choosing the Placement designation is still M14.1's, and belongs there rather than here, because PLACE-004 makes the designation unlock map authority that M14.5 builds — a selector offered before that exists would let an organizer grant nothing and be told it worked. | ORG-006; PLACE-003; data/API 10.2, 10.6 | Domain/UI tests; a test asserting a designated Placement or IC department cannot be removed from the event while designated; a test asserting removal archives the assignment rather than deleting it; a test asserting a returning department restores its row rather than being assigned twice; a test asserting a department of another organization is refused; a test asserting the read offers the same list for participation and for the Incident Command choice |
| M18.32 Kiosk surfaces | Build `kiosk.home`, `kiosk.workstation-login`, `kiosk.switch-user`, `kiosk.reauth`, `kiosk.shift-board`, and `kiosk.safe-timeout` in the Kiosk artifact. **Three of the six already exist** from M16.9 — the two a shared-workstation session begins and ends at, and the dashboard it holds open — so what this task builds is the other three plus the setup state the test expectation names. That state did not exist in any layer: `shared_workstations.organization_id` and `event_id` were created NOT NULL, so every workstation was pinned by construction and "if Meridian Kiosk starts without a pinned organization and event, it enters setup" described a row the table could not hold. Make both nullable, add the unauthenticated read a locked Kiosk asks its pinned context with — it has no credential to ask with, and whether it may offer a login screen at all is what the answer decides — and add `kiosk.setup` as where every other Kiosk route goes until the node says the machine is pinned. Keep the last answer against an unreachable node, which is remembering what the node said about this machine rather than one of the five inferences UI-020 names. Give the pinned-context change to `organization.events.manage` for the event and department halves, and leave the organization to God Mode: a workstation with no pinned organization has none for an organizer's authority to be held in, and cannot be signed in to at all, because a login code is scoped to a pinned event. Add the God Mode screen 13.1 has always required and no milestone built, since until now nothing outside a seeder ever wrote a pinned context. Make `kiosk.reauth` a fresh login code for the session's own user, checked by the node: 18.2 rules out a separate PIN as an independent central credential, and the login code is already scoped to one user and this workstation and generated in seconds from a phone against a node with no route to central. Make `kiosk.shift-board` the desk's attendance surface over the Logistics Desk read at the workstation's own scope, not a second copy of `staff.shift-board` — 12.8 gives it to a shift or department lead and kiosk guide 5 rules out self check-in — and queue its writes through the outbox that already carries them. | UI-019 through UI-023; UI contract 12.8, 18, 19.3; kiosk guide; technical spec 13.1, 13.3; data/API 12.3, 12.6 | UI/session tests; a test asserting an unpinned Kiosk enters setup rather than inferring context; a test asserting a session naming an event does not become a pinned context; a test asserting a re-authentication code for another user is refused and hands nothing over; a test asserting a department that does not work the event cannot be pinned; a test asserting a pinned-context change ends the live session |
| M18.33 Command palette | Build the command palette with its shortcuts and permission-filtered results, excluding camps and map places. Build it over the navigation derivation the shell menus and the home directory already render from, rather than over a read of its own: the palette is a second way into pages somebody can already reach, not a second answer about what they may reach, and two derivations would be two places for the answer to differ the day a grant is withdrawn. That is also how contract 7.2's filter list is satisfied without restating it — authenticated user, organization, event, department, role, and permission are what the session response already resolves, and the operations window reaches the results through the same document. Kiosk is the one branch: a shared workstation holds a machine rather than a personal session, so its results are built from the pinned context and the workstation state and carry no organizer or department page at all. **Records are not in scope.** Operating guide 10.3 offers incidents, Field Reports, and documents by title as something the palette *may* carry; what 7.1 and 7.2 require is actions and navigation, and the IMS half of 7.2 is answered by the three IMS pages following `incidents.view` rather than by a record search that would need a second rule. Camps and map places are excluded by construction rather than by a filter (MAP-018): nothing in navigation names one, and the palette adds no source of its own. | UI contract 7.1, 7.2; MAP-018 | UI/policy tests; a test asserting no unpermitted destination appears |
| M18.34 Orchid repair coverage | Add the missing God Mode screens: audit review, document acknowledgments, and field report and incident repair visibility under permission rules. **The audit review half shipped with M18.29**, because the product audit surface and the repair one are two readings of one table and building them apart would have meant two answers to "who did this" written weeks apart. `orchid.audit` carries the same organization/department/team filter bar the other God Mode lists use, and differs from `organizer.audit` in the three ways repair tooling has to: it spans organizations, it carries the node and system rows no organizer has scope for, and it excludes nothing — ORG-015 is a rule about what organizing reaches, not about support access. Narrow the team filter by subject rather than by scope, because a team is a thing changes happen *to* rather than a scope they happen *in*, and keep the map of team-owned records explicit for the reason `EventScopedWriteGuard` keeps its parent map explicit. `orchid.document-acknowledgments` reviews who accepted which document at which revision across the node, on the console permission alone, because an acknowledgment is an attestation about a document rather than content — the same side of the line the audit trail is on. `orchid.field-reports` and `orchid.incidents` are the other side, and are gated twice: the console permission opens the screen, and then the product's own rules decide which records are on it — `FieldReportPolicy` for a report, the `incidents.view` grant for an incident — so a console session cannot become event-wide reading of somebody's account of what happened to them. The entry screens re-ask the same question, because a list filter a typed address walks around is not a rule. That is the opposite call from the audit trail, and the line between them is a record versus its history: a row saying who reopened an incident is what repair work needs, and the incident's narrative is what ORG-015 and FR-005 are about. Neither screen writes. Technical spec 22.3 does let God Mode repair an incident, and a form writing fields straight back would produce a record whose own INC-007 history does not mention the change; visibility is what this task asks for. | UI contract 12.9; technical spec 22.2; requirements 2.4; ORG-015; Alpha 1 acceptance target 13 | Orchid feature/policy tests; a test asserting field report visibility in Orchid follows the same rules as the product; a test asserting the audit trail carries the incident history the product surface withholds; a test asserting each of the three scope filters narrows the trail; a test asserting the team-owned map covers every model that reaches a team through a column |

#### Part D: Reconciliation

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.35 Traceability restatement | Restate the traceability matrix so "In progress" distinguishes a requirement whose domain exists from one a user can exercise, and correct rows that read as delivered while their surface is fixture-driven. | Development process 5.2 | Process/doc checks |
| M18.36 Missing QA scripts | Add the QA scripts the plan already references: `QA-EXPORT-01`, `QA-MAP-01`, `QA-CLIENT-01`, `QA-INSIGHT-01`. | QA README | Human QA scripts |
| M18.37 Gap closure QA script | Add `QA-GAP-01-milestone-18-gap-closure.md`. | QA README | Human QA script |

#### Part E: The Event Horizon

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.38 Event Horizon readiness contract | Add the item-kind registration and the readiness query: given one event and the signed-in staff member, return each registered kind's items carrying state (outstanding or complete), the evaluation behind that state, what would complete it, its deadline where it has one, and its action link. Compile on read, register no kinds here, and persist no compiled result. Read only what the viewer is already authorized to read, omitting a kind whose records they cannot read rather than reporting it as unknown. | HORIZON-001 through HORIZON-004, HORIZON-007, HORIZON-008, HORIZON-016; technical spec 21D.1 through 21D.3, 21D.5, 21D.7; data/API 5.8A, 10.21 | Contract/registration tests; a test asserting no compiled result is persisted; a test asserting an item kind cannot be added, removed, or reordered by configuration; a test asserting the query refuses no operation and writes no record; a policy test asserting an unreadable kind is absent rather than empty |
| M18.38A Presentation window and event resolution | Present the Event Horizon only while the interface resolves to a single event, and only from the organization's configured lead-up window before the active event window start through the close of the operations window. Add the lead-up window to the M18.14 organization configuration surface with a documented 30-day default, under the same governance edit rules as the rest of that surface. | HORIZON-010, HORIZON-011; ORG-018, ORG-020, ORG-021; technical spec 21D.4; data/API 10.1 | Domain/config/UI tests; boundary tests at both edges of the window; a test asserting the surface is absent in organization-level context; a test asserting a moved event window moves the lead-up window with it; an audit test on the configuration change |
| M18.39 Acknowledgment and waiver items | Register the outstanding document acknowledgment and outstanding-or-expired waiver item kinds against the M18.6 acknowledgment path and the M18.18 waiver administration, each stating the requirement's scope and linking to the surface that completes it. | HORIZON-003; technical spec 21D.2; POL-043 through POL-047; WAIVER-003 through WAIVER-006; CRED-005 | Item tests over seeded fixtures; a test asserting an expired waiver reads outstanding rather than complete; a test asserting an acknowledgment satisfied at an earlier version stays complete (POL-045) |
| M18.40 Training items | Register the required training item kind covering trainings required by a department or team the member belongs to and by shifts they hold, distinguishing incomplete from expired, and linking to the training page. | HORIZON-003; technical spec 21D.2; TRAIN-002, TRAIN-008, TRAIN-009, TRAIN-010; SHIFT-005 | Item tests; a test asserting an expired training reads outstanding; a test asserting an online training links to its training page and an in-person one to its session signup |
| M18.41 Shift signup items | Register the shift signup item kind: shifts open to a team the member is on that still have capacity, and signup windows and schedule cutoffs about to close, ordered by how soon they close and linked to the M18.2 shift board. | HORIZON-003, HORIZON-006; technical spec 21D.2; SHIFT-004, SHIFT-007, SHIFT-008, SHIFT-011, SHIFT-017, SHIFT-018 | Item tests; a test asserting a full shift produces no item; a test asserting a closed cutoff produces no item; a test asserting a shift the member is already on reads complete rather than disappearing |
| M18.42 Team coverage gap items | Register the coverage gap item kind for teams the member leads: shifts below capacity within the event, reporting the shortfall and linking to the shift. Carry no staff names on the item. Produce nothing for a member who leads no team. | HORIZON-003, HORIZON-009; technical spec 21D.2, 21D.5; SHIFT-007; requirements 4.7 | Item/policy tests; a test asserting a member who leads no team sees no gap items; a test asserting a lead sees gaps for their own teams only; a test asserting no staff name appears in the item payload |
| M18.43 Event Horizon surface | Build `staff.event-horizon` as the ordered list: outstanding items first by soonest deadline then by catalogue order, each stating its evaluation, what completes it, and its action link; completed items retained, de-emphasized, and marked complete. Place it in the workflow menu and render it from cached data with an accurate staleness disclosure when no node is reachable. | HORIZON-001, HORIZON-004 through HORIZON-006, HORIZON-016; technical spec 21D.6, 21D.7; UI contract 12.3, 19C; CLIENT-001 | UI/policy/offline tests; a test asserting a completed item is present and marked rather than removed; an ordering test with mixed deadlines; a test asserting an action link enters its surface under that surface's own authorization; a test asserting an offline render discloses what it could not evaluate |
| M18.44 Personal dismissal | Let a staff member with nothing outstanding hide the Event Horizon from their workflow menu for that event, as personal view state per staff member per event, unaudited and invisible to others. Do not offer hiding while an item is outstanding. Restore it when an item becomes outstanding again, and let the member restore it themselves from `staff.me`. | HORIZON-012 through HORIZON-015; technical spec 21D.8; data/API 5.8A, 10.21; UI contract 19C.7 | Domain/UI tests; a test asserting hiding is refused server-side while an item is outstanding rather than only hidden in the UI; a test asserting a new outstanding item returns the surface; a test asserting the preference is not audited and not readable by another user |
| M18.45 Event Horizon QA script | Add `QA-HORIZON-01-event-horizon.md`. | QA README | Human QA script |

#### Part F: Offline sync features correction

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.46 Offline read set: regular staff | Add the authenticated endpoint that composes the technical spec 9.3 regular-staff cache set for the caller — own shifts, organization/department/team memberships, basic event data, the Field Report form, own submitted reports, visible published policy/procedure documents and referenced fragments, acknowledgment state, authored Notes and Briefing-included presentations, and readiness/sync state. Build it through `EffectiveRoleResolver`, the same resolver every API read already answers from, and bound it by the organization's active module set. Version the set so an unchanged one returns 304 rather than a payload, because the device asking is the one on a weak connection. **Record the measured payload size against seeded event data in the PR**: whether the section 9.3 set refreshes whole or needs deltas is the input M18.48 chooses storage on, and it is a measurement rather than a judgement. | CLIENT-021, CLIENT-022, MOD-016; technical spec 9.3, 9.5, 11A.7; data/API 7.1, 7.3 | Composition/scope tests ported from `Tests\Support\PowerSyncRules`; a test asserting a login with no staff profile receives nothing; a test asserting an unpublished document reaches nobody; a test asserting an inactive module's records are absent; a 304 test on an unchanged version; a recorded size measurement |
| M18.47 Offline read set: department and lead scopes | Extend the read set with the role-additive sections of technical spec 9.3: department Logistics searchable staff, equipment, and shift indexes for the current event/department plus presence, shift context, and open checkouts; department Operations assignments and deployment/location options; department Planning identity-free plan-versus-actual aggregates with freshness metadata; designated shift-lead roster and assignment fields; and department-lead roster, schedule, attendance, and maintainable documents. Hold every scope to the same resolver rules M16.13 established — a `shift_lead` grant reaches only members designated `membership_role = 'lead'`, an event-scoped grant reaches only its event, a revoked grant scopes nothing — and keep the staff field exclusions M8.2 asserted: no email, phone, date of birth, emergency contacts, status reasons, or profile-picture storage metadata. Incidents are not greedily included. **Record measured sizes per scope**; the Logistics indexes are the largest and are the ones that decide whether a query engine is needed. | CLIENT-021, CLIENT-022, TEAM-009; technical spec 9.3, 9.5, 11A.7; data/API 7.1, 7.3 | Scope tests ported from `PowerSyncPermissionScopedReplicationTest` and `PowerSyncDeviceCacheProjectionTest`, including a demoted lead who stops receiving the roster and an undesignated member of a granted team who never received it; a field-exclusion test; a test asserting incidents are absent; recorded size measurements per scope |
| M18.48 Client offline store | Replace the sixty-entry `localStorage` read cache with a store that holds the whole authorized read set. Choose the layer from the M18.46/M18.47 measurements rather than in advance: plain IndexedDB with in-memory filtering if the sets are small, RxDB with its free Dexie storage adapter and pull-only replication against these endpoints if the Logistics indexes need real queries. Adopting RxDB commits to the free adapter only — its IndexedDB, OPFS, SQLite, and Filesystem adapters are premium — and that constraint belongs in the PR description, not in a later surprise. Keep the store context-scoped through the registry the session cache and command outbox already use. | ADR-0003; technical spec 9.3; CLIENT-021 | Store/eviction tests; a test asserting the store is dropped on sign-out, on context switch, and on shared-workstation session end; a search test over the Logistics indexes at measured scale |
| M18.49 Proactive refresh and staleness | Fetch the read set on login, on regaining connectivity, and on context switch, rather than storing what the user happened to open. Apply the technical spec 11A.4 staleness rule the permission cache already uses — usable for the duration of the event the node is locked to — and refuse to serve a set past its event window. Surface refresh state through the existing offline banner view-model rather than a second indicator, feeding the `sync_queued` and `sync_failed` states M8.6 left for a later sync milestone. | CLIENT-001; technical spec 9.3, 11A.4; UI contract 11.13, 16 | Refresh-trigger tests; a test asserting a set past its event window is refused rather than served stale; banner state tests for a failed and a queued refresh |
| M18.50 Read model migration | Move the read models onto the store behind the single seam at `apps/client/src/api/meridianApi.ts`, keeping the `ReadFreshness` contract the surfaces already disclose from so every stale-read notice keeps working unchanged. Union locally pending outbox rows with stored rows on the device-generated identifier — `field_reports` has been device-UUID-keyed since M9.1, so a pending report and its accepted counterpart are the same key and de-duplication is an identifier match rather than a heuristic. Delete `readCache.ts`. | CLIENT-001, CLIENT-015; technical spec 9.3, 17.2 | Read-model tests per migrated surface; a test asserting a pending local report and its accepted copy render once; a test asserting freshness disclosure is unchanged for a stored read; a test asserting no surface reads `localStorage` for domain data |
| M18.51 PowerSync retirement | Remove `config/powersync.php`, `PowerSyncHealthClient`, `PowerSyncCheck`, the `deploy/powersync` service, and the PowerSync role, bucket-storage database, and publication provisioning in `deploy/docker`, keeping logical replication itself available for node sync. Replace the `sync.powersync` diagnostic and the event-mode fail-closed dependency with the read-set endpoint, so event mode gates on the thing offline readiness actually needs. Remove the offline sync service and web/client SDK rows from the technology baseline, and update technical spec 9.1, 9.2, 9.5, 11A.7, 8.6, 26.2, and 22A.8 plus the traceability matrix rows for sections 9.1-9.3 and 9.5. Land this only after M18.50, so the replacement is carrying reads before the rules are withdrawn. | ADR-0003; technical spec 8.6, 9.1, 9.2, 9.5, 11A.7, 22A.8, 26.2; SYS-033 | Diagnostics tests for the replacement check; event-mode fail-closed tests against the read-set probe; a test asserting no application code references PowerSync configuration; `docker compose config` smoke |
| M18.52 Two-tier connectivity state | Implement the `local_node_reachable` and `central_unreachable` states UI contract 11.13 and 16.1 have always defined and the client has never been able to express. Today `deviceConnectivityState` returns plain `online` whenever the pointed-at node answers, so a device working happily against an on-site node with central down reports "Central or expected sync target reachable", which is a claim it has not checked. Compose the state from both signals — the node this device is pointed at, and whether that node can reach central — and feed them through the existing view-model shape rather than a second indicator. **`useConnectivity.ts` currently defers these states to "PowerSync client sync state and Meridian node-sync signals"; ADR-0003 removes the first of those, so this task is where they land or they are orphaned again.** Make connected-only gating read from the local-node tier explicitly rather than incidentally: an IMS write is refused because no node is reachable, never because central is unreachable. | CLIENT-001; technical spec 9.4, 10; UI contract 11.13, 16.1, 16.1A | Connectivity-model tests for all seven states; a test asserting a reachable on-site node with central unreachable reports `central_unreachable` and not `online`; a test asserting an incident is creatable against a reachable local node with no central connectivity; a test asserting an incident is refused when no node is reachable |
| M18.53 Offline surface audit | Inventory every read-only surface in the shared client and hold each to one of two outcomes with no third: it renders from the stored read set, or it states plainly that it needs a connection. A surface that cannot work offline — reporting exports, which generate a file server-side; Insights, which is read-compiled; audit review; God Mode — is not a defect, but a spinner that never resolves, a blank panel, or a fetch error rendered as a bug is. Record the inventory in the PR so the set of offline-capable surfaces is a stated list rather than whatever the read set happened to cover. Where a surface is offline-capable, its freshness disclosure is the one its own contract already defines; where it is not, the message names what is unavailable and why. | CLIENT-001, UI-020; technical spec 9.3; UI contract 11.13, 16.2 | Per-surface offline render tests; a test asserting no read-only surface renders an unhandled fetch error offline; a test asserting a connection-required surface says so rather than rendering empty; the recorded surface inventory |
| M18.54 Logistics shift addition as an offline write | Make `add-staff-to-shift` — the Logistics unscheduled addition, not staff self-signup — an offline write, and amend technical spec 9.4 and data/API 7.2 to say so. Self-signup stays connected-only: `ShiftEligibilityService::assertCapacityForSelfSignup` is the only capacity guard in the domain and it is self-signup's alone, so a Logistics addition over capacity is already permitted online and needs no override to remain permitted offline. What will refuse on replay is eligibility — Do Not Staff, department membership, team eligibility, required trainings and waivers, and the on-site presence check — and none of those is answerable on a device. That is the accepted trade: an operator at a desk with no connectivity currently loses the work to paper, and a queued addition that is either accepted or returned with the node's reason is better than that. **Resolve the on-site coupling as part of this task**: `UnscheduledShiftAdditionService` refuses a staff member not already marked on-site, and `mark-staff-on-site` is connected-only, so an offline addition for someone not already on-site rejects every time. Either on-site marking becomes an offline write alongside this one, or this task states plainly why the pairing is left broken. | SLB-001 through SLB-022, CLIENT-015 through CLIENT-018; technical spec 9.4, 11A.5; data/API 5.6, 7.2 | Queue/replay tests; a test asserting an addition over capacity is accepted rather than refused; a test asserting an ineligible addition returns the node's reason and is not silently dropped; a test asserting the on-site pairing behaves as this task decides rather than by accident |
| M18.55 Rejected command resolution | Give a rejected command a second possible outcome beyond dismissal. Today `commandOutbox` holds a refusal with the node's reason until a person dismisses it, which is the right floor and is not enough: the person reading it often has the authority to decide the node's answer was wrong for the situation. Add a resolution path where a caller holding the authority re-issues a refused command as an override. **Issue it as a new command referencing the original, not a retry under the same idempotency key**, so the record holds both facts — the node refused this, and a named person then chose to proceed — rather than collapsing them into an acceptance that reads as though nothing was ever in question. Gate it on its own capability, audit it with the overridden reason code, and hold the overridable reasons to an explicit allowlist rather than making every refusal negotiable. `do_not_staff` is not on that list at any authority: it is an organization's exclusion decision and a Logistics operator does not overturn it from a desk. Which of `missing_required_training`, `missing_required_waiver`, `not_eligible_team_member`, `no_department_membership`, and `staff_not_on_site` join it is a product decision this task must record rather than assume. **Do not route this through `SyncConflict`.** That mechanism exists, with its Orchid list and detail screens, and MOD-017 already sends a refused queued write into it — but it resolves in God Mode, and its rationale is that the submitting device may be long gone. This is the opposite situation: the device is at the desk with a person looking at it, and the authority to add someone anyway belongs to that person, not to a technician in Orchid. Two resolution paths, divided by who holds the authority and who is present; this task builds the product-surface one and leaves the God Mode one alone. | CLIENT-017, CLIENT-017A, MOD-017; technical spec 11A.5; data/API 5.6; requirements 2.4 | Resolution/authority tests; a test asserting an override is refused without the capability; a test asserting `do_not_staff` cannot be overridden at any authority; an audit test asserting both the refusal and the override are recorded with the acting user and reason code; a test asserting the override is a distinct command from the original |
| M18.56 Offline read QA script | Add `QA-OFFLINE-01-offline-reads.md`, covering the offline read set, the two connectivity tiers, and the surface audit outcomes. | QA README | Human QA script |

#### Part G: On-site device sign-in

A staff member holding a session on their phone walks up to a Kiosk or a shared workstation and signs in to it. M16.8 built the node side of this and M16.9 built what a successful entry establishes, but the path a person actually walks is not there: `staff.workstation-code` is a UI contract 12.3 surface with no route, no view, no API method, and no palette entry, so the only way to obtain a code today is the God Mode console or curl. Underneath that, how a phone learns *which* workstation it is standing at was never specified in any document — `POST /api/auth/shared-workstation-login-code` requires a workstation UUID, no endpoint or screen hands one out, and the locked Kiosk does not display its own name. This part specifies the discovery step, builds the surface, and adds the scan path that makes the whole thing a two-tap interaction rather than a transcription exercise.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.57 Source-document amendment: on-site device sign-in | Amend the source documents before any code. Add AUTH-031 through AUTH-037: a code may be issued without naming a workstation and binds to the first trusted workstation that redeems it; a trusted pinned workstation may open a sign-in request and present it as a scannable code, which expires, grants once, and is collectable only by the workstation that opened it; a device holding a session may grant a request for its own user only; a request names its issuing node and a grant presented to a different node is refused; a granted request establishes a shared-workstation session under AUTH-030 and nothing more; re-authentication accepts a grant from the session's own user only; opening, granting, and collection are rate limited and audited, and request secrets are never logged, audited, or exported. Amend AUTH-026, which today implies a workstation is always named. Add technical spec 13.4, extend data/API 12.4, and correct kiosk guide 4.1, which describes only the typed path. **Correct the traceability matrix rows for AUTH-026 through AUTH-028 and for technical spec section 13**: each read Complete on backend evidence alone, with no client surface cited, which is how a documented surface reached Milestone 18 unbuilt. AUTH-029 stays Complete — its limits are reachable through God mode's console and the Kiosk keyboard — and gains the two limits M18.59 introduces. | AUTH-026 through AUTH-030; technical spec 13.2, 13.4; data/API 12.4; UI contract 12.3, 12.8, 18.2; kiosk guide 4 | Documentation change-control review; a traceability matrix row per new requirement at Planned; no code |
| M18.58 Untargeted login codes | Make `shared_workstation_id` nullable on `shared_workstation_login_codes` and stamp it at redemption rather than issuance, so a code names the workstation it was actually used at. `SharedWorkstationLoginCodeService::generate()` takes an optional workstation; without one the event comes from the caller's current event context rather than from a pinned context that does not exist yet. `redeem()` matches an unbound code only when the code's event equals the redeeming workstation's pinned event, and a targeted code still only at its own workstation. God Mode's console issues either kind and says which. A code is already one-use, so this is a change to what the record says as much as to behavior — **the audit entry must name the redeeming workstation for both kinds**, which is the property that makes the looser scope reviewable afterwards. | AUTH-026, AUTH-031; technical spec 13.2; data/API 12.4 | Domain tests; a test asserting an unbound code redeems at any trusted workstation pinned to its event; a test asserting it is refused at a workstation pinned to a different event; a test asserting redemption stamps the workstation and a second attempt is refused; a test asserting a targeted code is still refused elsewhere; an audit test asserting both kinds name the redeeming workstation |
| M18.59 Workstation sign-in requests | Add `shared_workstation_sign_in_requests` and the three endpoints the scan path needs. The workstation opens a request through an unauthenticated route keyed by the workstation id it already holds — the same reasoning as the M18.32 pinned-context read, because a locked workstation has no credential to ask with — and receives a request id, a **separate pickup secret held only by that machine**, an expiry, and the node's identity. The phone grants through `auth:sanctum` against the request id, for its own user, with no user field to name another. The workstation collects with its pickup secret; a grant is collectable once and only by the opener, so a shoulder-surfed code lets somebody sign *themselves* in there and never take the session. A request for an untrusted, revoked, or unpinned workstation is refused rather than opened. Also add a short human-typable `short_code` to `shared_workstations`, unique per event, generated at provisioning through `App\Support\TypableCode` alongside the login code and the mailed API code, and expose it on the pinned-context read — it is the typed fallback for a dead camera. **The unauthenticated open route warrants its own security review pass**: the M18.32 precedent discloses a pinned context and grants nothing, while this route hands back a secret that later collects a session key, and the request-id/pickup-secret split is the whole reason that is safe. | AUTH-031 through AUTH-035, AUTH-037; technical spec 13.4; data/API 12.4 | Endpoint/domain tests; a test asserting only the opener collects; a test asserting an expired request can be neither granted nor collected; a test asserting a grant naming another user is refused; a test asserting a granted request is single-use; a test asserting an untrusted, revoked, or unpinned workstation is refused; a test asserting a grant against a foreign node identity is refused; rate-limit tests per workstation and per user; a test asserting no request secret reaches the log, the audit trail, or any export |
| M18.60 Kiosk scan-to-sign-in surface | Extend `kiosk.workstation-login` to open a sign-in request on entering the locked state, render it as a QR carrying workstation id, node identity, and request id, poll for the grant with its pickup secret, and open the session on collection. Refresh an expired request rather than leaving a stale square on screen. Display the workstation's name and `short_code` beside the QR, because the point of the fallback is that it is readable when the camera is not. **Hold this to the M18.53 rule with no exception**: a node that cannot be reached, or a request that cannot be opened, falls back to the code field with a plain statement of what is unavailable — never a spinner that never resolves and never a blank square. Record the surface in `offlineSurfaceInventory`. | AUTH-032, AUTH-034; UI contract 12.8; CLIENT-001; technical spec 13.4; kiosk guide 4 | UI tests; a test asserting an expired request is replaced rather than left rendered; a test asserting an unreachable node falls back to the code field with a stated reason; a test asserting the polled session key is held in memory and never written to disk, as M16.9 established for the typed path; an inventory entry |
| M18.61 Field surface: `staff.workstation-code` | Build the surface UI contract 12.3 has listed since it was written and no milestone has produced. On a device holding a session: scan a workstation's QR, confirm the named workstation and event, and grant. Refuse a QR naming a different node with a message that says which node this device is using and which the workstation wants — the code would otherwise be issued into the wrong database and fail at the keyboard with no reason given. Offer the two fallbacks: enter a workstation `short_code`, or generate an untargeted code under M18.58 with no target at all. Show a generated code once, and say plainly that it will not be shown again. Place it in `staff.me` and the command palette, in `field` and `admin` modes and not in `kiosk`. **This task must record its QR decoding choice rather than assume it**: a Capacitor barcode plugin adds native build surface to `@meridian/mobile` and does nothing for the browser client, while `BarcodeDetector` with a WASM fallback serves both and is weaker on older devices. Add the `offlineSurfaceInventory` entry M18.53 could not audit because the surface did not exist. | AUTH-026 through AUTH-028, AUTH-033, AUTH-034; UI contract 12.3; CLIENT-001 | UI tests; a test asserting a foreign-node QR is refused with both node identities named; a test asserting the generated code renders once and is not retrievable on re-render; a test asserting the surface is absent in `kiosk` mode; a test asserting a denied camera permission reaches the typed fallback rather than a dead screen; an offline render test; an inventory entry |
| M18.62 Scan re-authentication | Extend `kiosk.reauth` to accept a grant as well as a typed code, over the same request mechanism with its purpose recorded as re-authentication and bound to the live session. A grant from anyone but the session's own user is refused and hands nothing over — the rule M18.32 already enforces for the typed path, and the reason UI contract 18.2 rejected a separate PIN as an independent central credential. Record `reauthenticated_at` identically whichever path confirmed it, so a privileged action's audit trail does not depend on how the person proved they were standing there. | AUTH-036; UI contract 18.2; technical spec 13.2, 13.4 | UI/domain tests; a test asserting a grant from another user is refused and the session is unchanged; a test asserting a re-authentication request cannot be collected as a sign-in or the reverse; a test asserting both paths stamp `reauthenticated_at` the same way |
| M18.63 On-site sign-in QA script | Add `QA-AUTH-02-on-site-device-sign-in.md`, covering scan-to-sign-in, the `short_code` fallback, the untargeted code, foreign-node refusal, request expiry and re-open, and scan re-authentication. QA-AUTH-01 exercises only the God Mode console path, which is why none of this has been checkable by hand. (Delivered as `QA-AUTH-04-on-site-device-sign-in.md`: the QA-AUTH-02 id this task named was already held by the Google OAuth login script.) | QA README | Human QA script |

Sequencing: M18.57 first, as change control requires. M18.58 and M18.59 are independent of each other and may run in parallel. M18.60 and M18.61 both need M18.59, and M18.60 lands first so there is something to scan. M18.62 needs both. M18.63 last.

#### Part H: The Directory

Meridian has a department roster, a team overview, and a staff profile a person maintains for themselves, and no way to see the shape of the organization. There is no surface that answers "which departments are there, what teams are in them, and who is where" — a reader who wants that today assembles it from department pages they may not all be permitted to open.

The Directory is that surface: the organization as a chart, and a handle search across it, both read-only. It is the second surface in this milestone whose whole content is people, and the visibility design is therefore the feature rather than a constraint on it. The rule is not a permission — it is derived from organizer status and leadership assignments that already exist (DIR-024) — and it is enforced in one place that the online read, the search, and the offline read set all consume, because three implementations of "who may see whom" is three chances to be wrong about it.

Two properties distinguish it from anything Part C built. The projection is purpose-built rather than a filtered staff record (DIR-028), so legal names and contact fields are never in the response to be hidden later. And visibility constrains *locations* as well as people (DIR-030): a viewer authorized to see somebody as a team lead is not thereby told which other departments that person belongs to, which is the leak the shape of the data invites.

**Task numbers M18.64 through M18.69 are already spent** on follow-up corrections that landed after Part G — kiosk workstation identity, session bar placement, polling, changelog and navigation fixes — and were tracked in commits rather than here. Part H therefore begins at M18.70.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M18.70 Source-document amendment: the Directory | Amend the source documents before any code, as change control requires. Add requirements section 3.47 and section 7.29 (DIR-001 through DIR-037), technical spec section 21E, and UI implementation contract 19D and the `directory` screen row in 12.3. Record the traceability matrix rows for the new requirements at Not started. Record technical spec open questions 37 and 38 — the years-of-service calculation, which requirements 2.8 names and no document defines, and the closest-occurrence tie-break for search selection — rather than leaving either to be decided inside an implementation task. **This documentation change is that task**; the code tasks below may not begin before it is reviewed. | DIR-001 through DIR-037; technical spec 21E; UI contract 12.3, 19D | Documentation change-control review; a traceability matrix row per new requirement at Not started; no code |
| M18.71 Directory visibility rule | Build the visibility resolution as one backend domain rule and nothing else — no controller, no endpoint, no surface. Given a viewer and a resolved context, it answers which staff members may be returned and which of each person's chart locations may be returned, additively over the positions the viewer holds and each within its own scope. Derive it from organizer status and leadership assignments through `EffectiveRoleResolver`, the resolver every other API read already answers from, and **add no named permission** (DIR-024). Apply the status filter here, so exclusion cannot be forgotten by a later consumer. Landing the rule alone, ahead of anything that calls it, is deliberate: it is the security boundary for every task after it, and it is reviewable on its own terms only while there is no surface competing for attention in the diff. | DIR-017 through DIR-026, DIR-030; technical spec 21E.4, 21E.5 | Domain/policy tests for every case in requirements 7.29: ordinary staff reach leadership and no ordinary members; a department lead reaches their own departments' members, teams, and unassigned members and not another department's; a team lead reaches their own teams and not the surrounding department; a department lead who also leads a team elsewhere gets team scope only there; multiple positions are additive; an organizer reaches the whole population; a Staff Coordinator, an IC role, a department Operator, and an organization owner each reach exactly ordinary-staff visibility; an excluded status is absent even holding a visible position; a test asserting the rule returns only authorized locations for an authorized person |
| M18.72 Directory organization setting | Add Directory availability to the M18.14 organization configuration surface, defaulting to enabled, under that surface's existing governance edit rules and audit. Disabled means absent rather than refused: no navigation entry, no palette entry, 404 rather than 403 on the route and the API, and nothing synchronized. Use the existing configuration pattern; do not add a configuration mechanism for one setting. | DIR-004, DIR-005; ORG-018, ORG-020, ORG-021; technical spec 21E.9 | Config/policy tests; a test asserting the default is enabled for an organization that has never touched the setting; a test asserting a disabled Directory answers 404 rather than 403 and identically for a permitted and an unpermitted viewer; an audit test on the change; a test asserting the edit is blocked during the active event window |
| M18.73 Directory chart read | Add the authenticated chart endpoint over the M18.71 rule: organization root, Organizers Department first, then departments, teams, leads, members, and the department-level Prospectives section, with departments and teams present whether or not the viewer sees anyone inside them. Return a purpose-built Directory projection — profile picture reference, handle, authorized locations, years of service — and **not a staff or membership model with fields omitted at render** (DIR-028). Compose it in a bounded number of queries rather than one per department, team, lead, or person, resolving the person set once and placing it many times. **This task must settle open question 37 with the product owner before rendering years of service**, rather than choosing a calculation: requirements 2.8 names the field and no document defines whether it counts from first organization status, first event worked, or first shift with recorded hours, nor what a participation gap does to it. | DIR-006 through DIR-015, DIR-027 through DIR-030; technical spec 21E.2, 21E.3, 21E.6, 21E.10 | Endpoint/projection tests; a test asserting no legal name, preferred name, email, phone, emergency contact, address, or date of birth appears anywhere in the response body; a test asserting an empty department and an empty team are both present; a test asserting a team lead appears once in their own team; a test asserting a person with memberships in three departments appears in every authorized one; a test asserting unauthorized locations are absent from an authorized person's entry; a query-count test over a seeded organization with many departments, teams, and repeated placements; an event-context and an organization-context test asserting neither population leaks into the other |
| M18.74 Directory search | Add handle search over the M18.71 authorized set, returning the handle and the authorized chart location as a breadcrumb. **Build the index from the authorized set rather than filtering an index built from everybody** (DIR-033) — filtering at the end is what produces a count that includes people who were removed, a partial match that confirms a handle exists, and a response measurably slower when there was something to take out. Match the handle only; Meridian models one handle and deliberately does not separately model callsign, playa name, or radio name (requirements 3.4), so there is one field here and no second concept to reconcile. | DIR-031 through DIR-034; technical spec 21E.7 | Search tests; a test asserting a legal name and a preferred name match nothing; a test asserting a department name, team name, and role name match nothing; a test asserting an unauthorized handle is absent from results, from counts, and from partial matches; a test asserting an unauthorized person is absent from the index rather than removed from results; a test asserting a person with several visible locations produces a row per location |
| M18.75 Directory client surface | Build `directory` as the chart: collapsed to departments on open, expandable by touch with no hover requirement anywhere, person entries carrying picture, handle, authorized locations, and years of service and nothing else. Place it in the Workflows menu and the command palette, in field, kiosk, and admin, in event and organization context alike. Render an empty department and an empty team as themselves, with no count, no lock, and no "hidden" marker. Design at the narrowest supported width and widen from there, spending extra width on legibility and on showing more of the tree rather than on hover affordances — and **do not produce a box-and-line diagram that must be panned horizontally**, which is the shape an org chart defaults to and the one that fails on both a phone and a wall display. | DIR-002, DIR-003, DIR-009 through DIR-016, DIR-029; UI contract 12.3, 19D | UI tests; a test asserting the initial state is collapsed to departments; a test asserting no person entry renders a name, email, phone, or contact control; a test asserting an empty branch renders with no count and no marker; a test asserting the surface is absent — menu, palette, and route — when the organization has it disabled; touch-target and keyboard-reachability assertions on the expand controls; render tests at phone, tablet, and large-display widths |
| M18.76 Directory search and filtering UI | Put search beside the chart rather than on a page or tab of its own, and add filtering by department, team, role, and status through visible touch-first controls — chips, segmented controls, or expandable facets — using the section 11 component vocabulary and not a dropdown holding every filter. Selecting a result expands the branches needed, scrolls to the closest applicable node, highlights every authorized occurrence with a marker and accessible text rather than color alone, and leaves search in place. **This task must settle open question 38 rather than assume a tie-break** for which occurrence is closest when several are equally applicable. | DIR-031, DIR-035, DIR-036; UI contract 19D.6, 19D.7; section 20 | UI tests; a test asserting search stays usable while the chart is browsed; a test asserting selecting a result expands the needed branches and highlights every authorized occurrence; a test asserting a previous highlight is cleared on the next search; a test asserting no filter control is a dropdown as its primary interaction; a test asserting a filtered count counts only visible people |
| M18.77 Directory offline read set | Extend the offline read set with the Directory projection for the signed-in user's authorized scope, holding it to the same rule M18.47 asserts for every other scope: the device holds what its user could have retrieved from the API and nothing else. Build the offline search index from that stored set. Add the `offlineSurfaceInventory` entry, and confirm rather than assume that the existing lifecycle covers visibility loss — the store is dropped on sign-out, on context switch, and on shared-workstation session end (M18.48), and a role change takes effect at the next refresh (M18.49). | DIR-037; CLIENT-021, CLIENT-022; technical spec 9.3, 11A.7, 21E.8 | Offline tests; a test asserting no PII field reaches the stored set; a test asserting a demoted department lead's stored member list is gone after the next refresh; a test asserting an unauthorized handle is absent from the offline index; a test asserting a disabled Directory synchronizes nothing; an offline render test; an inventory entry |
| M18.78 Directory QA script | Add `QA-DIR-01-directory-and-organization-chart.md`, walking the visibility matrix by hand with the seeded personas — an ordinary member, a department lead, a team lead, the persona who works three departments, and an organizer — plus the disabled-organization case, the offline case, and search selection. | QA README | Human QA script |

Sequencing: M18.70 first, as change control requires. M18.71 and M18.72 are independent of each other and may run in parallel. M18.73 needs both. M18.74 needs M18.71 and lands after M18.73 so the breadcrumb has a chart to name locations in. M18.75 needs M18.73; M18.76 needs M18.74 and M18.75. M18.77 needs M18.73 and M18.74. M18.78 last.

**Acceptance criteria and human QA checks:**

- every service in Part A is reachable from a product surface, and no domain service in the application has zero consumers;
- a staff member browses the shifts they are eligible for, signs up, sees why an ineligible shift is unavailable, and withdraws before the cutoff;
- Logistics marks a staff member on-site, adds them to a shift they had not signed up for, checks them in and out, and is refused when marking them off-site while they hold equipment;
- an attendance manager corrects a completed shift's hours from the Logistics Desk, and is refused once the grace period has closed with a message that says so;
- a staff member acknowledges a required document during signup, and the acknowledgment records the document version;
- a department designates a team as its Logistics team, and every member of that team gains exactly the Logistics capabilities and no others;
- a department designates a team as its Operator team, and its members can take a Field Report on behalf of another department staff member with author and submitter both recorded;
- the same Operator team gains incident create and edit for an event where its department is the Incident Command Department, and holds neither for an event where it is not;
- a Staff Coordinator reviews and approves an application and cannot manage departments, staff status, or credentials;
- an organizer sets the hours correction grace period and the lifecycle thresholds from a product surface, and the change is audited and blocked during the active event window;
- Prospective staff become Inactive when their threshold passes, without anyone performing the transition;
- credits calculate from a shift-specific policy where one exists and from the organization default otherwise, and freeze;
- a staff member changes their own preferred name, phone, and city/state and sees the change immediately, and cannot change their legal name, email, or date of birth from that surface;
- a staff member changes their handle twice without anyone approving it, and the third change becomes a request that an organizer or Staff Coordinator approves before the handle changes;
- a rejected or withdrawn handle request leaves the staff member with the same number of self-service changes they had before making it;
- a staff member submits a new profile picture, the organization keeps seeing the old one until the request is approved, and the submitted picture is visible to nobody but the submitter and its reviewers;
- a staff member removes their own picture without asking anyone;
- a reviewer approving a handle request is told when another active staff member in the organization already uses that handle, and can still decide either way;
- a staff member is notified of every decision on their requests, and a rejection carries the reviewer's reason;
- a waiver backed by a published document renders that document with fragment text inline, and completion records the document version;
- an approved applicant receives an email carrying their organization's name and mark, and a Do Not Staff auto-rejection sends nothing;
- an applicant requests a link by email, sees their applications, and withdraws one, and the request response is identical for an address with no applications;
- an organization submits interest from the public marketing surface, and the submission creates no organization, user, or staff record;
- Logistics hands out a tracked radio by scanning or typing its asset tag, and hands out three pooled headlamps by choosing a quantity, without reading a list of every unit the department owns;
- an asset tag from another department finds nothing and does not reveal that the item exists;
- equipment lookup keeps working on a Logistics Desk with no connectivity;
- a pooled kind shows fewer available as units go out and more as they come back, and never reads as Checked out;
- a pooled headlamp returned damaged reduces what the pool can hand out, with the reason recorded;
- every export in REPORT-001 through REPORT-005 runs from a reporting surface that states its scope and exclusions first, and downloads through a short-lived URL;
- dashboards render their widgets with quiet states, and organizer widgets surface no incident data without IC authority;
- a Kiosk with no pinned context enters setup;
- in the weeks before an event, a staff member opens the Event Horizon and reads one ordered list of every acknowledgment, waiver, training, and shift signup still outstanding for that event, each stating how it evaluates and what would complete it, each linking to the surface that resolves it;
- an item they have already satisfied stays on the list, de-emphasized and marked complete, rather than vanishing;
- a team lead additionally sees which of their team's shifts are below capacity, without reading a staff name to learn it;
- the Event Horizon is absent before the lead-up window opens, absent after the operations window closes, and absent wherever the interface is not resolved to one event;
- a staff member with nothing outstanding hides the Event Horizon from their workflow menu, and one with an item outstanding is not offered the option;
- a hidden Event Horizon comes back when a new item becomes outstanding, and its owner can bring it back themselves before then;
- the Event Horizon renders on a device with no node reachable and says plainly what it could not evaluate;
- a staff member who signs in on a device and then loses connectivity without opening anything still holds their shifts, their department and team, the event, the Field Report form, and the policies they are held to;
- a Logistics user searches their department's staff, equipment, and shift indexes with no node reachable, and the results state what copy they came from and when it was taken;
- a device holds nothing its user could not retrieve through the API: a demoted lead's stored roster is gone after the next refresh, and an undesignated member of a granted team never held it;
- a device holds no records belonging to a module its organization does not run;
- a stored read set past its event window is refused rather than served stale;
- event mode fails closed when the offline read set cannot be served, and no longer gates on a service nothing connects to;
- an IC user creates an incident against a reachable on-site node while central is unreachable, and is refused only when no node is reachable at all;
- a device working against a live on-site node with central down says so, rather than reporting itself plainly online;
- every read-only surface either renders from stored data or states that it needs a connection, and none renders a spinner that never resolves or a fetch error dressed as a bug;
- a Logistics operator at a desk with no connectivity adds a staff member to a shift, and the addition is accepted on reconnect even where the shift is over its capacity target;
- an addition that is refused on replay returns the node's own reason, stays where the operator can see it, and is neither dropped nor silently retried;
- a refused addition that its reader has the authority to overturn can be re-issued as an audited override naming them, and one refused for Do Not Staff cannot be overturned by anybody;
- a staff member standing at a Kiosk scans it with the phone they are already signed in on, confirms the workstation and event named back to them, and is signed in at the Kiosk without typing anything;
- the same staff member with a camera that will not work reads the workstation's short code off the screen, types it into their phone, and signs in with the code the node issues;
- a staff member with no workstation in front of them generates a code that works at the first trusted workstation they use it on, and nowhere afterwards;
- a phone pointed at a different node than the workstation says so, naming both, rather than issuing a code that fails at the keyboard for no stated reason;
- a Kiosk whose node is unreachable offers the code field and says what is unavailable, rather than a square that never resolves;
- a sign-in request left on screen expires and is replaced, and a grant collected once cannot be collected again;
- a scanned grant from somebody other than the signed-in user is refused at re-authentication and hands nothing over;
- a staff member opens the Directory and reads the shape of their organization — the Organizers Department, every other department, the teams inside them — including the departments and teams they have nobody visible in;
- that same staff member sees every organizer, department lead, and team lead in the organization, and no ordinary member of a department or team they do not lead;
- a department lead additionally sees their own departments' members, their teams' members, and the members assigned to the department with no team, and sees nothing more in a department they do not lead;
- a team lead who leads one team inside somebody else's department sees that team's members and none of the department's others;
- an organizer sees everyone, and a Staff Coordinator, an IC lead, and a department Operator each see exactly what an ordinary member sees;
- a person who works three departments appears in every one of them the reader is authorized to see, and in none the reader is not;
- no Directory response, stored record, or search index anywhere carries a legal name, a preferred name, an email address, or a phone number;
- a reader searches a handle, selects the result, and watches the chart open to that person and highlight every place they appear, without leaving the search;
- a reader narrows the chart by department, team, role, and status using controls they can see, on a phone, with one thumb;
- the Directory renders on a device with no node reachable, holding only the people its user was already allowed to see;
- an organizer switches the Directory off and it is gone — from the menu, from the palette, from the route, from the API, and from the next device refresh — without ever telling anybody it was withheld;
- the traceability matrix distinguishes domain-complete from user-exercisable.

**QA gate:** A human can complete the whole MVP operational path in normal Meridian Admin, Field, and Kiosk without Orchid: configure an organization and its designations, invite staff, run an application through a Staff Coordinator, have a staff member fill in their own profile and picture and settle on a handle, acknowledge a document and complete a document-backed waiver, sign up for a shift, be checked in and out at the Logistics Desk, have their hours corrected and then frozen, see credits calculated, and read every required export from a reporting surface — while an organizer reviews a handle and a picture change request, an applicant manages their own application by email link, and a prospective organization submits interest from the marketing surface. That same staff member can work the whole path from their Event Horizon, watching each item complete as they resolve it, and hide the surface from their menu once nothing is left.

**Decisions resolved during specification:**

1. **The Operator designation** is defined in requirements section 4.8A as the department dispatch and console function: it may take Field Reports on behalf of other department staff and holds the field report and incident shortcuts. Where its department is the event's Incident Command Department, the designated Operator team additionally carries event-scoped `ic_operator`, including incident create and edit (TEAM-012A). Covered by M18.10 and M18.10A.
2. **The hours correction grace period** defaults to 14 days after event end (ORG-017).
3. **Department and team addition notifications** collapse to one message when they occur together on first assignment, naming both department and team; a later team addition within a department the staff member already belongs to sends its own (NOTIFY-001A).
4. **Self-service address** means the city and state VOL-009 already names. Meridian holds no street address for staff and this milestone does not add one. A mailing address would be restricted personal data on the footing of emergency contacts, needing its own visibility rules and export exclusions, and nothing in the MVP operational path reads one.
5. **A submitted profile picture is pending until approved**, and the staff member's existing picture stays visible in the meantime (VOL-021). The alternative — publish immediately and review afterwards — puts an unreviewed image in front of the organization for however long review takes, which is the outcome review exists to prevent. Removal is the exception and is immediate (VOL-023): a staff member does not need permission to stop displaying a picture of themselves.
6. **The handle allowance is two applied changes for the life of the staff record** (VOL-017), not two per year and not two per organization. A handle is one person's operational identity across every organization on the node, so an allowance that resets or multiplies would let the same person cycle handles indefinitely while each individual organization saw a reasonable number. Setting a first handle is not a change, and only applied changes count (VOL-018).
7. **Reviewers are organizers and Staff Coordinators** of an organization the staff member holds a status with, through one new `staff.profile-change-requests.review` capability. This reuses the review authority M18.11 already establishes for applications rather than adding a second reviewer population.
8. **Equipment splits into tracked and pooled** (EQUIP-010). The checkout surface today renders one checkbox per available unit, which is workable for a department with a dozen items and unusable for one with hundreds. Splitting on whether a unit is individually identified is what makes both halves small: tracked units are found by entering or scanning the identifier they already carry, and pooled kinds collapse into a short list of names and quantities. Keeping every unit as its own record and only changing the widget would leave a department entering four hundred rows to represent four hundred zip ties, so pooled equipment carries a quantity instead.
9. **Pooled availability is derived, not stored** (EQUIP-016). The five EQUIP-005 states describe a unit's condition, and a pool holding forty units of which three are out is not in any one of them. Availability is therefore total quantity less open checkout quantity, and `checked_out` is never written to a pool.
10. **Barcode scanning means keyboard input** for Alpha 1. Handheld scanners present as keyboards, so an exact-match lookup field serves them with no scanner-specific code (EQUIP-013). Camera-based scanning is out of scope below.
11. **The Event Horizon reports and does not enforce** (HORIZON-008). Every condition it lists is already enforced somewhere: a missing training already blocks shift signup, an incomplete waiver already blocks credential eligibility. Making the surface a second enforcement point would give the same rule two implementations that can disagree, and the one a staff member reads would not be the one that decides. It compiles, orders, and links; it refuses nothing.
12. **Hiding is offered only at zero outstanding items, and returns on the next one** (HORIZON-012, HORIZON-015). A dismissal available while work is outstanding is an opt-out of the preparation the surface exists to drive, and a dismissal that never returns leaves a staff member unaware of an item that appeared after they hid it. Tying it to an empty list makes it what the staff member actually wants — "I am done, stop showing me this" — rather than a mute switch. It is personal view state, not a record: nobody else can see it and nothing audits it.
13. **The Event Horizon item catalogue is fixed in code** (HORIZON-003), on the same footing as the Insight metric catalogue and the module catalogue. Organizations configure one thing about it, the lead-up window length, and author no items. A configurable catalogue would let an organization define readiness differently from what Meridian enforces, which is the disagreement decision 11 exists to prevent.
14. **Directory visibility is derived, not granted** (DIR-024). It reads organizer status and leadership assignments and adds no named permission. A `directory.view_all` capability would be grantable to somebody who leads nothing, would become a second answer to who may see whom, and the first time it disagreed with the leadership records the disagreement would be invisible. God Mode is not an exception to this and needs none: Orchid reads the underlying records directly under its own audited access, which is a different surface rather than a bypass through this one.
15. **The Directory status filter is stated in Meridian's own status vocabulary.** The feature specification named Inactive, Suspended, and Removed as the excluded statuses; Meridian models no Suspended and no Removed. DIR-025 therefore excludes Inactive, Department Inactive, Do Not Staff, and Department Ineligible — the four exclusion states that exist (requirements 3.5, 3.6) — and includes Active, Prospective, Emeritus, and Retired with their department equivalents, as specified. Do Not Staff and Department Ineligible are read as the intended sense of Suspended and Removed rather than left undecided, because they are exclusion decisions an organization has made about a person and a directory that showed them anyway would be the worst place to be wrong. This mapping is the one product decision Part H makes that was not in the source specification, and it is recorded here so it can be overturned in one place.
16. **`Prospectives` is a Directory label, not the Prospective status** (DIR-013). A department member holding no team is presented under that heading regardless of their actual status, and an Active member with no team assignment appears there. The word collides with the status vocabulary in requirements 3.5, which is exactly why it is fixed as presentation on one surface, must not be styled with the status vocabulary, and mutates nothing. Part H does not investigate or report why a member holds no team; that question belongs to the department, not to a chart.

**Explicitly out of scope for this milestone:**

- anything the requirements document defers in section 8 or lists as an explicit non-goal in section 9;
- Milestone 14, 15, 16, and 17 scope, which remain their own milestones rather than gap-closure work;
- per-user notification preferences, digests, and opt-out categories (NOTIFY-010);
- SMS and push notifications;
- organization self-service creation, which remains a God Mode action (PUBLIC-004);
- staff self-service maintenance of emergency contact fields, date of birth, legal name, and email address. The first is a deliberate omission rather than a considered restriction and is worth revisiting: a staff member cannot currently correct their own emergency contact, and VOL-016 keeps only the other three assisted, for identity and eligibility reasons. The email change path is AUTH-011 through AUTH-015 work and is not gap closure;
- a street or mailing address on the staff record, per decision 4 above;
- retaining superseded profile pictures. Technical spec 18A stores only the current picture for Alpha 1, and a rejected submission is discarded rather than kept as history;
- camera-based barcode and QR scanning for equipment. A handheld scanner acting as a keyboard is covered by M18.24C; using a device camera adds a permission prompt, a decoding library, and a failure mode on every client target, and buys nothing a scanner or typed tag does not already do;
- generating or printing asset tags. Meridian records the identifier an item already carries;
- department-to-department allotments and full custody chains, which EQUIP-006 and EQUIP-008 keep out of MVP and which pooled quantities do not reopen;
- provision eligibility export, waiver completion export, and incident spreadsheet export, which remain not required for October MVP (REPORT-011 through REPORT-013);
- Event Horizon item kinds beyond the fixed HORIZON-003 catalogue, and any organization-authored item, threshold, ordering, or wording;
- a lead's or organizer's view of another staff member's Event Horizon. Readiness across a population is an aggregate question, which is what Insights answers; the Event Horizon is one person's own list;
- turning the Event Horizon into a task system: nothing on it may be hand-created, assigned, delegated, snoozed, or completed except by changing the record behind it (HORIZON-007);
- Event Horizon notifications, reminders, or digests. NOTIFY-001 already sends on the conditions it reports, and NOTIFY-010 keeps digests out of MVP;
- making the Event Horizon a gate on anything (HORIZON-008);
- any Directory write: no editing, no contact or messaging control, no administrative action, and no profile maintenance from the chart (DIR-001, DIR-029). The staff profile surface remains where a person maintains their own record;
- Directory search over anything but the handle (DIR-032). Searching a legal or preferred name would make the Directory a name lookup for a population it deliberately does not expose names for;
- a Directory profile or detail page for a person. Selecting a search result moves the chart and nothing else (DIR-035), on the same reasoning that keeps Name References from resolving to a profile route (requirements 3.21A);
- audit logging of Directory reads and searches (DIR-037);
- reporting or investigating why a department member holds no team. `Prospectives` is a heading, not a finding, per decision 16;
- nested teams or subteams in the Directory (DIR-012). The chart is four levels and team-lead visibility needs no recursive traversal;
- the years-of-service calculation as an assumption. It is technical spec open question 37 and is settled with the product owner inside M18.73, not chosen inside it.

---

### Milestone 19: Packaging, Organization Addressing, Modules, Event-Mode Safeguards, and Release Candidate QA

**Goal:** Produce versioned, installable Alpha 1 builds of all three products, make organizations reachable at organization subdomains beside their root paths, let an organization run only the modules it needs, verify release readiness, and make the server image installable on a third-party Docker app platform without changing how a node is deployed anywhere else.

**Primary source docs:** Technical spec sections 3.2 through 3.4, 8, 15A, 25, 26, 27, 28, 29; requirements 7.1 (ORG-018, ORG-022 through ORG-025), 7.27 (MOD-001 through MOD-022); data/API spec 5.9, 6.8, 7.6, 10.1A; process release checklist; `docs/process/versioning-strategy.md`; `deploy/runtipi/README.md`; QA README; UI/kiosk docs.

**Module sequencing note:** M19.11 through M19.19 build the module system. They land before the release candidate QA tasks (M19.6, M19.7) so that RC QA exercises a build in which modules can be turned off, and M19.19's QA script is part of the RC QA index M19.6 assembles.

**Application packaging sequencing note:** M19.20 through M19.25 turn the client builds into installable applications. Until they land, "packaging" in this milestone means the server deployment bundle only: technical spec 3.2 has named a mobile app package and a desktop installer since it was written, `docs/process/versioning-strategy.md` deferred both to "a later release workflow" that no task owned, and neither `@meridian/mobile` nor `@meridian/kiosk` could produce anything a person can install. They land before M19.6 and M19.7 for the same reason the module tasks do — release candidate QA has to be run against the artifacts that ship, not against a dev server — and M19.25's QA script is part of the RC QA index M19.6 assembles. M19.21 depends on M18.61 having recorded its QR decoding choice, because that choice decides whether the Android and iOS projects declare a camera permission.

**Runtipi distribution sequencing note:** M19.26 through M19.29 make the server image installable on [Runtipi](https://runtipi.io), a self-hosted Docker app platform whose app stores are git repositories of Compose files. `deploy/runtipi/README.md` is the agreed design and governs all four; the tasks below are its "Suggested order" table with owners attached.

They are the exception to this milestone's ordering rule: they land *after* M19.6 and M19.7 rather than before, and they do not gate the release candidate. Every other packaging task exists because an Alpha 1 product cannot ship without it, and RC QA has to run against what ships. A Runtipi install is a distribution convenience for people who already run a Docker app platform, and no Alpha 1 acceptance target names it. An Alpha 1 release candidate is complete with M19.26 through M19.29 unstarted, and a QA gate that said otherwise would hold the release on work nobody is waiting for.

M19.26 modifies `.github/workflows/release-artifacts.yml`, which M19.23 owns. It is new scope rather than deferred M19.23 work: technical spec 26.7 requires a release's artifacts to be produced from one build of the tagged commit and attached to the release, which M19.23 does, and mandates no registry anywhere. Publishing images is an addition to that workflow, and it is sequenced first because Runtipi pulls images and nothing below it can proceed until they are pullable.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M19.1 Version metadata | Add server, mobile, Electron, and config schema version display. | Technical spec 26.3 | Unit/UI tests |
| M19.2 Deployment config bundle | Package the Docker/Caddy/DNS deployment config bundle. No PowerSync service: M18.51 removed `deploy/powersync` under ADR-0003, so the stack runs the database, the server, a queue worker, a scheduler, and the reverse proxy. | Technical spec 4, 8, 26 | Build smoke test |
| M19.3 Event-mode secret safeguards | Refuse/default-generate secrets as specified. | Technical spec 7.4, 26.2 | Feature/config tests |
| M19.4 HTTPS and PowerSync fail-closed | Validate production/event secure connection policy. | Technical spec 8.2, 8.6, 26.2 | Config tests |
| M19.5 Electron health finalization | Show node name, role, event, sync, PowerSync, discovery, HTTPS, connected devices, and versions. | Technical spec 25.3 | Desktop QA |
| M19.6 Release candidate QA index | Add a release-candidate QA checklist that links milestone QA scripts. | Development process section 20 | Human QA script |
| M19.7 Install/deployment dry run | Document second-person install/deployment evidence requirement. | Development process section 20 | Human QA evidence |
| M19.8 Organization subdomain resolution | Resolve the organization from an organization-slug subdomain of the configured platform domain, serving the same content the root-path form serves with the slug segment omitted; an unknown subdomain is not found, and the marketing surface renders only at the deployment root. | ORG-022 through ORG-025; PUBLIC-001; Technical spec 8.7 | Feature tests driving requests by `Host` header (for example `northwood.localhost`); a test asserting the path form keeps working unchanged; a test asserting an unknown subdomain is not found; a test asserting the marketing surface does not render on an organization subdomain |
| M19.9 Subdomain sessions, links, and branding | Generate organization links host-aware so a visitor on a subdomain stays on it, scope session cookies so organization subdomains stay isolated from one another, and resolve the organization branding profile on subdomain hosts. | ORG-023, ORG-024; BRAND-003; Technical spec 8.7 | Feature tests; a test asserting a session cookie issued on one organization subdomain is not presented to another; a branding resolution test by host |
| M19.10 Wildcard host deployment config | Extend the deployment bundle (M19.2) with wildcard DNS/TLS host handling for organization subdomains (`*.<deployment-domain>`) and document `*.localhost` development use. | Technical spec 8, 8.7, 26 | Build smoke test; deployment doc check |
| M19.11 Module catalogue and state | Add the code-defined eight-module catalogue, the `organization_modules` table with separate entitled/enabled state, and a resolver that answers whether a module is active for an organization. Declare each domain namespace's owning module, defaulting an undeclared namespace to core. | MOD-001 through MOD-005, MOD-007, MOD-009; Technical spec 15A.2, 15A.3; Data/API 10.1A | Unit tests for the resolver; a test asserting an unknown module key is rejected; a test asserting a missing row resolves as entitled and enabled; a test asserting every domain namespace declares an owner or is core |
| M19.12 Module gate middleware | Add route-group module middleware that runs after organization resolution and before authorization, refusing inactive modules with `404` and a `module_inactive` reason. Declare the owning module for every module-owned route group, including commands. | MOD-012, MOD-013; Technical spec 15A.4, 15A.5; Data/API 5.9, 6.8 | Feature tests per module asserting refusal; a test asserting the refusal is identical for a permitted user, an unpermitted user, and God Mode; a test asserting the gate precedes the permission check; a route-coverage test asserting no module-owned route is ungated |
| M19.13 God Mode entitlement screen | Add the Orchid Organization modules screen for per-organization entitlement, always presenting all modules for all organizations on the central node, with audited transitions and reason capture. | MOD-006, MOD-011, MOD-021; Technical spec 15A.6, 22.2 | Feature tests; a test asserting the central-node console lists every module for an organization that has them all disabled; a test asserting entitlement changes are audited with previous and new state |
| M19.14 Node-bound console visibility | Hide an organization's inactive modules from operational console navigation on a node whose config binds it to a single organization, keeping the Organization modules screen reachable. | MOD-021; Technical spec 7.3, 15A.6 | Feature tests driving both node bindings; a test asserting an operator on a bound node can still activate a hidden module |
| M19.15 Organizer module configuration | Add module enable/disable to the ORG-018 organization configuration surface, offering only entitled modules, requiring `organization.configuration.manage`, blocked during the active event window, audited. | ORG-018, ORG-020, ORG-021; MOD-008, MOD-010, MOD-011; Data/API 6.8 | Feature tests; a test asserting an unentitled module is not offered; a test asserting an edit during the active event window is refused; a test asserting a non-organizer cannot change enablement |
| M19.16 Client module gating | Return the active module set from session resolution, cache it with the offline permission cache, and gate the client router and navigation on it. Distinguish module absence from permission denial in user-facing copy. | MOD-015, CLIENT-001 through CLIENT-004; Technical spec 11A.3, 11A.4, 15A.8 | Client unit tests; a test asserting a disabled module has no nav entry and no reachable route; a test asserting the offline client gates on the cached set; a test asserting module absence renders different copy than permission denial |
| M19.17 Module-scoped replication | Scope sync rules by active modules in addition to effective roles, declare each synced table's owning module, and refuse outbox replays against inactive modules as sync conflicts. | MOD-016, MOD-017; Technical spec 9.5, 11A.7, 15A.8; Data/API 7.6 | Sync rule tests asserting an inactive module's records do not replicate to a permitted user's device; a test asserting reactivation replicates them back; a test asserting a queued write against a now-inactive module becomes a sync conflict rather than a silent drop |
| M19.18 Vacuous requirements and aggregator degradation | Make gates owned by an inactive module evaluate as satisfied and unpresented, and make composing surfaces omit inactive modules' contributions. Covers shift waiver and training requirements, credential eligibility's signed-up-shift condition, acknowledgment requirements, the department operations read models, The Briefing, Insights, the Event Horizon, and exports. | MOD-018, MOD-019; HORIZON-017; Requirements 5.6, 5.8; Technical spec 15A.7; Data/API 5.9 | Feature tests per gate asserting satisfaction rather than blocking, and asserting the requirement records survive; a test asserting credential eligibility works with Scheduling inactive; tests asserting each department operations read model, The Briefing, Insights, and the Event Horizon render with each module inactive in turn; a test asserting an Event Horizon with no available item kind is not presented |
| M19.19 Organization creation module selection and QA script | Let organization creation choose the module set, defaulting to everything entitled and enabled; backfill existing organizations and the Northwood seed to all-on; add `QA-MOD-01-organization-modules.md`. | MOD-009, MOD-020; QA README; developer testing process | Feature tests for creation defaults and narrowing; a migration test asserting existing organizations keep every module; a test asserting the Northwood scenario seeds all modules active; human QA script |
| M19.20 Desktop installer packaging | Give `@meridian/kiosk` a packaging configuration (electron-builder or equivalent) that turns the compiled wrapper and the packaged Kiosk client build into Windows, macOS, and Linux installers from one config, carrying the `org.meridian.kiosk` application id, the Meridian Kiosk product name, and `apps/kiosk/assets/icon.png`. The package version is read from the root `package.json`; the wrapper declares none of its own. Alpha 1 artifacts are unsigned. | Technical spec 3.4, 25.2, 26.4, 26.6; versioning strategy | Packaging config tests asserting all three targets are declared and the version resolves from the root `package.json`; a test asserting the packaged resources contain the `kiosk` client build and no other UI mode's build; a build smoke test producing at least the host platform's installer |
| M19.21 Capacitor native platform projects | Commit the Android and iOS native projects for `@meridian/mobile` so the Field build can become an installable app at all. Carry the `org.meridian.field` application id and the Meridian Field product name already in `capacitor.config.ts`, the launcher icons and splash from the Meridian identity assets, a version derived from the root `package.json`, and only the native permissions a shipped surface asks for — the M18.61 QR path decides whether a camera permission is one of them. | Technical spec 3.3, 26.4; M18.61; versioning strategy | Config tests asserting both native projects declare the shared id, product name, and root-derived version; a test asserting the declared native permissions match a code-defined inventory so an unexplained permission fails the build; a test asserting the native projects reference `apps/client/dist/field` |
| M19.22 Mobile release signing configuration | Add release build configuration for both platforms: an Android release variant signed from an upload keystore, and an iOS archive/export configuration naming the distribution certificate and provisioning profile. Every credential is supplied through the environment and held outside the repository; a build that cannot find one fails rather than emitting an unsigned artifact that looks distributable. | Technical spec 26.5 | Build config tests asserting each credential resolves from the environment and none is defaulted in-repo; a repository test asserting no keystore, certificate, profile, or key material is committed; a test asserting a missing credential fails the release build rather than falling back to debug signing |
| M19.23 Release artifact workflow | Add the release workflow `docs/process/versioning-strategy.md` has deferred to "a later release workflow" since it was written and no task owned. On a version tag it builds, from that one commit, the server image and deployment bundle, the three desktop installers, and the Android app bundle and APK, derives every artifact version from the root `package.json`, and attaches the artifacts to the release. Where the automation has no macOS runner, the iOS archive is produced through M19.24's runbook and the workflow says so rather than silently omitting it. | Technical spec 26.4, 26.7; versioning strategy Release Packaging | Workflow validation/dry run; a test asserting every produced artifact's version equals the root `package.json` version; a test asserting no artifact is committed to the repository |
| M19.24 Distribution runbook and internal track exercise | Add `docs/process/release-packaging.md`: from a clean checkout to an installable artifact for each target, then the store-side path — creating the App Store Connect and Play Console application records, uploading to TestFlight and to the Play internal testing track, and installing the direct APK on an on-site device with no route to the Play Store. Name the credentials each step needs and where they are held, and state the unsigned first-run warning each desktop OS shows. | Technical spec 26.5, 26.6, 26.7; development process section 20 | Documentation check; second-person human evidence that a release build and an internal-track upload were performed by following the document, with no step supplied from memory |
| M19.25 Packaged application install QA | Add `QA-PKG-01-packaged-application-install.md`: install each desktop installer on its own OS and install Meridian Field from its internal track and from the direct APK, launch each, and verify the Settings Versions section reports the app version, UI mode, and deployment target M19.1 specifies, that Kiosk opens fullscreen with the health panel reachable, and that Field runs against an on-site node. Record the unsigned first-run trust warning each desktop OS shows so the install document and reality agree. | Development process section 20; technical spec 25.3, 26.3, 26.6; QA README | Human QA script, linked from the M19.6 release candidate QA index |
| M19.26 Registry-published deployment images | Push the two Dockerfile targets to a container registry from the tagged release workflow, as `meridian-server` and `meridian-server-web` tagged with the root `package.json` version, alongside the `docker save` tarballs M19.23 already attaches. amd64 only: the Dockerfile compiles PHP extensions from source, an arm64 build under QEMU would dominate release time, and Meridian has no arm64 target to serve. `MERIDIAN_IMAGE` in `.env.deployment.example` already exists for a registry prefix, so a node that pulls rather than builds needs no new configuration. | Technical spec 26.4, 26.7; `deploy/runtipi/README.md` | Workflow validation/dry run; a test asserting the pushed tag equals the root `package.json` version; a test asserting the tarball artifacts M19.23 produces are unchanged |
| M19.27 Reverse-proxy deployment mode | Let the deployment image serve correctly behind a reverse proxy it does not own: a `Caddyfile.proxied` serving the shared `meridian.snippet` on `:80` with automatic HTTPS off, a `MERIDIAN_TRUSTED_PROXIES` setting so Laravel reads the forwarded scheme instead of generating `http://` URLs on an HTTPS site, and a `MERIDIAN_APP_KEY_SEED` the entrypoint derives `APP_KEY` from when none is set — `SecretGenerator` refuses to write a key it cannot persist, and a one-click install has no step to run `key:generate`. Every one of the three is inert unless configured, so an existing deployment is unaffected. The bundle validator holds deployment Caddyfiles to HTTPS-only per technical spec 8.2; teach it the difference between a TLS-terminating configuration and a proxied one rather than loosening the rule. | Technical spec 8.2, 8.6, 7.4, 26.2; `deploy/runtipi/README.md` | Feature/config tests for the derived key and the forwarded scheme; a test asserting an unchanged `.env.deployment` renders the same stack as before; a `caddy adapt` build-time parse of the new Caddyfile matching the existing three; a validator test asserting a TLS-terminating Caddyfile is still refused when it serves plain HTTP |
| M19.28 Runtipi app definition and generator | Add the Runtipi app — `config.json`, `docker-compose.yml`, and store metadata — generated from the root `package.json` by a `scripts/deploy` script rather than maintained by hand, so a published app cannot pin an image tag the code has moved past. The app requires a domain (`force_expose`), because any non-development node refuses plain HTTP and one installable at `http://<ip>:<port>` would install cleanly and then answer 503 to everything; SMTP fields are required for the same class of reason, since login is a mailed code. The two open questions in `deploy/runtipi/README.md` are unresolved: M19.29 answers them against a real host, and this task must not answer them by assumption. | Technical spec 26.4; `deploy/runtipi/README.md` | Generator tests asserting the app declares the root version and the amd64 architecture the images are built for; a schema check against Runtipi's published app format; a Compose render test asserting the worker and scheduler services are present |
| M19.29 Runtipi install QA and distribution runbook | Install Meridian on a real Runtipi host from the app definition, and write what it took: `QA-RUNTIPI-01-runtipi-app-install.md` plus the store-side path — creating and publishing the app store repository, adding it in Runtipi, and updating it on a Meridian release. Resolve and record the two open questions in `deploy/runtipi/README.md`, which are drawn from Runtipi's documentation rather than a running instance. Backup, restore, and the fact that an update also runs migrations must be stated where a person installing will read them, not only here. | Development process section 20; technical spec 26.2, 26.7; QA README; `deploy/runtipi/README.md` | Human QA script; second-person evidence that an install was performed by following the document with no step supplied from memory; a documentation check that both open questions are closed with observed behavior |

**QA gate:** A second human can follow install/deployment instructions, run critical QA scripts, and verify release candidate readiness — an organization resolves at both its root path and its organization subdomain, with the marketing surface only at the deployment root — and an organization with Scheduling, Incident Management, and Documents disabled still intakes staff, runs status, checks people in and out, records hours, and calculates credits, with no navigation entry, API route, replicated record, or export for a disabled module anywhere in the product. That same second human installs Meridian Kiosk from a desktop installer and Meridian Field from its internal testing track onto a real device, and runs the critical QA scripts against those installed applications rather than against a development server, with every app reporting the same root version.

**Explicitly out of scope for this milestone:**

- dedicated per-organization infrastructure. ORG-025 requires only that subdomain addressing not preclude a future S-tier offering where an entire organization subdomain runs on dedicated, isolated hardware; no Alpha 1 task provisions it (requirements section 8, Dedicated Organization Infrastructure);
- a generic or third-party plugin system. The module catalogue is fixed in Meridian's own code (MOD-003, technical spec 5.2), and no task makes it extensible, installable, or data-defined;
- sub-module feature toggles. Modules are the only unit of enablement in Alpha 1;
- billing, pricing, or offering enforcement built on entitlement. Entitlement is the mechanism a hosted offering may later use (MOD-006), and no task connects it to payment;
- code signing and macOS notarization of the desktop installers. Technical spec 26.6 makes Alpha 1 desktop artifacts unsigned on purpose: the certificates are a purchase and an organizational identity decision, not an engineering one, and M19.24's install document states the first-run trust warning instead of hiding it. Beta signs them;
- public App Store and Google Play listings: store metadata, screenshots, privacy and data-safety declarations, age ratings, review submission, and rejection handling. Alpha 1 distributes Meridian Field through TestFlight, the Play internal testing track, and the direct APK (technical spec 26.5). What M19.22 and M19.24 require is that the accounts, the signing identities, and the upload path exist and have been exercised, so beta's first submission is a submission rather than a first build;
- self-update for any packaged application. Technical spec 26.6 installs a new desktop version the way the first one was installed, and section 26.3's version mismatch warnings are what tell the operator to do it. Mobile updates arrive through the internal track;
- building the server as anything but the M19.2 deployment bundle and image. There is no desktop-managed server: technical spec 25.1 keeps server process management out of the Electron wrapper for Alpha 1. M19.26 through M19.29 are not an exception to this and were checked against it before being added — a Runtipi app runs the M19.2 image unchanged, and adds a Compose file and an install form rather than a second way to build a server. If a later task proposes a genuinely different server package, this exclusion still refuses it;
- publishing Meridian to the official Runtipi app store. That store accepts no new applications and limits pull requests to bug fixes, so M19.28 produces a Meridian-owned store a user adds by URL. Asking whether the policy has an exception is a message, not a task;
- arm64 images, and therefore Raspberry Pi and ARM mini-PC hosts. M19.26 publishes amd64 only and M19.28 declares it, so Runtipi hides the app on hardware it cannot run on rather than offering an install that fails at first start. Revisit against a real deployment, not against the possibility of one;
- listing Meridian in community Runtipi app-store aggregators, and the discovery work around it. It costs an afternoon and needs M19.29's install to have happened first, so it is post-Alpha-1 outreach rather than an engineering task.

---

### Milestone 20: Platform Landing Page

**Goal:** Build the public landing page that explains the Meridian platform, introduces each major feature with screenshots from the Northwood example organization, and describes the platform offerings.

**Primary source docs:** Requirements 7.25 (PUBLIC-001 through PUBLIC-009) and BRAND-003; technical spec 8.7; UI style guide; accessibility checklist; `docs/process/developer-testing-process.md` (Northwood development scenario).

**Inputs:** A landing page prompt supplied by the product owner will guide copy, structure, and visual direction for these tasks. Where the prompt and the governing documents disagree, the documents win until they are amended through the normal process (planning rule 8).

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M20.1 Landing page structure and copy | Build the landing page at the deployment root: what Meridian is, who it serves, and the section skeleton for the feature tour and offerings, carrying Meridian identity and no organization branding. | PUBLIC-001, PUBLIC-007; BRAND-003 | Feature/UI tests; a test asserting no organization branding profile resolves on the marketing surface |
| M20.2 Northwood screenshot assets | Capture curated screenshots of each featured surface from the seeded Northwood development scenario, commit them as static assets, and document the recapture process so they can be refreshed after UI changes. | PUBLIC-008; developer testing process | Asset presence check; documented recapture steps; human review confirming no real organization's data appears |
| M20.3 Feature tour | Build the feature introduction sections, one per major feature area, each with its Northwood screenshot and a short description of what the feature does for an organization. | PUBLIC-007, PUBLIC-008 | UI tests; accessibility checks, including alt text for every screenshot |
| M20.4 Offerings section | Describe the three platform offerings: free and open-source self-hosting; hosted self-starter without support, at a lowered fee; fully hosted and managed with full support, including an on-site technician. Descriptive only — no payment, billing, or signup path. | PUBLIC-009 | UI tests; a test asserting no payment or self-service signup path exists |
| M20.5 Interest form placement | Surface the existing organization interest form from the landing page sections so a prospective organization can act on what it just read. | PUBLIC-002 through PUBLIC-005 | Feature tests reusing the existing interest form coverage |
| M20.6 Landing page QA script | Add `QA-PUBLIC-01-platform-landing-page.md`. | QA README | Human QA script |

**QA gate:** A visitor who has never heard of Meridian reads the landing page at the deployment root, understands what the platform does, sees each major feature introduced with a Northwood screenshot, understands the three offerings and how they differ, and submits an organization interest inquiry — and no real organization's data appears anywhere on the surface.

**Explicitly out of scope for this milestone:**

- payment, billing, or checkout for any offering;
- self-service organization creation, which remains a God Mode action (PUBLIC-004);
- a CMS or admin editing surface for landing page content;
- serving the marketing surface from an on-site or event-locked node (PUBLIC-006).

---

## 6. Milestone QA Order

QA should run in this order:

1. `QA-BOOT-01`: repository and boot path.
2. Application/onboarding QA.
3. Policy/procedure QA.
4. Shift signup and credential QA.
5. Device readiness QA.
6. Offline field report QA.
7. Department operations and attendance QA.
8. Incident management QA.
9. Central/on-site sync QA.
10. Export/reporting QA.
11. `QA-SYS-01`: system configuration and diagnostics QA.
12. Event geography and maps QA.
13. The Briefing Notes + add-to-Briefing + hub shells QA.
14. Organization and department branding QA.
15. God Mode console orientation, documentation, and changelog QA.
16. God Mode console visual identity QA.
17. Client session and API wiring QA.
18. Insights framework and initial metrics QA.
19. Gap closure QA.
20. `QA-HORIZON-01`: Event Horizon QA.
21. `QA-MOD-01`: organization modules QA.
22. Platform landing page QA.
23. Release candidate QA.

Each QA script should remain readable by someone who did not implement the feature.

---

## 7. Traceability Expectations

At minimum, new issue files and PRs created from this plan should update or reference:

- `docs/process/traceability-matrix.md`
- one or more requirement IDs from `docs/meridian-requirements-document.md`
- one or more technical spec sections from `docs/meridian-technical-spec.md`
- data/API sections when schema, API, sync, permissions, audit, or exports change
- UI docs when screens, components, accessibility, kiosk, IMS, or field surfaces change
- QA docs when a human-visible workflow changes

If a task cannot name a source requirement or technical section, it is not ready for implementation.

---

## 8. Explicitly Deferred From This Plan

The following are acknowledged only as exclusions because the source documents defer or exclude them:

- SMS and push notifications.
- Native app-store distribution.
- Staff self check-in/out.
- GPS collection.
- Configurable form structure.
- Offline incident creation.
- Offline policy/procedure acknowledgments.
- Policy/procedure packet assembly.
- Full-text policy/procedure search.
- Team-scoped acknowledgment requirements.
- Full multi-on-site-node implementation.
- Generic plugin system. The organization module system (M19.11 through M19.19) is a fixed catalogue defined in Meridian's own code; it does not make capability installable, extensible, or data-defined, and no PR may treat it as the beginning of one.
- Sub-module feature toggles. Modules are the only unit of enablement.
- Full inventory custody chains and department-to-department allotments.
- Provision inventory and provision eligibility export for Alpha 1.
- Incident spreadsheet export for Alpha 1.
- Waiver completion export for Alpha 1.
- Full GIS editor, drawing suite, automatic geocoding, and public map builder.
- A `hybrid` map type beyond linkable placement/topographic maps.
- Arbitrary dropped pins and volunteer-submitted map corrections.
- Camps/map places in the global command palette.
- Structured map/location fields on Field Reports.
- Live GPS tracking, turn-by-turn routing, and real-time personnel icons.
- Multiple Placement departments per event.
- Equipment map-location fields (equipment location is not yet modeled).
- Typography/font customization for organizations and departments, by curated list or upload.
- Department override of the full color palette beyond accent and surface background.
- Event-level, per-UI-mode, and per-presentation-profile branding variants.
- Independent organization-defined light and dark palette variants.
- Automatic contrast repair or derived foreground selection for branding colors.

Any PR adding these behaviors must first update the relevant source documents through the normal change-control process.
