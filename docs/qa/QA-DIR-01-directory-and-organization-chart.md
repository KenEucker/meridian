# QA-DIR-01: The Directory and the organization chart

## Purpose

Walk the Directory's visibility matrix by hand with the seeded personas, and
verify the surface around it: the organization drawn as a touch-first
disclosure chart with the Organizers Department first; person entries carrying
a picture or lettermark, the handle, the authorized locations, and years of
service and nothing else; handle search beside the chart over exactly the
authorized set; filtering through chips rather than dropdowns; the
organization availability switch whose off position means absent rather than
refused; and the offline copy that holds what this user could have retrieved
from the API and nothing else.

The visibility design is the feature rather than a constraint on it. Nothing
here is granted by a permission — what a viewer sees is derived from organizer
standing and leadership assignments that already exist — so a person reached
through this surface who should not have been is a security finding, not a
rendering bug. The only person-identifying text anywhere on the surface is the
handle: a legal name, an email address, or a phone number found in a response
body, a page, or the stored offline set is a finding regardless of whether it
was drawn.

## Requirements covered

- `DIR-001` through `DIR-005`: the read-only surface, its three UI modes and
  two contexts, touch-first operation, and the organization availability
  setting whose disabled state is absence.
- `DIR-006` through `DIR-016`: the two populations, chart structure and order,
  the Prospectives section, repeated placements, empty branches drawn without
  counts or markers, and the collapsed initial state.
- `DIR-017` through `DIR-026`: the visibility matrix, additivity, no widening
  by any other role, no named permission, and the status exclusions.
- `DIR-027` through `DIR-030`: the purpose-built projection, the handle as the
  only person-identifying field, the person entry's complete field list, and
  locations constrained per viewer.
- `DIR-031` through `DIR-036`: search beside the chart, handle-only matching,
  the authorized index, breadcrumb rows, selection behavior, and touch-first
  filtering with honest counts.
- `DIR-037`: the offline read set holding the authorized projection and
  nothing else.
- `ORG-018`, `ORG-020`, `ORG-021`: the availability switch as audited,
  freeze-bound organization configuration.
- Technical spec section 21E (including settled open questions 37 and 38);
  UI implementation contract sections 12.3 and 19D.

## Environment

- Development server environment with a migrated and seeded database
  (`php artisan migrate:fresh --seed`)
- Shared Meridian client (`apps/client`): Field or Admin artifact for
  sections A through F, a Kiosk workstation with a live session for section G
- A device that can be taken offline for section F, and a phone-width
  viewport plus the largest display available for section G
- For API checks, authenticated requests against
  `/api/organizations/{organization}/directory`,
  `/api/events/{event}/directory`, the `/directory/search` variants of both,
  `/api/offline-read-set`, and
  `/api/commands/update-organization-configuration`

## Personas

All from the seeded development scenario:

- Vera Staff — ordinary member of Rangers / Dirt, holding nothing
- Tess Teamlead — designated lead (`membership_role = lead`) of Dirt only
- Dana Departmentlead — department lead of Rangers (Ranger Leads team)
- Sam Shiftlead — designated lead of Ranger Shift Leads, ordinary member of
  Dirt: the subject for the authorized-locations check
- Milo Multidept — ordinary member of Rangers / Dirt, Gate / Operator, and
  DPW / Logistics: the repeated-placement subject
- Olive Organizer — member of the Organizers Department, organizer
- Ivy ICViewer — an Incident Command role and nothing else: the
  no-other-role-widens check
- Ira Ineligible — Gate member whose department status is `ineligible`
- Gwen Godmode — a login holding no staff standing in the organization

## Setup data

- The seeded development scenario as `migrate:fresh --seed` leaves it: the
  Northwood Collective, its four departments with their leads teams, and the
  personas above
- Recorded hours for at least one person across two non-consecutive calendar
  years, for the years-of-service check (step 16); the seeded attendance
  scenario provides recorded hours, and a second year can be added with one
  corrected record
- An event whose active window can be moved over today and back, for the
  configuration freeze (step 5)
- A Kiosk workstation pinned and trusted, with a persona able to sign in at
  it, for section G

## Steps

### A. Availability and configuration (DIR-004, DIR-005)

1. As Olive, open **Configuration** and confirm the Directory switch reads
   enabled without anyone having set it.
