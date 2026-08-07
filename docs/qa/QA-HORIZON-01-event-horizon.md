# QA-HORIZON-01: The Event Horizon

## Purpose

Verify the Event Horizon end to end: the readiness list compiled on read from
the five fixed item kinds, the presentation window derived from the
organization's configured lead-up and the event's own dates, ordering decided
by the node and rendered as sent, completed items retained and marked rather
than removed, action links entering their surfaces under those surfaces' own
authorization, offline rendering that never presents "nothing outstanding" it
has not established, and the personal whole-surface dismissal that is refused
while anything is outstanding and returns when something is again.

The Event Horizon is a report, not a workflow. It gates nothing, refuses no
operation of its own, and stores nothing beyond one personal preference per
staff member per event — a compiled item, an outstanding count, or a readiness
state found persisted server-side is an architecture finding against technical
spec 21D.3 regardless of how well the surface renders.

## Requirements covered

- `HORIZON-001` through `HORIZON-009`: the surface, event-access-only
  authorization with unreadable kinds absent rather than unknown, the fixed
  five-kind catalogue, item anatomy and action links, retained completed
  items, deterministic ordering, no per-item dismissal, gating nothing, and
  nameless coverage gaps.
- `HORIZON-010`, `HORIZON-011`: single-event presentation and the configurable
  lead-up window with its 30-day default.
- `HORIZON-012` through `HORIZON-015`: personal dismissal, its server-side
  refusal while outstanding, its invisibility to others, and its return.
- `HORIZON-016`: offline compilation with honest incompleteness.
- `POL-045`, `WAIVER-006`, `TRAIN-002`, `TRAIN-008` through `TRAIN-010`,
  `SHIFT-004`, `SHIFT-007`, `SHIFT-008`, `SHIFT-011`, `SHIFT-017`,
  `SHIFT-018`, `CRED-005`: the rules each kind restates without re-deciding.
- `ORG-018`, `ORG-020`, `ORG-021`: the lead-up window as audited, freeze-bound
  organization configuration.
- Technical spec section 21D; data/API sections 5.8A and 10.21; UI
  implementation contract sections 12.3 and 19C.

## Environment

- Development server environment with a migrated and seeded database
  (`php artisan migrate:fresh --seed`)
- Shared Meridian client (`apps/client`), Field artifact
- An event whose active event window can be moved during the session, and a
  device that can be taken offline for section F
- For API checks, authenticated requests against
  `/api/events/{event}/event-horizon`,
  `/api/commands/hide-event-horizon`, `/api/commands/show-event-horizon`, and
  `/api/commands/update-organization-configuration`

## Personas

- Vera Staff — a Rangers member on a team, holding no roles at all
- Tam Teamlead — a designated lead (`membership_role = lead`) of one Rangers
  team, and not of the others
- Olive Organizer — organizer, for the lead-up window configuration
- Gus Godmode — a console login holding no staff profile in the organization,
  for the standing refusal

## Setup data

- The seeded scenario, with the event's active window set so that today is
  inside the 30-day lead-up (for example, the window starting two weeks from
  now)
- One organization-scoped acknowledgment requirement on a published document
  Vera has not acknowledged, and one she acknowledged before its document
  gained a revision
- One waiver in Vera's scope with no completion, one with an expired
  completion, and one with a current completion
- One online training and one in-person training required by Vera's department
  or team, one training whose completion has expired, and one training
  required by a shift Vera holds
- Shifts open to Vera's team: one with capacity remaining and a signup close
  in the future, one full, one whose signup window has closed, and one Vera is
  already on
- Shifts for Tam's led team with capacity, at least one below it

## Steps

### A. Presence and the presentation window

1. As Olive, read the organization configuration and confirm the Event
   Horizon lead-up window reads 30 days without anyone having set it.
2. As Vera, inside the lead-up window, confirm **Event Horizon** appears in
   the workflow menu and opens the readiness list for the event.
3. As Olive, set the lead-up window to a length that puts today outside it.
   Confirm the change is audited with before and after values, then as Vera
   confirm the menu entry is gone, and that the typed address lands at home
   rather than on an empty page. Restore the lead-up afterwards.
4. Attempt the same configuration change during an active event window (or
   move the window over today first) and confirm it is refused as frozen, the
   same way the rest of organization configuration is.
5. Move the event's active window and confirm the lead-up moved with it
   without anyone re-entering a date — the window is days, not a date.
6. As Gus (no staff profile in the organization), request
   `/api/events/{event}/event-horizon` and confirm a refusal — not an empty
   list.

