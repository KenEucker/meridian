# QA-ORG-04: Context and Organizer Surfaces

## Purpose

Verify the four product surfaces M18.29 adds to the ones M16.7 and M18.21A
already built: entering a department space from the session the client is
holding, reading one application on its own address, administering the
organization's events without the God Mode console, and reading the
organization's audit record.

Two boundaries are what this script is really for, and both fail quietly if
nobody looks:

- application review authority is organizer and Staff Coordinator, and nobody
  else — a department lead reaches the same application read-only and is refused
  the decision by the node rather than by a hidden button;
- an organizer's audit record carries no incident and no Field Report history,
  because organizing reaches neither (`ORG-015`), and an audit row naming an
  incident is a way of reading it.

## Requirements covered

- `APP-003`: the application status set, shown on the detail surface.
- `APP-005`: approval happens at the organization level.
- `APP-011`: department interest is shown, labelled as interest, never edited
  during review; department leads reach read-only visibility over applications
  naming their department.
- `APP-019`: application review is reachable from a normal product surface.
- `ORG-005`, `ORG-006`: the organization default Incident Command Department and
  the event override, which must be an active department assigned to that event.
- `ORG-015`: the Organizers Department grants no access to incidents or Field
  Reports.
- `TEAM-014`, requirements section 4.4: the Staff Coordinator reviews
  applications and carries no other organizer governance capability.
- Requirements section 2.4 Auditability: changes are attributed to the person
  who made them.
- `CLIENT-005`, `CLIENT-006`: an unpermitted destination is absent from
  navigation, and the node refuses the request regardless of what was rendered.
- UI implementation contract section 12.2: `context.organizations`,
  `context.events`, `context.departments`; department entry stays available
  offline.
- UI implementation contract sections 12.6 and 12.10.2:
  `organizer.applications`, `organizer.application-detail`, `organizer.events`,
  `organizer.audit`.
- Technical spec section 10.2: during an event's active window the on-site
  primary node is authoritative for that event's records.
- Data/API spec section 10.2 `events`; section 14.1 `audit_events`.
- Accessibility checklist sections 8, 9, 14, and 18.

## Environment

- Development server environment with a migrated and seeded database
- Shared Meridian client (`apps/client`)
- Permission catalog carrying `organization.events.manage` and
  `organization.audit.review` for `organizer` and `lead_organizer`, and neither
  for `staff_coordinator`
- For API checks, authenticated requests against
  `/api/organizations/{organization}/events`,
  `/api/organizations/{organization}/audit`, `/api/applications/{application}`,
  and `/api/commands/create-event` and `/api/commands/update-event`

## Personas

- Olive Organizer — organizer, holds every capability under test
- A Staff Coordinator — a team in the Organizers Department carrying the
  `staff_coordinator` designation
- Dana Departmentlead — Rangers department lead, for the `APP-011` read-only
  visibility and for the absences
- Vera Staff — regular staff, holding no roles at all

## Setup data

- The seeded scenario, which supplies two events, four departments, and
  applications in every terminal state plus two undecided
- At least one submitted application naming Rangers as a department interest
- At least one application with no department interest recorded
- An event whose active event window is **not** open, for the editing steps
- An event whose active event window **is** open (the seeded Emberfall 2026),
  for the authority step
- Audit rows in the organization: seeding writes them, and any department or
  team edit made during this script adds more
- At least one incident and one Field Report in the seeded event, so the
  `ORG-015` absence is an absence of something that exists
- Where an on-site node is available, one paired to the running event, so step 27
  has a node to name; where it is not, the row states the phase alone

## Steps

### Department spaces (`context.departments`)

1. Sign in as Dana Departmentlead and open the home surface.
2. Under **Context**, choose **Department spaces**, or navigate to
   `/events/{eventId}/departments`.
3. Confirm every department the session carries is listed, each with a line
   describing the standing that opens it, and that the department currently
   being worked in is marked.
4. Choose **Enter** on a department other than the current one and confirm the
   department dashboard opens for it and the shell's department follows.
5. Disconnect the device from the network, reload the page, and confirm the list
   still renders and the entries can still be followed.
6. Sign out and navigate directly to `/events/{eventId}/departments`; confirm the
   client says it is holding no session rather than showing an empty list.

### One application (`organizer.application-detail`)

7. Sign in as Olive Organizer and open **Applications**.
8. Choose an applicant's name and confirm the detail surface opens at
   `/organizer/applications/{id}`.
9. Confirm the surface shows the scope applied to, the email, when it was
   submitted, and the department interest, with a sentence saying interest is
   not an assignment and no control to edit it.
10. Confirm an application with no interest recorded says so plainly rather than
    showing an empty list.
11. Confirm **Reject** is unavailable until a reason is entered, then reject an
    application with a reason.
12. Reload the surface and confirm it now shows the status, who decided it, when,
    and the reason, and offers no second decision.
13. Copy the address, sign out, sign in as the Staff Coordinator, and open the
    same address. Confirm the application opens and can be decided.
