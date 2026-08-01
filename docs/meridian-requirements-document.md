# Meridian Requirements Document

**Project:** Meridian  
**Type:** Open-source Volunteer Operations Platform  
**Phase:** Discovery / Requirements Gathering  
**Status:** Draft v0.3  
**Architecture:** Intentionally out of scope for this document
**Additive Update:** Policies and Procedures requirements added in v0.2.
**Additive Update:** Policies and Procedures discovery decisions incorporated in v0.3.
**Additive Update:** Name References requirements added for IMS notes and Field Reports.
**Additive Update:** Event Geography & Maps (event maps, camps, map locations, and the event-level Placement department designation) added for MVP.
**Additive Update:** Immutable Field Report titles added for MVP.
**Additive Update:** Fixed Meridian UI modes and deployment target requirements added.
**Additive Update:** The Briefing (Command hub: Notes, After Action Reports, Directions, Action Plan, Notices) added. Notes are standalone; Command adds them to The Briefing/AAR by reference or link. Alpha 1 implements Notes + add-to-Briefing + hub shells.
**Additive Update:** Milestone 18 gap-closure requirements added — organization configuration and lifecycle evaluation (ORG-017–ORG-021), staff profile surface (VOL-014), applicant self-service portal (APP-012–APP-015), department team designations, the Department Operator role, and Staff Coordinator (TEAM-011–TEAM-018, section 4.8A), trainer authority (TRAIN-011), document-backed waivers and waiver administration (WAIVER-007–WAIVER-010), relative schedule cutoff and staff shift signup (SHIFT-017, SHIFT-018), Logistics Desk hours correction (SLB-031, SLB-032), Field Reports taken on behalf of another staff member (FR-015–FR-017), equipment assignment scope and the EQUIP-007 duplicate renumbered to EQUIP-008 (EQUIP-009), reporting surfaces (REPORT-014, REPORT-015), notifications (7.24), and public platform surfaces (7.25).
**Additive Update:** System Configuration and Diagnostics requirements (SYS-001-SYS-041, section 7.26) added for the environment/configuration catalogue, node-local database overrides, the diagnostics framework, sanitized exports, and node health reporting.
**Additive Update:** Pooled and individually tracked equipment requirements (EQUIP-010–EQUIP-017) added for equipment lookup by asset tag, serial number, or search at checkout, quantity-based pooled equipment, and derived pooled availability, replacing the unit-by-unit checklist as the way equipment is handed out.
**Additive Update:** Staff self-service profile maintenance requirements (VOL-015–VOL-026) added for direct editing of preferred name, phone, and city/state, a two-change allowance on self-service handle changes with reviewed handle change requests beyond it, reviewed profile picture change requests, and the audit and notification of both.

---

## 1. Purpose

Meridian is an open-source volunteer operations platform for events.

Meridian supports organizations that recruit, approve, coordinate, schedule, credential, track, and report on staff work across events and departments.

Meridian is not an HR system, payroll system, personnel file, or employee management platform.

All operational users are referred to as Staff, including organizers, department leads, shift leads, trainers, and incident users.

Staff may include unpaid volunteers and paid personnel. Meridian tracks participation, eligibility, credentials, hours, credits, and operational history, but payment, wages, payroll, and employment status remain outside Meridian's scope.

Meridian also supports policies and procedures as human-readable documents that may include reusable text fragments defined at organization, department, or team scope.

Meridian supports The Briefing as an event-scoped Incident Command hub that shares Command-authored and Command-curated operational information across departments, including Notes, After Action Reports, Directions, Action Plans, and Notices.

---

## 2. Core Principles

### 2.1 Staff Managing Staff

Meridian models volunteer operations, not employment.

The platform should avoid employee/payroll/HR framing and instead support:

- staff status
- department participation
- team membership
- training completion
- shift eligibility
- credential eligibility
- hours worked
- credits earned
- field reporting
- incident management
- Command briefing and after-action reporting

### 2.2 Data Lifecycle

Data may be:

- added
- modified
- appended to
- stricken
- frozen

Data is generally not destroyed.

Historical operational records should be preserved, especially for:

- incidents
- field reports
- hours worked
- credits earned
- credential changes
- status changes
- organizer removals

### 2.3 Human Readability

Operational history should be understandable to humans without requiring access to database internals.

Important historical records should preserve the names, labels, and meanings that were true at the time the work happened.

Example:

If a team named `Dirt` is later changed to `Water`, historical shifts worked as `Dirt` should still appear as `Dirt`.

### 2.4 Auditability

Changes should be attributed to the user making the change.

Important changes should preserve history, including:

- incident updates
- incident notes
- stricken incident content
- field report attachment/removal
- staff status changes
- organizer removals
- credential revocations
- hour corrections
- credit calculations

### 2.5 Configurability

Organizations and departments should be able to configure their own operational concepts without Meridian hardcoding one organization’s process.

Configurable areas include:

- departments
- teams
- incident states
- incident types
- trainings
- waivers
- shift eligibility
- credit policies
- staff lifecycle durations
- active/inactive/emeritus thresholds
- organization-level Organizers Department
- event-specific Incident Command Department
- policy/procedure documents
- reusable policy/procedure fragments

### 2.6 Separation of Governance and Operations

Organizations manage governance.

Departments manage operations.

Organizations are responsible for:

- organization settings
- permissions
- organizer management
- staff approval
- department creation
- organization-level status
- organization-level policy
- Organizers Department
- default Incident Command Department
- default credit policy

Departments are responsible for:

- department staff pools
- department status
- teams
- trainings
- shift setup
- shift operations
- deployment/location assignments
- department-scoped exports
- department staff emergency contacts

### 2.7 Organization Status Supersedes Department Status

Staff have both organization-level status and department-level status.

Organization status is always stronger.

A department cannot override an organization-level blocking status.

### 2.8 Persistent Staff History

Staff history persists across events.

Meridian should preserve:

- events applied to
- events worked
- departments worked for
- teams worked under
- shifts worked
- actual hours worked
- credits earned
- credentials granted/blocked/revoked
- years of service
- status history

### 2.9 Planned Work and Actual Work Are Different

Scheduled shifts represent planned coverage.

Actual hours represent work performed.

Meridian must track both.

A staff member may work different hours than scheduled.

A staff member may be added to a shift during operations, provided they satisfy eligibility requirements.

### 2.10 MVP Pragmatism

The October MVP should support operational truth and export/reporting needs.

It does not need to fully automate every real-world process.

Where appropriate, the MVP may provide eligibility reporting and spreadsheet exports while allowing physical logistics to remain manual.

### 2.11 Reusable Policy Text

Meridian should support reusable policy and procedure text without forcing organizations, departments, and teams to duplicate common language manually.

Reusable text should be defined as fragments and referenced from policy/procedure documents.

Policy/procedure document content and fragment content should use Markdown only for MVP.

Fragments should not contain references to other fragments.

Nested fragments should not be supported.

When a policy/procedure document is viewed, referenced fragment text should appear inline as normal document text.

When a policy/procedure document is edited, fragment references should remain visible as references and should show the referenced fragment version.

Policy/procedure documents should use the latest fragment text when rendered.

When a fragment changes, documents that reference it should automatically render the updated fragment text.

When an included fragment changes, the referencing document version should be bumped so acknowledgments can record the document version that was acknowledged.

### 2.12 Fixed UI Modes and Deployment Targets

Meridian has one shared product UI codebase, but the product is delivered through three fixed UI modes:

- **Meridian Admin**: the server-hosted web application;
- **Meridian Field**: the Capacitor mobile application;
- **Meridian Kiosk**: the Electron desktop/on-site application.

UI mode is selected by the deployment target and must not be inferred from viewport size, pointer type, device type, network state, user role, permission grants, or authentication state.

UI mode controls shell, navigation posture, session framing, and workflow presentation. It does not grant permissions. Authentication and authorization remain separate requirements and must work consistently wherever a surface is available.

Responsive layout, touch affordances, density, fullscreen presentation, and trusted-workstation state are lower-level presentation or session concerns. They must not be named or implemented as `field`, `kiosk`, or `admin` mode switches.

---

# 3. Glossary

## 3.1 Organization

An organization produces events and manages staff.

Example:

- Northwood Collective

Organizations own or configure:

- departments
- staff
- organizers
- policies
- events
- trainings
- waivers
- credit policies
- incident taxonomies
- Organizers Department
- default Incident Command Department

Organizations approve event applicants into the staff pool.

Organizations define organization-level staff status.

---

## 3.2 Event

An event is a specific occurrence produced by an organization.

Example:

- Emberfall 2026

Events may contain:

- applications
- department participation
- shifts
- deployments/locations
- credentials
- equipment checkouts
- field reports
- incidents
- hours worked
- credits earned
- provision eligibility reports

Events may override the organization’s default Incident Command Department.

---

## 3.3 Department

A department is a persistent operational unit within an organization.

Examples:

- Rangers
- Gate
- DPW
- LNT

Departments manage staff pools across multiple events.

A staff member may belong to multiple departments simultaneously.

Department membership is persistent and not limited to one event.

An organization defines one persistent Organizers Department.

The Organizers Department does not change per event.

Organizer authority comes from membership or grants within the configured Organizers Department, not from free-floating user permissions.

Membership in the Organizers Department grants elevated governance authority but does not grant unrestricted visibility into all organization data.

Departments manage:

- department staff status
- teams
- trainings
- shifts
- shift eligibility
- shift operations
- deployment/location assignments
- hours worked for the department
- department-scoped exports
- emergency contact access for their staff

Departments may participate in one or more events.

A department may add its staff to an event without requiring a new event application, provided each staff member is already approved and active enough to participate.

---

## 3.4 Staff

A staff member is a person known to an organization and approved, prospective, active, inactive, emeritus, retired, or blocked from participation.

All Meridian users are staff.

A staff member may belong to:

- multiple organizations
- multiple departments
- multiple teams within a department

A staff member has:

- organization-level status
- department-level status per department
- team memberships
- training records
- waiver completion records
- shift signup history
- hours worked
- credits earned
- credential eligibility history

Staff profile fields include:

- legal name
- email
- preferred name
- handle
- phone
- emergency contact
- city/state
- age/date of birth

The term `handle` refers to the staff member's operational/radio handle. Meridian does not separately model playa name, callsign, Ranger name, or radio name.

After a staff member becomes Active in an organization, the staff member may upload, replace, or remove one current profile picture for their own staff profile.

Staff profile pictures are visible only to people who can already view that staff profile.

Meridian does not need to preserve previous staff profile pictures after replacement or removal.

Staff records may be created with only legal name and email when full profile details are not available yet. Additional profile fields may be completed during onboarding or later profile maintenance.

---

## 3.5 Organization Status

Organization status applies across the entire organization and supersedes department status.

Organization statuses include:

- Prospective
- Active
- Inactive
- Emeritus
- Retired
- Do Not Staff

### Prospective

A newly approved applicant becomes Prospective.

A Prospective staff member has been approved at the organization level but has not yet completed the steps required to become Active.

Prospective status lasts for a configurable number of years.

After that period, the staff member becomes Inactive and must reapply.

### Active

An Active staff member is currently eligible to participate in the organization, subject to department/team/training/waiver/shift rules.

Working for any department should prevent a staff member from becoming inactive at the organization level.

### Inactive

Inactive means the staff member is not currently active but is not blocked from future participation.

Inactive staff may reapply.

### Emeritus

Emeritus means the staff member is no longer expected to perform regular duties but may still listen in, advise, or contribute if asked.

Emeritus is similar to contract-basis participation.

### Retired

Retired means the staff member is no longer working in any capacity.

Retired is distinct from Emeritus.

### Do Not Staff

Do Not Staff, or DNS, is an organization-wide blocking status.

DNS means the person may not access the system or participate in the organization.

DNS is permanent unless changed by organizers.

Only organizers may change DNS status.

Applications from DNS email addresses are automatically rejected without automatic notice to the applicant.

Meridian may track DNS individuals by email address even before an account exists.

---

## 3.6 Department Status

Department status applies within a specific department.

Department statuses include:

- Prospective
- Active
- Inactive
- Ineligible
- Emeritus
- Retired

### Department Prospective

A staff member may be Prospective within a department until required department/team trainings are complete.

If the department has no required trainings, the staff member becomes Active after department assignment.

### Department Active

A staff member is Active in a department after satisfying the department’s activation requirements.

### Department Inactive

Inactive means the staff member is not currently on the team for that department, but may still work elsewhere.

### Department Ineligible

Ineligible means the staff member may not work for that department unless restored by a department lead.

Ineligible is department-specific and indefinite until changed.

Ineligible does not prevent working for other departments.

### Department Emeritus

A department Emeritus staff may advise, listen in, or contribute if asked but has no standing obligation.

### Department Retired

A department Retired staff member is no longer working in that department in any capacity.

Department status is changed by department leads only.

---

## 3.7 Team

A team is a named group within a department.

Teams replace the earlier concept of roles.

Examples:

- Dirt
- Operator
- Command
- Officer of the Day
- Logistics
- Leadership
- Department Leads

Team membership may grant:

- shift eligibility
- required trainings
- system authority
- operational identity
- leadership responsibility

Team names are arbitrary and do not define system authority by themselves.

Department-scoped permission grants may be assigned to any team in the
department. Alpha 1 department operational grants include:

- `department_logistics`
- `department_operations`
- `department_administration`
- `department_planning`

The same team may carry more than one grant, and different teams may share the
same grant.

Every department has a default team.

Departments may rename their default team.

Staff cannot belong to a department without belonging to at least one team.

A staff member may belong to multiple teams.

Teams are persistent across events.

Teams may be archived.

Archived teams remain visible in historical records.

Team names used in historical worked shifts are preserved as they were at the time of work.

A shift may have its own displayed title/function, but eligibility to work that shift derives from team membership.

A shift does not require membership in multiple teams.

A shift has exactly one team.

System authority should not be granted as free-floating permissions. Authority should come through organization, department, or team membership.

Organizer authority should come through the organization’s configured Organizers Department.

Lead Organizer authority should be grantable within the configured Organizers Department rather than modeled as a direct user-only exception.

---

## 3.8 Training

A training is a qualification associated with an organization, department, team, event, or shift eligibility rule.

Trainings may be:

- event-specific
- annual
- one-off

Trainings are delivered in-person or online:

- In-person trainings with a scheduled session are listed as a shift signup for the training's team or department and are signed up for like other shifts.
- Online trainings do not require signup and carry a URL where staff complete the training at their own pace.

Every training has a training page where staff can read when and where the training is available, the time commitment, and what follows completion (for example unlocked shifts, team placement, or provisions that come with the training).

Trainings may:

- enable team membership
- enable shift eligibility
- be required before department activation
- be required before shift signup
- have prerequisites
- expire
- have completion dates
- be entered manually by authorized trainers/leads
- be imported from a spreadsheet

Trainings cannot be waived.

If an authorized person determines that a staff member satisfies a training requirement, they record the training as complete.

Meridian does not separately model waivers/equivalencies for trainings in MVP.

A staff member cannot sign up for or be added to a shift if required training is incomplete.

---

## 3.9 Waiver

A waiver is a required document or acknowledgment assigned at the organization, department, or team level.

Meridian tracks waiver completion as complete/incomplete.

Meridian does not need to store signed document contents.

Waivers may expire.

Waiver completion may gate:

- application approval
- department assignment
- team membership
- shift signup
- event check-in
- credential issuance

A staff member cannot be added to a shift if required waivers are incomplete.

If a required waiver expires after shift signup but before the event, credential eligibility becomes blocked until the waiver is completed again.

---

## 3.10 Application

An application is an event-specific intake record.

People apply to events, not directly to departments.

An application may optionally record **department interest**: a non-binding intake signal indicating which event-participating departments the applicant is open to working with. Department interest is not department assignment, not department membership, not approval, not access, not team selection, and not routing. Empty department interest means no preference (open to any). Department interest does not change the event-level nature of the application.

Applications are operational signals.

Applications do not directly alter department membership, team membership, shifts, or system access.

Application statuses include:

- Submitted
- Approved
- Rejected
- Deferred
- Withdrawn
- Auto-rejected due to DNS

Only the applicant may withdraw their own application.

Approval happens at the organization level.

An approved applicant becomes a Prospective staff member.

Department assignment happens after organization approval.

An approved application may be rescinded before team assignment.

If an approved application is rescinded before team assignment, the person becomes Inactive.

Once a staff member is assigned to a team, the application can no longer be rescinded. Future changes are handled through staff and department status.

Existing active staff may apply to events to signal event participation interest, including optional department interest on the application form, but active staff can also be added to future events by department leads without submitting a new application. Returning or active staff department memberships are not prefilled as department interest.

---

## 3.11 Shift

A shift is a planned block of staff coverage for a department.

A shift defines:

- event
- department
- time
- displayed title/function
- eligible team
- capacity
- required trainings
- required waivers
- signup availability dates
- credit policy, if different from the organization default
- deployment/location options, if used

Shifts answer:

> Who should be working?

Shift signup is immediate when the staff member is eligible.

Shifts may become full.

Staff may remove themselves from shifts before schedule lock/cutoff rules apply.

Departments may lock schedules after a configured cutoff date.

Department leads may remove staff from shifts.

Shift overlap is discouraged and should warn the user rather than hard-blocking by default.

Leads may assign overlapping shifts with elevated authority.

A staff member may be added to a shift during operations if they satisfy all eligibility rules.

---

## 3.12 Shift Signup

Shift signup is the planned assignment of a staff member to a shift.

Shift signup determines planned coverage and credential eligibility.

Shift signup does not guarantee actual hours worked.

Eligibility may depend on:

- organization status
- department status
- team membership
- training completion
- waiver completion
- age requirements
- shift capacity
- signup availability dates
- schedule lock rules

A staff member may not sign up for a shift before required training is complete.

A staff member may not sign up for a shift before required waivers are complete.

A staff member may not be added to a shift if department-level status is Ineligible.

---

## 3.13 Check-In / Check-Out

Check-in records the start of actual shift participation.

Check-out records the end of actual shift participation.

Staff may check in before the scheduled start time.

Staff may check out after the scheduled end time.

Check-out creates the actual hours record.

Department Logistics may edit actual start/end time during check-out.

Authorized attendance managers may correct hours after check-out during the organization-wide correction grace period.

---

## 3.14 Hours Worked

Hours worked represent actual staff participation.

Hours worked are distinct from scheduled hours.

Hours must always be associated with:

- an event
- a department
- a shift
- a staff member
- actual start time
- actual end time

Hours cannot exist without a department.

Hours cannot exist without a shift.

Setup, teardown, standby, emergency coverage, or unscheduled labor must be represented as shift work if it should count for hours/credits.

Hours are recorded through Department Logistics check-out.

Staff do not self-report hours in MVP.

Hours may be corrected during the organization-wide post-event grace period.

After the grace period closes, hours are frozen.

---

## 3.15 Credits

Credits are value earned through staff service.

Credits are derived from finalized hours worked.

Credits are calculated after the organization-wide correction grace period closes.

Credits are frozen after calculation.

Credits belong to staff and are associated with the department and shift work that produced them.

Credits are calculated using:

1. the shift-specific credit policy, if one exists
2. otherwise, the organization default credit policy

There is no department default credit policy.

Departments may assign shift-specific credit policies to incentivize coverage for key operations.

Examples:

- overnight shifts may be worth more
- hard-to-fill shifts may be worth more
- critical operations may be worth more

Historical credit calculations do not change retroactively after the freeze.

---

## 3.16 Credential

A credential is event-specific approval to work an event.

A credential is not a ticket, wristband, laminate, parking pass, meal pog, or T-shirt.

Those are provisions or external operational workarounds.

A credential is more than entry permission. It represents approval to participate as a staff member for the event.

A staff member may have only one event credential per event.

That credential may be based on shifts for one department or multiple departments.

Credential eligibility requires:

- at least one signed-up shift
- all required waivers complete
- age requirements satisfied
- no organization-level blocking status
- no department-level Ineligible status for the department being worked

Working an unscheduled shift does not retroactively make a staff member credential-eligible.

Credential states include:

- Eligible
- Blocked
- Revoked

### Eligible

The staff currently satisfies credential requirements.

### Blocked

The staff no longer satisfies credential requirements.

Examples:

- all shifts removed
- required waiver expired
- age requirement not satisfied
- organization-level blocking status applied
- department-level Ineligible status applied

### Revoked

A credential has been intentionally revoked by an authorized person.

Credentials may be revoked only by:

- organizers
- Incident Command Department leads

When a credential is revoked, future shifts are removed where possible.

Completed shifts and recorded hours remain preserved.

For October MVP, Meridian determines credential eligibility and provides reporting. It does not need to physically issue credentials.

