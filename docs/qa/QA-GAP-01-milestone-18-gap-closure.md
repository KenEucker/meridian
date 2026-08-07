# QA-GAP-01: Milestone 18 Gap Closure

## Purpose

Answer the milestone 18 QA gate: the whole MVP operational path runs in normal
Meridian Admin, Field, and Kiosk without opening Orchid. Milestone 18 existed
because domain code had no surface, surfaces read fixtures, and scope arrived
that no milestone carried; this script walks the closed gaps in one sitting —
presence, shift signup, unscheduled addition, hours correction, document
acknowledgment, the staff document library, team designations, the Staff
Coordinator, organization configuration, lifecycle thresholds, credit
policies, document-backed waivers, notifications, and dashboards.

It consolidates deliberately. Where a milestone 18 task already has its own
script, this one names it instead of repeating it:

- staff profile, handles, and pictures — `QA-STAFF-03`
- applicant portal — `QA-APPLY-02`
- marketing surface and organization interest — `QA-PUBLIC-02`
- pooled/tracked equipment and lookup — `QA-EQUIP-02`
- dictated Field Reports — `QA-FR-02`
- department roster, deployments, and credits surfaces — `QA-DEPT-01`
- context, organizer, event, and audit surfaces — `QA-ORG-04`
- kiosk surfaces — `QA-KIOSK-01`
- command palette — `QA-NAV-01`
- God Mode record screens — `QA-GOD-03`
- exports and reporting surfaces — `QA-EXPORT-01`
- client session and fixture removal — `QA-CLIENT-01`

The Event Horizon (Part E, M18.38 through M18.45) is its own gate and arrives
with `QA-HORIZON-01`; nothing here depends on it.

## Requirements covered

- `SLB-015` through `SLB-018` (presence and the off-site refusals), `SLB-007`,
  `SLB-008`, `SLB-031`, `SLB-032`, `SHIFT-011` through `SHIFT-018`,
  `HOURS-007`, `HOURS-008`.
- `POL-023` through `POL-027`, `POL-043` through `POL-047`, `POL-006`,
  `POL-008` through `POL-013`, `POL-055` (acknowledgments and the staff
  document library).
- `TEAM-011` through `TEAM-018`, requirements 4.4 and 4.8A (designations, the
  Operator, the Staff Coordinator, the attendance manager).
- `ORG-017` through `ORG-021`, `STAT-009` through `STAT-011`, `INC-007`
  (organization configuration, lifecycle thresholds, incident types).
- `ORG-009`, `ORG-010`, `CREDIT-001` through `CREDIT-005`, `SHIFT-010`
  (credit policies).
- `WAIVER-001` through `WAIVER-010` (document-backed waivers and waiver
  administration).
- `NOTIFY-001` through `NOTIFY-010`, `BRAND-002` (notification delivery).
- `CRED-009` through `CRED-014` (revocation; administered path checked in
  `QA-CRED-01` section H).
- UI implementation contract sections 13.1 through 13.6 (dashboards).
- Development process 5.2 (the traceability restatement this milestone's Part
  D delivers).

## Environment

- Development server environment with a migrated and seeded database
  (`php artisan migrate:fresh --seed`).
- Shared Meridian client (`apps/client`), Admin and Field artifacts; the
  development mail viewer for section H.
- No Orchid/God Mode window is opened at any step except where a step says so
  to prove a boundary.

## Personas

From the seeded operational scenario:

- Olive Organizer — organizer for Northwood Collective.
- Dana Departmentlead — department lead for Rangers.
- Quinn Quartermaster — Department Logistics standing for Rangers.
- Vera Staff — regular staff on a Rangers team.
- Sam Shiftlead — shift lead.
- Omar ICOperator — IC operator, for the dashboard exclusion check.
- Pat Prospective — Prospective organization status, for the lifecycle
  threshold.

All sign in with the documented development password.

## Setup data