14. Sign in as Dana Departmentlead and open the address of an application naming
    Rangers. Confirm it opens read-only with no Approve, Defer, or Reject.
15. With Dana still signed in, `POST /api/commands/approve-application` for that
    application and confirm the node answers 403 and the application is
    unchanged.
16. Open the address of an application naming no department Dana leads and
    confirm the surface states the node's refusal.

### Event administration (`organizer.events`)

17. Sign in as Olive Organizer and open **Events** under Organization pages, or
    navigate to `/organizer/events`.
18. Confirm every event of the organization is listed, and that each row states
    the published dates and the active event window separately.
19. Confirm an event that names no Incident Command Department of its own reports
    the organization default and says it is inherited.
20. Choose **Create event**, enter a name, an address, and a time zone, and save.
21. Confirm the new event appears in the list.
22. Attempt to create a second event using the address just used and confirm the
    node refuses it, naming the event that already holds the address.
23. Attempt to save an event whose end is before its start and confirm the node
    refuses it.
24. Edit an event that has participating departments and confirm the Incident
    Command selector offers only that event's own departments plus the option to
    inherit the organization default.
25. Choose a participating department, save, and confirm the row now reports that
    department and no longer says "inherited".
26. Using the API, attempt `update-event` with an `ic_department_id` naming a
    department that does not participate in that event, and confirm the node
    refuses it with 422 and the designation is unchanged.
27. Find the event whose active window is open. Confirm the row states that it is
    running now and, where an on-site node is paired for it, names that node as
    holding the event's shifts, attendance, and hours. Confirm **Edit** is still
    offered, open it, set the active window's close to a moment in the past, and
    save. Confirm the save is accepted and the event leaves its active window.
28. Sign in as the Staff Coordinator and confirm **Events** is absent from
    navigation; navigate directly to `/organizer/events` and confirm the surface
    states the node's refusal.

### Audit review (`organizer.audit`)

29. Sign in as Olive Organizer and open **Audit** under Organization pages, or
    navigate to `/organizer/audit`.
30. Confirm the record lists changes with the person who made them, what was
    changed, when, the reason where one was given, and the names of the fields
    that changed.
31. Confirm no entry shows a before or after **value**.
32. Confirm an entry written by a scheduled job names a scheduled job rather than
    leaving the actor blank.
33. Filter by an action and confirm the list narrows; filter by a date range and
    confirm the same.
34. Confirm the action and record-type filters offer only values that appear in
    the record this reader may see.
35. Search the record for any entry naming an incident or a Field Report — by
    scanning the record-type filter and by paging the list — and confirm there is
    none, even though the seeded event has both.
36. Sign in as Vera Staff and confirm **Audit** is absent from navigation;
    navigate directly to `/organizer/audit` and confirm the surface states the
    node's refusal.

### The God Mode audit trail (`orchid.audit`)

37. Sign in to the God Mode console as a user holding `platform.audit` and open
    **Audit Trail** under God Mode.
38. Confirm the trail lists recorded changes newest first, each naming the
    action, the record type in words rather than as a namespace, who made it,
    the organization and department where the row carries them, and the source.
39. Confirm the organization, department, and team filters are present, and that
    choosing each narrows the list.
40. With the team filter set to a team, confirm the list carries changes to the
    team itself and to records belonging to it — a grant, a membership, a shift
    — and no changes belonging to a different team.
41. Confirm the trail carries rows the product surface does not: an incident or
    Field Report entry, and a row with no organization such as a node pairing.
42. Open an entry and confirm it shows the recorded before and after values, the
    reason, the actor identifiers, and the signature metadata where present.
43. Confirm the entry screen offers no edit or delete of any kind.
44. Sign in as a console user without `platform.audit` and confirm both the trail
    and a direct entry address are refused.

### Audit volume controls (`orchid.audit-settings`, God Mode)

45. Still in God Mode, open **Audit Settings**. Confirm the list names every
    organization with its level, its exceptions count, the rows and estimated
    size it is currently holding, and its limits — or "No limit" where none is
    set.
46. Confirm the page states whether this node's audit table is partitioned by
    month, and lists the partitions where it is.
47. Open an organization. Confirm the required actions are listed as always
    recorded and are not editable.
48. Set the level to **Minimal** and save. Perform an ordinary operational
    action in the product — check a staff member in — and confirm no audit entry
    was written for it.
49. Perform a required action — revoke an event credential — and confirm an
    entry **was** written despite the Minimal level.
50. Add `attendance.checked_in` to **Always record**, save, check somebody in
    again, and confirm the entry is now written.
51. Set a **Maximum entries** limit below the organization's current row count,
    save, and choose **Apply limits now**.
52. Confirm the screen reports how many entries were archived, that the row
    count has fallen to the limit, and that the newest entries are the ones that
    remain.
53. Confirm an `audit.archived` entry appears in the God Mode audit trail naming
    the file, the row count, and the range removed.