---

## 3.17 Provision

A provision is a physical or issued benefit.

Examples:

- wristband
- laminate
- parking pass
- meal pog
- T-shirt
- future ticket
- staff credential item

Provisions are distinct from credentials.

A staff member may earn a future benefit without being credentialed for the current event.

Provision eligibility may be based on:

- hours worked
- manual issuance

For MVP, Meridian only needs provision eligibility/export if later prioritized. Provision eligibility export is not required for the October MVP.

Provision inventory tracking is out of scope for October MVP.

---

## 3.18 Equipment

Equipment is a tracked physical item used during operations.

Examples:

- radios
- vests
- flags

For MVP, equipment tracking is visible/manual and limited.

MVP equipment tracking supports:

- checkout to individual staff members
- check-in from individual staff members
- visibility on the Department Overview and Logistics Desk
- manual correction when physical handoffs happen outside the system

MVP equipment states include:

- Available
- Checked out
- Returned
- Missing
- Damaged

Department Logistics may check equipment in/out.

Department-to-department allotments are future scope.

Full inventory custody chains are future scope.

Equipment does not need to be tied to a shift for MVP.

Equipment may be issued before, during, or after a staff member's shift.

A staff member may not be marked off-site for a department while holding
checked-out equipment for that department/event unless the equipment is returned
or marked Missing/Damaged.

---

## 3.19 Deployment / Location

A deployment or location is the current assignment of a staff member during a shift.

Deployments answer:

> Where are staff currently assigned?

For MVP, deployment tracking only needs to show the current deployment/location assignment.

Department Operations may assign and update a staff member's current
deployment/location from the Operations Center.

Staff may be moved between deployments/locations during a shift.

A staff member has at most one current deployment/location per shift.

Department Operations may assign or change deployment before or during the shift.

MVP does not need to preserve deployment movement history.

Future versions may preserve full deployment history.

---

## 3.20 Field Report

A field report is a single-perspective operational report written by an authorized staff.

A field report contains:

- event
- author
- title
- report text
- appended entries, if any

The title is required plain text. Outer whitespace is trimmed. Titles must be 1–200 characters after trimming. Duplicate titles are allowed within an event. Appends do not have titles and cannot change the original title.

Field reports are not private to the author.

Field reports are visible to:

- the author
- the event’s Incident Command Department

Field reports are not visible to non-IC department leads by default, even if their staff authored them.

Field reports cannot be edited. The original title and body are immutable after submission.

Field reports cannot be stricken.

Only the author may append to their own field report.

If a field report contains incorrect, spammy, malicious, or disputed content, the expected response is to add ordinary incident notes where relevant. Meridian does not provide a special corrective-note mechanism for field reports.

Field reports may exist independently.

Field reports may be attached to one or more incidents.

When a field report is attached to an incident, the field report content is copied into the incident notes as `Field Report: <title>`, followed by the Field Report author and body.

When a field report is appended to, only the added content is copied into associated incidents.

When a field report is removed from an incident, the incident history should show that relationship as stricken.

Field Report body text may contain Name References. Field Report titles do not participate in Name Reference parsing.

Name References in Field Report body text and appends are parsed after submission for permitted search, display, and rendering support. They remain part of the original report text and do not give the author any additional access to incidents, reports, or search results.

---

## 3.21 Incident

An incident is an operational record managed by the event’s Incident Command Department.

Incidents are event-specific.

Incident fields include:

- IMS Number
- state
- started timestamp
- summary
- involved staff
- incident types
- location
- attached field reports
- linked incidents
- attachments
- tags
- Name References derived from notes and attached field reports
- notes

Incidents are not destroyed.

Incidents are not merged away.

Incidents may be edited regardless of state.

Incident state affects list filtering and operational status, not editability.

Incident timestamps are not retroactively changed.

Incident changes are preserved in history.

Incident attachments may be stricken but not deleted.

Incidents may be printed/exported to PDF by Incident Command Department leads.

Incidents are not part of general spreadsheet exports for MVP.

Incident views may display Name Reference chips near existing incident tags when Name References are present in incident notes or attached Field Reports.

Clicking a Name Reference runs a normal permission-filtered search for the reference text without the `@` prefix. It does not open a Name Reference profile, detail page, volunteer profile, alias record, or canonical person/entity record.

---

## 3.21A Name References

Name References are lightweight inline `@name` markers in Incident notes and Field Reports.

They are syntax sugar for operational text. They are intended to make names, handles, camps, vehicles, or other informal identifiers easier to visually scan and search across permitted IMS text.

Examples:

```text
@ranger-bucket
@bucket
@blue-hat
@blue_hat
@camp-moonbeam
@white-truck
```

A Name Reference starts with `@` and continues through letters, numbers, hyphens, and underscores.

Supported characters after `@` are:

```text
A-Z
a-z
0-9
-
_
```

Whitespace or punctuation ends the Name Reference.

Examples:

```text
@bucket.        -> bucket
@bucket,        -> bucket
@bucket)        -> bucket
@blue-hat       -> blue-hat
@blue_hat       -> blue_hat
@ranger bucket  -> ranger
```

Multi-word references should use hyphens or underscores. Bracket syntax such as `@[Ranger Bucket]` is not supported for MVP.

Name Reference matching and search are case-insensitive. The original typed casing may be preserved in rendered source text, but derived index/search behavior should normalize case.

The source of truth is always the original Incident note or Field Report text.

Meridian may maintain a rebuildable derived index of extracted Name Reference tokens for search performance, incident summary chips, rendering/highlighting support, and offline/local-first usability where appropriate.

The derived index is a search/display artifact, not a person, identity, alias, entity, suspect, volunteer profile, or canonical record.

Name References are supported for MVP only in:

- Incident notes
- Field Report body text and appends

Name References are not supported for MVP in:

- Incident titles
- Field Report titles
- structured incident detail fields
- volunteer profiles
- shift records
- training records
- policy/procedure documents
- general comments outside IMS notes or Field Reports

Name References must not create notifications, mention Meridian users, link to volunteer profiles, create autocomplete suggestions, create context menus, or grant access to related records.

---

## 3.22 IMS Number

An IMS Number is the incident identifier.

The identifier should support four conceptual sections:

- organization
- year
- event
- count

Different views may display a shortened version when context is already known.

Example:

Within an event view, only the final count section may need to be shown.

---

## 3.23 Incident Command Department

The Incident Command Department is the department responsible for incident management for an event.

Organizations define a default Incident Command Department.

Events may override that choice.

Examples:

- Rangers
- Safety
- Operations

By default, only the Incident Command Department has access to incidents and field reports.

Additional visibility requires explicit authorization.

## 3.24 Policy Document

A policy document is a human-readable document that describes organizational, departmental, or team expectations, rules, agreements, or governance practices.

Policy documents may contain normal text and references to reusable fragments.

Policy documents support Markdown content for MVP.

Examples:

- organization code of conduct
- behavioral agreement
- department participation policy
- team mission statement reference
- radio-use policy

Policy documents may be scoped to:

- organization
- department
- team

Organization-level policy documents are visible to everyone in the organization when published, including staff who are not assigned to a current event.

Department-level policy documents are visible to members of that department.

Team-level policy documents are visible to members of that team, and to department leads and team leads within the department.

Department leads and team leads may see all policy/procedure documents within their department according to their leadership scope.

Organizers may see all published policy/procedure documents across the organization, including organization-, department-, and team-scoped documents.

Organizer visibility into published policy/procedure documents is read-oriented governance visibility and does not grant maintenance authority over department- or team-scoped documents.

Draft and archived department- or team-scoped policy/procedure documents remain visible only to maintainers for that scope unless another explicit permission grants access.

Policy/procedure documents should not be generally public before login, except as part of staff signup for an organization.

Policy document states include:

- Draft
- Published
- Archived

Policy documents do not need a separate Active state.

Draft documents are editable but not generally visible as active policy.

Published documents are visible according to their scope.

Archived documents are retained for history but are no longer active.

Archived documents remain available for historical acknowledgment and export review.

Policy documents are a distinct document type from procedure documents because policy and procedure behavior may diverge after MVP.

## 3.25 Procedure Document

A procedure document is a human-readable document that describes how operational work should be performed.

Procedure documents may contain normal text and references to reusable fragments.

Procedure documents support Markdown content for MVP.

Examples:

- logistics check-in procedure
- radio checkout procedure
- field report procedure
- incident escalation procedure
- department opening or closing procedure

Procedure documents share the same states, scoping, fragment behavior, visibility rules, and export behavior as policy documents.

Procedure documents are a distinct document type from policy documents because policy and procedure behavior may diverge after MVP.

## 3.26 Fragment

A fragment is a reusable named group of text that can be referenced inside policy/procedure documents.

Fragments allow common language to be reused without copying and pasting it into many documents.

Fragments support Markdown content for MVP.

Fragments do not contain references to other fragments.

Nested fragments are not supported.

Examples:

- organization-level behavioral agreement
- department-level radio expectations
- team mission statement
- standard safety language
- standard reporting expectations

Fragments may be scoped to:

- organization
- department
- team

Organization-level fragments may be referenced by policy/procedure documents below the organization scope.

Department-level fragments may be referenced by documents within that department and its teams.

Team-level fragments may be referenced by documents for that team.

Fragments do not need Draft, Published, or Archived states for MVP.

Fragments have an auto-incrementing version.

A fragment version increments when the fragment changes.

Fragment references are version-aware.

Policy/procedure documents render the latest fragment text.

When a fragment changes, documents that reference it automatically render the updated fragment text.

Changing a fragment should bump the versions of policy/procedure documents that include it so acknowledgments can record the document version that was acknowledged.

When editing a policy/procedure document, the editor should show the referenced fragment and its version.

When viewing a policy/procedure document, the referenced fragment text should render inline as document text.

## 3.27 Policy/Procedure Acknowledgment

A policy/procedure acknowledgment records that a staff member has acknowledged a policy or procedure document.

Acknowledgments may be required during staff signup or as part of training.

For MVP, acknowledgments are scoped to organization or department requirements.

Policy/procedure acknowledgments should happen higher in the staff lifecycle than shift signup or credential issuance.

Policy/procedure acknowledgments should not be modeled as direct shift-signup gates or credential-eligibility gates in MVP.

Acknowledgments do not need to be re-required automatically when a document or included fragment changes.

Acknowledgment records should store the document and document version that the staff acknowledged.

Acknowledgment records do not need to store a rendered copy of the text the staff saw.

---

## 3.28 Event Map

An event map is an event-scoped map record used to show what is where for an event and to reference operational locations from other workflows.

Events support maps by default. The map feature is enabled by default for new events.

An event may have zero or more maps. An event may have multiple maps of different types, including but not limited to a placement map, a topographic/landscape map, and an operations map if useful later.

Map types include:

- `placement`: a 2D top-down local event/site map showing what is where and how much space it occupies, using a local coordinate plane.
- `topographic`: a real-world landscape/geospatial map for terrain, roads, access routes, and other geography relevant to some events, using real-world coordinates or prepared map packages/assets.

For MVP, a map is created from an uploaded/imported map asset or prepared map package, with lightweight map metadata and camp/location records placed on top of it. Meridian does not provide a full GIS editor, complex drawing suite, automatic geocoding, or a public map builder for MVP.

A map has whole-map lifecycle states:

- Draft
- Published
- Archived

Only the whole map has Draft / Published / Archived state for MVP. Individual camps and map locations do not have separate lifecycle states.

Only Published maps are visible to permitted operational users. Draft and Archived maps are limited to users with map edit/admin permissions.

Placement maps and topographic maps should be linkable/georeference-compatible over time, but georeferencing is not required for MVP.

## 3.29 Camp

A camp is an event-scoped operational/location record representing a named placement on an event map.

A camp is its own event-scoped entity. It is not a generic map feature, not a hierarchy level under organizations or departments, and not a child of the Placement department.

For MVP, a camp record includes only:

- camp name
- location

Location may be represented as a point, a simple footprint/area, local placement-map coordinates, or geospatial coordinates where available. Not every camp is required to have GPS coordinates.

Camp records are designed so future versions can add description, lead/contact information, department or operational affiliation, public/private display flags, and additional placement metadata. Camps do not have notes for MVP.

Camp names are not public to all volunteers by default. Camp visibility follows map permissions and operational role needs.

## 3.30 Map Location / Map Feature

A map location (map feature) is a lightweight event-scoped operational location placed on an event map that is not a camp.

Feature/place types may include:

- `department_hq`
- `gate`
- `road`
- `landmark`
- `deployment_location`
- `service_location`
- `restricted_area`
- `parking`
- `other`

Map locations remain lightweight for MVP. They carry enough structure to display the feature on the map and to be referenced from operational workflows, but they do not form a large GIS subsystem.

Geometry should be capable of representing points, lines, and polygons over time, using GeoJSON-compatible concepts where appropriate while allowing local/non-geographic placement coordinates.

## 3.31 Placement Department

The Placement department is the department designated for an event as responsible for event geography, placement, camp/location records, and published map data used across the system.

The Placement department designation follows the same general pattern as the Incident Command Department designation. It is a normal Meridian department that has been designated for a specific event; it does not create a new organizational hierarchy.

Organizations may define a default Placement department. Events may designate zero or one Placement department, and may override the organization default.

The designated Placement department must be one of the departments assigned to that event.

Designating a department as Placement applies only for the event where it is designated. It does not make that department globally special across all events, and does not automatically make it the Incident Command Department or Organizers Department.

If no Placement department is designated for an event, map editing falls back to organizers/admins/map managers according to documented permissions.

## 3.32 Deployment Target

A deployment target is the fixed runtime packaging channel that delivers Meridian to users.

MVP deployment targets are:

- server-hosted web application;
- Capacitor mobile application;
- Electron desktop/on-site application.

The deployment target selects exactly one UI mode at build/package time.

## 3.33 UI Mode

A UI mode is one of Meridian's three fixed product modes:

- `admin`;
- `field`;
- `kiosk`.

UI mode controls the product shell and workflow framing. It does not replace authentication, permissions, role checks, device trust, event authority, sync scope, or offline support.

## 3.34 Presentation Profile

A presentation profile is a lower-level UI adaptation such as compact, roomy, touch-first, keyboard-first, fullscreen, narrow, wide, table-first, card-first, or reduced-motion.

Presentation profiles may respond to viewport, pointer capability, device hardware, user accessibility preferences, or screen needs. They must not be treated as Meridian UI modes.

## 3.35 Kiosk Context

Kiosk context is the pinned organization, event, and optional department associated with a trusted shared workstation.

A Kiosk context is a session/workstation constraint, not a user permission grant. Authorized organizers, lead organizers, and God Mode users may change a Kiosk context from Kiosk setup/support surfaces.

---

## 3.36 The Briefing

The Briefing is an event-scoped Incident Command hub surface that aggregates Command-relevant operational communication shared across departments.

The Briefing is a hub, not a single parent document. It aggregates:

- Notes that Command has added to the Briefing (by reference or link)
- After Action Reports (AARs)
- Directions
- Action Plans
- Notices

Notes themselves are a **standalone** domain type. The Briefing and AARs do not own Notes; they only reference or link Notes created by individuals.

“Command” in this feature means the event’s Incident Command Department and IC-authorized roles (`ic_lead`, `ic_operator`, and where read-only visibility is required, `ic_viewer`), not merely a team whose display name is “Command.”

The Briefing is distinct from:

- IMS incident notes and field reports
- organization/department/team Policies and Procedures
- staff Event Info placeholders assembled from other documents
- the standalone Notes pool (before Command adds a Note to the Briefing)

---

## 3.37 Note

A Note is a standalone, event-scoped Markdown text block authored by an individual.

Notes may be created by department leads, team leads, and IC operators/leads.

Notes are **immutable after creation**. They cannot be edited or appended after create. Correction requires creating a new Note.

Before Command adds a Note to The Briefing (or an AAR), Notes are readable only by:

- the Note’s author
- Command (IC operators/leads; `ic_viewer` may read for Command visibility where granted)

Ordinary approved event staff who are not the author and not Command cannot read Notes that have not been added to The Briefing.

Command may add a Note to The Briefing and/or to an AAR using one of two inclusion modes:

1. **Reference** — Command writes a separate summary of the Note for Briefing/AAR readers. Readers can open/view the original Note. Credit remains with the original author. Used when Command wants to clarify or restate an idea while still attributing the suggestion to the author.
2. **Link (verbatim)** — Command includes the Note body verbatim in The Briefing/AAR. Credit for that section goes to the original author. The linked content reads as Command communication attributed to that individual (including when the author is themselves a Command member).

The same Note may be added to The Briefing, to an AAR, or to both.

Once Command adds a Note to The Briefing, that Briefing inclusion becomes visible according to its audience mark:

- **Event staff (default)** — all approved event staff for the event
- **Department leads only** — all department leads for the event, plus Command, plus organizers; team leads are excluded unless they are also a department lead, Command, or organizer

The same audience marks apply to Directions, Action Plans (whole plan and/or individual sections), and Notices.

---

## 3.38 After Action Report (AAR)

An After Action Report is a versioned Markdown document used for operational debrief content within The Briefing.

AARs use a fixed ICS section template on every submission:

- Command
- Operations
- Logistics
- Planning
- Admin

There are two AAR kinds:

1. **Submission AAR** — created by a department lead or team lead for their department or team scope. Leads may edit, submit, and resubmit during the event and for **30 days after event end**.
2. **Final AAR** — the event’s compiled Command after-action document. IC may publish a Final AAR after event end. If none is published by **45 days after event end**, Meridian auto-assembles a Final AAR from the latest submitted version of each Submission AAR and freezes it.

AAR documents follow the same general versioning posture as Policies/Procedures (document revisions). Notes included in an AAR use the same reference or link (verbatim) inclusion modes as The Briefing. Because Notes are immutable, Note mutation cannot change included content after the fact.

Visibility:

- A submitter may read their own Submission AARs.
- IC and organizers may read all Submission AARs for the event.
- Peer department/team leads may not read other leads’ Submission AARs until the Final AAR is published.
- After the Final AAR is published (or auto-assembled and frozen), it is readable by all approved event staff.

---

## 3.39 Direction

A Direction is an IC-authored Markdown instruction block in The Briefing.

Directions may deep-link to Meridian entities (for example departments, teams, shifts, maps, camps/locations, policies, procedures, deployments, and other Briefing items).

Directions may target one or more departments or teams. Organization-wide Directions are visible to all approved event staff. Targeted Directions are visible to members of the targeted scopes and to IC/organizers.

Directions may also be marked **department leads only**. When so marked, they are visible only to department leads for the event, Command, and organizers (team leads excluded unless they also hold one of those roles).

Directions live in The Briefing hub. They do not surface as page banners.

---

## 3.40 Action Plan

An Action Plan is an IC-authored event operational plan in The Briefing.

An Action Plan may contain sections scoped to a department or team. All approved event staff may read the Action Plan when it is marked for event staff; targeted sections are emphasized for members of those scopes.

The Action Plan as a whole, and/or individual sections, may be marked **department leads only**. When so marked, that plan or section is visible only to department leads for the event, Command, and organizers (team leads excluded unless they also hold one of those roles).

During the active event window, targeted Action Plan sections may surface as **banners** on a fixed allowlist of product surfaces/pages (not free-form URL targeting). Department-leads-only sections banner only to users who can see them.

Publishing or updating an Action Plan may spawn Notices.

---

## 3.41 Notice

A Notice is a short, time-sensitive Briefing item used to disseminate emergency information or process updates.

Notices may be created manually by IC, or auto-created from Action Plan publish/update events.

Notices appear in The Briefing hub and as dismissible per-user alerts. Notices may be event-wide or targeted to departments/teams, and may carry an optional expiry.

Notices may also be marked **department leads only**. When so marked, they are visible only to department leads for the event, Command, and organizers (team leads excluded unless they also hold one of those roles).

---

## 3.42 Branding Profile

A branding profile is the stored set of identity and color values that determine how Meridian presents itself to a given organization or department.

An organization branding profile carries the organization display name, logo assets, and the organization color palette. It replaces Meridian's own name and mark on signed-in product surfaces, so staff experience the product as their organization's system rather than as Meridian.

A department branding profile is deliberately narrower. It carries a department logo, one department accent color, and one department surface background color. It identifies department context inside the organization's palette; it does not redefine that palette.

Branding profiles never carry meaning. Status, severity, priority, and restriction are communicated through canonical labels, iconography, and structure, and remain readable regardless of which branding profile is active.

Branding is organization governance data, not event-scoped operational data.

---

## 3.43 Insight Metric

An Insight Metric is a reusable, system-defined unit of compiled operational information.