- The seeded scenario with the event outside its active window at the start
  (the configuration steps in section E require the governance freeze to be
  off, and one step turns it on deliberately).
- One shift eligible to Vera's team with open signup and capacity, one at
  capacity, one requiring a training Vera has not completed, and one whose
  signup window has closed.
- A published policy document with an acknowledgment requirement scoped to
  signup, and a waiver backed by a published document with fragments.
- A credit policy set as the organization default and a different one attached
  to one shift.

## Steps

### A. Presence and the Logistics Desk (M18.1, M18.3, M18.4)

1. As Quinn Quartermaster, open the Logistics Desk, find Vera Staff, and mark
   her on-site. Confirm the control writes through the command outbox and the
   workspace shows her on-site.
2. Add Vera to a started shift she had not signed up for. Confirm a staff
   member not on-site is refused the same addition, and that a staff member
   outside the department is refused.
3. Check Vera in, then attempt to mark her off-site. Confirm the refusal names
   the open shift. Check her out, hand her a radio, and attempt off-site
   again. Confirm the refusal names the held equipment. Return it and confirm
   off-site now succeeds.
4. Open a completed shift record for Vera and correct its actual start/end.
   Confirm prior values survive in history. Freeze the record (or use one past
   the grace period) and confirm a second correction is refused with a message
   naming the closed grace period.

### B. Staff shift signup (M18.2)

5. As Vera Staff, open the shift board. Confirm she can browse the shifts her
   teams make her eligible for, each denial reason rendering on the ineligible
   ones: the full shift, the missing training, the closed window.
6. Sign up for the open shift. Confirm an overlapping signup warns and does
   not block. Withdraw before the cutoff and confirm the withdrawal; confirm
   no withdraw control is offered past a closed cutoff.

### C. Documents: acknowledgment and the library (M18.6, M18.7)

7. As Vera, trigger the signup acknowledgment path for the required document.
   Acknowledge it and confirm the recorded acknowledgment names the document
   and the version acknowledged.
8. Confirm acknowledgment is nowhere presented as a gate on shift signup or
   credential eligibility.
9. Open the staff document library. Confirm published policies and procedures
   are readable outside any administration surface, and that an unpublished
   document is absent for a non-maintainer.

### D. Designations and the Staff Coordinator (M18.10 through M18.13)

10. As Dana Departmentlead, open the department Admin page and designate a
    team as the department's Logistics team. As a member of that team,
    confirm exactly the Logistics capabilities appear — and, in the
    permission explanation, that the designation is named as the reason.
11. Remove the designation and confirm the derived authority is gone.
12. Designate an Operator team. Confirm the designation records and audits.
    *The Operator capability set and the `ic_operator` elevation are
    M18.10A's; until it lands, taking Field Reports for department staff and
    the IC elevation check stay with `QA-FR-02`'s noted gap.*
13. As Olive Organizer, designate a Staff Coordinator team from the
    organization configuration surface. As a member of it, review and approve
    a submitted application — then confirm department management, staff
    status, and credential administration are all absent and their commands
    refused.
14. As Quinn, Sam Shiftlead, and Dana in turn, confirm check-in, check-out,
    mark-no-show, and hours correction agree on who is authorized: all three
    standings may perform all four.

### E. Organization configuration and lifecycle (M18.14, M18.14A, M18.15)

15. As Olive, open organization configuration and set the hours correction
    grace period, the lifecycle thresholds, and the calendar year start.
    Confirm each change is audited.
16. Move the event into its active window and confirm the same edits are
    refused under the governance freeze; move it back out.
17. Add an incident type, rename one, archive one. Confirm an incident
    carrying the archived type keeps it while the assignable list drops it,
    and that a non-organizer cannot edit the list.
18. Let the Prospective inactivity threshold pass for Pat Prospective (adjust
    the threshold to make it pass) and run the scheduler. Confirm Pat becomes
    Inactive through the audited status path with nobody performing the
    transition, that a re-run changes nothing, and that a staff member with
    active department work is not transitioned.

