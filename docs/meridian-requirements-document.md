# Meridian Requirements Document

**Project:** Meridian  
**Type:** Open-source Volunteer Operations Platform  
**Phase:** Discovery / Requirements Gathering  
**Status:** Draft v0.3  
**Architecture:** Intentionally out of scope for this document
**Additive Update:** Policies and Procedures requirements added in v0.2.
**Additive Update:** Policies and Procedures discovery decisions incorporated in v0.3.

---

## 1. Purpose

Meridian is an open-source volunteer operations platform for events.

Meridian supports organizations that recruit, approve, coordinate, schedule, credential, track, and report on staff work across events and departments.

Meridian is not an HR system, payroll system, personnel file, or employee management platform.

All operational users are referred to as Staff, including organizers, department leads, shift leads, trainers, and incident users.

Staff may include unpaid volunteers and paid personnel. Meridian tracks participation, eligibility, credentials, hours, credits, and operational history, but payment, wages, payroll, and employment status remain outside Meridian's scope.

Meridian also supports policies and procedures as human-readable documents that may include reusable text fragments defined at organization, department, or team scope.

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

---

# 3. Glossary

## 3.1 Organization

An organization produces events and manages staff.

Example:

- Idaho Burners

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

- Idaho Decompression 2026

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

Existing active staff may apply to events to signal interest, but active staff can also be added to future events by department leads without submitting a new application.

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

Shift leads may edit actual start/end time during check-out.

Shift leads and department leads may correct hours after check-out during the organization-wide correction grace period.

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

Hours are recorded by shift leads or department leads.

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
- visibility on the Shift Lead Board
- manual correction when physical handoffs happen outside the system

MVP equipment states include:

- Available
- Checked out
- Returned
- Missing
- Damaged

Shift leads and department leads may check equipment in/out.

Department-to-department allotments are future scope.

Full inventory custody chains are future scope.

Equipment does not need to be tied to a shift for MVP.

---

## 3.19 Deployment / Location

A deployment or location is the current assignment of a staff member during a shift.

Deployments answer:

> Where are staff currently assigned?

For MVP, deployment tracking only needs to show the current deployment/location assignment.

Shift leads may assign and update a staff member's current deployment/location from the Shift Lead Board.

Staff may be moved between deployments/locations during a shift.

MVP does not need to preserve deployment movement history.

Future versions may preserve full deployment history.

---

## 3.20 Field Report

A field report is a single-perspective operational report written by an authorized staff.

A field report contains:

- event
- author
- report text
- appended entries, if any

Field reports are not private to the author.

Field reports are visible to:

- the author
- the event’s Incident Command Department

Field reports are not visible to non-IC department leads by default, even if their staff authored them.

Field reports cannot be edited.

Field reports cannot be stricken.

Only the author may append to their own field report.

If a field report contains incorrect, spammy, malicious, or disputed content, the expected response is to add ordinary incident notes where relevant. Meridian does not provide a special corrective-note mechanism for field reports.

Field reports may exist independently.

Field reports may be attached to one or more incidents.

When a field report is attached to an incident, the field report content is copied into the incident notes.

When a field report is appended to, only the added content is copied into associated incidents.

When a field report is removed from an incident, the incident history should show that relationship as stricken.

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

- shift lead check-in procedure
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

Department Leads cannot override organization-level blocking status.

Department Leads cannot access field reports or incidents unless they are part of the Incident Command Department or explicitly authorized.

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

Team Lead authority does not override Department Lead authority.

---

## 4.8 Shift Lead

A Shift Lead manages live shift operations.

Shift Leads may:

- view current shift roster
- check staff in
- check staff out
- edit actual start/end time during check-out
- correct hours during the grace period
- assign deployment/location
- move staff between deployments/locations
- view checked-in staff
- view equipment checked out
- check equipment in/out to individuals
- add eligible unscheduled staff to a shift
- access field report shortcut
- access incident shortcut

Shift Leads may not add staff to a department/team. If a staff member is not already in the relevant department/team, a Department Lead must add them first.

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

IC Department Leads are the only users who may print incident PDFs.

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
3. Application may also collect required profile/contact fields.
4. If applicant email matches DNS, application is auto-rejected without automatic notice.
5. Staff Coordinator or Organizer reviews application.
6. Application becomes one of:
   - Approved
   - Rejected
   - Deferred
   - Withdrawn
   - Auto-rejected due to DNS
7. If approved, applicant becomes Prospective at the organization level.
8. Approved applicant may be assigned to departments.
9. Department leads decide whether to accept the approved applicant into their department.
10. Department/team/training process determines when the staff member becomes Active.

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

1. A staff member arrives for shift.
2. Shift Lead opens Shift Lead Board.
3. Shift Lead views roster.
4. Shift Lead checks staff in.
5. Shift Lead may add eligible unscheduled staff to the shift.
6. Shift Lead assigns deployment/location.
7. Shift Lead checks out equipment to individual staff members if needed.
8. Shift work occurs.
9. Staff may move between deployments/locations.
10. Shift Lead updates current deployment/location.
11. Shift Lead checks staff out.
12. Check-out creates actual hours record.
13. Shift Lead may edit actual start/end time during check-out.
14. Equipment is checked back in.
15. Hours may be corrected during the grace period.
16. Credits are calculated after the grace period.