A metric may carry a value or set of values, a state describing whether expectations are being met, a visualization or structured presentation, a link to the operational surface where an authorized user can investigate or act, fixed evaluation rules, and configuration supplied where the metric is placed.

Metric types are defined by developers and registered as data. Organizations do not author metric logic.

A metric compiles current authorized data. It is not a stored result.

---

## 3.44 Insight Sheet

An Insight Sheet is an organization-owned, configurable page containing any number of Insight Metrics.

A sheet may combine metrics from different operational domains, and a metric may appear on more than one sheet. Each placement of a metric on a sheet carries its own configuration.

A sheet reads one event at a time. It renders organization-wide or department-scoped data according to the viewer's authorization and selected department context, so the same sheet shows different data to different people.

Filters are configured at the sheet level and apply to the whole sheet.

An Insight Sheet is not a Report. Reports are fixed, formal, or historical outputs; Insight Sheets are live compiled views. A PDF taken from a sheet is a snapshot of what the viewer was looking at and does not make the sheet a Report.

---

# 4. User Roles

## 4.1 Staff

A staff member participates in events and departments.

Staff may:

- apply to events
- complete waivers
- complete trainings
- join departments
- join teams
- sign up for eligible shifts
- check in/out through authorized leads
- submit field reports if authorized
- view their own submitted field reports
- earn hours and credits
- view published policy/procedure documents visible to them
- acknowledge required policy/procedure documents during signup or training when required

Staff do not self-report hours in MVP.

---

## 4.2 Organizer

An organizer manages organization-level governance.

Organizer authority comes through the organization’s configured Organizers Department.

Organizers may:

- manage organization settings
- approve event applications
- reject event applications
- defer event applications
- rescind approved applications before team assignment
- manage organization-level staff status
- change DNS status
- configure organization-level policies
- create departments
- manage organization-level taxonomies
- export organization/event-wide reports, excluding emergency contacts
- revoke credentials
- manage organization default credit policy
- maintain organization-scoped policy/procedure documents
- maintain organization-scoped fragments
- publish organization-scoped policy/procedure documents and fragments
- view all published policy/procedure documents in the organization

Organizers do not have default access to emergency contacts.

Organizers do not have default access to all incidents or all field reports.

Organizers do not directly add staff to department event participation.

Organizers cannot change department-scoped or team-scoped policy/procedure documents by default.

Department leads manage their own staff.

---

## 4.3 Lead Organizer

A Lead Organizer is an organizer with authority over organizer membership.

Lead Organizer authority should come through a role or grant within the configured Organizers Department.

There may be multiple Lead Organizers.

An organization may grant Lead Organizer authority to any subset of the Organizers Department, including all members of that department.

Only Lead Organizers may remove organizers.

The final Lead Organizer cannot be removed.

Organizer removals must be audited.

Additional anti-malicious-removal safeguards are out of scope for MVP.

---

## 4.4 Staff Coordinator

A Staff Coordinator manages staff applications and approval workflows.

Staff Coordinator responsibilities may include:

- reviewing submitted applications
- approving applications
- rejecting applications
- deferring applications
- coordinating applicant intake
- supporting department assignment after approval

Staff Coordinators operate at the organization level.

---

## 4.5 Department Lead

A Department Lead manages a department’s staff and operations.

Department Leads may:

- manage department staff status
- assign staff to the department
- assign staff to teams
- reject approved applicants for their department
- manage department trainings
- manage shifts
- remove staff from shifts
- correct hours during the grace period
- export department-scoped reports
- view/export emergency contacts for staff in their department
- check equipment in/out
- manage department event participation
- maintain department-scoped policy/procedure documents
- maintain department-scoped fragments
- publish department-scoped policy/procedure documents and fragments
- view policy/procedure documents within their department
- create immutable Notes for events their department participates in
- create, edit, submit, and resubmit department-scoped After Action Report submissions for those events within the AAR window

Department Leads cannot override organization-level blocking status.

Department Leads cannot access field reports or incidents unless they are part of the Incident Command Department or explicitly authorized.

Department Leads cannot read peer Notes they did not author unless they have Command authority. Department Leads cannot read peer department or team Submission AARs until the event Final AAR is published.

---

## 4.6 Team Member

A Team Member is a staff member assigned to a team within a department.

Team membership may grant:

- shift eligibility
- system authority
- operational identity
- required training obligations
- leadership scope

Team membership replaces the earlier concept of role assignment.

---

## 4.7 Team Lead

A Team Lead is a staff member with leadership responsibility for a team within a department.

Team Leads may:

- view policies/procedures within their department
- maintain team-scoped policy/procedure documents for their team
- maintain team-scoped fragments for their team
- help manage team-specific policy/procedure content
- publish team-scoped policy/procedure documents and fragments
- create immutable Notes for events their department participates in
- create, edit, submit, and resubmit team-scoped After Action Report submissions for those events within the AAR window

Team Lead authority does not override Department Lead authority.

Team Leads cannot read peer Notes they did not author unless they have Command authority. Team Leads cannot read peer department or team Submission AARs until the event Final AAR is published.

---

## 4.8 Shift Lead

A Shift Lead is an operational identity for a team or shift.

Shift Lead status does not by itself grant every live-operation permission.
Alpha 1 live-operation permissions come from department-scoped team grants.

---

## 4.8A Department Operational Roles

Department operational roles are permission grants assigned through teams within
a department. Team names remain arbitrary.

Department Logistics may:

- mark eligible department staff on-site/off-site
- view current shift assignments
- check staff in
- check staff out
- edit actual start/end time during check-out
- correct hours during the grace period
- view checked-in staff
- view equipment checked out
- check equipment in/out to individuals
- add eligible unscheduled staff to a shift
- access field report shortcut
- access incident shortcut

Department Logistics may not add staff to a department/team. If a staff member
is not already in the relevant department/team, Department Administration or a
Department Lead must add them first.

Department Operations may:

- view current shift assignments
- assign deployment/location
- move staff between deployments/locations
- access field report shortcut
- access incident shortcut

Department Planning may:

- view shift schedule
- view shift signups
- view team members

Department Administration may:

- manage department team membership and department settings as permitted
- view department logistics, operations, and planning settings

Department Operator is the department's dispatch and console function. Department Operator may:

- create Field Reports on behalf of another staff member of the department, recording the reporting staff member as the author and the Operator as the submitter
- access the field report shortcut
- access the incident shortcut

Department Operator is the role a person holds while sitting at a radio, taking a report from someone in the field who cannot file it themselves. Further dispatch duties may attach to this role later; the capabilities above are what MVP defines.

Where the department carrying the Operator designation is the event's Incident Command Department, Department Operator additionally carries the event-scoped `ic_operator` capabilities defined in the technical specification section 16.2, including incident create and edit. That authority is event-scoped and applies only for events where that department is the designated Incident Command Department.

---

## 4.9 Trainer

A Trainer is an authorized staff who records training completion.

Trainers may:

- run trainings
- mark staff as passed/completed
- import training completion by spreadsheet, if supported

Trainer authority may come through team membership or department assignment.

---

## 4.10 Incident Command Department Lead

An Incident Command Department Lead manages incident operations for an event.

IC Department Leads may:

- view field reports
- view incidents
- create incidents
- update incidents
- attach field reports to incidents
- remove field reports from incidents by striking the relationship
- add incident notes
- print incidents to PDF
- revoke credentials
- manage incident state
- manage incident attachments as stricken when needed
- manage The Briefing for the event, including adding Notes to The Briefing or AARs by reference or link, Directions, Action Plans, Notices, and Final AAR compile/publish
- create Notes and read Notes authored by others for the event (Command visibility)

IC Department Leads are the only users who may print incident PDFs.

---

## 4.11 Placement Department Lead

A Placement Department Lead is the Department Lead of the department designated as the event's Placement department.

A Placement Department Lead is the default operational owner of event maps, camp records, placement locations, and geography data before the event operations window begins.

For the event where their department is designated as Placement, Placement Department Leads may:

- create/edit event maps before the operations window begins
- create/edit camp records (name and location) before the operations window begins
- create/edit map locations/features before the operations window begins
- manage map layers and map assets/packages before the operations window begins
- publish and archive event maps
- view published, draft, and archived maps for that event

Placement Department Leads do not gain Incident Command or Organizer authority through the Placement designation.

Placement department authority applies only for the event where the department is designated as Placement.

Placement department members who are not leads may receive view access by default and may receive edit access only when granted a map-management role/grant within the Placement department, following Meridian's existing team-grant model.

Locked-map data overrides after the operations window begins are reserved for organizers/admins and are not part of the Placement Department Lead role.

---

# 5. Primary Workflows

## 5.1 Organization Setup

1. Organization is created.
2. Organization creator becomes initial organizer / Lead Organizer.
3. Organization defines default settings.
4. Organization creates departments.
5. Each department receives a default team.
6. Departments may rename their default teams.
7. Organization defines event(s).
8. Organization opens applications for an event.

---

## 5.2 Event Application and Approval

1. Applicant submits an event application.
2. Application collects legal name and email unless the applicant already has a login.
3. Application may optionally collect department interest: zero or more unordered, non-binding signals for eligible event-participating departments. Omitted or empty selection means no preference. If no eligible participating departments exist for the event, the department interest field is hidden and submission proceeds normally.
4. Application may also collect required profile/contact fields.
5. If applicant email matches DNS, application is auto-rejected without automatic notice.
6. Staff Coordinator or Organizer reviews application.
7. Application becomes one of:
   - Approved
   - Rejected
   - Deferred
   - Withdrawn
   - Auto-rejected due to DNS
8. If approved, applicant becomes Prospective at the organization level.
9. Approved applicant may be assigned to departments.
10. Department leads decide whether to accept the approved applicant into their department.
11. Department/team/training process determines when the staff member becomes Active.

Department interest recorded at submission is visible during review but does not affect approval, routing, notifications, assignment, membership, access, or applicant status.

---

## 5.3 Department Onboarding

1. Approved staff enters Prospective status.
2. Department Lead assigns staff to a department.
3. Staff must be assigned to at least one team.
4. Department/team requirements are evaluated.
5. Required trainings are assigned or completed.
6. Required waivers are completed where applicable.
7. If the department has required trainings, the staff member becomes Active after completing them.
8. If the department has no required trainings, the staff member becomes Active after department assignment.
9. Staff may then sign up for eligible shifts.

---

## 5.4 Team Assignment

1. Department Lead assigns staff to one or more teams.
2. Team membership grants shift eligibility and any associated system authority.
3. Team membership may impose training or waiver requirements.
4. Archived teams remain visible for historical records.
5. Historical worked shifts preserve the team/function name at the time the work happened.

---

## 5.5 Shift Signup

1. Staff views available shifts.
2. System checks:
   - organization status
   - department status
   - team membership
   - required trainings
   - required waivers
   - age restrictions
   - capacity
   - signup window
   - schedule locks
3. Eligible staff signs up.
4. Signup is immediate.
5. If shift is full, signup is unavailable.
6. Staff may change schedule before cutoff.
7. Lead may remove staff from shift if necessary.
8. Overlapping shifts produce warnings, not default hard blocks.
9. Elevated lead permissions may allow overlap assignment.

---

## 5.6 Credential Eligibility

1. Staff signs up for at least one shift.
2. System checks credential requirements:
   - at least one signed-up shift
   - required waivers complete
   - age requirements satisfied
   - no organization-level blocking status
   - no department-level Ineligible status
3. If requirements are satisfied, the staff member becomes credential-eligible.
4. If requirements later fail, credential becomes Blocked.
5. If organizers or IC department leads manually revoke, credential becomes Revoked.
6. If credential is revoked, future shifts are removed where possible.
7. Completed shifts and recorded hours remain preserved.

For MVP, Meridian provides credential eligibility reporting, not physical credential issuance.

---

## 5.7 Shift Operations

1. A staff member arrives for the event or department work area.
2. Department Logistics opens the Logistics Desk.
3. Logistics verifies the staff member and marks them on-site for the department.
4. Logistics confirms the staff member's schedule/signups.
5. Logistics issues event equipment if needed.
6. Logistics checks the staff member into a scheduled shift, or adds an on-site eligible unscheduled staff member to the shift.
7. Department Operations opens the Operations Center.
8. Operations assigns or confirms the staff member's deployment/location.
9. Shift work occurs.
10. Staff may move between deployments/locations.
11. Operations updates current deployment/location.
12. Logistics checks staff out.
13. Check-out creates actual hours record.
14. Logistics may edit actual start/end time during check-out.
15. Equipment is checked back in, returned, or marked Missing/Damaged.
16. Logistics marks the staff member off-site only after open shift and equipment issues are resolved.
17. Hours may be corrected during the grace period.
18. Credits are calculated after the grace period.

---

## 5.8 Unscheduled Shift Work

1. A staff member shows up or is needed for a shift without prior signup.
2. Department Logistics attempts to add staff to shift.
3. System checks:
   - staff member is marked on-site for the department
   - staff member belongs to relevant department/team
   - required training complete
   - required waiver complete
   - status allows participation
4. If eligible, the staff member is added to shift.
5. The staff member may check in/out and earn hours.
6. Unscheduled work does not retroactively grant credential eligibility.

If the staff member is not in the relevant department/team, Department Administration or a Department Lead must add them first.

---

## 5.9 Hours and Credits

1. Department Logistics checks staff out.
2. Check-out creates hours record using actual start/end time.
3. Authorized attendance managers may correct hours during grace period.
4. Grace period closes.
5. Hours freeze.
6. Credits are calculated from finalized hours.
7. Shift-specific credit policy is used where present.
8. Organization default credit policy is used as fallback.
9. Credits freeze after calculation.
10. Credits are exported for reporting.

---

## 5.10 Field Report Creation

1. An authorized staff member creates a field report.
2. Field report records event, author, title, and report text.
3. Name References in report body text are parsed after submission as a derived search/display artifact. Titles are not parsed for Name References.
4. Field report is visible to author and IC department.
5. Field report may exist independently.
6. Author may append additional entries.
7. Field report cannot be edited or stricken.
8. IC department may attach field report to one or more incidents.

---

## 5.11 Incident Management

1. IC department creates incident.
2. Incident receives IMS number.
3. Incident state, summary, type, location, tags, Name References, involved staff, notes, and attachments are managed.
4. IC department attaches field reports where relevant.
5. Field report content is copied into incident notes.
6. Later field report additions are copied into associated incidents.
7. Incident notes are added over time.
8. Incident fields may be updated.
9. Incident state may change.
10. Incident may be closed, reopened, placed on hold, or otherwise filtered by state.
11. Incident is not merged or destroyed.
12. Attachments or relationships may be stricken.
13. IC leads may print incident to PDF.

---

## 5.12 Reporting / Spreadsheet Exports

MVP exports include:

- credential eligibility
- shift rosters
- staff contact lists
- hours worked
- credits earned

Organizers may export organization/event-wide reports, excluding emergency contacts.

Department leads may export department-scoped reports.

Shift roster exports exclude phone numbers and emergency contacts.

Department staff contact exports may include phone numbers and emergency contacts.

Organizer exports do not include emergency contacts.

Not required for October MVP:

- waiver completion export
- provision eligibility export
- incident spreadsheet export

---

## 5.13 Policy/Procedure Authoring

1. Authorized organizer, department lead, or team lead creates a policy/procedure document.
2. Author assigns the document to organization, department, or team scope.
3. Author writes normal Markdown document text.
4. Author references reusable fragments where appropriate.
5. During editing, fragment references show the referenced fragment version.
6. Document remains Draft until published.
7. Published document becomes visible according to its scope.
8. Archived document remains retained for history but is no longer active.
9. Organization-scoped documents are published by organizers.
10. Department-scoped documents are published by department leads.
11. Team-scoped documents are published by team leads.
12. Organizers cannot change department-scoped documents by default.

## 5.14 Fragment Reuse

1. Authorized maintainer creates a reusable Markdown fragment.
2. Fragment is scoped to organization, department, or team.
3. Policy/procedure authors reference the fragment from documents within the allowed scope.
4. When editing the document, the reference and fragment version are visible.
5. When viewing the document, the latest fragment text appears inline as normal text.
6. When a fragment changes, its version increments automatically.
7. When a fragment changes, documents referencing it automatically use the latest fragment text.
8. When a fragment changes, referencing document versions are bumped.
9. Nested fragments are not supported.

## 5.15 Policy/Procedure Acknowledgment

1. Organization or department defines a policy/procedure acknowledgment requirement for MVP.
2. Staff encounters the required acknowledgment during staff signup or training.
3. Staff acknowledges the document.
4. Meridian records the acknowledgment.
5. The acknowledgment record stores the document and version acknowledged.
6. Meridian does not need to store the full rendered text the staff saw.
7. Acknowledgment is not modeled as a direct shift signup gate or credential eligibility gate in MVP.
8. Acknowledgment is not required anywhere outside signup or training for MVP.
9. Acknowledgment does not need to be automatically re-required when a document or included fragment changes.

## 5.16 Policy/Procedure Export

1. Authorized user selects one or more visible policy/procedure documents.
2. Meridian exports or prints the documents as PDF or Markdown.
3. A department may manually assemble and export a policy/procedure packet containing multiple documents.
4. Rendered exports show fragment text inline.
5. Policy/procedure packet exports include document contents only.
6. Policy/procedure packet exports do not include staff acknowledgment status.

## 5.17 Event Map and Placement Setup

1. An organization may define a default Placement department.
2. An event may designate zero or one Placement department from the departments assigned to the event, overriding the organization default where set.
3. Meridian validates that the designated Placement department is assigned to the event.
4. Before the operations window begins, an authorized map editor (Placement department lead, organizer/admin, or granted map manager) uploads/imports a map asset or prepared map package and creates an event map with lightweight metadata.
5. The editor creates camp records with a name and a location on the map.
6. The editor creates map locations/features for operational places such as department HQs, gates, and deployment locations where useful.
7. The editor publishes the map. Only published maps become visible to permitted operational users.
8. When the event enters its operations window, published map geometry, camp records, and map location records are locked against normal editing.
9. After the operations window begins, corrections to locked map data require an organizer/admin override with an explicit reason or a post-event update path.
10. Permitted devices receive the published event map package and permitted camp/location records offline by default.

---

## 5.18 The Briefing

1. Staff with event access open The Briefing hub for the event.
2. Department leads, team leads, and IC create immutable Notes as needed. Before Briefing inclusion, Notes are visible only to the author and Command.
3. Command adds selected Notes to The Briefing by **reference** (Command summary + view original, credited to author) or **link** (verbatim body, credited to author as Command-attributed content). Command may mark the inclusion **event staff** or **department leads only**.
4. Command may also add Notes to AARs using the same reference or link modes.
5. Department leads and team leads author Submission AARs with fixed ICS sections, submit them, and may resubmit within 30 days after event end.
6. IC authors Directions and the Action Plan (with optional department-leads-only marks on Directions, Action Plan, and/or sections); targeted Action Plan sections may banner on allowlisted surfaces during the active event window.
7. IC creates Notices (optionally department-leads-only), or Action Plan updates spawn Notices; staff dismiss Notices per user when visible to them.
8. IC publishes a Final AAR after event end, or the system auto-assembles and freezes a Final AAR at day 45 from latest submitted Submission AARs.
9. Approved event staff read published Final AAR and other Briefing items according to visibility rules.

Alpha 1 implements Notes create/list/detail with author+Command visibility, Command add-to-Briefing (reference or link) with event-staff or department-leads-only audience so added Notes appear in the hub for permitted viewers, and hub shells for AAR, Directions, Action Plan, and Notices. Full AAR submit/compile, Directions linking, Action Plan banners, and Notices alerts are post–Alpha 1.

---

# 6. MVP Scope

## 6.1 MVP Target

The MVP is targeted for an October event.

The MVP should provide enough functionality to support real event operations while relying on generic admin screens or manual processes where acceptable.

The MVP should prioritize:

- staff application intake
- staff approval
- department/team assignment
- shift eligibility
- shift signup
- credential eligibility reporting
- Department Overview, Logistics Desk, Operations Center, and Planning Table
- field report creation
- incident list/editor
- hours recording
- post-event credit calculation
- operational spreadsheet exports
- The Briefing hub (Alpha 1: Notes plus shells; full Briefing types post–Alpha 1)

---

## 6.2 MVP Screens

Priority operational screens:

1. Staff Application
2. Staff Coordination
3. Department Overview / Logistics Desk / Operations Center / Planning Table
4. Incident List / Incident Editor
5. Field Report Creation
6. The Briefing hub

Generic admin CRUD screens may exist to support missing workflows during MVP development.

---

## 6.3 MVP In Scope

### Staff Intake

- event applications
- DNS auto-rejection by email
- application statuses
- applicant withdrawal
- organization approval
- rescission before team assignment
- Prospective staff member creation

