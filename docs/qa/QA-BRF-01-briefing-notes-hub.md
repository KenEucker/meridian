# QA-BRF-01: Notes and The Briefing Hub

## Purpose

Verify the Milestone 15 Briefing Alpha slice: leads/IC create immutable Notes visible only to author and Command; Command adds Notes to The Briefing by reference or link; approved event staff then see those inclusions in the hub; edit/append is impossible; hub shells for AARs, Directions, Action Plan, and Notices are visible as placeholders; Orchid exposes Note repair visibility.

## Requirements covered

- `BRF-001` through `BRF-008C2`, `BRF-027`, `BRF-028`, `BRF-029`, `BRF-030`
- Technical spec section 21B
- Data/API spec section 11A
- UI Briefing surface specification sections 4–5
- UI implementation contract section 12.7A
- Meridian Alpha 1 tasks M15.1 through M15.10

## Environment

- Fresh checkout or task branch with server and shared client dependencies installed.
- Laravel app migrated and seeded with the development scenario:
  ```bash
  cd apps/server
  php artisan migrate:fresh --seed
  ```
- Development web server running at `http://127.0.0.1:8000`.
- Shared client available for product UI steps.
- Orchid available at `http://127.0.0.1:8000/admin`.

## Personas

- Department lead: seeded department lead for an event-participating department.
- Team lead: seeded team lead in an event-participating department.
- IC operator or lead: seeded IC-capable user for the event.
- Ordinary event staff: seeded active staff with event access who is not a lead/IC.
- Restricted user: authenticated user without event approval/access.

## Setup data

- Organization: `Northwood Collective` (`northwood-collective`).
- Event: `Emberfall 2026` (or current seeded event).
- Note title (optional): `QA Radio Net`
- Note body Markdown: `Command radio net opens at **10:00**.`
- Reference summary Markdown: `Radio net opens at 10:00 (clarified for all departments).`

## Steps

1. Sign in as ordinary event staff and open The Briefing hub for the event.
2. Confirm hub shows shells for AAR, Directions, Action Plan, and Notices, and no access to the private Notes pool.
3. Sign in as department lead, create the QA Note while connected.
4. Confirm the lead can view their own Note and cannot edit/append it.
5. Sign in as ordinary event staff and confirm they cannot read the unadded Note.
6. Sign in as IC, confirm Command can read the lead’s Note, then add it to The Briefing by **reference** with the QA summary and **event staff** audience.
7. Confirm hub shows the summary with author credit and a path to view the original Note.
8. As ordinary event staff, confirm the reference inclusion is visible in the hub and the original Note can be viewed from it.
9. As IC, add a Note by **link** with **department leads only** audience and confirm verbatim body with author credit.
10. As ordinary event staff and as a team lead who is not a department lead, confirm the department-leads-only inclusion is not visible.
11. As a department lead, Command, or organizer, confirm the department-leads-only inclusion is visible.
12. Confirm unauthorized users cannot create Notes or add Notes to The Briefing.
13. In Orchid, open Notes list/detail and confirm created Notes are visible for repair review.

## Expected results

- Notes are immutable after create.
- Pre-inclusion visibility is author + Command only.
- Reference and link inclusions behave as specified.
- Event-staff audience inclusions are visible to approved event staff; department-leads-only inclusions are visible only to department leads, Command, and organizers (team leads excluded unless they also hold one of those roles).
- Shells for non-Note Briefing types are present and clearly not fully implemented.
- Unauthorized create/add and event access fail closed.
- Orchid Note scaffold shows created Notes.

## Evidence to capture

- Screenshot of Briefing hub with shells and at least one event-staff inclusion.
- Screenshot of reference inclusion (summary + credit + view original).
- Screenshot of department-leads-only link inclusion and denied view for ordinary staff/team lead.
- Notes showing ordinary staff denied pre-inclusion read.
- Orchid Note list/detail screenshot.
- Automated test names covering immutability, visibility, audience, and add-to-briefing if present.

## Failure notes

Record any deviation, including wrong visibility, editable Notes, missing credit, missing shells, offline create/add unexpectedly allowed, or Orchid gaps.
