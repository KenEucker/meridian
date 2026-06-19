# QA-APPLY-01 Public Event Application

## Purpose

Verify that a public (or authenticated) applicant can open an event-specific application form and submit an application that is recorded in the Submitted state, without any department or team selection. This script covers submission and organizer review list/detail; approval, DNS auto-rejection, withdrawal, and department/team assignment are delivered by later Milestone 5 tasks.

## Requirements covered

- `APP-001`
- `APP-002`
- `APP-003`
- `APP-004`
- `APP-005`
- Requirements sections 3.10 and 5.2
- Data/API spec section 10.5 `event_applications`
- UI implementation contract sections 9.3 and 12.1 (`public.apply`), 12.6 (`organizer.applications`)
- Meridian Alpha 1 task M5.1, M5.2

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and development scenario seeded (`php artisan migrate:fresh --seed`).
- Development web server running.

## Personas

- Public applicant: an unauthenticated visitor with the application link.
- Authenticated applicant: any signed-in user (for example, Vera Staff).
- Organizer: an Orchid user with the `platform.applications` permission (for example, a development admin granted Applications access).

## Setup data

- At least one non-archived event exists (the seeded "Idaho Decompression 2026" event under organization slug `idaho-burners` and event slug `idaho-decompression-2026` is sufficient). Note both slugs for the application URL.
- One archived event (archive an event in Orchid) to confirm the closed state.

## Steps

1. As the public applicant, open `/{organization-slug}/{event-slug}/apply` for the non-archived event (for the seeded event: `/idaho-burners/idaho-decompression-2026/apply`).
2. Confirm the form shows the event name, a legal name field, and an email field, and offers no department or team selection control.
3. Submit the form with a blank legal name and an invalid email; confirm validation errors and that no application is created.
4. Submit the form with a valid legal name and email.
5. Confirm the submitted confirmation page appears.
6. Confirm one `event_applications` row exists for this event with status `submitted`, a `submitted_at` timestamp, the organization derived from the event, and the normalized (lowercased) email.
   - From `apps/server`, run:
     ```bash
     php artisan tinker --execute="App\Models\EventApplication::query()->with('event')->latest()->get(['id','event_id','organization_id','applicant_email','applicant_legal_name','status','submitted_at'])->each(fn (\$a) => print(\$a->toJson(JSON_PRETTY_PRINT).PHP_EOL));"
     ```
   - Or query PostgreSQL directly:
     ```sql
     SELECT ea.applicant_email, ea.applicant_legal_name, ea.status, ea.submitted_at, e.slug AS event_slug
     FROM event_applications ea
     JOIN events e ON e.id = ea.event_id
     ORDER BY ea.submitted_at DESC;
     ```
7. As the organizer, sign in to Orchid and open **Operations → Applications**.
8. Confirm the submitted application appears with applicant name, email, event name, organization name, status **Submitted**, and submitted timestamp.
9. Open the application detail and confirm legal name, email, event, organization, status **Submitted**, submitted timestamp, and the note that approval occurs at the organization level. Confirm there are no Approve, Reject, Defer, or Save actions on this screen.
10. Submit the form again for the same event using the same email (any letter case); confirm it is blocked with a duplicate message and no second row is created.
11. Open `/{organization-slug}/{event-slug}/apply` for the archived event and confirm an "Applications closed" state with no usable form.
12. Sign in as the authenticated applicant, open the apply form for the non-archived event, and confirm submission also succeeds.

## Expected results

- The public form is reachable without authentication and is scoped to one event (APP-001, APP-002).
- Submitting valid data creates exactly one `event_applications` record in the `submitted` state with `submitted_at` set (APP-003).
- Invalid input is rejected without creating a record.
- A duplicate active application for the same event and email is blocked.
- Archived events show a closed state and never accept a submission.
- Authenticated users can also submit.
- Organizers with `platform.applications` permission can list and inspect applications with canonical status labels and organization context (APP-003, APP-005).

## Evidence to capture

- Screenshot of the application form for a non-archived event.
- Screenshot of the submitted confirmation page.
- Screenshot or query output of the `event_applications` row showing `status = submitted`.
- Screenshot of the Orchid Applications list showing the submitted application.
- Screenshot of the application review detail screen.
- Screenshot of the validation errors and the duplicate-submission message.
- Screenshot of the closed state for the archived event.

## Failure notes

- If a department or team selection appears on the public form, stop and report scope leakage; assignment belongs to M5.6/M5.7.
- If submission produces a status other than `submitted` (for example, approved or auto-rejected), stop and report scope leakage; review decisions and DNS handling belong to M5.3 through M5.5.
- If the archived event still accepts submissions, stop and report because applications must not be created for events that are not accepting applications.
- If Approve, Reject, Defer, or Save actions appear on the review detail screen, stop and report scope leakage; decision actions belong to M5.4/M5.5.