### Staff Profile

- legal name
- email
- preferred name
- handle
- phone
- emergency contact
- city/state
- age/date of birth

### Status

- organization status
- department status
- DNS
- Ineligible
- status reasons/audit
- organizer control of organization status
- department lead control of department status

### Departments and Teams

- departments
- default team per department
- team assignment
- teams replacing roles
- archived teams preserved in history
- no department membership without team membership

### Training and Waivers

- required trainings for eligibility
- required waivers for eligibility
- training completion
- training expiration
- waiver expiration
- no training waivers
- no signed document storage

### Policies and Procedures

- policy/procedure documents
- separate policy and procedure document types
- Markdown-only document content for MVP
- document states: Draft, Published, Archived
- no separate Active document state
- organization, department, and team scope
- organization and department acknowledgment scope for MVP
- reusable Markdown text fragments
- no nested fragments
- fragment version auto-increment on change
- latest fragment text used when rendering documents
- referencing document version bumped when included fragments change
- fragment references visible while editing
- fragment text rendered inline while viewing
- policy/procedure acknowledgment during staff signup or training
- acknowledgment records store document and version only
- PDF export/print
- Markdown export
- manually assembled policy/procedure packets
- policy/procedure search for staff

### Shifts

- shift creation/configuration
- team-based eligibility
- required trainings
- required waivers
- capacity
- signup windows
- schedule lock/cutoff
- shift-specific credit policy
- organization default credit fallback
- overlap warnings
- lead removal from shifts

### Credential Eligibility

- one event credential per staff member per event
- Eligible / Blocked / Revoked states
- automatic eligibility determination
- blocked when requirements fail
- revoked by organizers or IC department leads
- future shifts removed where possible after revocation
- reporting/export

### Department Operations Surfaces

- Department Overview for lead situational awareness on a selected shift
- Logistics Desk staff-first search and staff workspace
- department on-site/off-site status
- check-in / check-out with editable default-now timestamps
- actual start/end time
- hour creation
- hour correction during grace period
- add eligible unscheduled staff from the staff workspace
- Operations Center capability-composed modules
- current deployment/location assignment
- move staff between locations
- equipment checked out summaries and staff-workspace handoff
- equipment checkout/check-in to individuals
- identity-free Planning Table aggregates
- field report shortcut or module only where already permitted
- incident overview module only with Incident Command capability

### Equipment

- visible/manual tracking
- individual checkout/check-in
- states:
  - Available
  - Checked out
  - Returned
  - Missing
  - Damaged

### Field Reports

- author-created reports
- required immutable title
- author can view own reports
- author can append
- IC department visibility
- not editable
- not stricken
- attach to incidents
- copied into incident notes as `Field Report: <title>` followed by body
- Name References parsed from body text and appends after submission

### Incidents

- incident list
- incident editor
- configurable states/types/tags
- Name Reference chips derived from incident notes and attached field reports
- IMS number
- incident notes/history
- linked incidents
- field report attachment
- attachments stricken only
- PDF print by IC leads
- no incident spreadsheet export

### Hours

- actual start/end time
- check-out creates hours
- hours tied to shift and department
- no free-floating hours
- corrections during grace period
- freeze after grace period

### Credits

- calculated after grace period
- shift-specific policy first
- organization default fallback
- frozen after calculation
- credits earned export
- calculation basis included in export

### Event Maps and Geography

- event maps enabled by default
- multiple maps per event (placement, topographic, operations if useful later)
- `placement` and `topographic` map types
- whole-map Draft / Published / Archived lifecycle
- uploaded/imported map asset or prepared map package
- lightweight map metadata
- camps as event-scoped entities with name and location only
- lightweight map locations/features for operational places
- point/line/polygon-capable geometry, GeoJSON-compatible where appropriate, plus local placement coordinates
- event-level Placement department designation with organization default
- map view/edit permissions through organizers/admins and the designated Placement department
- camp names not public to all volunteers
- operations-window locking of published map geometry and camp/location records
- organizer/admin locked-map override with explicit reason
- optional IMS incident reference to a camp/map location
- kiosk dashboard map by default when published and permitted
- offline map package/data sync to permitted devices by default

### Reports

- credential eligibility export
- shift roster export
- staff contact list export
- hours worked export
- credits earned export

### The Briefing

Full product design (MVP target; Alpha 1 implements the thin slice noted below):

- event-scoped Briefing hub aggregating Command-added Notes, AARs, Directions, Action Plans, and Notices
- standalone immutable Notes created by department leads, team leads, and IC
- Notes readable only by author + Command until added to The Briefing
- Command adds Notes to The Briefing and/or AARs by **reference** (summary + view original, credited to author) or **link** (verbatim, credited to author as Command-attributed content)
- Briefing Note inclusions, Directions, Action Plans (whole and/or sections), and Notices may be marked **department leads only** (visible to department leads + Command + organizers; team leads excluded)
- Notes added to The Briefing become visible per their audience mark (event staff or department leads only)
- Submission AARs by department leads and team leads with fixed ICS sections (Command, Operations, Logistics, Planning, Admin)
- submit/resubmit window through 30 days after event end
- Final AAR IC publish or auto-assemble/freeze at day 45
- Directions with deep links and optional dept/team targeting (no page banners)
- Action Plan with dept/team sections and allowlisted page banners during active event
- Notices (manual and Action-Plan-spawned), dismissible per user

Alpha 1 Briefing slice:

- Notes create/list/detail with immutability and author+Command visibility
- Briefing hub with Command add-to-Briefing (reference or link, with event-staff or department-leads-only audience) so added Notes appear for permitted viewers
- empty shell / placeholder sections for AAR, Directions, Action Plan, and Notices
- Orchid/admin and permission scaffolding for Notes
- no full AAR submit/compile, Directions deep-link authoring, Action Plan banners, or Notices alerts in Alpha 1

Organization and department branding:

- organization branding profile with display name, full logo lockup, compact mark, platform palette, and neutral palette
- organization identity replacing the Meridian name and mark on signed-in product surfaces, document titles, PDF exports, and system email
- Meridian identity retained on login, node first-run setup, Orchid, and desktop chrome
- department branding profile with logo, accent color, and surface background color
- generated lettermark fallback for organizations and departments without a logo
- server-side WCAG 2.1 AA contrast validation that rejects failing combinations rather than repairing them
- organization-level switch to disable department branding overrides
- branding sync to on-site nodes and permitted offline devices

God Mode console:

- Meridian orientation landing screen replacing the framework welcome content
- attention list covering configuration readiness, organizational data gaps, and unresolved sync conflicts
- in-console Documentation page served from a packaged `docs/technician/` tree
- in-console Changelog page grouped by Meridian version, generated at build time and refreshed from the source repository only by the central node
- removal of framework documentation links, framework changelog links, and framework version display
- Meridian logo, compact mark, and favicon throughout the console and its authentication surfaces
- console chrome resolved from the shared Meridian design tokens instead of framework defaults
- footer stating the Meridian license, a 2026-to-present copyright range, and the Meridian build version

---

## 6.4 MVP Out of Scope

The following are not required for October MVP:

- full equipment inventory/custody chains
- department-to-department equipment allotments
- provision inventory
- provision eligibility export
- waiver completion export
- incident spreadsheet export
- full credential/provision issuance workflow
- storing signed waiver documents
- field report visibility outside IC department and author
- full organizer removal safeguards beyond Lead Organizer + audit
- payroll
- HR/personnel notes
- staff self-reported hours
- free-floating permissions outside org/department/team membership
- free-floating hours outside shifts
- deployment movement history
- training waiver/equivalency modeling
- general public policy/procedure browsing outside staff signup
- policy/procedure acknowledgments as direct shift signup gates
- policy/procedure acknowledgments as direct credential eligibility gates
- policy/procedure acknowledgments outside signup or training for MVP
- Name Reference autocomplete, notifications, alias merging, profile/detail pages, volunteer profile links, user mentions, canonical person/entity records, and management screens
- team-scoped policy/procedure acknowledgments for MVP
- nested fragments
- rich policy/procedure formatting beyond Markdown for MVP
- automatic policy/procedure packet assembly
- policy/procedure packet exports that include staff acknowledgment status
- full GIS editor, complex drawing suite, automatic geocoding, or public map builder
- hybrid map type beyond linkable placement/topographic maps
- arbitrary dropped pins on maps
- camps/map places in the global command palette
- structured map/location fields on Field Reports
- volunteer-submitted map corrections
- live volunteer GPS tracking, turn-by-turn routing, or real-time moving personnel icons
- per-camp lifecycle states separate from the whole map
- camp notes, descriptions, contacts, or affiliation fields for MVP
- multiple Placement departments per event
- georeferencing requirement for placement maps
- free-form Action Plan banner targeting outside the fixed surface allowlist
- editable or appendable Notes after creation
- broad event-staff visibility of Notes that Command has not added to The Briefing
- peer visibility of Submission AARs before Final AAR publication

---

# 7. Requirements

## 7.1 Organization Requirements

### ORG-001

Meridian shall support organizations that produce events and manage staff.

### ORG-002

Organizations shall define departments.

### ORG-003

Organizations shall define organization-level staff statuses.

### ORG-004

Organization-level status shall supersede department-level status.

### ORG-005

Organizations shall define a default Incident Command Department.

### ORG-006

Events shall be able to override the default Incident Command Department.

### ORG-007

Organizations shall define an Organizers Department.

### ORG-008

The Organizers Department shall be persistent at the organization level and shall not vary per event.

### ORG-009

Organizations shall define an organization default credit policy.

### ORG-010

Meridian shall not support department default credit policies.

### ORG-011

Only organizers shall change organization-level staff status.

### ORG-012

Organizer removals shall be audited.

### ORG-013

Only Lead Organizers shall remove organizers.

### ORG-014

The final Lead Organizer shall not be removable.

### ORG-015

Membership in the Organizers Department shall not grant default access to all incidents or all field reports.

### ORG-016

Organizers shall be able to view all published policy/procedure documents in the organization.

### ORG-017

Organizations shall define an hours correction grace period, expressed in days after event end, during which authorized attendance managers may correct hours (HOURS-007) and after which hours freeze (HOURS-008).

The grace period shall default to 14 days after event end. Credits shall not be calculated for an event before its grace period closes (CREDIT-001).

### ORG-018

Organizations shall provide a configuration surface in Meridian Admin covering the organization values that govern staff lifecycle and operational timing, including the Prospective and Active inactivity thresholds, the hours correction grace period, the calendar year start, the default credit policy, and the Organizers, default Incident Command, and default Placement department designations.

Organization configuration shall not be reachable only through God Mode.

### ORG-019

Meridian shall evaluate the organization staff lifecycle thresholds on a schedule and apply the resulting status transitions, so that Prospective staff become Inactive after the configured Prospective threshold (STAT-011) and Active staff become Inactive after the configured Active threshold without a person performing the transition.

Evaluation shall be idempotent, shall respect STAT-009, and shall write each transition through the audited status path.

### ORG-020

Only organizers and Lead Organizers shall edit organization configuration. Configuration changes shall be audited.

### ORG-021

Organization configuration shall be organization governance data. The central node shall be authoritative for it, and configuration edits shall be blocked during the active event window under the same governance edit rules that apply to policy and procedure documents (BRAND-021).

---

## 7.2 Staff Requirements

### VOL-001

Meridian shall treat all operational users as staff.

### VOL-002

Staff shall have organization-level status.

### VOL-003

Staff shall have department-level status per department.

### VOL-004

A staff member may belong to multiple departments.

### VOL-005

A staff member may belong to multiple teams.

### VOL-006

A staff member shall not belong to a department without belonging to at least one team.

### VOL-007

Meridian shall preserve staff history across events.

### VOL-008

Meridian shall require legal name and email for applicants unless they already have a login.

### VOL-009

Staff profiles shall include legal name, email, preferred name, handle, phone, emergency contact, city/state, and age/date of birth, though only legal name and email are required when a staff record is first created.

### VOL-010

Handle shall refer to the staff member's radio/operational handle.

### VOL-011

Organizers shall not have default access to emergency contacts.

### VOL-012

Department leads shall have access to emergency contacts for staff in their department.

### VOL-013

Active staff may upload, replace, and remove one current picture on their own staff profile.

Staff profile pictures shall be visible only to users who can already view that staff profile.

### VOL-014

Meridian shall provide a staff profile surface on which a staff member maintains their own profile fields (VOL-009) and their profile picture (VOL-013).

Profile picture upload, replace, and remove shall be online-only. The surface shall be available in every UI mode where the authenticated user can reach their own profile.

### VOL-015

A staff member shall change their own preferred name, phone number, and city/state from their staff profile surface, taking effect immediately and without review.

### VOL-016

Legal name, email address, and date of birth shall not be self-editable from the staff profile surface. Each is identity or eligibility data rather than presentation: legal name and email identify the person to the organization and to authentication, and date of birth governs event age eligibility. Changing any of them shall remain an assisted path through an organizer or God mode.

### VOL-017

A staff member shall change their own handle without review twice. Setting a handle where the staff record holds none is not a change and shall not count against that allowance.

Every later handle change shall be submitted as a handle change request and shall take effect only on approval.

### VOL-018

Only an applied handle change shall count against the allowance in VOL-017. A request that is rejected or withdrawn, and a self-service change that fails validation, shall leave the allowance as it was.

### VOL-019

A handle change request shall be reviewed by an organizer or a Staff Coordinator of an organization the staff member holds a status with. Approval shall apply the requested handle; rejection shall leave the handle unchanged. Approval and rejection shall be audited with the reviewer, the decision, and the previous and requested handle.

### VOL-020

A handle change request under review shall name to the reviewer any other staff member with active status in the same organization already using the requested handle, so a handle collision is a decision a reviewer makes rather than one Meridian makes silently.

### VOL-021

Submitting a profile picture shall create a profile picture change request. The staff member's current picture shall remain the visible picture until the request is approved.

A submitted picture awaiting review shall be visible only to the staff member who submitted it and to the users who may review it.

### VOL-022

Approving a profile picture change request shall make the submitted picture the staff member's current picture under VOL-013. Rejecting one shall discard the submitted picture and leave the current picture unchanged. Both shall be audited with the reviewer and the decision.

### VOL-023

Removing one's own current profile picture shall require no review. A staff member shall not need permission to stop displaying a picture of themselves.

### VOL-024

A staff member shall hold at most one outstanding request of each kind at a time, shall be able to withdraw their own outstanding request, and shall be able to see the state of their requests and the decisions already made on them.

### VOL-025

A decision on a profile change request shall notify the staff member who submitted it, through the notification path in section 7.24. A rejection shall carry the reason the reviewer gave.

### VOL-026

Self-service profile changes and profile change request decisions shall be audited with the actor and the previous and new value of each changed field.

---

## 7.3 Status Requirements

### STAT-001

Organization statuses shall include Prospective, Active, Inactive, Emeritus, Retired, and Do Not Staff.

### STAT-002

Department statuses shall include Prospective, Active, Inactive, Ineligible, Emeritus, and Retired.

### STAT-003

Do Not Staff shall be organization-wide.

### STAT-004

Do Not Staff shall prevent system access.

### STAT-005

Do Not Staff shall be permanent unless changed by organizers.

### STAT-006

Applications from DNS email addresses shall be auto-rejected without automatic notice.

### STAT-007

Ineligible shall be department-specific.

### STAT-008

Ineligible shall be indefinite until changed by a department lead.

### STAT-009

Working for any department shall prevent a staff member from going inactive at the organization level.

### STAT-010

Prospective staff members shall become Active after completing required trainings, or after department assignment if no trainings are required.

### STAT-011

Prospective status shall last for a configurable number of years before becoming Inactive.

---

## 7.4 Application Requirements

### APP-001

Applications shall be event-specific.

### APP-002

Applicants shall apply to events, not directly to departments.

Optional department interest (APP-011) collected during application submission is a non-binding intake signal. It does not constitute applying to a department, department assignment, department membership, approval, access, or team selection.

### APP-003

Application statuses shall include Submitted, Approved, Rejected, Deferred, Withdrawn, and Auto-rejected due to DNS.

### APP-004

Only applicants shall withdraw their own applications.

### APP-005

Approval shall occur at the organization level.

### APP-006

Approved applicants shall become Prospective staff members.

### APP-007

Department assignment shall occur after organization approval.

### APP-008

Approved applications may be rescinded before team assignment.

### APP-009

If an approved application is rescinded before team assignment, the person shall become Inactive.

### APP-010

Applications shall not be rescinded after team assignment.

### APP-011

Event application department interest shall be optional, collected during application submission, and stored as a non-binding intake signal separate from department assignment (APP-007), department membership, approval (APP-005), access, team selection, routing, notifications, exports, and special audit behavior.

Rules:

- Empty or omitted department interest means no preference (open to any). There is no explicit “No preference” option.
- Applicants may select multiple department interests with no maximum and no preference order (no 1st/2nd/3rd behavior).
- Eligible departments are non-archived departments belonging to the event’s organization that participate in the event through `event_department_assignments` (or equivalent event-department participation).
- If no eligible participating departments exist for the event, the department interest field is hidden and submission proceeds normally.
- Department interest is available to public and authenticated applicants on the event application form.
- Returning or active staff department memberships shall not be prefilled as department interest.
- Applicants shall not edit department interest after submit in Alpha 1.
- Organizers shall not edit department interest during review in Alpha 1.
- No separate withdraw/reapply workflow is introduced solely to change department interest.
- Organizers and Staff Coordinators who can review applications shall see department interest. The organizer application list shall support filtering by department interest in Alpha 1.
- Department leads may see read-only applications that expressed interest in their department before organization approval and before department assignment. This visibility does not grant review authority, approval authority, assignment authority, routing authority, notification behavior, or access to unrelated applications unless the user also has organizer or Staff Coordinator permissions.
- Department interest shall not affect routing, notifications, approval, access, department membership, team membership, shifts, trainings, credentials, or applicant status.
- Department interest shall not be included in exports in Alpha 1.
- There is no “Other / not listed” option and no team interest or team selection on the application form.
- If a department is archived, removed from the event, or renamed after applications are submitted, submitted interest records are preserved for review and history. UI may display the department’s current name when available and shall use inactive or archived treatment when applicable. Historical interest records are not deleted merely because the department is no longer eligible for new applications.

### APP-012

An applicant shall be able to reach their own applications through a signed magic link sent to the email address recorded on the application, without holding a Meridian staff record or an existing session.

The link shall use the same signed magic-link mechanism as primary email verification (AUTH-010) and shall be requestable from the public application surface by entering an email address.

### APP-013

The applicant portal shall show the applicant every application submitted under that email address, with event, submission date, and current application status, and shall allow the applicant to withdraw an application that is still withdrawable (APP-004).

### APP-014

The applicant portal shall not disclose whether an email address has any applications when a link is requested, and shall not reveal DNS auto-rejection (STAT-006). An auto-rejected DNS application shall not appear in the portal.

### APP-015

Requesting an applicant portal link shall be rate limited per email address and per requesting client. Portal link issuance and applicant withdrawal shall be audited.

---

## 7.5 Team Requirements

### TEAM-001

Teams shall replace the earlier concept of roles.

### TEAM-002

Each department shall have a default team.

### TEAM-003

Departments may rename their default team.

### TEAM-004

Teams shall be persistent across events.

### TEAM-005

Teams may be archived.

### TEAM-006

Archived teams shall remain visible in historical records.

### TEAM-007

Historical worked shifts shall preserve the team/function name in effect when the work happened.

### TEAM-008

Team membership may grant shift eligibility.

### TEAM-009

Team membership may grant system authority.

### TEAM-010

System authority shall not be granted as free-floating permissions outside organization, department, or team membership.

### TEAM-011

A department shall be able to designate which of its teams carries each department operational function, following the same designation pattern the organization uses for its Organizers, default Incident Command, and default Placement departments.

A designation is a configuration act that attaches a department operational grant to a named team. It does not create a new hierarchy level, does not rename the team, and does not bypass TEAM-010: authority still reaches a staff member through membership in the designated team.

### TEAM-012

Department team designations shall cover the department operational roles defined in section 4.8A:

- Logistics — carries `department_logistics`
- Operations — carries `department_operations`
- Planning — carries `department_planning`
- Administration — carries `department_administration`
- Operator — carries `department_operator`

A department may designate zero or one team per function. The same team may hold more than one designation. A team holding no designation carries no department operational grant.

### TEAM-012A

Where a department holds the Operator designation and that department is the event's designated Incident Command Department, members of the designated Operator team shall additionally hold the event-scoped `ic_operator` role for that event.