54. Locate the archive file on the node and confirm it carries one JSON object
    per archived row, including the before and after values.
55. Sign in as a console user holding `platform.audit` but not
    `platform.audit.settings`, and confirm the settings screens are refused
    while the trail still opens.

## Expected results

- **Department spaces.** Every department listed is one the session carries, with
  the standing that opens it named from the node's own role names. Entering
  records the choice, and the shell, navigation, and surface all follow it. The
  screen renders with the device disconnected, because the departments in scope
  are part of the cached session. A client holding no session renders nothing to
  enter and says which of the two nothings that is.
- **One application.** The detail surface reads the application by its own
  address rather than lifting a row out of a filtered queue, so a pasted link
  works. Department interest appears labelled as interest, with a neutral empty
  state, and there is no control to edit it. Rejection requires a reason. A
  decided application shows the decision, the decider, and the reason, and offers
  no second decision.
- **Review authority.** Organizers and Staff Coordinators decide. A department
  lead reads an application naming their department and is refused the decision
  by the node with 403, with the application left Submitted. An application
  naming no department they lead is refused outright.
- **Event administration.** The published dates and the active event window are
  separate fields and are never conflated. A duplicate address, a backwards
  window, and an Incident Command department that does not participate in the
  event are each refused by the node with its own sentence. An event with no
  override reports what it inherits (`ORG-005`) rather than showing a blank. An
  event inside its active window names the node holding its other records and still offers
  the edit, because closing that window is the edit.
  A Staff Coordinator reaches none of it.
- **Audit review.** Every entry attributes the change to an actor, or names a
  scheduled job where there was none. Field names appear; field values do not.
  Filters are applied by the node and offer only values present in what this
  reader may see. No incident or Field Report history appears anywhere in the
  record, at any filter, on any page.
- **The God Mode trail.** The same rows, read as repair tooling: node-wide,
  narrowed by the same organization/department/team filter bar the other God
  Mode lists carry, carrying the incident and Field Report history the product
  surface withholds and the node/system rows no organizer has scope for, and
  showing the recorded values on an entry. `ORG-015` governs what organizing
  reaches, not what support access reaches. Nothing on it is editable, because
  audit rows refuse updates and deletes at the model.
- **One vocabulary.** A row with nobody behind it is called the same thing on
  both surfaces, and a record type reads as the same words on both, because both
  read one definition on the model rather than each formatting its own.
- **Volume controls.** The level governs what is written; the required floor
  overrides it in both directions — a required entry is written at Minimal, and
  no exception removes one. Limits archive rather than delete: the rows leave
  the table only after they are in a file and the archival is itself recorded,
  and the newest history is what survives. Reading the trail and changing what
  the trail will contain are separate permissions.
- **Absences.** Every surface a persona does not hold is absent from navigation
  rather than shown disabled, and every direct navigation to one is refused by
  the node rather than rendering an empty page.

## Evidence to capture

- Screenshot of the department spaces screen with the current department marked,
  and the same screen with the device disconnected
- Screenshot of an application detail surface showing department interest and the
  interest-is-not-assignment sentence
- Screenshot or API response body of the 403 a department lead receives from
  `approve-application`
- Screenshot of the events list showing published dates and active event window
  as separate rows, and of an event inside its window with no edit offered
- API response body of the refused Incident Command designation (step 26)
- Screenshot of the audit record showing changed field names and no values
- Screenshot of the God Mode Audit Trail with a scope filter applied, and of one
  entry showing its before and after values
- Screenshot of Audit Settings showing measured usage beside the configured
  limits, and of the partition list where the node is partitioned
- The `audit.archived` entry from step 53, and the first line of the archive file
  from step 54
- The record-type filter list from step 35, as evidence that no incident or Field
  Report type is offered

## Failure notes

- If a decided application still offers Approve, Defer, or Reject, record the
  status the node reported and whether a second decision was accepted.
- If an audit entry shows a before or after value, record the action and the
  fields — that is a disclosure boundary, not a formatting bug.
- If any incident or Field Report entity type appears in the *product* audit
  record or its filters, stop and record the entity type verbatim; `ORG-015` is
  the rule it crosses. The God Mode trail is expected to carry them.
- If a required action is missing after step 49, stop. The floor is the property
  the whole verbosity feature rests on, and a gap in it means an obligation under
  requirements 2.4 or data/API section 8 is configurable, which it must not be.
- If step 52 removed rows but step 54 finds no archive file, stop and record
  both. History leaving the table without an archive is the one outcome the
  archival ordering exists to prevent.
- If a department lead's approve succeeds, record the application id, the
  resulting status, and whether a staff record was created — an approval creates
  organization standing and is not undone by rejecting afterwards.
- If event administration is reachable by a Staff Coordinator, record which
  capability the session response carried for them.
- If the save in step 27 is refused, record the message verbatim. Closing an
  active event window from the node an organizer is standing at is the one event
  edit that must never be gated on event authority (M12.6), because a refusal
  there leaves a window nobody can close.
