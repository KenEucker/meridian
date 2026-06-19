# QA-APPLY-01 Public Event Application

## Purpose

Verify that a public (or authenticated) applicant can open an event-specific application form, optionally record non-binding department interest, and submit an application that is recorded in the Submitted state or auto-rejected due to DNS when applicable. This script covers submission, organizer/Staff Coordinator review list/detail (including department interest display and filtering), department lead read-only visibility where implemented, DNS auto-rejection without applicant notice, and verification that department interest does not create assignment or access side effects. Approval, withdrawal, and department/team assignment are delivered by later Milestone 5 tasks.

## Requirements covered

- `APP-001`
- `APP-002`
- `APP-003`
- `APP-004`
- `APP-005`
- `APP-011`
- `STAT-006`
- Requirements sections 3.10 and 5.2
- Data/API spec sections 10.4 and 10.5 (`staff_organization_statuses`, `event_applications`, `event_application_department_interests`, `submit-application`)
- UI implementation contract sections 8.2, 9.3 and 12.1, 12.6, 12.10 (`public.apply`, `organizer.applications`, `organizer.application-detail`)
- Meridian Alpha 1 tasks M5.1, M5.2, M5.3, M5.4

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and development scenario seeded (`php artisan migrate:fresh --seed`).
- Development web server running.

## Personas

- Public applicant: an unauthenticated visitor with the application link.
- Authenticated applicant: any signed-in user (for example, Vera Staff).
- Organizer: an Orchid user with the `platform.applications` permission (for example, a development admin granted Applications access).
- Department lead: a user who leads a department participating in the event (for read-only interest visibility checks when M5.3 is complete).

## Setup data

- At least one non-archived event exists with two or more non-archived departments assigned through `event_department_assignments` (the seeded "Idaho Decompression 2026" event under organization slug `idaho-burners` and event slug `idaho-decompression-2026` is sufficient if participating departments are configured). Note organization slug, event slug, and participating department names.
- One event with **no** eligible participating departments (no active `event_department_assignments`, or all assigned departments archived) to confirm the department interest field is hidden.
- One archived event (archive an event in Orchid) to confirm the closed state.
- One staff record in the event organization with organization status `do_not_staff` and a known email address (the seeded Debbie DNS persona is sufficient when present).
- Optional: one archived department or a department from another organization to use in invalid-interest validation tests.

## Steps

### A. Baseline form and submission (M5.1 / M5.2)

1. As the public applicant, open `/{organization-slug}/{event-slug}/apply` for the non-archived event with participating departments.
2. Confirm the form shows the event name, a legal name field, and an email field.
3. Confirm there is **no team selection** control.
4. When eligible participating departments exist, confirm an optional **Department interest** multi-select/checklist is shown with helper text stating the field is optional, non-binding, and that leaving all options unchecked means no preference / open to any. Confirm there is no explicit “No preference” option and no maximum-count validation message.
5. Submit the form with a blank legal name and an invalid email; confirm validation errors and that no application is created.
6. Leave department interest empty and submit with a valid legal name and email.
7. Confirm the submitted confirmation page appears.
8. Confirm one `event_applications` row exists for this event with status `submitted`, a `submitted_at` timestamp, the organization derived from the event, and the normalized (lowercased) email, and **no** `event_application_department_interests` rows for this application.
   - From `apps/server`, run:
     ```bash
     php artisan tinker --execute="App\Models\EventApplication::query()->with(['event','departmentInterests'])->latest()->get(['id','event_id','organization_id','applicant_email','applicant_legal_name','status','submitted_at'])->each(fn (\$a) => print(\$a->toJson(JSON_PRETTY_PRINT).PHP_EOL));"
     ```
   - Or query PostgreSQL directly:
     ```sql
     SELECT ea.applicant_email, ea.applicant_legal_name, ea.status, ea.submitted_at, e.slug AS event_slug
     FROM event_applications ea
     JOIN events e ON e.id = ea.event_id
     ORDER BY ea.submitted_at DESC;
     ```
9. As the organizer, sign in to Orchid and open **Operations → Applications**.
10. Confirm the submitted application appears with applicant name, email, event name, organization name, status **Submitted**, submitted timestamp, and a department interest empty state (for example, “No department preference” or “Open to any”).
11. Open the application detail and confirm legal name, email, event, organization, status **Submitted**, submitted timestamp, department interest empty state, and the note that approval occurs at the organization level. Confirm there are no Approve, Reject, Defer, or Save actions on this screen.
12. Submit the form again for the same event using the same email (any letter case); confirm it is blocked with a duplicate message and no second row is created.
13. Open `/{organization-slug}/{event-slug}/apply` for the archived event and confirm an "Applications closed" state with no usable form.
14. Sign in as the authenticated applicant, open the apply form for the non-archived event, and confirm submission also succeeds.

### B. Department interest capture (M5.3 / APP-011)

15. Submit a new application selecting **two or more** eligible departments in any order. Confirm submission succeeds.
16. Confirm `event_application_department_interests` rows exist for each selected department with **no preference order** field.
17. Re-submit the same combination in a different checkbox order for a different email; confirm stored interests are equivalent (unordered set), not ranked preferences.
18. As the organizer, confirm list and detail show **Department interest** (not assignment) with the selected department names.
19. Use the organizer application list department interest filter to show only applications interested in one selected department; confirm the filter works and unrelated applications are excluded.
20. Open the apply form for the event with **no** eligible participating departments; confirm the department interest field is **hidden** and submission with legal name and email still succeeds.