That elevation shall be derived from the designation and the Incident Command designation together. It shall not persist for events where the department is not the Incident Command Department, and it shall not be separately grantable.

### TEAM-013

Designating a team shall not remove the ability to attach a department operational grant to an additional team directly. Designation is the ordinary configuration path; direct grants remain available for departments whose structure does not fit a single team per function.

### TEAM-014

An organization shall be able to designate which team within its configured Organizers Department carries Staff Coordinator authority.

Staff Coordinator shall be an effective permission role scoped to the organization, carrying application review, approval, rejection, and deferral authority (section 4.4) without carrying the remaining organizer governance authority.

### TEAM-015

Authorized attendance managers, as referenced in HOURS-007, SLB-007, and SLB-029, shall be the holders of `department_logistics` for the department, together with department leads and shift leads for that department.

### TEAM-016

Department team designations shall be maintained from the department administration surface by department leads and department administration. Organization-level designations shall be maintained from the organization configuration surface (ORG-018) by organizers and Lead Organizers.

### TEAM-017

Creating, changing, and removing a team designation shall be audited, and the audit entry shall record the department or organization, the function designated, and the team designated.

### TEAM-018

A permission explanation shown to a user shall name the designation that granted the authority where one exists, so that a user reads why they hold an operational capability rather than only that they hold it.

---

## 7.6 Training and Waiver Requirements

### TRAIN-001

Trainings may be event-specific, annual, or one-off.

### TRAIN-002

Trainings may expire.

### TRAIN-003

Trainings shall have completion dates.

### TRAIN-004

Trainings may have prerequisites.

### TRAIN-005

Authorized trainers/leads may record training completion.

### TRAIN-006

Training completion may be imported from spreadsheets.

### TRAIN-007

Meridian shall not model training waivers in MVP.

### TRAIN-008

A staff member shall not sign up for or be added to a shift without required training completion.

### TRAIN-009

Trainings shall be marked as in-person or online. In-person trainings with a scheduled session are listed as a shift signup for the training's team or department; online trainings require no signup and carry a training URL.

### TRAIN-010

Every training shall have a training page describing when and where the training is available, the time commitment, and what follows completion.

### TRAIN-011

Trainer authority (section 4.9) shall come from team leadership: a team lead is the authorized trainer for trainings scoped to their team, and a department lead is the authorized trainer for trainings scoped to their department.

Trainer shall not be a separately grantable role.

### WAIVER-001

Waivers may be assigned at organization, department, or team level.

### WAIVER-002

Waivers may expire.

### WAIVER-003

Meridian shall track waiver completion as complete/incomplete.

### WAIVER-004

Meridian shall not store signed waiver document contents.

### WAIVER-005

A staff member shall not sign up for or be added to a shift without required waiver completion.

### WAIVER-006

If a required waiver expires before the event, credential eligibility shall become Blocked until renewed.

### WAIVER-007

A waiver shall be able to reference a published policy or procedure document as the text the staff member is agreeing to.

A document-backed waiver renders that document's content, including fragment text inline (POL-022), at the point of completion. Meridian still stores completion rather than a signed document (WAIVER-004).

### WAIVER-008

Completing a document-backed waiver shall record the acknowledged document and document version alongside the waiver completion, using the same version-recording rule as policy/procedure acknowledgments (POL-043).

A document-backed waiver completion and a policy/procedure acknowledgment remain distinct records: the acknowledgment satisfies a signup or training requirement (POL-046), while the waiver completion gates the operational events listed in section 3.9.

### WAIVER-009

A waiver shall not be required to reference a document. A waiver with no document reference behaves exactly as specified in WAIVER-001 through WAIVER-006.

### WAIVER-010

Meridian shall provide waiver administration surfaces where authorized maintainers create waivers, assign them to organization, department, or team scope, set expiration, and optionally attach a published document; and where authorized staff record waiver completion.

Waiver administration authority shall follow the scope of the waiver, matching the policy/procedure maintenance rule: organization-scoped waivers by organizers, department-scoped by department leads, team-scoped by team leads.

---

## 7.7 Shift Requirements

### SHIFT-001

Shifts shall belong to an event and department.

### SHIFT-002

Shifts shall have scheduled start and end times.

### SHIFT-003

Shifts shall have a displayed title/function.

### SHIFT-004

Shifts shall define eligible team membership.

### SHIFT-005

Shifts may define required trainings.

### SHIFT-006

Shifts may define required waivers.

### SHIFT-007

Shifts may define capacity.

### SHIFT-008

Shifts may define signup availability dates.

### SHIFT-009

Shifts may define schedule lock/cutoff behavior.

### SHIFT-010

Shifts may define a shift-specific credit policy.

### SHIFT-011

Shift signup shall be immediate when eligibility requirements are satisfied.

### SHIFT-012

Full shifts shall not allow additional staff self-signup.

### SHIFT-013

Leads may remove staff from shifts.

### SHIFT-014

Schedule overlaps shall warn rather than hard-block by default.

### SHIFT-015

Authorized leads may assign overlapping shifts.

### SHIFT-016

Required trainings and waivers shall be enforced for both scheduled and unscheduled shift additions.

### SHIFT-017

A department shall be able to express its schedule lock/cutoff (SHIFT-009) relative to the event's active event window as well as by an absolute timestamp, so that a cutoff configured once remains correct when event dates move.

A relative cutoff shall be expressed as an offset before the active event window start and shall resolve to an absolute time whenever the window is known.

### SHIFT-018

Meridian shall provide a staff shift signup surface on which a staff member browses the shifts they are eligible for in an event and signs up (SHIFT-011), and removes themselves before the cutoff (section 3.11).

The surface shall show why an ineligible shift is unavailable, using the eligibility reasons in section 3.12, and shall surface overlap as a warning rather than a block (SHIFT-014).

---

## 7.8 Credential Requirements

### CRED-001

A credential shall represent event-specific approval to work an event.

### CRED-002

A credential shall not represent a physical item.

### CRED-003

A staff member shall have at most one credential per event.

### CRED-004

Credential eligibility shall require at least one signed-up shift.

### CRED-005

Credential eligibility shall require required waivers complete.

### CRED-006

Credential eligibility shall require age requirements satisfied as of the event date.

### CRED-007

Credential eligibility shall require no organization-level blocking status.

### CRED-008

Credential eligibility shall require no department-level Ineligible status for the relevant department.

### CRED-009

Credential states shall include Eligible, Blocked, and Revoked.

### CRED-010

Removing all shifts shall move credential eligibility into Blocked state.

### CRED-011

Manual credential revocation shall be restricted to organizers and Incident Command Department leads.

### CRED-012

Credential revocation shall remove future shifts where possible.

### CRED-013

Credential revocation shall preserve completed shifts and recorded hours.

### CRED-014

Unscheduled work shall not retroactively grant credential eligibility.

---

## 7.9 Department Operations Surface Requirements

### SLB-001

The Department Overview shall let department leads select a shift and review current department operations for that shift.

### SLB-002

The Department Overview shall show exceptions requiring attention first, then checked-in staff currently working, then full shift assignments, then compact equipment and deployment summaries.

### SLB-003

The Logistics Desk shall allow staff check-in after staff are marked on-site for the department through a staff-first search and staff operational workspace.

### SLB-004

The Logistics Desk shall allow staff check-out from the selected staff member's active or outgoing shift workspace.

### SLB-005

Check-out shall create an actual hours record.

### SLB-006

Department Logistics shall be able to edit actual start/end time during check-out.

### SLB-007

Authorized attendance managers shall be able to correct hours during the correction grace period.

### SLB-008

The Logistics Desk shall allow on-site eligible unscheduled staff to be added to a shift from the selected staff member's workspace.

### SLB-009

The Operations Center shall always include a deployment/location module for users with Department Operations capability.

### SLB-010

The Operations Center deployment module shall allow staff to be moved between deployments/locations.

### SLB-011

The Department Overview shall show a compact summary of equipment checked out, and the Logistics Desk staff workspace shall show equipment checked out to the selected staff member.

### SLB-012

The Logistics Desk shall support equipment checkout/check-in to individual staff members from the staff operational workspace.

### SLB-013

Department operations surfaces shall provide a field report shortcut or module only where the actor already has Field Report permission.

### SLB-014

The Operations Center shall show an incident overview module only when the actor has event-scoped Incident Command capability. Opening the Operations Center shall not grant incident access.

### SLB-015

Meridian shall track on-site/off-site status per event, department, and staff member.

### SLB-016

Only Department Logistics shall mark department staff on-site/off-site from the Logistics Desk staff workspace.

### SLB-017

Staff shall not be marked off-site while checked into a shift for that department/event.

### SLB-018

Staff shall not be marked off-site while holding checked-out equipment for that department/event unless the equipment is returned or marked Missing/Damaged.

### SLB-019

The Planning Table shall show identity-free plan-versus-actual aggregates by shift/team window, including capacity target, signed-up or assigned count, checked-in count, no-show count, unscheduled additions, planned hours, actual hours, and variance or status. It shall not expose individual staff identities, signup lists, or team-member lists.

### SLB-020

Department Overview and Planning Table shall be department-scoped by default. Optional team or date filters may narrow the view without changing authorization or revealing identities on the Planning Table.

### SLB-021

The Logistics Desk shall provide department-scoped offline search across staff, equipment, and shifts for the current event and department.

### SLB-022

The Operations Center shall compose overview modules from capabilities the actor already holds. The shell itself shall not grant access to incidents, equipment, maintenance tickets, or other modules.

### SLB-023

Meridian shall determine no-show automatically. A scheduled shift for which the assigned staff member has not checked in by the end of the accepted sign-in window shall be recorded as a no-show without a lead marking it.

### SLB-024

The accepted sign-in window shall extend before and after the scheduled shift start by 5% of the scheduled shift duration.

### SLB-025

Automatic no-show determination shall write an attendance operation through the existing append-only attendance path, audited and synchronized like any other attendance operation.

### SLB-026

Automatic no-show shall be determined by the node holding authority for the event: the on-site primary node during the active event window, and central otherwise.

### SLB-027

A shift excluded from coverage shall not be determined a no-show. Cancelled shifts and shifts with an excused attendance record shall be excluded.

### SLB-028

A check-in recorded after an automatic no-show shall supersede it. The derived attendance state shall become checked-in, and both operations shall be preserved in attendance history.

### SLB-029

The manual mark-no-show operation shall remain available to authorized attendance managers alongside automatic determination.

### SLB-030

A staff member shall be considered to have arrived late when they check in after the end of the accepted sign-in window defined in SLB-024. Given SLB-025 and SLB-028, a late arrival is an automatic no-show that a later check-in superseded, so one threshold separates on time, late, and missed with no gap or overlap between them.

### SLB-031

Hours correction (SLB-007, HOURS-007) shall be performed from the Logistics Desk staff workspace. An authorized attendance manager searches for the staff member, opens their workspace, selects a completed shift, and edits the recorded actual start and end times.

Correction shall be refused once the hours record is frozen (HOURS-008), and the refusal shall state that the correction grace period has closed.

### SLB-032

A correction shall write an attendance operation through the existing append-only attendance path, preserving the prior values in history and auditing the change, so a corrected record shows what it was as well as what it became.

---

## 7.10 Hours and Credit Requirements

### HOURS-001

Hours worked shall be distinct from scheduled hours.

### HOURS-002

Hours worked shall have actual start and end times.

### HOURS-003

Hours worked shall always be associated with a shift.

### HOURS-004

Hours worked shall always be associated with a department.

### HOURS-005

Hours shall not exist without a shift.

### HOURS-006

Hours shall not exist without a department.

### HOURS-007

Authorized attendance managers may correct hours during the organization-wide correction grace period.

### HOURS-008

Hours shall freeze after the correction grace period.

### CREDIT-001

Credits shall be calculated from finalized hours after the correction grace period.

### CREDIT-002

Credits shall use the shift-specific credit policy when present.

### CREDIT-003

Credits shall use the organization default credit policy when no shift-specific policy exists.

### CREDIT-004

Credits shall freeze after calculation.

### CREDIT-005

Credits earned export shall include calculation basis.

---

## 7.11 Field Report Requirements

### FR-001

Authorized staff members may create field reports.

### FR-002

Field reports shall be event-specific.

### FR-003

Field reports shall record author, a required title, and report text.

The title shall be plain text, trimmed of outer whitespace, 1–200 characters after trimming, and may duplicate other titles within the same event. Appends shall not have titles.

### FR-004

Field reports shall be visible to the author.

### FR-005

Field reports shall be visible to the event’s Incident Command Department.

### FR-006

Field reports shall not be visible to non-IC department leads by default.

### FR-007

Field reports shall not be editable after submission. The original title and body shall remain immutable.

### FR-008

Field reports shall not be stricken.

### FR-009

Only the author may append to their field report.

### FR-010

Field reports may exist independently.

### FR-011

Field reports may be attached to multiple incidents.

### FR-012

When attached to an incident, field report content shall be copied into incident notes as `Field Report: <title>`, followed by the Field Report author and body.

### FR-013

When a field report is appended, only the added content shall be copied into associated incidents.

### FR-014

When a field report is removed from an incident, the incident history shall show the relationship as stricken.

### FR-015

A Department Operator, and an `ic_operator` or `ic_lead`, may create a Field Report on behalf of another staff member who is reporting to them and cannot file it themselves.

A Field Report created this way shall record the reporting staff member as the author and the creating user as the submitter. Both shall be preserved and both shall be visible wherever the report is shown, so a reader can tell that the report was taken rather than written.

### FR-016

A Field Report taken on behalf of another staff member shall be immutable on the same terms as any other Field Report (FR-007, FR-008).

Append authority shall follow the recorded author (FR-009). The submitter shall not gain append authority from having taken the report, and taking a report shall not grant the submitter any access they did not already hold.

### FR-017

The reporting staff member selectable when taking a Field Report shall be limited to staff the creating user is already permitted to see, and selection shall not disclose staff outside that scope.

---

## 7.12 Incident Requirements

### INC-001

Incidents shall be event-specific.

### INC-002

Incidents shall be managed by the event’s Incident Command Department.

### INC-003

Incidents shall have an IMS Number.

### INC-004

IMS Numbers shall support organization-year-event-count structure.

### INC-005

Incidents shall support configurable states.

### INC-006

Incidents shall support configurable types.

### INC-007

Incidents shall support summary, started timestamp, location, tags, notes, attachments, linked incidents, and involved staff.

### INC-008

Incidents shall not be destroyed.

### INC-009

Incidents shall not be merged away.

### INC-010

Incidents may be edited regardless of state.

### INC-011

Incident state shall affect filtering/status, not editability.

### INC-012

Incident timestamps shall not be retroactively changed.

### INC-013

Incident attachments may be stricken but not deleted.

### INC-014

Incident changes shall be preserved in history.

### INC-015

IC department leads may print incidents to PDF.

### INC-016

Incidents shall not be included in general spreadsheet exports for MVP.

---

## 7.12A Name Reference Requirements

### NR-001

Name References shall be lightweight inline `@name` markers in Incident notes and Field Reports.

### NR-002

Name References shall be stored in the original Incident note or Field Report text.

### NR-003

Meridian may maintain a rebuildable derived index of extracted Name Reference tokens for search, display, rendering, or offline/local-first support.

### NR-004

The derived Name Reference index shall not be treated as a canonical person, alias, identity, entity, suspect, volunteer profile, or independent source of truth.

### NR-005

A Name Reference shall start with `@` and continue through letters, numbers, hyphens, and underscores until whitespace or punctuation.

### NR-006

Name Reference matching and search shall be case-insensitive.

### NR-007

Field Report body text and appends shall be parsed for Name References after submission. Field Report titles shall not be parsed for Name References.

### NR-008

Incident-level Name Reference chips shall include references extracted from the incident's own notes and Field Reports attached to the incident.

### NR-009

Name Reference chips shall be visually distinct from `#tags` and shall appear near existing incident tag/metadata areas where appropriate.

### NR-010

Clicking a Name Reference shall run normal permission-filtered search for the reference text without the `@` prefix.

### NR-011

Name References shall not create notifications, Meridian user mentions, volunteer profile links, autocomplete, context menus, alias merge behavior, canonical identity/entity records, or dedicated detail pages.

### NR-012

Name References shall inherit visibility from their source Incident note or Field Report and shall not grant access to additional incidents, reports, or search results.

### NR-013

Name Reference click/search behavior shall not require special Name Reference-specific audit events beyond existing read/view/search audit behavior where applicable.

### NR-014

Name Reference source text shall sync as part of the existing Incident note and Field Report sync behavior; any local or server-side derived index shall remain rebuildable from source text.

---

## 7.13 Equipment Requirements

### EQUIP-001

MVP equipment tracking shall be visible/manual.

### EQUIP-002

MVP equipment tracking shall support checkout to individual staff members.

### EQUIP-003

MVP equipment tracking shall support check-in from individual staff members.

### EQUIP-004

Department Logistics may check equipment in/out.

### EQUIP-005

MVP equipment states shall include Available, Checked out, Returned, Missing, and Damaged.

These five are the stored states. Presentation distinctions such as overdue, lost, or unknown shall be derived from a stored state plus the associated shift or event window, and shall not be added as stored states.

### EQUIP-006

Department-to-department allotments are out of scope for MVP.

### EQUIP-007

Equipment may be checked out to staff before, during, or after a shift.

### EQUIP-008

Full inventory custody chains are out of scope for MVP.

### EQUIP-009

An equipment checkout shall record whether it is assigned for a shift or for the event, so that a checkout still open after its shift ends can be distinguished from one still open after the event ends.

A shift-assigned checkout shall reference the shift it was issued for. An event-assigned checkout shall reference no shift.

### EQUIP-010

Equipment shall be recorded as either individually tracked or pooled.

Individually tracked equipment is one physical unit per record, identified by an asset tag or a serial number, whose whereabouts Meridian follows unit by unit.

Pooled equipment is interchangeable units of one kind held as a quantity on a single record, carrying no per-unit identifier. A department hands out three of them without Meridian needing to know which three.

### EQUIP-011

A checkout of individually tracked equipment shall name the unit checked out. A checkout of pooled equipment shall record the quantity handed out.

### EQUIP-012

An operator shall find individually tracked equipment for checkout by entering or scanning an asset tag or serial number, or by searching name, asset tag, or serial number.

A department's individually tracked equipment shall not be presented as a list of every unit for the operator to read through. A department may hold hundreds of tracked units, and a list of that length is slower to work than the handoff it is meant to support.

### EQUIP-013

An entered value matching exactly one item's asset tag or serial number shall add that item to the checkout without further selection, so that a barcode scanner acting as a keyboard completes a handoff without the operator touching the screen.

An entered value matching more than one item, or none, shall report that rather than guessing.

### EQUIP-014

Pooled equipment shall be presented as a short list of kinds with the quantity available for each, and the operator shall choose a quantity for each kind rather than selecting units.

### EQUIP-015

Equipment lookup shall offer only equipment within the department and event scope the operator is authorized for, and shall not disclose the existence of equipment outside it.

Lookup shall resolve against the department inventory the surface already holds, so it works on a node or device with no connectivity.

### EQUIP-016

The quantity of a pooled record available to hand out shall be derived from its total quantity less the quantity currently checked out and not returned.

A pooled record shall never be stored in the `checked_out` state, because a pool is not wholly held by one staff member.

### EQUIP-017

Returning pooled equipment shall record the quantity returned and its condition.

Pooled units returned Missing or Damaged shall reduce the pool's serviceable quantity through an audited inventory adjustment carrying a reason, rather than changing the pooled record's state.

---

## 7.14 Reporting Requirements

### REPORT-001

MVP shall support credential eligibility export.

### REPORT-002

MVP shall support shift roster export.

### REPORT-003

MVP shall support staff contact list export.

### REPORT-004

MVP shall support hours worked export.

### REPORT-005

MVP shall support credits earned export.

### REPORT-006

Organizers may export organization/event-wide reports, excluding emergency contacts.

### REPORT-007

Department leads may export department-scoped reports.

### REPORT-008

Shift roster exports shall exclude phone numbers and emergency contacts.

### REPORT-009

Department staff contact exports may include phone numbers and emergency contacts.

### REPORT-010

Organizer exports shall not include emergency contacts.

### REPORT-011

Waiver completion export is not required for October MVP.

### REPORT-012

Provision eligibility export is not required for October MVP.

### REPORT-013

Incident spreadsheet export is not required for October MVP.

### REPORT-014

Meridian shall provide reporting surfaces from which an authorized user runs the exports in REPORT-001 through REPORT-005: an organization/event-scoped surface for organizers and a department-scoped surface for department leads.

Each surface shall offer only the exports the actor is authorized to run, and shall state the scope and the excluded fields of an export before it is generated, so an organizer sees that emergency contacts are excluded (REPORT-010) without having to open the file.