2. As Vera, confirm **Directory** appears in the Workflows menu and in the
   command palette, and opens the chart. Vera holds no capability at all:
   the entry is association-permitted, and finding it gated is a finding.
3. As Olive, disable the Directory. Confirm the change is audited with before
   and after values. As Vera, confirm the menu entry and the palette entry
   are gone with nothing explaining why, and that the `/directory` address
   renders page-not-found copy with no mention of a switched-off feature.
4. Request `/api/organizations/{organization}/directory` as Olive and as
   Gwen, and confirm both receive 404 — not 403, and not two
   distinguishable answers (DIR-005: absence must not disclose the feature
   exists).
5. Attempt to re-enable the Directory during an active event window (move
   the window over today first) and confirm the edit is refused as frozen.
   Move the window back, re-enable, and confirm the entry returns.

### B. The visibility matrix (DIR-018 through DIR-026)

6. As Vera, open the Directory. Confirm the chart opens collapsed to
   departments with the Organizers Department first, and that expanding by
   touch reaches: Olive (organizer), Dana (department lead), Gabe and Dex
   (leads of their departments), and Tess and Sam (team leads) — and **no
   ordinary member anywhere**: no Nora, no Felix, no Milo, and not Vera
   herself.
7. Still as Vera, expand a department she may see nobody in and confirm the
   department and its teams render as themselves — no count, no lock, no
   "hidden" marker, no explanatory copy (DIR-015).
8. As Dana, confirm all of Rangers is reachable: every team's members, and
   the department-level **Prospectives** section for members holding no team
   (DIR-013, DIR-019). Confirm Gate's and DPW's ordinary members are still
   absent while their leads remain visible.
9. As Tess, confirm Dirt's members are reachable and nothing else widened:
   no Rangers Prospectives, no members of other Rangers teams, no Gate
   (DIR-020).
10. As Olive, confirm the whole population is reachable, and that Milo
    appears in every one of his three departments (DIR-014, DIR-021).
11. As Ivy — an IC role and nothing else — confirm the chart shows exactly
    what Vera's does (DIR-023). Repeat the comparison for any other elevated
    persona to hand (a Staff Coordinator if one is seeded, Sam's department
    capabilities): none may widen the Directory.
12. As Olive, confirm Ira's Gate placement is absent (department status
    `ineligible` is excluded) while any other standing Ira holds remains
    (DIR-025). Then, from organizer staff management, set Dana's
    organization status to Inactive and confirm she vanishes from the chart
    for every viewer — a department lead is not drawn because they are a
    lead (DIR-026). Restore her status afterwards.

### C. The person entry and its locations (DIR-027 through DIR-030)

13. On any person entry, confirm it carries the profile picture or a
    lettermark derived from the handle, the handle, the authorized
    locations, and years of service — and no name, no email, no phone, no
    contact or message control, and no administrative action (DIR-029).
14. Inspect the chart response body (`/api/organizations/{organization}/directory`)
    as Vera and confirm no legal name, preferred name, email, phone,
    emergency contact, address, or date of birth appears anywhere in it
    (DIR-028) — the projection must not carry what the page declines to
    draw.
15. As Vera, find Sam. Confirm his entry lists his team-lead location only:
    his ordinary Dirt membership is a location Vera holds no position over,
    and it appears neither on his entry nor in the chart (DIR-030).
16. Confirm years of service reads as the count of distinct years with
    recorded hours (settled open question 37): a person with hours recorded
    in two calendar years with a gap between them reads 2, and a person with
    no recorded hours reads 0.

### D. Search (DIR-031 through DIR-035)

17. As Vera, type in the search box beside the chart. Confirm the chart
    stays interactive while results are on screen — search is not a page,
    a tab, or a mode.
18. Search for a legal name, a preferred name, a department name, and a role
    name, and confirm each matches nothing: the handle is the only matched
    field (DIR-032).
19. Search for the handle of an ordinary member Vera may not see (Felix),
    including partial prefixes, and confirm no result, no count, and no
    partial match discloses the handle exists (DIR-033).
20. As Olive, search for Milo and confirm one row per visible location, each
    carrying the handle and a breadcrumb such as
    `Rangers → Dirt → Member` (DIR-034).
