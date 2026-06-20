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

### Milestone 2: Mobile and Desktop Shells

**Goal:** Establish the Vue/Capacitor field app and Electron on-site wrapper shells before field workflows.

**Primary source docs:** Technical spec sections 3.2, 3.3, 9, 12, 13, 25, 26, and 29; UI implementation contract sections 3, 5, 6, 12, and 18; kiosk guide.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M2.1 Vue app shell | Add mobile/field app shell with routing placeholder and no domain workflows. | Technical spec section 3.2; UI implementation contract section 3 | App builds; smoke test loads |
| M2.2 Capacitor baseline | Add Capacitor project configuration and documented local run path. | Technical spec section 3.2 | Build/config validation |
| M2.3 Electron wrapper shell | Add Electron app that opens the local Meridian UI. | Technical spec sections 3.3, 25.1, 25.2 | Desktop app opens configured URL |
| M2.4 Electron health placeholder | Display local node/server version placeholders from server health. | Technical spec section 25.3; kiosk guide section 12 | Manual desktop QA |
| M2.5 Shared UI tokens baseline | Add semantic token skeleton shared by field/admin surfaces. | Style guide; UI implementation contract section 10; component spec section 3 | Visual smoke test |

**QA gate:** A reviewer can open the web/admin app, mobile shell, and Electron shell, even though product workflows are still placeholders.

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

**QA gate:** Seeded users, organization, event, departments, teams, memberships, statuses, and permission scaffolds are visible in admin surfaces and covered by tests.

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
| M8.5 Readiness UI | Add field app readiness checklist surface. | Technical spec 14; UI implementation contract 16 | Component/UI tests |
| M8.6 Offline state UI | Add shared offline/sync status display for field app and kiosk. | UI implementation contract 11.13, 16 | UI tests/manual QA |
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
| M9.8 Photo sync and storage | Sync photos up, store server-side, restrict downloads. | Technical spec 18.5, 18.6 | Feature/security tests |
| M9.9 Field report QA script | Add `QA-FR-01-offline-field-report.md`. | QA README | Human QA script |

**QA gate:** A reviewer can submit a field report offline, reconnect, see FRA assignment, verify immutability, confirm IC-only visibility, and verify Name References remain plain text for authors while parsing into permitted derived search/display behavior.

---

### Milestone 10: Shift Lead Board, Attendance, Hours, Deployments, and Equipment

**Goal:** Support staff-mediated field operations and actual work records.

**Primary source docs:** Requirements sections 3.13-3.15, 3.18-3.19, 5.7-5.9, 7.9-7.10, 7.13; Technical spec sections 20, 23, 27.1; data/API sections 10.10, 10.12-10.14, 15.2; UI implementation contract sections 12.5, 13.2-13.3, 16.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M10.1 Shift Lead Board roster | Show current shift roster and checked-in state. | SLB-001, SLB-002 | UI/feature tests |
| M10.2 Check-in operation | Shift lead checks staff in with operation record. | SLB-003; technical spec 20.2 | Domain/policy tests |
| M10.3 Check-out and hours | Check-out creates actual hours with actual start/end. | SLB-004 through SLB-006; HOURS-001 through HOURS-006 | Domain tests |
| M10.4 No-show operation | Shift lead marks no-show idempotently. | Technical spec 20.2 | Domain tests |
| M10.5 Offline attendance queue | Check-in/check-out/no-show work offline and sync later. | Technical spec 20.1; data/API 7.2 | Sync/idempotency tests |
| M10.6 Hours correction grace period | Allow corrections during grace period and freeze later. | HOURS-007, HOURS-008 | Domain/audit tests |
| M10.7 Unscheduled eligible staff member | Add an eligible unscheduled staff member during operations. | SLB-008; SHIFT-016 | Domain/UI tests |
| M10.8 Deployment/location assignment | Assign and move current deployment/location. | SLB-009, SLB-010; data/API 10.14 | Domain/UI tests |
| M10.9 Equipment checkout/check-in | Manual equipment workflows and states. | SLB-011, SLB-012; EQUIP-001 through EQUIP-005 | Domain/UI tests |
| M10.10 Field report/incident shortcuts | Add shift board shortcuts without bypassing permissions. | SLB-013, SLB-014 | UI/policy tests |
| M10.11 Attendance QA script | Add `QA-SLB-01-checkin-checkout-hours.md`. | QA README | Human QA script |