### REPORT-015

Exports shall be retrieved through a short-lived scoped download URL (CLIENT-019, CLIENT-020) rather than a credentialed link, and export generation shall be audited with the requesting user, scope, and export type.

---

## 7.15 Policy, Procedure, and Fragment Requirements

### POL-001

Meridian shall support policy documents.

### POL-002

Meridian shall support procedure documents.

### POL-003

Policy/procedure documents shall support organization, department, and team scope.

### POL-004

Policy/procedure document states shall include Draft, Published, and Archived.

### POL-005

Draft policy/procedure documents shall be editable by authorized maintainers.

### POL-006

Published policy/procedure documents shall be visible according to their scope.

### POL-007

Archived policy/procedure documents shall be retained for history but shall not be treated as active.

### POL-008

Organization-level policy/procedure documents shall be visible to everyone in the organization.

### POL-009

Department-level policy/procedure documents shall be visible to members of the department.

### POL-010

Team-level policy/procedure documents shall be visible to members of the team.

### POL-011

Department leads and team leads shall be able to see policies/procedures within their department.

### POL-012

Organizers shall be able to see all published policy/procedure documents across the organization.

### POL-013

Policy/procedure documents shall not be generally public-facing before login except as part of staff signup for an organization.

### POL-014

Meridian shall support reusable text fragments for policy/procedure documents.

### POL-015

Fragments shall support organization, department, and team scope.

### POL-016

Organization-level fragments shall be maintained by organizers.

### POL-017

Department-level fragments shall be maintained by department leads.

### POL-018

Team-level fragments shall be maintained by team leads.

### POL-019

Policy/procedure documents shall support references to fragments.

### POL-020

Fragment references shall be version-aware.

### POL-021

When editing a policy/procedure document, Meridian shall show fragment references and the referenced fragment version.

### POL-022

When viewing a policy/procedure document, Meridian shall render referenced fragment text inline as document text.

### POL-023

Meridian shall support policy/procedure acknowledgments.

### POL-024

Policy/procedure acknowledgments may occur during staff signup.

### POL-025

Policy/procedure acknowledgments may occur as part of training.

### POL-026

Policy/procedure acknowledgments shall not be modeled as direct shift signup gates in MVP.

### POL-027

Policy/procedure acknowledgments shall not be modeled as direct credential eligibility gates in MVP.

### POL-028

Meridian shall support PDF print/export for policy/procedure documents.

### POL-029

Meridian shall support Markdown export for policy/procedure documents.

### POL-030

Meridian shall support exporting policy/procedure packets containing multiple documents.

### POL-031

Policy/procedure exports shall render referenced fragment text inline.

### POL-032

Policy/procedure documents shall not have a separate Active state.

### POL-033

Policy documents and procedure documents shall be separate document types.

### POL-034

Policy/procedure document content shall support Markdown only for MVP.

### POL-035

Fragment content shall support Markdown only for MVP.

### POL-036

Fragments shall not reference other fragments.

### POL-037

Nested fragments shall not be supported.

### POL-038

Fragments shall not require Draft, Published, or Archived states for MVP.

### POL-039

Fragments shall have an auto-incrementing version that increments when fragment text changes.

### POL-040

Policy/procedure documents shall use the latest fragment text when rendered.

### POL-041

When a fragment changes, policy/procedure documents that reference it shall automatically render the updated fragment text.

### POL-042

When an included fragment changes, the referencing policy/procedure document version shall be bumped.

### POL-043

Acknowledgment records shall store the acknowledged document and document version.

### POL-044

Acknowledgment records shall not be required to store the rendered text the staff saw.

### POL-045

Policy/procedure acknowledgments shall not need to be automatically re-required after a document or included fragment changes.

### POL-046

Policy/procedure acknowledgments shall be limited to staff signup and training for MVP.

### POL-047

Policy/procedure acknowledgment requirements shall support organization and department scope for MVP.

### POL-048

Organization-scoped policy/procedure documents and fragments shall be published or maintained by organizers.

### POL-049

Organizers shall not change department-scoped or team-scoped policy/procedure documents by default.

### POL-050

Department-scoped policy/procedure documents and fragments shall be published or maintained by department leads.

### POL-051

Team-scoped policy/procedure documents and fragments shall be published or maintained by team leads.

### POL-052

Archived policy/procedure documents and fragment versions shall remain available for historical acknowledgment and export review.

### POL-053

Policy/procedure packets shall be manually assembled for MVP.

### POL-054

Policy/procedure packet exports shall include document contents only and shall not include staff acknowledgment status.

### POL-055

Policy/procedure documents shall be searchable by staff according to visibility permissions.

---

## 7.16 Authentication Requirements

### AUTH-001

Meridian shall support email magic-link login, Google OAuth login, and Discord OAuth login for Alpha 1.

### AUTH-002

Meridian shall not support internal username/password login for Alpha 1.

### AUTH-003

Magic-link login may create a user account only when system account creation for magic links is enabled.

### AUTH-004

Magic-link account creation shall default to enabled for Alpha 1 testing and development.

### AUTH-005

When magic-link account creation is disabled, a magic link for an unknown email shall fail without creating a user account.

### AUTH-006

Google and Discord login shall require a verified provider email.

### AUTH-007

A user may have one optional secondary email address in addition to their primary email address.

### AUTH-008

Primary and secondary email addresses shall be globally unique across users.

### AUTH-009

A secondary email address shall not be usable for login matching until verified.

### AUTH-010

Secondary email verification shall use the same signed magic-link mechanism as primary email verification.

### AUTH-011

Users may add or remove their own secondary email address.

### AUTH-012

Only God mode may change a user's primary email address.

### AUTH-013

God mode is trusted to mark a changed primary email address as verified.

### AUTH-014

Adding, verifying, and removing a secondary email address shall be audited.

### AUTH-015

Changing a primary email address shall be audited.

### AUTH-016

Google and Discord may authenticate against either a verified primary email address or a verified secondary email address.

### AUTH-017

Google or Discord provider linking through a secondary email shall require the already-authenticated user to explicitly start provider linking.

### AUTH-018

Meridian client applications shall authenticate to the Meridian API with a bearer token rather than relying on a browser session cookie, so that the web client, the mobile Field application, and the desktop application all use one authentication mechanism.

### AUTH-019

Meridian shall expose API login endpoints so a client application can request a magic link and complete verification without leaving the application, receiving a bearer token on success.

### AUTH-020

Google and Discord login from a client application shall complete through a system browser and return the resulting bearer token to the requesting application. The provider flow itself shall not be reimplemented inside the client.

### AUTH-021

Every issued bearer token shall be bound to a device record. A token that cannot be associated with a device shall not be issued.

### AUTH-022

God mode shall be able to list issued tokens by user and by device, and revoke any individual token or every token for a device.

### AUTH-023

A revoked token shall stop authenticating at the next request the node receives from it. Revocation shall not depend on the client cooperating.

### AUTH-024

Bearer tokens shall expire. Expiry shall be configurable per node with a documented default, and shall be independent of the shared-workstation session timeout in the technical specification section 13.3.

### AUTH-025

Token issuance, expiry, and revocation shall be audited. Raw token values shall never be written to logs, audit entries, or exports.

### AUTH-026

The shared-workstation login codes described in the technical specification section 13.2 shall be generatable both by God mode and by the user the code is for, from a device on which that user already holds a valid session.

### AUTH-027

Self-service login code generation shall require only reachability of the node that will accept the code. It shall not require internet access, central node reachability, email delivery, or any other out-of-band channel.

### AUTH-028

A self-service login code shall be scoped to the generating user and shall not be generatable on behalf of another user. God mode retains the ability to generate a code for another user.

### AUTH-029

Login code generation shall be rate limited per user and per node, and failed login code attempts shall be rate limited per workstation.

### AUTH-030

A successful login code entry shall establish a shared-workstation session as described in the technical specification section 13.3. It shall not issue a personal device token and shall not establish a trusted personal device session.

---

## 7.17 Event Map, Geography, and Placement Requirements

### MAP-001

Events shall support maps, and the map feature shall be enabled by default for new events.

### MAP-002

An event may have zero or more maps, and may have multiple maps of different types.

### MAP-003

Maps shall support the `placement` and `topographic` map types.

### MAP-004

Placement maps shall represent a 2D top-down local event/site map using a local coordinate plane.

### MAP-005

Topographic maps shall represent real-world geography using real-world coordinates or prepared map packages/assets.

### MAP-006

Maps shall have whole-map lifecycle states of Draft, Published, and Archived.

### MAP-007

Individual camps and map locations shall not have lifecycle states separate from the whole map for MVP.

### MAP-008

Only Published maps shall be visible to permitted operational users; Draft and Archived maps shall be limited to users with map edit/admin permissions.

### MAP-009

For MVP, maps shall be created from an uploaded/imported map asset or prepared map package with lightweight metadata, with camp/location records placed on top.

### MAP-010

Meridian shall not provide a full GIS editor, complex drawing suite, automatic geocoding, or public map builder for MVP.

### MAP-011

Placement maps and topographic maps shall be designed to be linkable/georeference-compatible over time, but georeferencing shall not be required for MVP.

### MAP-012

Before the event operations window begins, authorized map editors may create/update maps, camps, and map locations.

### MAP-013

When the event enters its operations window, published map geometry, camp records, and map location records shall be locked against normal editing.

### MAP-014

After the operations window begins, corrections to locked map data shall require an organizer/admin override with an explicit reason, or a post-event update path.

### MAP-015

Map publishing, archiving, Placement department designation, and locked-map overrides shall be recorded as audited command-style writes.

### MAP-016

Geometry shall be capable of representing points, lines, and polygons over time, using GeoJSON-compatible concepts where appropriate while allowing local/non-geographic placement coordinates.

### MAP-017

Meridian shall not support arbitrary dropped pins for MVP.

### MAP-018

Camps and map locations shall not be added to the global command palette for MVP.

### MAP-019

A map surface may provide a scoped map search/filter panel for permitted users; it shall not expose camp names or operational locations to users who lack map permissions.

### CAMP-001

Camps shall be event-scoped entities, not generic map features, not a hierarchy level under organizations or departments, and not children of the Placement department.

### CAMP-002

For MVP, a camp record shall include only a camp name and a location.

### CAMP-003

Camp location may be represented as a point, a simple footprint/area, local placement-map coordinates, or geospatial coordinates where available.

### CAMP-004

Camps shall not be required to have GPS coordinates.

### CAMP-005

Camp records shall be designed so future versions can add description, lead/contact information, department or operational affiliation, public/private display flags, and additional placement metadata.

### CAMP-006

Camps shall not have notes for MVP.

### CAMP-007

Camp names shall not be public to all volunteers by default, and camp visibility shall follow map permissions and operational role needs.

### LOC-001

Map locations/features shall be lightweight event-scoped operational locations that are not camps.

### LOC-002

Map location types may include department_hq, gate, road, landmark, deployment_location, service_location, restricted_area, parking, and other.

### LOC-003

Map locations shall carry enough structure to display on the map and be referenced from operational workflows, without becoming a large GIS subsystem.

### PLACE-001

Organizations may define a default Placement department.

### PLACE-002

Each event may designate zero or one Placement department, and may override the organization default.

### PLACE-003

The designated Placement department shall be one of the departments assigned to that event.

### PLACE-004

The Placement department designation shall unlock map and placement-related permissions only for the event where the department is designated.

### PLACE-005

Designating a department as Placement shall not make that department globally special across all events, and shall not automatically make it the Incident Command Department or Organizers Department.

### PLACE-006

Only one Placement department shall be designated per event for MVP.

### PLACE-007

If no Placement department is designated, map editing shall fall back to organizers/admins/map managers according to documented permissions.

### PLACE-008

A department may be designated as both Placement and another special department (such as Incident Command or Organizers) for the same event, consistent with existing special-department rules, but each designation shall grant only its own authority.

### PLACE-009

Placement department leads may create/edit maps, camp records, placement locations, map layers/assets, and related geography records before the operations window begins.

### PLACE-010

Placement department leads may publish and archive event maps.

### PLACE-011

Placement department members who are not leads shall receive view access by default and may receive edit access only when granted a map-management role/grant within the Placement department.

### PLACE-012

Locked-map data overrides after the operations window begins shall be reserved for organizers/admins and shall not be part of the Placement department lead role.

### MAPIMS-001

IMS incidents may optionally reference a camp or operational map location.

### MAPIMS-002

A map/location reference shall not be required to create an incident.

### MAPIMS-003

IMS incident create/edit shall support selecting a known camp/location where permitted.

### MAPIMS-004

When an incident references a camp, the IMS view shall be able to display useful camp location details to IC roles.

### MAPIMS-005

Map references shall not replace existing incident free-text location/summary behavior.

### MAPIMS-006

Incident map/location visibility shall follow existing IMS permissions.

### MAPFR-001

Field Reports shall have one required title and one unstructured body text field, and shall not gain structured map/location fields, dropped pins, coordinates, or camp selectors for MVP.

### MAPOPS-001

Operational map locations may be referenced by shift meeting/check-in locations, deployment locations, department HQ locations, and equipment/storage locations where equipment locations are already modeled.

### MAPOPS-002

Shift, deployment, and equipment records shall not be required to have a map location.

### MAPKIOSK-001

The kiosk dashboard shall include a map by default when an event has a published map and the current kiosk/user has permission to view it.

### MAPSYNC-001

Published placement maps, published/topographic map packages, and permitted camp/location records shall be eligible for offline sync to authorized users/devices by default.

### MAPSYNC-002

Sensitive map layers/features shall not sync to users without permission, and UI hiding alone shall not be sufficient.

### MAPSYNC-003

Locked operations-window map data shall remain stable offline.

### MAPCORR-001

Volunteers shall not submit map corrections for MVP; map corrections are an admin/map-manager/Placement department responsibility before the operations window, with organizer/admin override after the operations window begins.

---

## 7.18 Fixed UI Mode Requirements

### UI-001

Meridian shall define exactly three MVP UI modes: `admin`, `field`, and `kiosk`.

### UI-002

The server-hosted web application shall use `admin` mode and the product name `Meridian Admin`.

### UI-003

The Capacitor mobile application shall use `field` mode and the product name `Meridian Field`.

### UI-004

The Electron desktop/on-site application shall use `kiosk` mode and the product name `Meridian Kiosk`.

### UI-005

UI mode shall be selected only by deployment target/build artifact, not by viewport, device class, touch capability, network state, current user, current role, permissions, authentication state, trusted-workstation state, or user preference.

### UI-006

Meridian shall not provide a user-facing UI mode switcher.

### UI-007

Authentication shall work in every UI mode.

### UI-008

Authorization shall be enforced by the same server-side policies, domain services, API command acceptance flows, and sync upload rules in every UI mode.

### UI-009

Responsive layout, touch adaptations, density, fullscreen presentation, and accessibility adaptations shall be modeled as presentation profiles or component-level behavior, not as UI modes.

### UI-010

Public event application shall be reachable from every deployment target when network access and event application state allow it.

### UI-011

Readiness and About surfaces shall be available in every UI mode.

### UI-012

Policy and procedure read surfaces shall be available in every UI mode where the authenticated user is permitted to view them.

### UI-012A

The Briefing hub shall be available in every UI mode where the authenticated user is permitted to view it. Note create and author/Command Note reads shall be available where the actor has authority. Notes Command has added to The Briefing shall be readable by approved event staff. Note create shall require server connection in Alpha 1.

### UI-013

Policy and procedure write/maintenance surfaces shall be available only in Admin mode for MVP.

### UI-014

Department roster, teams, trainings, shifts, equipment, credits, documents, maps, and department operations surfaces shall be available in every UI mode where the authenticated user is permitted to use them.

### UI-015

Organizer screens shall be available in Kiosk and Admin modes, and unavailable in Field mode.

### UI-016

Staff dashboard and shift surfaces shall be available in Field, Kiosk, and Admin modes, with the mode-specific shell controlling presentation.

### UI-017

Kiosk switch, re-authentication, safe-timeout, and setup/support surfaces shall be available in Kiosk mode. Admin mode may configure, review, and support those Kiosk surfaces, but Admin mode shall not become a quick switcher for its own session.

### UI-018

Orchid shall be treated as God Mode and repair tooling available only from Admin mode.

### UI-019

Kiosk mode shall require a pinned organization and event context before normal operation, with an optional pinned department context.

### UI-020

When Kiosk mode starts without a pinned context, it shall enter setup rather than inferring context from the current user, event data, viewport, local network, or last route.

### UI-021

Authorized organizers, lead organizers, and God Mode users may change Kiosk pinned context from Kiosk setup/support surfaces.

### UI-022

Kiosk inactivity timeout shall be 5 minutes for MVP.

### UI-023

Kiosk timeout shall abandon unsaved work, while locally queued saved operations remain queued and sync when available.

### UI-024

Offline Field Report creation, Field Report photo attachment sync, check-in, check-out, and mark-no-show shall remain supported wherever the corresponding surface is available and the device has the necessary synced local data.

### UI-025

Incident reads may be available offline when synced and authorized, but incident creation and mutation shall require server connection in every UI mode.

### UI-026

Offline server rejections and conflicts shall be deferred to the God Mode conflict queue. Until that queue exists, product surfaces may fail silently after recording the local queued/sync-failed state needed for later repair.

---

## 7.19 The Briefing and Notes Requirements

### BRF-001

Meridian shall provide an event-scoped Briefing hub that aggregates Notes Command has added to The Briefing, After Action Reports, Directions, Action Plans, and Notices.

### BRF-002

The Briefing shall be Command-owned operational communication for the event’s Incident Command Department and shall be distinct from IMS incident notes, Field Reports, Policies/Procedures, and the standalone Notes pool.

### BRF-003

Approved event staff shall be able to open The Briefing hub for events they can access.

### BRF-004

Department leads, team leads, and IC operators/leads shall be able to create Notes for an event.

### BRF-005

Notes shall be standalone Markdown text blocks scoped to an event. The Briefing and AARs shall not own Notes; they shall only reference or link Notes.

### BRF-006

Notes shall be immutable after creation. Meridian shall not allow edit or append of an existing Note.

### BRF-007

Until Command adds a Note to The Briefing, that Note shall be readable only by its author and by Command (`ic_lead`, `ic_operator`, and `ic_viewer` where Command read visibility is granted).

### BRF-008

IC operators/leads shall be able to add a Note to The Briefing by **reference** or by **link**.

### BRF-008A

A **reference** inclusion shall present a Command-authored summary of the Note, credit the original author, and provide access to view the original Note.

### BRF-008B

A **link** inclusion shall present the Note body verbatim in The Briefing, credit the original author, and read as Command communication attributed to that individual (including when the author is a Command member).

### BRF-008C

Each Briefing Note inclusion shall carry an audience mark of **event staff** (default) or **department leads only**.

### BRF-008C1

Event-staff Briefing Note inclusions shall be visible to all approved event staff for the event.

### BRF-008C2

Department-leads-only Briefing Note inclusions shall be visible only to department leads for the event, Command, and organizers. Team leads shall not gain visibility from that mark unless they are also a department lead, Command, or organizer.

### BRF-008D

IC operators/leads shall be able to add the same Note to an AAR by reference or link, independently of whether it was added to The Briefing.

### BRF-009

Department leads and team leads shall be able to create Submission After Action Reports for their department or team scope for an event.

### BRF-010

Every After Action Report shall use a fixed ICS section template: Command, Operations, Logistics, Planning, and Admin.

### BRF-011

Submission AARs shall be versioned documents. Leads may edit and resubmit within the submission window.

### BRF-012

The Submission AAR window shall remain open through **30 days after event end**. After that window closes, leads shall not submit or resubmit Submission AARs.

### BRF-013

Submitters shall read their own Submission AARs. IC and organizers shall read all Submission AARs for the event. Peer department/team leads shall not read other leads’ Submission AARs until the Final AAR is published.

### BRF-014

IC operators/leads shall be able to publish a Final AAR for the event after event end.

### BRF-015

If no Final AAR is published by **45 days after event end**, Meridian shall auto-assemble a Final AAR from the latest submitted version of each Submission AAR and freeze it.

### BRF-016

After the Final AAR is published or auto-assembled and frozen, it shall be readable by all approved event staff. Frozen Final AARs shall be read-only except for organizer/god_mode repair paths.

### BRF-017

AAR Note inclusions shall use the same reference and link modes as The Briefing. Because Notes are immutable, document revision bumps shall not be caused by Note mutation.

### BRF-018

IC operators/leads shall be able to create Directions as Markdown instruction blocks in The Briefing.

### BRF-019

