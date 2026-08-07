# QA-INSIGHT-01: Insights Framework

> **Status: waiting on Milestone 17.** This script was added by M18.36 because
> the plan has referenced it since Milestone 17 was specified, and a referenced
> script that does not exist reads as coverage that was never planned. None of
> the surfaces it names are built yet. It is written from the Milestone 17
> acceptance criteria and QA gate, becomes runnable as M17.1 through M17.19
> land, and M17.20 owns correcting it to what actually ships before it is run
> as a gate.

## Purpose

Verify the Insights framework end to end: automatic no-show determination and
late-arrival supersession in the attendance domain, registered metrics placed
on organization-owned sheets, permission and privacy enforcement including the
under-5 suppression rule, live and offline compilation with honest staleness,
Command sharing with originating-department labelling, action links, the
personal volunteer view, and the browser PDF snapshot that Meridian stores no
copy of.

Insights are not Reports. Nothing here replaces the fixed exports covered by
QA-EXPORT-01; a sheet that starts behaving like a report generator is a
finding.

## Requirements covered

- `SLB-023` through `SLB-030`: the ±5% sign-in window, automatic no-show on the
  node holding event authority, exclusion of cancelled and excused shifts,
  preserved manual `mark-no-show`, late-arrival supersession, and idempotent
  determination.
- `INSIGHT-001` through `INSIGHT-062`: metric registration and the render
  contract, sheets and placements, the three permission capabilities, scope
  resolution, restricted-domain and privacy enforcement, operational and
  data-quality state axes, live refresh and offline compilation, Command
  sharing, action links, personal volunteer Insights, browser PDF snapshots,
  favorites, and the three initial metrics (missed shifts, equipment not
  returned, extended shift presence).
- Technical spec sections 20.2 and 21C; data/API sections 5.8, 6.7, 10.19; UI
  implementation contract sections 12.7B and 19B.

## Environment

- Development server environment with a migrated and seeded database
  (`php artisan migrate:fresh --seed`), including the attendance scenario.
- Shared Meridian client (`apps/client`), Admin and Field artifacts.
- One device that can be taken offline for section G.
- A browser able to save the PDF snapshot for section H.

## Personas

From the seeded operational scenario:

- Dana Departmentlead — department lead for Rangers, holding `insights.view`
  and `insights.sheets.manage` for her department and `insights.share_with_command`.
- Olive Organizer — organizer, reading across departments.
- Mira Commandstaff — Command standing, for the receiving side of sharing.
- Omar ICOperator — IC standing, for the IMS-derived metric check.
- Vera Staff — regular staff, for `insights.me` and for what stays hidden.
- Sam Shiftlead — shift lead, whose shift supplies the attendance boundary
  cases.

All sign in with the documented development password.

## Setup data

- The seeded scenario with shifts positioned so that one assignment's ±5%
  sign-in window closes during the session, one shift is cancelled, and one
  assignment is excused.
- Seeded equipment with one item explicitly missing and one shift-assigned
  checkout left open past its shift end.
- A department whose relevant aggregate covers fewer than 5 people (seed or
  narrow a filter until one does).

## Steps

### A. Automatic no-show and supersession

1. As Sam Shiftlead, watch an assignment whose ±5% window closes with no
   check-in. Confirm it becomes a no-show with nobody marking it, and that the
   operation is an audited append-only attendance operation.
2. Confirm the cancelled shift and the excused assignment produced no no-show.
3. Check the same staff member in well after the window. Confirm the derived
   state becomes checked-in, the no-show operation survives in history, and
   the arrival reads late.
4. Re-run the determination (or let it run again). Confirm nothing is written
   twice.

### B. Sheets and placements

5. As Dana Departmentlead, create a sheet and place the missed shifts metric on
   it twice with different per-placement configuration. Confirm both
   placements render independently and hold their own configuration.
6. Add the equipment not returned and extended shift presence metrics, order
   the placements, and confirm the ordering holds.
7. Confirm unknown configuration keys are refused at write time rather than
   ignored.
8. As Vera Staff, confirm no sheet-management surface is reachable.

### C. Scope

9. Open the same sheet as Dana, as Olive Organizer, and as Mira Commandstaff.
   Confirm Dana reads Rangers only, Olive reads across departments, and Mira
   reads Command's own department plus only what has been shared.
10. Confirm sheet-level filters are the only filters offered and that
    selections survive navigation within the session and reset with it.