**QA gate:** A reviewer can run a shift, check staff in/out, create hours, mark no-show, assign deployments, and check equipment in/out.

---

### Milestone 11: Incident Management

**Goal:** Support online-only incident management for the configured Incident Command Department.

**Primary source docs:** Requirements sections 3.21-3.23, 3.21A, 5.11, 7.12, 7.12A; Technical spec sections 16, 19, 23, 24, 27.1, 28; data/API sections 6.5, 10.16, 15.4, 15.4A; IMS surface specification; UI implementation contract section 15.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M11.1 IC department selection | Configure event IC department. | INC-002; requirements 3.23 | Model/feature tests |
| M11.2 IC team grants | Grant `ic_viewer`, `ic_operator`, `ic_lead` through teams. | Technical spec 16; data/API 6.5 | Policy tests |
| M11.3 Incident model and numbers | Add incidents with IMS number assignment. | INC-001, INC-003, INC-004 | Model/domain tests |
| M11.4 Online incident create | IC operator/lead creates incident only while connected. | Technical spec 19.2, 19.4 | Feature/policy tests |
| M11.5 Incident list/detail | Build restricted incident list/detail surfaces. | IMS spec sections 5-7 | UI/policy tests |
| M11.6 Incident timeline notes | Add append-only incident timeline entries. | INC-007, INC-014; data/API 10.16 | Domain tests |
| M11.6A Incident Name References | Extract Name References from incident notes and attached Field Reports, render chips near tags, and wire chip clicks to normal permission-filtered search. | NR-001 through NR-014; technical spec 19.9, 19.10 | Parser/search/UI/policy tests |
| M11.7 Incident status/title edits | Edit regardless of state; status affects filtering only. | INC-010 through INC-012 | Domain/UI tests |
| M11.8 Link/unlink field report | Copy field report content into incident notes and strike relationship on removal. | FR-011 through FR-014; INC-014 | Domain tests |
| M11.9 Incident attachments strike | Allow incident attachments to be stricken, not deleted. | INC-013; data/API 10.17 | Domain/security tests |
| M11.10 Incident PDF print | IC leads print incidents to PDF. | INC-015 | Export test/sample |
| M11.11 Incident QA script | Add `QA-INC-01-incident-management.md`. | QA README | Human QA script |

**QA gate:** A reviewer can verify IC-only incident access, create/edit incidents online, link field reports, review history, and confirm Name Reference chips/search do not expose unauthorized incidents or Field Reports.

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

### Milestone 15: Packaging, Event-Mode Safeguards, and Release Candidate QA

**Goal:** Produce versioned Alpha 1 builds and verify release readiness.

**Primary source docs:** Technical spec sections 8, 25, 26, 27, 28, 29; process release checklist; QA README; UI/kiosk docs.

| Task | PR-sized outcome | Source references | Test/QA expectation |
|---|---|---|---|
| M15.1 Version metadata | Add server, mobile, Electron, and config schema version display. | Technical spec 26.3 | Unit/UI tests |
| M15.2 Deployment config bundle | Package Docker/Caddy/PowerSync/DNS deployment config bundle. | Technical spec 4, 8, 26 | Build smoke test |
| M15.3 Event-mode secret safeguards | Refuse/default-generate secrets as specified. | Technical spec 7.4, 26.2 | Feature/config tests |
| M15.4 HTTPS and PowerSync fail-closed | Validate production/event secure connection policy. | Technical spec 8.2, 8.6, 26.2 | Config tests |
| M15.5 Electron health finalization | Show node name, role, event, sync, PowerSync, discovery, HTTPS, connected devices, and versions. | Technical spec 25.3 | Desktop QA |
| M15.6 Release candidate QA index | Add a release-candidate QA checklist that links milestone QA scripts. | Development process section 20 | Human QA script |
| M15.7 Install/deployment dry run | Document second-person install/deployment evidence requirement. | Development process section 20 | Human QA evidence |

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
7. Shift Lead Board and attendance QA.
8. Incident management QA.
9. Central/on-site sync QA.
10. Export/reporting QA.
11. Event geography and maps QA.
12. Release candidate QA.

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

Any PR adding these behaviors must first update the relevant source documents through the normal change-control process.