Directions may deep-link to Meridian entities and may target one or more departments or teams. Organization-wide Directions shall be visible to all approved event staff; targeted Directions shall be visible to targeted scopes plus IC/organizers.

### BRF-019A

Directions may be marked **department leads only**. When so marked, they shall be visible only to department leads for the event, Command, and organizers (team leads excluded unless they also hold one of those roles).

### BRF-020

Directions shall not surface as page banners.

### BRF-021

IC operators/leads shall be able to create and maintain an event Action Plan with optional department- or team-scoped sections.

### BRF-022

An Action Plan marked for event staff shall be readable by all approved event staff. Targeted sections shall be emphasized for members of those scopes.

### BRF-022A

An Action Plan as a whole, and/or individual Action Plan sections, may be marked **department leads only**. When so marked, that plan or section shall be visible only to department leads for the event, Command, and organizers (team leads excluded unless they also hold one of those roles).

### BRF-023

During the active event window, targeted Action Plan sections may surface as banners on a fixed allowlist of product surfaces. Free-form URL or arbitrary-page targeting shall not be supported. Department-leads-only sections shall banner only to users who can see them.

### BRF-024

Publishing or updating an Action Plan may spawn Notices.

### BRF-025

IC operators/leads shall be able to create Notices manually. Notices may also be auto-created from Action Plan publish/update events.

### BRF-026

Notices shall appear in The Briefing hub and as dismissible per-user alerts. Notices may be event-wide or targeted and may carry an optional expiry.

### BRF-026A

Notices may be marked **department leads only**. When so marked, they shall be visible only to department leads for the event, Command, and organizers (team leads excluded unless they also hold one of those roles).

### BRF-027

Alpha 1 shall implement Notes create/list/detail with immutability and author+Command visibility, Command add-to-Briefing by reference or link with event-staff or department-leads-only audience, Briefing hub display of added Notes to permitted viewers, Orchid/admin scaffolding for Notes, and empty shell/placeholder sections for AARs, Directions, Action Plans, and Notices.

### BRF-028

Alpha 1 shall not require full Submission/Final AAR workflows, AAR Note inclusion UI beyond what is needed for Briefing add, Directions deep-link authoring, Action Plan banners, or Notice alert delivery. Those behaviors remain specified for post–Alpha 1 implementation.

### BRF-029

Note and Briefing create/mutate operations during the active event window shall follow event authority rules (on-site primary authoritative for event-scoped operational records).

### BRF-030

Note create, Briefing/AAR Note add (reference or link), AAR submit/publish, Direction/Action Plan/Notice mutations, Notice dismissals, and freeze actions shall be audited.

---

## 7.20 Branding and Theming Requirements

### BRAND-001

Meridian shall support an organization branding profile defining an organization display name, organization logo assets, and an organization color palette.

### BRAND-002

The organization branding profile shall replace the Meridian display name and Meridian mark on signed-in product surfaces, including the application header and Home control, the browser/document title, generated PDF exports, and system-generated email.

### BRAND-003

Meridian identity shall remain on pre-authentication surfaces (login, magic-link landing, node first-run setup), the Orchid administrative interface, and desktop application chrome and installers. Organization branding shall not replace Meridian identity on those surfaces.

### BRAND-003A

The one exception to the desktop chrome rule is the running window and taskbar icon of a desktop or Kiosk application that is locked to an event. While the application is locked to an event, that icon shall show the organization compact mark, falling back to the organization full lockup and then to Meridian's mark.

This is deliberately narrow. The packaged application icon, the installer, the executable metadata, and the application name shown by the operating system remain Meridian's, because they identify the software rather than the deployment, and they must stay correct on a machine that is not currently running an event and on a machine serving more than one organization. An unlocked desktop or Kiosk application shows Meridian's icon.

### BRAND-004

Organizations shall be able to upload, replace, and remove one current full logo lockup and one current compact mark. Meridian does not need to preserve previous logo assets after replacement or removal.

### BRAND-005

If an organization has no logo asset for a required form, Meridian shall render a generated lettermark using initials or letters from separate words in the organization display name.

### BRAND-006

Organizations shall define the four platform palette colors (primary, secondary, tertiary, accent) and the neutral set (canvas, surface, foreground, muted foreground, border, focus).

### BRAND-007

Action, status, severity, attention/priority, chart series, and department accent tokens shall continue to resolve through the organization platform palette. They shall not be independently settable.

### BRAND-008

The organization branding profile shall apply to every event, department, UI mode, and presentation profile in that organization, except where a department branding override applies.

### BRAND-009

Departments shall be able to define a department branding profile containing a department logo, one department accent color, and one department surface background color.

### BRAND-010

The department logo shall appear in the application header while the user is in that department's context, in the department identity badge, and on department-scoped surfaces. If the department has no logo, Meridian shall render a generated lettermark from the department name.

### BRAND-011

Departments shall not override foreground/text, border, focus/highlight, status, severity, attention/priority, or chart colors. Those values shall resolve from the organization palette.

### BRAND-012

A department surface background override shall apply only to department-scoped surfaces. It shall not apply to incident/IMS surfaces, The Briefing, or organization-level and cross-department surfaces.

### BRAND-013

Organizations shall be able to disable department branding overrides for the entire organization. When disabled, departments retain logo and accent identity only.

### BRAND-014

Meridian shall validate every submitted branding color combination server-side and shall reject any combination that fails WCAG 2.1 AA contrast for its intended use, requiring at least 4.5:1 for normal text, 3:1 for large text, and 3:1 for non-text user interface and graphical indicators.

### BRAND-015

A rejected branding submission shall identify the failing color pair, the measured contrast ratio, and the required ratio.

### BRAND-016

Meridian shall not silently adjust, auto-correct, or auto-derive submitted branding colors. Invalid combinations are rejected rather than repaired.

### BRAND-017

Branding shall never become the sole carrier of state or status meaning. Canonical status labels, iconography, and structural communication shall remain unchanged by any branding profile.

### BRAND-018

Branding administration surfaces shall present a preview of the resulting appearance and the contrast validation result before the change is saved.

### BRAND-019

Only organizers and Lead Organizers shall edit an organization branding profile. Department administration and department leads shall edit only their own department branding profile.

### BRAND-020

Branding profile create, update, and asset removal operations shall be audited.

### BRAND-021

Branding profiles are organization governance data. The central node shall be authoritative for branding, and branding edits shall be blocked during the active event window under the same governance edit rules that apply to policy and procedure documents.

### BRAND-022

Branding assets and palette values shall sync to on-site nodes and permitted offline devices, and shall render from cache when a device is offline.

### BRAND-023

Logo uploads shall be constrained by permitted MIME type and maximum file size, and shall be stored and served through the existing attachment path.

### BRAND-024

Typography customization shall not be part of this scope. Organizations and departments shall not select or upload fonts.

### BRAND-025

Teams shall be able to define a team logo. Teams shall not define an accent color, a surface background color, or any other branding value; those shall resolve from the department and organization branding profiles. A team logo shall be edited under the same authority as the department branding profile of the team's department.

### BRAND-026

The team logo shall appear where a team is identified on its own: the team overview, team lists and pickers, and team rosters. If the team has no logo, Meridian shall render a generated lettermark from the team name.

### BRAND-027

The department logo shall appear in the application header beside the department name and the event name, and the logos of the other departments the signed-in user belongs to shall appear in the header as controls that switch department context.

### BRAND-028

Events shall be able to define an event logo. Events shall not define an accent color, a surface background color, or any other branding value; those shall resolve from the organization branding profile. An event logo shall be edited under the same authority as the organization branding profile.

### BRAND-029

Where an install is locked to an event and that event has a logo, that logo and the event name shall replace the organization mark and name in the application header, the browser tab icon, and the document title, and the logo shall replace the organization mark in the desktop window icon. Where the install is not locked to an event, or the locked event has no logo, the organization mark and name shall be shown, falling back to Meridian's where the organization has no branding profile. Whether an install is locked to an event shall be read from the node, not from the signed-in user.

### BRAND-030

The mark and the name shall always identify the same party. A surface shall not present one party's mark beside another party's name.

### BRAND-031

Event branding shall not extend beyond the chrome named in BRAND-029. The organization palette, generated PDF exports, and system email shall continue to carry organization identity, every surface that is not event-locked shall continue to carry organization identity, and the surfaces BRAND-003 protects shall continue to carry Meridian's.

---

## 7.21 God Mode Console Requirements

### GOD-001

The God Mode console shall present Meridian's own identity, terminology, and content. It shall not present administrative framework branding, framework documentation, or framework release information as if it were Meridian's.

### GOD-002

The God Mode landing screen shall replace the framework welcome content with a Meridian orientation summary and an attention list.

### GOD-003

The orientation summary shall explain in brief bulleted form how Meridian works end to end, covering organizations and departments, events and the active event window, staff, teams, shifts and eligibility, operations, attendance and hours, policies and acknowledgments, incidents, and the central/on-site node model with its sync authority rules.

### GOD-004

The orientation summary shall state that God Mode is repair and break-glass tooling, and that normal organizer, department, and staff workflows belong in Meridian Admin.

### GOD-005

The God Mode landing screen shall surface items requiring attention in three groups: deployment and configuration readiness, organizational data gaps, and unresolved sync conflicts.

### GOD-006

Deployment and configuration readiness shall report node configuration completeness, node role and pairing state, presence of required secrets, secure connection policy status, and PowerSync connectivity.

### GOD-007

Organizational data gaps shall report organizations without departments, organizations without a configured Organizers Department, organizations and events without a resolvable Incident Command Department, organizations without an active Lead Organizer, and events without assigned departments.

### GOD-008

Unresolved sync conflicts shall report the outstanding conflict count and link to the conflict resolution screen.

### GOD-009

Every attention item shall link to the screen where it can be resolved.

### GOD-010

Attention items shall reflect current state at view time and shall not perform mutations as a side effect of being displayed.

### GOD-011

When no attention items exist, the landing screen shall state that explicitly rather than rendering an empty region.

### GOD-012

The God Mode console shall provide a Documentation page served from Meridian's own operator documentation, replacing the external framework documentation link.

### GOD-013

Technician documentation shall be maintained in the repository under `docs/technician/` and shall be written for node technicians and God Mode users, covering deployment, node setup and pairing, configuration and config source resolution, data repair, sync conflict resolution, and break-glass procedures.

### GOD-014

The Documentation page shall render operator documentation from content packaged with the deployment. It shall not require network access and shall not fetch documentation from an external service.

### GOD-015

The Documentation page shall not serve the requirements document, technical specification, data/API specification, UI documentation, QA scripts, architecture decision records, development plan, traceability matrix, or issue documents.

### GOD-016

The Documentation page shall provide a document index, render Markdown headings, lists, tables, and fenced code, and allow filtering documents by title and heading.

### GOD-017

The Documentation page shall display the packaged operator documentation version alongside the running build version so an operator can tell whether the documentation matches the deployment.

### GOD-018

The God Mode console shall provide a Changelog page describing Meridian releases, replacing the external framework changelog link.

### GOD-019

Changelog entries shall be derived from merged pull requests, using pull request title, body, number, merge date, and author, and shall be grouped under the Meridian version number in which each change shipped.

### GOD-020

Every merged pull request shall appear in the Changelog. Changelog content shall not be filtered by conventional-commit type or change category.

### GOD-021

A release build step shall generate a changelog data file from repository history and package it with the deployment, so the Changelog page renders completely without network access.

### GOD-022

When the central node has network access and a configured source-repository credential, the Changelog page shall refresh from the source repository and merge newer entries into the packaged baseline.

### GOD-023

Changelog refresh shall never be required for the page to render. Absent network, missing credential, or failed refresh shall degrade to the packaged baseline and display the time of the last successful refresh.

### GOD-024

Only the central node shall perform changelog refresh. On-site nodes shall render the packaged baseline.

### GOD-025

Changelog refresh shall not block page rendering and shall not be performed during the active event window.

### GOD-026

Source-repository credentials used for changelog refresh shall be stored through the existing configuration mechanism, shall be read-only in scope, and shall not be displayed in the console or written to logs.

### GOD-027

Documentation and Changelog shall be console pages within Meridian, not external links, and shall not open an external browser context.

### GOD-028

Version information displayed in God Mode navigation shall be the Meridian build version, not the administrative framework version.

### GOD-029

The God Mode console shall present Meridian's visual identity rather than the default appearance of the administrative framework it is built on.

### GOD-030

The console shall display the Meridian logo in its navigation and authentication surfaces, including a compact mark form for the collapsed navigation state.

### GOD-031

The console shall serve the Meridian favicon.

### GOD-032

The console footer shall state the Meridian license, a copyright range of 2026 to present, and the Meridian build version. It shall not state the administrative framework's license, copyright range, or version.

### GOD-033

The console footer license statement shall match the license the repository is actually published under.

### GOD-034

The console shall resolve its surface, foreground, border, focus, and action colors, its typography scale, and its spacing from the shared Meridian design tokens rather than from framework defaults.

### GOD-035

The console shall use Meridian's own default palette and shall not adopt an organization branding profile, consistent with BRAND-003.

### GOD-036

Console restyling shall not reduce any text, control, or focus indicator below the contrast and visibility requirements in the accessibility checklist.

### GOD-037

Console restyling shall be applied through supported framework configuration and template extension points wherever those exist, so that framework upgrades remain possible without reapplying the visual identity by hand.

### GOD-038

Authentication, logout, and node first-run setup surfaces shall present the same Meridian visual identity as the console.

---

## 7.22 Client Session and API Binding Requirements

These requirements govern how a Meridian client application establishes a session, learns what its user is permitted to do, resolves the organization, event, and department it is operating within, and reaches the API. They cover the web client, the mobile Field application, and the desktop application.

They do not add domain behavior. Every surface named here already has requirements elsewhere in this document; these requirements state that the client must be driven by the server rather than by built-in fixture data.

### CLIENT-001

A Meridian client application shall obtain the identity, effective roles, and permission capabilities of its user from the server. It shall not derive them from bundled fixture data, build-time configuration, or hardcoded identifiers.

### CLIENT-002

Meridian shall expose an authenticated endpoint that returns, for the calling user: the user's identity, the effective role codes the user holds, the permission capability codes those roles carry, and the organizations, events, departments, and teams the user is associated with.

### CLIENT-003

The endpoint in CLIENT-002 shall return role codes and permission capability codes as published by the permission catalog. It shall not return precomputed navigation decisions, screen lists, or menu structures.

### CLIENT-004

A client shall derive navigation, available actions, and surface availability from the permission capabilities it receives, applying the existing UI rules that unavailable actions are generally hidden and that permission-denied surfaces explain the required role to elevated users and state only that access is restricted to default staff.

### CLIENT-005

A client shall not present a surface, action, or navigation entry for which the user holds no permitting capability, and shall not rely on the server rejecting the request as the only enforcement.

### CLIENT-006

Server-side authorization shall remain the enforcement boundary. Client-side capability checks are presentation, and a client that fails to hide an action shall still be refused by the server.

### CLIENT-007

A client shall cache the most recent response from CLIENT-002 durably, and shall use the cached response to establish navigation and permissions when the node is unreachable.

### CLIENT-008

The cached response shall remain usable for the duration of the event the node is locked to. Once that event window has ended, or when the client holds no event context, the client shall require a successful refresh before granting access.

### CLIENT-009

A client operating from a cached response shall indicate that its permissions are cached and shall record when they were last refreshed.

### CLIENT-010

A client shall refresh the CLIENT-002 response on regaining connectivity, and shall apply any reduction in permissions immediately.

### CLIENT-011

A client shall resolve its organization and event context from the node it is connected to when that node is locked to an event, and shall narrow that context to the user's own departments and teams from the CLIENT-002 response.

### CLIENT-012

A connected client shall allow a user who belongs to more than one event, or to more than one organization, to switch to any other event or organization in which that user holds an association.

### CLIENT-013

A client without connectivity shall be locked to the context the node provides and shall not offer switching.

### CLIENT-014

Switching organization or event shall re-resolve permissions, navigation, branding, and cached context, and shall not leave data from the previous context visible.

### CLIENT-015

A client shall submit every command through a durable local queue, so that a command issued without connectivity is retained and transmitted when connectivity returns.

### CLIENT-016

Each queued command shall carry a client-generated idempotency key, and the server shall treat a repeated key as the same command rather than as a new one.

### CLIENT-017

A client shall show the user which of their commands are queued, which have been accepted, and which have been rejected, and shall not silently discard a rejected command.

### CLIENT-018

Commands that the technical specification restricts to connected operation shall be refused at queue time rather than queued and rejected later.

### CLIENT-019

Authenticated file downloads, including exports, generated documents, and attachments, shall be reached through a short-lived server-issued URL requested by the authenticated client, rather than by placing credentials in a link.

### CLIENT-020

A short-lived download URL shall expire, shall be scoped to the single resource it was issued for, and shall be subject to the same authorization as a direct request for that resource.

### CLIENT-021

Offline data replicated to a device shall be limited to what the device's user is permitted to read. A device shall not receive records its user could not retrieve through the API.

### CLIENT-022

A change to a user's effective roles shall change what subsequently replicates to that user's devices.

### CLIENT-023

Client surfaces for organization administration, departments and teams, trainings, equipment, shifts, documents, incident management, attendance and logistics, and reporting exports shall read from and write to the Meridian API rather than to bundled fixture data.

### CLIENT-024

Removal of fixture-driven behavior shall not remove the ability to run the client's automated tests without a live server.

---

## 7.23 Insights Requirements

Insights are live compiled views that help authorized users understand whether current operations are meeting expectations and where attention may be required. They are distinct from Reports, which remain fixed, formal, or historical outputs.

### Framework

#### INSIGHT-001

Meridian shall provide Insights as live compiled views of current authorized operational data, distinct from Reports.

#### INSIGHT-002

Insight Metric types shall be defined by developers and registered as data. Organizations shall not author metric logic, formulas, or arbitrary queries.

#### INSIGHT-003

An Insight Metric shall be reusable and may be placed on more than one Insight Sheet.

#### INSIGHT-004

Each placement of an Insight Metric on a sheet shall carry its own configuration, supported by that metric type.

#### INSIGHT-005

An Insight Sheet shall be owned by the organization and shall contain an ordered arrangement of any number of Insight Metric placements.

#### INSIGHT-006

A sheet may combine metrics from different operational domains.

#### INSIGHT-007

Insights shall be quantitative. Insights shall not carry user-authored commentary, recommendations, conclusions, or narrative annotation.

#### INSIGHT-008

Insights shall not create persistent saved views or stored results.

### Scope and context

#### INSIGHT-009

An Insight Sheet shall read data from one selected event at a time.

#### INSIGHT-010

Insights shall be available within organization, event, and department usage contexts.

#### INSIGHT-011

A sheet shall render organization-wide or department-scoped data according to the viewer's authorization and selected department context, so the same sheet renders different data for different viewers.

#### INSIGHT-012

An authorized user may switch department context, and the sheet shall re-render for the newly selected department.

### Filters

#### INSIGHT-013

Filters shall be configured at the sheet level, and the sheet definition shall determine which supported filters are offered to viewers.

#### INSIGHT-014

Individual metrics shall not carry independent user-facing filters.

#### INSIGHT-015

A viewer's filter selections shall be remembered for that viewer for the current session and are not required to persist across sessions.

### Authorization

#### INSIGHT-016

Insights authorization shall use the existing Meridian permission model. Insights shall not introduce a parallel permission system.

#### INSIGHT-017

Organizers may access Insights across departments for the selected event, subject to domain restrictions defined elsewhere.

#### INSIGHT-018

A department lead who is not also authorized through a team under the Organizers Department shall see only data for the department being viewed, and may switch among departments they are authorized to lead.

#### INSIGHT-019

Command shall see Insights for Command's own department and content explicitly shared with Command by another department. Command shall not automatically receive access to every department's Insights.

#### INSIGHT-020

Planning and Logistics shall see only their own department's data unless another permission independently grants broader access.

#### INSIGHT-021

A user with permission to use Insights may create and configure organization-owned Insight Sheets. The data rendered on those sheets shall remain limited by that user's authorization.

#### INSIGHT-022

A user shall not be able to configure a sheet in a way that exposes data they are not authorized to access.

#### INSIGHT-023

Authorization shall be enforced server-side in policies, query handlers, API responses, Orchid, and synchronization rules. Hiding a control in the interface shall not be the enforcement mechanism.

### Restricted data

#### INSIGHT-024

Insights shall not display personally identifiable information.

#### INSIGHT-025

Insights shall not display the names of individual volunteers to department leads, Command, Planning, Logistics, or organizers.

#### INSIGHT-026

Insights shall not provide individual-level drilldown into another person's data.

#### INSIGHT-027