21. Select one of Milo's rows. Confirm the chart expands the branches
    holding his occurrences, scrolls to the selected row's own node
    (settled open question 38), highlights every occurrence with a visible
    marker and the accessible text "Search match" rather than color alone,
    and leaves the search box and results exactly where they were
    (DIR-035).
22. Run a new search and confirm the previous highlight is cleared.

### E. Filtering (DIR-036)

23. Confirm the filters are immediately visible touch targets — chips with a
    pressed state — and that no filter's primary interaction is a dropdown.
24. Apply a role chip and a status chip and confirm the count counts only
    people visible to this viewer, with no wording implying there was
    something else to count.

### F. Offline (DIR-037)

25. As Dana, with the node reachable, open the Directory so the read set
    synchronizes. Take the device offline and reload: confirm the chart
    renders from the stored copy, disclosed as one with the moment it was
    taken, and that entries render lettermarks — profile pictures do not
    travel to devices.
26. Offline, search for a Rangers member and confirm a result; search for a
    handle outside Dana's scope and confirm the offline index has no entry
    for it — the stored set is the authorized set (DIR-033, DIR-037).
27. Inspect the stored set (`/api/offline-read-set` as Dana, or the
    device's store) and confirm no PII field appears in the
    `directory_people` rows.
28. Back online, revoke Dana's department lead standing (remove her from
    the Ranger Leads team or revoke the grant), refresh, and confirm the
    stored member list is gone on the next composition — then restore her.
29. Disable the Directory as Olive and confirm the read set carries no
    `directory_departments` or `directory_people` section at all: a
    disabled Directory synchronizes nothing.

### G. Kiosk and widths (DIR-002, DIR-003; 19D.9)

30. On a Kiosk workstation with a live session, open the command palette and
    confirm **Directory** is offered and opens the chart; end the session
    and confirm the entry is gone with it.
31. At phone width, confirm the chart is usable by touch alone: disclosure
    targets comfortably tappable, no hover-only affordance, and no
    horizontal panning anywhere — depth is indentation, and the page
    scrolls vertically only.
32. On the largest display available, confirm added width is spent on
    legibility and more of the tree — not on a box-and-line diagram — and
    that the chart remains operable by touch at arm's length.

## Expected results

- The visibility matrix holds exactly: each persona reaches the set section B
  names and not one person or location more, additively over the positions
  they hold, with exclusion winning over position and no role outside
  DIR-018 through DIR-021 widening anything.
- The handle is the only person-identifying text anywhere: on the page, in
  the response bodies, in the search index, and in the stored offline set.
- A disabled Directory is absent — 404 indistinguishable between viewers, no
  menu or palette entry, page-not-found copy, nothing synchronized — and an
  enabled one is present for every staff member with no capability involved.
- Search matches handles only, reaches only the authorized set including
  counts and partial matches, returns a row per authorized location, and
  selection expands, scrolls to the selected row's own node, highlights
  every authorized occurrence with accessible text, and leaves search in
  place, with the highlight cleared by the next search.
- Filters are chips, never dropdowns, and every count counts only what the
  viewer can see.
- Offline, the chart and search answer from the stored authorized projection,
  disclosed as a stored copy, with no PII and no pictures on the device, and
  a demoted lead's stored members gone on the next refresh.

## Evidence to capture

- Screenshots of the chart as Vera, Dana, Tess, and Olive at the same
  branches, showing the matrix widening and nothing else
- The two 404 response bodies from step 4, shown identical
- The audit entries for the disable and re-enable in step 3/5
- The chart response body from step 14, with the search for PII field names
  coming back empty
- A screenshot of Milo's three highlighted occurrences after step 21
- The stored `directory_people` rows from step 27, before and after the
  demotion in step 28

## Failure notes

- A person or location reached outside the matrix, a PII field found in any
  response or store, or an unauthorized handle disclosed through a count,
  partial match, or the offline index is a security finding against
  DIR-017 through DIR-033 — file it as such, not as a rendering defect.
- A disabled Directory answering 403, or its absence explained anywhere, is
  a finding against DIR-005.
- A branch labeled with a count, a lock, or a "hidden" marker is a finding
  against DIR-015 even where the people behind it were correctly withheld.
- Record any step's failure with the persona, the context (organization or
  event), the request or screen, and the expected-versus-observed answer.
