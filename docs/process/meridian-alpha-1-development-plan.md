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
| M15B.1 Console spec alignment | Extend technical spec section 22 with the God Mode landing screen, operator documentation, and changelog behavior so the console has a specification beyond screen inventory. | GOD-001 through GOD-028; technical spec 22.1-22.4 | Doc/traceability checks |
| M15B.2 Meridian landing screen | Replace the framework welcome partial and "Welcome to your Orchid application" description with the Meridian orientation summary, including the God-Mode-is-repair-tooling boundary statement. | GOD-001 through GOD-004 | Screen/content tests |
| M15B.3 Configuration readiness checks | Add the deployment/configuration attention group covering node configuration completeness, node role and pairing state, required secrets, secure connection policy, and PowerSync connectivity. | GOD-005, GOD-006, GOD-009; technical spec 25.3, 26.2 | Domain/check tests including healthy and degraded states |
| M15B.4 Organizational data gap checks | Add the data gap attention group covering missing departments, missing Organizers Department, unresolvable Incident Command Department, absent active Lead Organizer, and events without assigned departments. | GOD-005, GOD-007, GOD-009; ORG-002, ORG-005 through ORG-008 | Domain/check tests against seeded gap fixtures |
| M15B.5 Conflict surfacing and clean state | Add the unresolved sync conflict group with a link to conflict resolution, guarantee checks are read-only, and render an explicit all-clear when nothing needs attention. | GOD-008 through GOD-011 | Domain/UI tests; a test asserting no writes occur on view |
| M15B.6 Operator documentation tree | Add `docs/operator/` covering deployment, node setup and pairing, configuration and config source resolution, data repair, conflict resolution, and break-glass procedures, written for operators rather than as specification. | GOD-013 | Doc/process checks |
| M15B.7 Documentation page | Add the in-console Documentation page rendering the packaged operator tree with an index, Markdown rendering, title/heading filtering, and documentation-versus-build version display. Serves only `docs/operator/`. | GOD-012, GOD-014 through GOD-017 | Rendering/packaging tests; a test asserting spec, QA, plan, and issue documents are not reachable |
| M15B.8 Changelog generation | Add a release build step that generates a changelog data file from repository history, capturing pull request title, body, number, merge date, and author grouped by shipped version, and package it with the deployment. | GOD-019, GOD-021; versioning strategy | Generator unit tests; build smoke test |
| M15B.9 Changelog page | Add the in-console Changelog page rendering the packaged data grouped by Meridian version with no change-type filtering. | GOD-018 through GOD-021 | Rendering tests; offline rendering test |
| M15B.10 Changelog refresh | Add central-node-only refresh that merges newer source-repository entries into the packaged baseline, degrades to the baseline on missing network, missing credential, or failure, shows last successful refresh time, is skipped during the active event window, and never blocks rendering. | GOD-022 through GOD-026 | Refresh/degradation/authority tests; credential redaction test |
| M15B.11 Framework link and version cleanup | Remove the external Orchid documentation and changelog menu entries and the framework version badge, replacing them with the internal pages and the Meridian build version. | GOD-027, GOD-028; technical spec 26.3 | Navigation tests asserting no external framework links remain |
| M15B.12 Console QA script | Add `QA-GOD-01-console-orientation-docs-changelog.md`. | QA README | Human QA script |

**QA gate:** A reviewer can open God Mode and read a Meridian orientation summary instead of framework welcome content, see attention items for a misconfigured node, an organization missing its Organizers Department, and an outstanding sync conflict, follow each item to the screen that resolves it, watch the list report all-clear once resolved, read operator documentation in-console with no network access and confirm specification and planning documents are not reachable there, read a version-grouped changelog on an offline on-site node, and confirm no menu entry links to Orchid documentation, Orchid's changelog, or the framework version.

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

**Goal:** To be specified.

This milestone is reserved for scope identified as missed across earlier milestones. Its scope, source documents, tasks, acceptance criteria, and QA gate will be added through the normal process before any task under it is implemented.

---

### Milestone 19: Packaging, Event-Mode Safeguards, and Release Candidate QA

**Goal:** Produce versioned Alpha 1 builds and verify release readiness.

**Primary source docs:** Technical spec sections 8, 25, 26, 27, 28, 29; process release checklist; QA README; UI/kiosk docs.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M19.1 Version metadata | Add server, mobile, Electron, and config schema version display. | Technical spec 26.3 | Unit/UI tests |
| M19.2 Deployment config bundle | Package Docker/Caddy/PowerSync/DNS deployment config bundle. | Technical spec 4, 8, 26 | Build smoke test |
| M19.3 Event-mode secret safeguards | Refuse/default-generate secrets as specified. | Technical spec 7.4, 26.2 | Feature/config tests |
| M19.4 HTTPS and PowerSync fail-closed | Validate production/event secure connection policy. | Technical spec 8.2, 8.6, 26.2 | Config tests |
| M19.5 Electron health finalization | Show node name, role, event, sync, PowerSync, discovery, HTTPS, connected devices, and versions. | Technical spec 25.3 | Desktop QA |
| M19.6 Release candidate QA index | Add a release-candidate QA checklist that links milestone QA scripts. | Development process section 20 | Human QA script |
| M19.7 Install/deployment dry run | Document second-person install/deployment evidence requirement. | Development process section 20 | Human QA evidence |

**QA gate:** A second human can follow install/deployment instructions, run critical QA scripts, and verify release candidate readiness.

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
11. Event geography and maps QA.
12. The Briefing Notes + add-to-Briefing + hub shells QA.
13. Organization and department branding QA.
14. God Mode console orientation, documentation, and changelog QA.
15. God Mode console visual identity QA.
16. Client session and API wiring QA.
17. Insights framework and initial metrics QA.
18. Gap closure QA, once Milestone 18 is specified.
19. Release candidate QA.

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
- Generic plugin system.
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