Insights shall not display data that bypasses restrictions already defined for the source domain.

#### INSIGHT-028

An aggregate covering fewer than 5 people shall be suppressed rather than displayed, so that an aggregate cannot effectively identify one person. Suppression shall state that the value is withheld for privacy rather than render as empty or zero.

#### INSIGHT-029

A sheet or metric drawing on a restricted domain shall be hidden from users who cannot access that domain. Metrics derived from incidents or Field Reports shall be visible only to members of the designated Incident Command Department holding the applicable Incident Command permissions.

#### INSIGHT-030

Unauthorized sheets shall be hidden rather than presented as empty or inaccessible navigation entries.

### Personal volunteer Insights

#### INSIGHT-031

A volunteer shall be able to see their own event-level aggregate metrics: completed shift count, missed shift count, late arrival count, hours worked, hours worked broken down by team, and credits earned for the event.

#### INSIGHT-032

The personal Insights view shall not authorize any user to inspect another person's individual-level data, and shall not provide peer comparison, ranking, or visibility into another volunteer's metrics.

### Metric state

#### INSIGHT-033

An Insight Metric shall communicate whether conditions require attention and shall equally communicate when conditions are healthy or meeting expectations.

#### INSIGHT-034

Evaluation thresholds shall be fixed system rules. Organization-configurable thresholds are not required.

#### INSIGHT-035

Insight states shall not require dismissal, acknowledgement, assignment, or resolution, and Insights shall not introduce a task-management workflow.

#### INSIGHT-036

A metric's state shall reflect current conditions. A metric may continue to display after a problem is corrected, but shall then show that expectations are being met.

#### INSIGHT-037

A metric shall link to the operational surface where an authorized user can investigate or act, and following that link shall not bypass authorization.

### Live data, synchronization, and offline

#### INSIGHT-038

Insights shall refresh after each synchronized change to the underlying data.

#### INSIGHT-039

Insights shall remain available offline, compiled from the data currently available on the device.

#### INSIGHT-040

Insights shall disclose what the system knows about data quality and freshness, including stale, waiting to sync, incomplete, offline, and last synchronized time as applicable.

#### INSIGHT-041

Insights shall not present incomplete or stale data as though it were current and complete.

#### INSIGHT-042

Insight evaluation shall not circumvent synchronization rules, local data rules, upload validation, command handlers, or authorization policies.

#### INSIGHT-043

Insights shall compile current authorized domain data and shall not store calculated results as canonical records.

### Sharing with Command

#### INSIGHT-044

A department lead may share an entire Insight Sheet with Command, or an individual metric placement from a sheet with Command.

#### INSIGHT-045

Sharing may be configured as an ongoing setting or enabled temporarily for the event.

#### INSIGHT-046

A temporary share shall stop applying when the event's operations window closes, and may be removed earlier by the sharing department.

#### INSIGHT-047

Shared content shall clearly identify its originating department.

#### INSIGHT-048

A department may stop sharing previously shared content.

#### INSIGHT-049

Sharing and unsharing shall be audited.

#### INSIGHT-050

Sharing shall not grant Command access to data Command is otherwise prohibited from seeing, and sheet-level sharing shall not expose a restricted metric contained on that sheet.

#### INSIGHT-051

Metric-level sharing shall apply to the shared metric placement only, not to every use of the registered metric type.

### PDF snapshots

#### INSIGHT-052

An authorized user may save the current Insight Sheet view as a PDF generated in the browser and downloaded to the user.

#### INSIGHT-053

The PDF shall represent what the user is viewing, including the visible metrics, selected filters, current metric states, freshness and synchronization disclosures, organization, selected event, selected department where applicable, sheet name, generation timestamp, generating user, and the originating department of shared content where applicable.

#### INSIGHT-054

Meridian shall not store the PDF, shall not create a snapshot entity, and shall not provide snapshot history or retrieval.

#### INSIGHT-055

The PDF shall not include personally identifiable information or unauthorized individual information, and shall carry the same suppression applied to the rendered view.

### Navigation and organization

#### INSIGHT-056

Insights shall appear in primary navigation.

#### INSIGHT-057

The Insights landing page shall show all sheets available to the current user.

#### INSIGHT-058

A user may favorite or pin sheets they use frequently.

#### INSIGHT-059

Role-specific default sheets are not required.

### Initial metrics

#### INSIGHT-060

Meridian shall provide a missed shifts Insight Metric, using the automatic no-show determination in SLB-023 through SLB-029.

#### INSIGHT-061

Meridian shall provide an equipment not returned Insight Metric. Equipment is not returned when it is explicitly marked missing, when shift-assigned equipment remains checked out after the associated shift has ended, or when event-assigned equipment remains checked out after the event has ended.

#### INSIGHT-062

Meridian shall provide an extended shift presence Insight Metric identifying how many people remain on shift beyond their scheduled time. Its detailed calculation is designed with the metric and is not specified here.

---

## 7.24 Notification Requirements

Meridian sends transactional email. It does not send SMS or push notifications, and it is not a messaging platform: a notification tells a staff member that something happened to a record they are party to, and points them at the surface where they can act on it.

### NOTIFY-001

Meridian shall send transactional email for the operational events that change what a person may do and that the person would otherwise have no reason to check for.

The MVP notification set is:

- application approved
- application rejected
- application deferred
- staff member added to a department
- staff member added to a team
- required document acknowledgment outstanding

- required waiver outstanding or expired
- credential eligibility blocked
- staff removed from a shift by a lead
- shift cancelled

### NOTIFY-001A

One operational action shall produce at most one notification.

Because a staff member cannot belong to a department without belonging to a team (VOL-006), department addition and team addition occur together on first assignment. That pair shall send one notification naming both the department and the team. A later team addition within a department the staff member already belongs to shall send its own notification.

### NOTIFY-002

An application auto-rejected due to Do Not Staff shall send no notification (STAT-006). No notification shall disclose Do Not Staff status, and no notification shall disclose the existence of a staff record to a sender who is not its subject.

### NOTIFY-003

Notification email shall carry organization identity per BRAND-002: the organization display name, the organization mark, and organization palette values, falling back to Meridian's identity where the organization has no branding profile.

### NOTIFY-004

Notification content shall be human-readable operational language and shall name the event, organization, and department the notification concerns.

A notification shall link to the Meridian surface where the recipient can act, and following that link shall not bypass authentication or authorization.

### NOTIFY-005

Notifications shall be addressed to a user's verified primary email address, or to the application email address where the recipient has no user account.

An unverified address shall not receive notification email.

### NOTIFY-006

Notification delivery shall be queued and shall not block the operation that caused it. A delivery failure shall not roll back the underlying operation.

### NOTIFY-007

Notification sends shall be recorded with the recipient, notification type, subject record, and delivery outcome, so an operator can answer whether a person was told. Message bodies shall not be retained in the audit trail.

### NOTIFY-008

Only the central node shall send notification email. An on-site node shall queue notifications generated during the active event window and shall hand them to central through the existing node sync path.

### NOTIFY-009

Meridian shall support a configured send-suppression switch per organization and a global development suppression, so a test deployment and a restored backup do not mail real people.

Suppression shall be visible in the God Mode console readiness surface.

### NOTIFY-010

Per-user notification preferences, digests, opt-out categories, and notification history surfaces are out of scope for MVP. Every notification in NOTIFY-001 is transactional and is sent when its event occurs.

---

## 7.25 Public Platform Surface Requirements

### PUBLIC-001

Meridian shall serve a public marketing surface at the deployment root describing the platform to organizations that do not yet use it.

The marketing surface shall carry Meridian identity and shall not resolve an organization branding profile (BRAND-003).

### PUBLIC-002

The marketing surface shall present an organization interest form collecting the prospective organization name, a contact name, a contact email, and a free-text description of what the organization runs.

### PUBLIC-003

An organization interest submission shall be stored as an inquiry record. It shall not create an organization, a user, a staff record, or any operational data.

### PUBLIC-004

Organization interest submissions shall be reviewable in the God Mode console. Organization creation shall remain a God Mode action for MVP.

### PUBLIC-005

The organization interest form shall be rate limited and shall be protected against automated submission without requiring the submitter to solve a challenge that blocks legitimate use. Submissions shall be audited.

### PUBLIC-006

The marketing surface shall not be served by an on-site node, and shall not be reachable when the node is locked to an event.

---

## 7.26 System Configuration and Diagnostics Requirements

### SYS-001

The Meridian server's `.env.example` file shall be the catalogue of environment variables known to Meridian. A variable absent from `.env.example` is not a catalogued variable.

### SYS-002

The catalogue shall derive each variable's label, description, section, example value, data type, Laravel configuration key mapping, secret status, required status, bootstrap-lock status, managed status, and activation requirement from `.env.example` content and its structured `@tag` metadata comments. No separately maintained registry of environment variables shall exist.

### SYS-003

Catalogue sections shall come from `## Heading` lines and descriptions from the contiguous comment block above each variable. Commented-out variables of the form `# NAME=value` remain catalogued.

### SYS-004

The catalogue shall not infer types or configuration-key mappings where inference could change configuration semantics. A variable without a declared `@config` mapping shall be treated as unmapped and shall not be overridable from the database.

### SYS-005

Meridian shall support node-local database overrides for editable catalogued variables. Overrides shall be applied to Laravel's runtime configuration repository at application boot, and application code shall continue to read settings through `config()`. Effective precedence shall be: database override, then process environment / `.env`, then Laravel configuration default.

### SYS-006

Override values shall be validated against the variable's declared type before saving, and again before being loaded at boot. An override that fails validation shall never reach the runtime configuration repository.

### SYS-007

Override storage shall preserve the distinction between a missing value, an empty string, `null`, `false`, `0`, and the string `"0"`. Values shall not be flattened to untyped strings.

### SYS-008

Each variable shall carry an activation class: bootstrap-locked, active for new requests and newly booted processes, requires worker restart, or requires service restart/redeployment. The UI shall display the activation requirement, and a saved override that is not yet effective in the running process shall be shown as pending activation rather than active.

### SYS-009

Removing or disabling an override shall restore the normal environment/`.env` or Laravel default value at the next activation point.

### SYS-010

Bootstrap-locked variables - the application key, primary database credentials, cache and session bootstrap stores, and node signing identity - shall be visible in the catalogue but shall never be overridable from the database, even when a stored row claims otherwise.

### SYS-011

System configuration overrides and secret values shall never be replicated through PowerSync device sync.

### SYS-012

System configuration overrides shall be node-local. They shall not be carried by node-to-node sync, and central overrides shall not be copied to on-site nodes automatically. Overrides shall keep working while the node is offline.

### SYS-013

Secret values shall be encrypted at rest and masked in every table, detail screen, log line, export, and CLI output. No Meridian surface shall display a stored secret's plaintext.

### SYS-014

A user authorized to manage secret configuration may replace, disable, or remove a secret override but shall not be able to retrieve the stored plaintext. Secret presence shall be described with states such as configured, overridden, missing, invalid, or pending activation.

### SYS-015

Every override create, change, disable, enable, and removal shall be audited through the standard audit service with actor, node, variable name, action, redacted values, secret status, and change reason. Secret plaintext shall never be stored in audit records. Changing a secret shall require a change reason.

### SYS-016

The System Configuration screen shall show, for each catalogued variable: name, label, section, effective value, effective source, override state, mapped configuration keys, type, secret indicator, editability, activation requirement, validation status, and last-change metadata.

### SYS-017

Effective sources shall be truthful. Because Laravel loads `.env` into the process environment, the two shall be presented as one combined source ("Environment / .env"), alongside Database Override, Laravel Default, Missing, Invalid, and Unmapped.

### SYS-018

The configuration table shall support search by name, label, section, and configuration key, and filtering by section, source, override state, secret status, editability, bootstrap lock, validation state, and unmapped state.

### SYS-019

Bootstrap-locked variables shall render read-only with an explanation of why, and with the deployment-level change path stated.

### SYS-020

Variables managed by another Meridian surface (node identity and pairing state managed by Node Configuration) shall render read-only on the configuration screen with a pointer to that surface, so their side effects run where they are defined.

### SYS-021

Editing a critical or secret setting shall present a prominent warning stating the expected effect and the restart/redeployment steps required, and shall require a change reason for secrets.

### SYS-022

The application shall remain bootable when the override table cannot be read: it shall log one sanitized error naming the exception class only, continue on environment configuration, avoid re-querying during the same boot, and surface the failure as a critical diagnostics result. Invalid or undecryptable overrides shall be skipped and reported.

### SYS-023

Parsed catalogue metadata shall be cached and invalidated when the Meridian build version or the `.env.example` file changes. Secret plaintext shall not be cached outside normal process memory.

### SYS-024

Capabilities for viewing system configuration, managing system configuration, managing secret configuration, viewing configuration audit history, viewing system diagnostics, and exporting sanitized diagnostics shall be separate console permissions.

### SYS-025

System configuration and diagnostics permissions shall default to God Mode operators. Organization, organizer, department, and team roles shall never receive infrastructure administration capabilities through the organization permission model.

### SYS-026

Viewing system configuration shall not grant changing it, and managing non-secret configuration shall not grant changing secrets.

### SYS-027

Exporting the sanitized diagnostics bundle shall require its own capability, enforced at the endpoint rather than only in the UI.

### SYS-028

System Configuration and System Diagnostics shall be separate screens. The diagnostics screen shall not display environment or configuration values.

### SYS-029

Diagnostics shall be implemented as independent checks behind a common contract with a stable key, label, category, required/optional flag, and a run method returning a status, summary, sanitized details, and recommended action. One check failing shall not stop the run.

### SYS-030

Supported check statuses shall include healthy, warning, critical, unknown, and not applicable. Each completed check shall record its duration and timestamp.

### SYS-031

Overall node health shall be derived consistently from individual results: a critical result from a required check makes the node critical; a critical result from an optional check, any warning, or a required check that could not run degrades the node to warning; not-applicable results are ignored. One failed optional integration shall never mark the installation critical.

### SYS-032

Checks shall be read-only against Meridian state and non-destructive against external services: no messages sent, no external resources created, and probe files cleaned up. A check that cannot measure something - worker liveness, host metrics without privileged access - shall report unknown rather than pretending it ran.

### SYS-033

Diagnostics shall cover, where applicable to the install: application runtime and build state, security configuration warnings, override loading, database availability and latency, cache and session stores, queue backlog and failed jobs, scheduler liveness, storage writability and disk space, required providers/bindings/routes, PowerSync reachability, node-to-node sync state, node identity and keys, and configured external integrations.

### SYS-034

Authorized users shall be able to export a sanitized JSON diagnostics bundle carrying build and version information, node identity and type, check results, and configuration-source metadata.

### SYS-035

The diagnostics export shall never contain secret values, tokens, passwords, keys, cookies, session identifiers, connection strings, or personally identifiable operational data. Non-secret configuration shall export as source and status metadata, not raw values. Automated tests shall prove known secrets are redacted.

### SYS-036

An intentionally offline on-site node shall not be presented as failed. Queued sync operations on an offline on-site node shall be presented as expected offline operation, and only failures, refusals, and open conflicts shall ask for attention.

### SYS-037

Each node shall periodically build a sanitized health report, store it locally, and deliver it to its paired central node signed with the node private key over the node-authenticated report channel. The central installation shall display the latest report per known node.

### SYS-038

Central shall verify each received report's origin node, pairing status, freshness, and signature against the public key learned at pairing, and shall refuse and audit anything unverified. An unverified report shall never be stored.

### SYS-039

A node health report shall carry only node identity, versions, overall and per-category statuses, numeric sync/disk/memory summaries, and sanitized warning lines. It shall never contain environment values, secrets, credentials, connection strings, tokens, user data, incident data, field-report content, or volunteer data.

### SYS-040

Node health reports older than the staleness window shall be labelled stale rather than silently trusted.

### SYS-041

CLI diagnostics (`meridian:diagnostics`) shall exit non-zero when any required check is critical so container health checks and deployment tooling can gate on it, and shall apply the same redaction rules as every other surface. CLI commands shall also list the configuration catalogue and validate stored overrides.

---

# 8. Deferred / Future Scope

The following concepts are acknowledged but deferred beyond October MVP:

## Full Equipment Ownership and Allotments

Future versions may support:

- organization-owned equipment
- department-owned equipment
- event-owned equipment
- department-to-department allotments
- serial-numbered inventory
- custody history
- equipment managers via team permissions

## Provision Inventory

Future versions may support:

- inventory counts
- issue/revoke behavior
- issued-in-error tracking
- repeatability rules
- per-event provision policies

## Deployment History

Future versions may preserve full movement history across deployments during a shift.

MVP only tracks current deployment/location.

## Advanced Organizer Governance

Future versions may add more safeguards against malicious organizer removal.

MVP includes:

- Lead Organizer role
- removal audit
- final Lead Organizer cannot be removed

## Advanced Training Modeling

Future versions may support:

- equivalencies
- external certifications
- complex prerequisite chains
- more detailed trainer workflows

MVP only requires training completion, expiration, prerequisites, and import/manual entry.

## Advanced Permissions

Future versions may support more granular team-based permission management.

MVP should avoid free-floating permissions and keep authority tied to organization, department, and team membership.

## Advanced Policy and Procedure Configuration

Future versions may support:

- configurable policy/procedure form structure
- team-scoped acknowledgment requirements
- richer formatting beyond Markdown
- automatic packet assembly by scope or onboarding path
- acknowledgment status included in administrative exports
- behavior divergence between policy documents and procedure documents

## Advanced Event Geography and Maps

Future versions may support:

- in-app map drawing/editing beyond uploaded/imported assets
- georeferencing placement maps to real-world coordinates
- a `hybrid` map type if placement/topographic linking proves insufficient
- richer camp records (description, lead/contact, affiliation, public/private flags, additional placement metadata)
- camp/location aliases
- public/volunteer-facing map navigation and map correction workflows
- automatic geocoding
- additional map-derived permissions and effective-permission-level role codes for the Placement department

MVP only requires uploaded/imported maps, lightweight map metadata, camps with name and location, lightweight map locations, the Placement department designation, operations-window locking, optional IMS references, and offline sync to permitted devices.

## Advanced Branding and Theming

MVP branding is specified in section 7.20. Future versions may support:

- typography customization, whether by curated font list or uploaded font files, including the licensing, file-size, offline-delivery, and rendering-fallback handling that requires
- department override of the full color palette rather than accent and surface background only
- event-level branding profiles distinct from the organization profile
- per-UI-mode or per-presentation-profile branding variants
- light and dark palette variants defined independently by the organization
- automatic contrast repair or derived foreground selection, which MVP explicitly rejects in favor of blocking invalid combinations (BRAND-016)
- branding preview against a live sample of real product surfaces rather than a representative preview
- externally hosted or CDN-delivered branding assets

MVP only requires the organization and department branding profiles, the Meridian identity replacement rules, generated lettermark fallbacks, blocking contrast validation, and branding sync to permitted nodes and devices.

## The Briefing Beyond Alpha 1

The full Briefing and Notes product design is specified in section 7.19. Alpha 1 ships Notes, Command add-to-Briefing, and hub shells (BRF-027, BRF-028).

Post–Alpha 1 implementation shall deliver:

- Submission and Final AAR workflows, including day-30 and day-45 windows
- AAR Note inclusion UI (reference and link)
- Directions deep links and targeting
- Action Plan sections and allowlisted banners
- Notices and per-user dismissible alerts

---

# 9. Explicit Non-Goals

Meridian is not intended to be:

- payroll software
- an HR platform
- a disciplinary record system
- an employee management system
- a document-signing repository
- a full inventory management system in MVP
- a replacement for all physical event credentialing workflows
- a system that deletes operational history
- a full GIS, CAD, or emergency-dispatch system
- a live volunteer GPS tracking, turn-by-turn routing, or real-time personnel-tracking system
- a public, unauthenticated map sharing or public volunteer navigation system in MVP
- a public map correction platform
- a map-pin-centric brand identity
- a general-purpose editable collaborative notes wiki
- free-form banner injection onto arbitrary URLs

---

# 10. Open Notes

The current requirements are coherent enough to proceed from discovery into formal requirements refinement.

Architecture, implementation planning, database modeling, API design, and screen design remain intentionally out of scope until the requirements artifacts are accepted.

Briefing follow-ups that may need later refinement without blocking the Alpha 1 Notes + add-to-Briefing slice:

- exact Action Plan banner surface allowlist
- exact entity types permitted in Direction deep links
- exact Final AAR auto-assembly merge formatting
- whether organizers may author Directions/Action Plans/Notices without IC grants
- exact Orchid vs product-UI split for Briefing admin beyond Notes
- whether `ic_viewer` may add Notes to The Briefing or only read