### B. The items and their kinds

7. As Vera, read the list. Confirm each item states what it is, how it
   currently evaluates, and what would complete it, and carries one action
   link — and that no item carries a dismiss, snooze, or mark-as-done control.
8. Confirm the unacknowledged requirement reads outstanding, and the one
   acknowledged at an earlier document version reads complete (`POL-045`).
9. Confirm the never-completed waiver and the expired waiver both read
   outstanding — the expired one saying it expired and naming renewal — and
   the current one reads complete and stays listed.
10. Confirm the expired training reads outstanding and says it expired rather
    than "incomplete"; the online training's link opens its training page and
    the in-person one's its session signup; and the shift-required training
    carries that shift's start as its deadline.
11. Confirm the open shift with capacity reads outstanding with its close as
    its deadline, the full shift and the closed-signup shift produce no item
    at all, and the shift Vera already holds reads complete rather than
    disappearing.
12. Follow an action link and confirm it enters the linked surface under that
    surface's own authorization.

### C. Ordering

13. Confirm outstanding items come before completed ones; within each,
    soonest deadline first with undated items after dated ones, catalogue
    order breaking ties — and that there is no sort control, filter, or
    search on the page.

### D. Coverage gaps for leads

14. As Vera, confirm no coverage gap item and no coverage gap kind appears.
15. As Tam, confirm gaps appear for the led team's under-capacity shifts
    only — not for other teams — reporting the shortfall against capacity,
    and inspect the payload to confirm **no staff name appears in it**
    (`HORIZON-009`). Who is assigned is read on the linked shift.

### E. Personal dismissal

16. As Vera with items outstanding, confirm no hide control renders, then
    issue `hide-event-horizon` directly against the API and confirm it is
    refused (`HORIZON-013` is server-side, not a hidden checkbox).
17. Resolve or remove Vera's outstanding items, re-read, and confirm the hide
    checkbox appears, states that it is a personal preference scoped to this
    event, signs nothing off, and names `staff.me` as the way back.
18. Hide it. Confirm the menu entry disappears, the preference is absent from
    the audit trail, and another member's read of the same event reports
    their own preference unaffected.
19. From **Me**, restore it and confirm the entry returns.
20. Hide it again, then make something newly outstanding for Vera (a new
    acknowledgment requirement is the quickest). Confirm the surface returns
    on its own, and that completing the new item afterwards does **not**
    re-hide it — the old dismissal did not survive the new item.

### F. Offline

21. As Vera on a device that has read the list, stop the node or disconnect,
    and reopen the surface. Confirm it renders the stored copy with the
    moment it was taken disclosed, states that the copy cannot establish
    "nothing outstanding", and offers no hide control off a stored copy.
22. Reconnect, change an underlying record, re-read, and confirm the item
    moved without anything being written by the read itself.

## Expected results

- The surface is present exactly within the lead-up window through the close
  of the operations window, for a single resolved event, and absent — not
  empty, not explained — outside it.
- The catalogue is the five fixed kinds and nothing offers a way to add,
  remove, reorder, or configure one; the lead-up length is the only
  organization-configurable value, audited and freeze-bound.
- Every item states its evaluation and its completion, links to the surface
  that resolves it, and completes only because the record behind it changed.
- Expired waivers and trainings read outstanding with expiry named; an
  earlier-version acknowledgment stays complete; full and closed shifts
  produce no item; a held shift reads complete; gap items carry no names.
- Hiding is refused server-side while anything is outstanding, is invisible
  to everyone else and to the audit trail, restores from Me, and does not
  survive a new outstanding item.
- An offline render is disclosed as a stored copy and never claims readiness
  it has not established.

## Evidence to capture

- The configuration audit rows for the lead-up window change, and the freeze
  refusal during the active window.
- Screenshots of the same list as Vera (no gap kind) and Tam (gaps, no
  names), and the ordering with mixed deadlines.
- The API refusal for the outstanding-items hide, and the absence of audit
  rows for hide and restore.
- The offline render with its stored-copy disclosure.

## Failure notes

Record the persona, the event's window dates, the configured lead-up, and the
exact item wording. A staff name in a coverage gap payload is a privacy
finding and blocks the milestone. A hide that succeeds while an item is
outstanding, a compiled item or readiness state found persisted server-side,
or an offline render presenting "nothing outstanding" from a stored copy are
findings against HORIZON-013, technical spec 21D.3, and HORIZON-016
respectively, and block the milestone regardless of how much of the surface
otherwise works.