### F. Credits and waivers (M18.16 through M18.18)

19. As Olive, open credit policy administration. Confirm the organization
    default and the shift override are both visible, and that credits
    calculate from the shift policy where one exists and the default
    otherwise.
20. Confirm a calculated entry is frozen: rename or re-rate the policy behind
    it and confirm the entry does not move.
21. As Vera, complete the document-backed waiver. Confirm the document renders
    inline with fragment text at completion, and the completion records the
    acknowledged document version. Confirm a waiver with no document behaves
    as before.
22. As Dana (or the waiver's scope authority), administer waiver scope and
    expiration. Confirm an expired waiver blocks credential eligibility.

### G. Dashboards (M18.28)

23. As Vera, Dana, Olive, and Omar in turn, open the dashboard. Confirm each
    reads only widget groups their own surfaces would grant, quiet states
    render as sentences rather than empty tiles, and a group the reader does
    not hold is absent.
24. As Olive — an organizer with no IC standing — confirm no widget surfaces
    an incident, a Field Report, or a count of either.

### H. Notifications (M18.21)

25. In the development mail viewer, confirm the decisions of section D and the
    approval of section D's application produced emails carrying the
    organization's name and mark.
26. Submit an application for an address the organization has marked Do Not
    Staff and confirm the auto-rejection sends nothing. Confirm an unverified
    address receives nothing.
27. Stop the mail path (suppression switch) and perform one more notifying
    action. Confirm the operation itself succeeds — a delivery failure rolls
    nothing back.

### I. The paper trail (M18.35 through M18.37)

28. Open `docs/process/traceability-matrix.md` and confirm the status
    vocabulary distinguishes **In progress (domain)** from **In progress
    (usable)**, and that `scripts/process/check.sh` passes.

## Expected results

- Every Part A service is reachable from a product surface and behaves as its
  refusal cases specify: off-site blocked by open shift and held equipment,
  unscheduled addition requiring on-site presence and department membership,
  hours correction refused once frozen with the grace period named.
- The shift board explains every denial, warns on overlap without blocking,
  and withdraws only before the cutoff.
- Acknowledgments record document and version and gate nothing else;
  published documents are readable from the staff library and unpublished
  ones are absent.
- A designation grants exactly its documented capability set, names itself in
  the permission explanation, and removal removes the derived authority; the
  Staff Coordinator reviews applications and holds no other governance
  capability; the four attendance operations agree on their authority.
- Organization configuration is audited, frozen during the active window, and
  its incident type list is live during events by design; lifecycle
  transitions run unattended, idempotently, through the audited path.
- Credits resolve shift-over-default and freeze; document-backed waivers
  render inline and record versions; expired waivers block eligibility.
- Dashboards disclose nothing their reader could not open, and the organizer
  group contains no incident data by construction.
- Notifications carry organization branding, send nothing to DNS-rejected or
  unverified addresses, and never roll back the operation they report.

## Evidence to capture

- Screenshots of both off-site refusals naming their reason.
- The shift board with all four eligibility states visible.
- The acknowledgment record showing document and version.
- The permission explanation naming a designation.
- The audit rows for a configuration change, the freeze refusal, and Pat's
  automatic transition.
- A frozen credit entry beside its renamed policy.
- The organizer dashboard beside the IC dashboard for the incident-data
  exclusion.
- The mail viewer showing a branded decision email and the absence of a DNS
  auto-rejection email.

## Failure notes

Record the persona, surface, and the exact refusal or absence observed, and
whether Orchid was needed to complete any step that this script says runs in
the product — needing the console for an operational step is itself the
finding, because closing that gap is what milestone 18 was for. A capability
that appears without its designation, survives the designation's removal, or
widens past its documented set is a permission finding and blocks the gate.