### C. Validation and no side effects (M5.3 / APP-011)

21. Attempt submission with invalid `department_interest_ids` (duplicate IDs, archived department, department not assigned to the event, department from another organization, or team ID if exposed). Confirm the submission is rejected with validation errors and **no partial** application or interest rows are created.
22. After a successful submission with department interest, verify **none** of the following were created or changed solely because of department interest: department membership, team membership, department assignment, event staff assignment, shift signup eligibility, training completion, credential eligibility, application status other than `submitted`, or notifications/routing to department leads.
23. Sign in as a department lead (without organizer/Staff Coordinator review permissions) when M5.3 read-only visibility is implemented. Confirm they can view read-only list/detail for applications that expressed interest in their department, including before org approval, and **cannot** view unrelated applications, approve, reject, defer, assign, or edit interest.

### D. DNS auto-rejection (M5.4 / STAT-006)

24. Submit the public application form using the known DNS email address for the same organization and any letter case variation of that email.
25. Confirm the applicant sees only the generic submitted/received confirmation. The page must not say DNS, Do Not Staff, rejected, auto-rejected, blocked, or anything equivalent.
26. Confirm one `event_applications` row exists for this event and email with status `auto_rejected_dns`, a normalized lowercased email, `submitted_at` set, `reviewed_at` set, no `reviewed_by_user_id`, no `staff_id`, and a decision reason suitable for organizer review.
27. If department interest was selected on the DNS submission, confirm the interest remains a non-binding record only and creates no department membership, team membership, assignment, access, routing, or notification side effects.
28. As an organizer or Staff Coordinator with application review permission, open the application detail and confirm the canonical status label is **Auto-rejected due to DNS**.
29. As a department lead without organizer/Staff Coordinator review permission, confirm the DNS auto-rejected application is not visible through interest-only read-only application visibility.
30. Submit the same DNS email to an event in a different organization where that staff email does not have `do_not_staff` status; confirm it follows the normal non-DNS submission path for that organization.

## Expected results

- The public form is reachable without authentication and is scoped to one event (APP-001, APP-002).
- Applicants apply to the **event**; optional department interest is a non-binding intake signal, not department application or assignment (APP-002, APP-011).
- Submitting valid data with empty department interest creates exactly one `event_applications` record in the `submitted` state with no interest rows (APP-003).
- Submitting with multiple unordered department interests stores one row per department with no ranking (APP-011).
- Invalid department interest values reject the entire submission (APP-011).
- The department interest field is hidden when no eligible participating departments exist (APP-011).
- Invalid input is rejected without creating a record.
- A duplicate active application for the same event and email is blocked.
- Archived events show a closed state and never accept a submission.
- Authenticated users can also submit; existing department memberships are not prefilled as interest (APP-011).
- Organizers with `platform.applications` permission can list and inspect applications with canonical status labels, organization context, and department interest display/filter (APP-003, APP-005, APP-011).
- Department interest does not create assignment, membership, access, routing, or notification side effects (APP-011).
- Department leads see only read-only interested applications when implemented; they gain no review authority from interest alone (APP-011).
- DNS email applications are auto-rejected only for the matching organization and do not automatically notify the applicant (STAT-006).
- DNS auto-rejected applications remain visible to organizers/Staff Coordinators for review history and are not exposed to department leads through interest-only visibility.

## Evidence to capture

- Screenshot of the application form showing optional department interest for an event with participating departments.
- Screenshot of the application form with the department interest field hidden (no eligible departments).
- Screenshot of the submitted confirmation page.
- Screenshot or query output of `event_applications` and `event_application_department_interests` rows.
- Screenshot of the Orchid Applications list showing department interest and filter.
- Screenshot of the application review detail screen with department interest labeled as interest, not assignment.
- Screenshot of validation errors for invalid department interest and the duplicate-submission message.
- Screenshot of the closed state for the archived event.
- Screenshot of the generic confirmation after DNS email submission, plus query output showing `auto_rejected_dns`.
- Screenshot of organizer detail for the DNS auto-rejected application.
- Evidence that no membership/assignment/access side effects occurred after interest submission.

## Failure notes

- If **team selection** appears on the public form, stop and report scope leakage; team assignment belongs to M5.8.
- If department interest is labeled or behaves like department **assignment** or **application to a department**, stop and report scope leakage (APP-002, APP-011).
- If a non-DNS submission produces a status other than `submitted`, stop and report scope leakage; review decisions belong to M5.5/M5.6.
- If a DNS email submission tells the applicant that they are DNS, Do Not Staff, rejected, auto-rejected, or blocked, stop and report because STAT-006 requires no automatic applicant notice.
- If a DNS auto-rejected application is visible to a department lead only because department interest was selected, stop and report because DNS-sensitive status should remain with organizer/Staff Coordinator review.
- If the archived event still accepts submissions, stop and report because applications must not be created for events that are not accepting applications.
- If Approve, Reject, Defer, Assign, or edit-interest actions appear on the review detail screen for users without the appropriate later-task permissions, stop and report scope leakage; decision actions belong to M5.5/M5.6 and assignment to M5.7/M5.8.
- If department interest creates department membership, team membership, credentials, shifts, trainings, routing, or notifications, stop and report scope leakage (APP-011).
- If returning staff department memberships are prefilled as department interest, stop and report (APP-011).