---

## 5.8 Unscheduled Shift Work

1. A staff member shows up or is needed for a shift without prior signup.
2. Shift Lead or Department Lead attempts to add staff to shift.
3. System checks:
   - staff member belongs to relevant department/team
   - required training complete
   - required waiver complete
   - status allows participation
4. If eligible, the staff member is added to shift.
5. The staff member may check in/out and earn hours.
6. Unscheduled work does not retroactively grant credential eligibility.

If the staff member is not in the relevant department/team, a Department Lead must add them first.

---

## 5.9 Hours and Credits

1. Shift Lead checks staff out.
2. Check-out creates hours record using actual start/end time.
3. Shift Lead or Department Lead may correct hours during grace period.
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
2. Field report records event, author, and report text.
3. Field report is visible to author and IC department.
4. Field report may exist independently.
5. Author may append additional entries.
6. Field report cannot be edited or stricken.
7. IC department may attach field report to one or more incidents.

---

## 5.11 Incident Management

1. IC department creates incident.
2. Incident receives IMS number.
3. Incident state, summary, type, location, tags, involved staff, notes, and attachments are managed.
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
- Shift Lead Board operations
- field report creation
- incident list/editor
- hours recording
- post-event credit calculation
- operational spreadsheet exports

---

## 6.2 MVP Screens

Priority operational screens:

1. Staff Application
2. Staff Coordination
3. Shift Lead Board
4. Incident List / Incident Editor
5. Field Report Creation

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

### Shift Lead Board

- current shift roster
- check-in
- check-out
- actual start/end time
- hour creation
- hour correction during grace period
- add eligible unscheduled staff
- current deployment/location assignment
- move staff between locations
- equipment checked out
- equipment checkout/check-in to individuals
- field report shortcut
- incident shortcut

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
- author can view own reports
- author can append
- IC department visibility
- not editable
- not stricken
- attach to incidents
- copied into incident notes

### Incidents

- incident list
- incident editor
- configurable states/types/tags
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

### Reports

- credential eligibility export
- shift roster export
- staff contact list export
- hours worked export
- credits earned export

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
- team-scoped policy/procedure acknowledgments for MVP
- nested fragments
- rich policy/procedure formatting beyond Markdown for MVP
- automatic policy/procedure packet assembly
- policy/procedure packet exports that include staff acknowledgment status

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

## 7.9 Shift Lead Board Requirements

### SLB-001

The Shift Lead Board shall show the current shift roster.

### SLB-002

The Shift Lead Board shall show checked-in staff.

### SLB-003

The Shift Lead Board shall allow staff check-in.

### SLB-004

The Shift Lead Board shall allow staff check-out.

### SLB-005

Check-out shall create an actual hours record.

### SLB-006

Shift leads shall be able to edit actual start/end time during check-out.

### SLB-007

Shift leads shall be able to correct hours during the correction grace period.

### SLB-008

The Shift Lead Board shall allow eligible unscheduled staff to be added to a shift.

### SLB-009

The Shift Lead Board shall allow deployment/location assignment.

### SLB-010

The Shift Lead Board shall allow staff to be moved between deployments/locations.

### SLB-011

The Shift Lead Board shall show equipment checked out.

### SLB-012

The Shift Lead Board shall support equipment checkout/check-in to individual staff members.

### SLB-013

The Shift Lead Board shall provide a field report shortcut.

### SLB-014

The Shift Lead Board shall provide an incident shortcut.

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

Shift leads and department leads may correct hours during the organization-wide correction grace period.

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

Field reports shall record author and report text.

### FR-004

Field reports shall be visible to the author.

### FR-005

Field reports shall be visible to the event’s Incident Command Department.

### FR-006

Field reports shall not be visible to non-IC department leads by default.

### FR-007

Field reports shall not be editable after submission.

### FR-008

Field reports shall not be stricken.

### FR-009

Only the author may append to their field report.

### FR-010

Field reports may exist independently.

### FR-011

Field reports may be attached to multiple incidents.

### FR-012

When attached to an incident, field report content shall be copied into incident notes.

### FR-013

When a field report is appended, only the added content shall be copied into associated incidents.

### FR-014

When a field report is removed from an incident, the incident history shall show the relationship as stricken.

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

## 7.13 Equipment Requirements

### EQUIP-001

MVP equipment tracking shall be visible/manual.

### EQUIP-002

MVP equipment tracking shall support checkout to individual staff members.

### EQUIP-003

MVP equipment tracking shall support check-in from individual staff members.

### EQUIP-004

Shift leads and department leads may check equipment in/out.

### EQUIP-005

MVP equipment states shall include Available, Checked out, Returned, Missing, and Damaged.

### EQUIP-006

Department-to-department allotments are out of scope for MVP.

### EQUIP-007

Full inventory custody chains are out of scope for MVP.

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

---

# 10. Open Notes

The current requirements are coherent enough to proceed from discovery into formal requirements refinement.

Architecture, implementation planning, database modeling, API design, and screen design remain intentionally out of scope until the requirements artifacts are accepted.