11. Confirm the sheet reads one event with no comparison or multi-event view.

### D. Privacy and restricted domains

12. As Vera (or any non-IC persona holding `insights.view`), confirm any
    incident- or Field-Report-derived metric is absent entirely. As Omar
    ICOperator, confirm it renders.
13. Narrow a filter until an aggregate covers fewer than 5 people. Confirm the
    value is withheld with a stated privacy reason — never zero, blank, or
    null.
14. Inspect any rendered payload and the audit trail for the session's
    actions. Confirm no volunteer name or other PII appears.

### E. States and action links

15. Find one metric that is simultaneously healthy and stale (stop the node or
    stale the data). Confirm operational state and data-quality state render
    as separate indicators, and that no dismiss/acknowledge/resolve control
    exists.
16. Follow a metric's action link. Confirm it opens the operational surface
    under that surface's own authorization — and that the same link followed
    as Mira from a shared sheet does not admit Command to the sharing
    department's surface.

### F. Command sharing

17. As Dana, share the whole sheet with Command in ongoing mode, and share one
    single placement in temporary mode. Confirm both show Rangers as the
    originating department to Mira.
18. Confirm a restricted metric on the shared sheet stays restricted for Mira,
    and that the placement-level share did not share the metric type's other
    uses.
19. Close the event's operations window (or move past it) and confirm the
    temporary share stops applying on read.
20. Remove the ongoing share and confirm it disappears for Mira. Confirm
    sharing and unsharing are audited, and that favoriting and PDF generation
    are not.

### G. Offline

21. As Dana on a synced device, go offline. Confirm the sheet still renders,
    compiled on-device from permission-scoped synced data, with staleness
    disclosed accurately and incomplete reported where local data is missing —
    never a value reaching past the sync boundary.
22. Reconnect, change an underlying record, and confirm the metric updates
    after sync without a manual refresh.

### H. Personal Insights and the snapshot

23. As Vera Staff, open `insights.me` without holding `insights.view`. Confirm
    it shows her own completed shifts, missed shifts, late arrivals, hours,
    hours by team, and credits, and offers no peer comparison, ranking, or any
    other volunteer's data.
24. As Dana, favorite a sheet and confirm the favorite is personal view state,
    absent from Orchid and from the audit trail.
25. Save the sheet to PDF from the browser. Confirm the file carries the
    visible metrics, filters, states, freshness disclosures, organization,
    event, department, sheet name, timestamp, generating user, and originating
    department; that a suppressed aggregate stays suppressed in it; and that
    the server stored no snapshot entity.
26. Confirm Insights appears in primary navigation and that a sheet the user
    cannot access is absent from the index rather than disabled.

## Expected results

- No-shows derive automatically at the window boundary, exclude cancelled and
  excused shifts, are idempotent, and are superseded — not erased — by a late
  check-in.
- Sheets are organization-owned and configurable; the same metric type carries
  independent configuration per placement; management authority never widens
  data access.
- Every viewer reads exactly their authorized slice of one sheet; IMS-derived
  metrics stay inside the IC pool; under-5 aggregates are withheld with a
  stated reason; no PII renders anywhere.
- Operational and data-quality states are independent axes with no
  acknowledgement workflow.
- Offline devices render honestly from synced data; shares carry their
  originating department, respect restrictions, expire with the window, and
  are audited.
- `insights.me` needs no `insights.view` and reaches nobody else's data.
- The PDF matches the screen, including suppression, and Meridian keeps no
  copy.

## Evidence to capture

- The attendance history showing no-show, superseding check-in, and both
  operations preserved.
- Screenshots of the same sheet as Dana, Olive, Mira, and (absent) Vera.
- A screenshot of a withheld under-5 aggregate with its stated reason.
- A screenshot of one metric showing healthy operational state and stale
  data-quality state at once.
- The audit rows for share and unshare, and the absence of rows for favorite
  and PDF.
- The offline render with its staleness disclosure, and the saved PDF.

## Failure notes

Record the persona, department context, sheet and placement configuration, and
the exact rendering or refusal. A suppressed aggregate rendering as zero or
blank, an IMS-derived metric visible outside the IC pool, PII in any payload,
or a shared action link admitting Command to another department's surface are
privacy findings and block the milestone regardless of how much of the
framework otherwise works. A compiled value found persisted server-side, or a
snapshot entity created by the PDF path, is an architecture finding against
technical spec 21C.
