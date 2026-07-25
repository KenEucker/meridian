# QA-INC-02: Incident Search, List Filters, Paging, and Presets

## Purpose

Verify that IC users can find incidents through explicit list search, filters,
and sortable headings — beyond opening the list/detail pages and clicking Name
Reference chips — and that none of that behavior widens who can see an incident.
Search and filters are a read concern: they only ever narrow rows the requesting
user could already see for the requested event.

## Requirements covered

- `INC-001` through `INC-015`: incidents are event-scoped operational records visible only to IC roles, editable regardless of status, with status affecting filtering only, and with history preserved rather than deleted.
- `NR-001` through `NR-014`: Name Reference chips run normal permission-filtered search for the reference text without the `@` prefix, never a profile or identity surface, and never reveal incidents or Field Reports the user cannot see.
- Technical spec sections 19.9 and 19.10 (Name Reference search and permission filtering).
- UI implementation contract sections 12.7 (`ims.incidents`) and 15.1 (sortable headings; state and priority filters defaulting to active states while still allowing Closed to be included).
- IMS surface specification section 9 (list cross-links, sortable headings, filters).
- Data/API specification sections 5.1 (list query parameters and paging) and 10.16A (`incident_list_presets`).

## Documented search and filter rules

- Free-text search covers the incident number, title, location name/address/details, incident type labels, responder names, active timeline notes, and actively attached Field Reports, plus Name Reference tokens.
- A leading `@` or `#` is stripped, so a Name Reference chip and a typed query reach the same list search.
- Stricken notes and unlinked Field Reports never produce a match; they are history, not current content.
- `state` defaults to `active` and excludes Closed; `all` and each canonical status are selectable.
- `priority`, `type`, and `responder` default to all and narrow to one value.
- `started_from` / `started_to` bound the incident started timestamp; a bare date upper bound covers that whole day.
- Filters combine (AND); search does not replace the other filters.
- Sorting is available on the incident, state, priority, types, location, and last-update headings. State and priority sort by operational order (Open → Closed, Critical → Routine), not alphabetically.
- Unknown filter values are refused (422), never silently defaulted.
- The IC permission check runs before any filter is parsed.
- Paging uses `page` (default 1) and `per_page` (default 25, maximum 100). The reported total counts the filtered result, not the whole event, and deterministic sort tiebreakers keep a record from appearing on two pages or being skipped between them.
- Changing any filter, search, or sort returns to the first page, because page 4 of the previous result is meaningless against a new one.
- Saved presets are personal view state for one user on one event. They store the selection but never the paging position, are never an authorization source, and are unique by name per user per event with a save-over-existing-name overwrite.

## Environment

- Development server environment with migrated database and `DevelopmentScenarioSeeder` (or equivalent personas)
- Shared Meridian client (`apps/client`) IMS surfaces at `/ims/incidents`
- For API checks, authenticated requests against `GET /api/events/{event}/incidents` with `search`, `state`, `priority`, `type`, `responder`, `started_from`, `started_to`, `sort`, `direction`, `page`, and `per_page`, plus the `save-incident-list-preset` and `delete-incident-list-preset` commands

## Personas

- `ic_viewer` for the event's configured IC department
- `ic_operator` for the same event (to add notes and attach Field Reports)
- `ic_viewer` for a **different** event (cross-event denial)
- Organizer with no IC grant (must fail closed)
- Department lead outside the IC department (must fail closed)

## Setup data

- One event with a configured Incident Command department, plus a second event with its own IC department
- In the first event, at least four incidents:
  - `INC-…-000101` "Medical assist near Gate A", state On Scene, priority Serious, type `Medical`, responder `Vera Ranger`, location name `Gate A`
  - `INC-…-000102` "Radio relay check", state Monitoring, priority Routine, type `Radio`, no responders
  - `INC-…-000103` "Closed supply handoff", state Closed, priority Important, type `Logistics`
  - `INC-…-000104` "Later record", started at least one day after the others
- On `…000102`, one operational note reading `Monitoring signal reports from the west side.`
- On `…000101`, one attached Field Report whose body contains `@Blue-Hat`
- On `…000101`, one additional note reading `Retracted detail.` that is then stricken
- In the second event, one incident titled `Medical assist near Gate A` (same text, different event)

## Steps

1. Sign in as the `ic_viewer` for the first event and open `/ims/incidents`. Confirm the default list shows the active incidents, excludes the Closed incident, and states how many incidents match the current filters.
2. Confirm the page offers a Home link and a cross-link to the IC Field Reports list.
3. Set **State** to `All states`. Confirm the Closed incident appears. Set **State** to `Closed`. Confirm only the Closed incident appears.
4. Reset, then set **Priority** to `Serious`. Confirm only `…000101` remains and the summary count updates.
5. Set **Type** to `Radio`. Confirm only `…000102` remains, and that the Type control lists only types actually used by this event's incidents.
6. Reset, then set **Responder** to `Vera Ranger`. Confirm only `…000101` remains, and that the Responder control lists only responders on this event's incidents.
7. Search for `west side`. Confirm `…000102` is found through its note body.
8. Search for `Gate A`. Confirm `…000101` is found through its location, and that the second event's identically titled incident is not shown.
9. Search for `Retracted`. Confirm nothing matches, because the note was stricken.
10. Unlink the attached Field Report from `…000101` as the `ic_operator`, then search for text unique to that report. Confirm it no longer matches, and that the incident's history still records the attachment.
11. Re-attach the Field Report, then open `…000101` and click the `@Blue-Hat` Name Reference chip. Confirm it runs a normal list search for `Blue-Hat`, returns the incident, and does not open a Name Reference profile, detail page, drawer, or management screen.
12. With a search active, also set **Priority** to `Routine`. Confirm the filters combine rather than replace each other, and that **Reset filters** returns to the default active list.
13. Sort by the **State** heading ascending. Confirm the order follows Open, On Scene, Monitoring, On Hold, Closed rather than alphabetical order.
14. Sort by the **Priority** heading ascending. Confirm Critical sorts first and Routine last; reverse the direction and confirm the order inverts.
15. Sort by the **Incident**, **Types**, **Location**, and **Last update** headings. Confirm each toggles direction and that the sorted column is announced through `aria-sort`.
16. Apply a started-window filter through the API: `?started_from={day of …000104}`. Confirm only `…000104` is returned, and that `?started_to={day of the others}` returns the earlier incidents including the whole of that day.
17. Request `?state=archived`, `?priority=Urgent`, `?sort=responder`, `?direction=sideways`, and `?started_from=not-a-date`. Confirm each returns 422 with a readable message and no incident rows.
18. Request `?started_from=` a date later than `?started_to=`. Confirm it is refused rather than silently returning nothing.
19. Sign in as the `ic_viewer` for the **second** event and request the first event's list with a search that would match. Confirm 403 with the restricted-access message and no rows.
20. Repeat step 19 as the organizer and as the non-IC department lead. Confirm 403 in both cases.
21. Sign out and request the list unauthenticated. Confirm 401.
22. Confirm no filter or search response contains an incident from another event.
23. Set **Per page** to 10 on an event with more than ten active incidents. Confirm the page controls appear, the summary reports the total match count alongside the current page, and **Previous** is unavailable on the first page.
24. Page forward to the last page. Confirm **Next** is unavailable there, that the active filters and sort survive the move, and that no incident appears on two pages or is skipped between them.
25. While on page 2, change a filter. Confirm the list returns to page 1 rather than showing an empty page of a smaller result.
26. Request `?per_page=101`, `?per_page=0`, and `?page=0` through the API. Confirm each returns 422.
27. Request a page number beyond the end through the API. Confirm it returns 200 with an empty `incidents` array and an accurate `pagination.total`, not an error.
28. Apply a filter combination, enter a preset name, and save it. Confirm the preset appears in the saved list.
29. Reset the filters, then apply the saved preset. Confirm the full selection is restored, the list lands on page 1, and the result matches what was saved.
30. Save again under the same name with a different selection. Confirm it overwrites rather than creating a duplicate.
31. Attempt to save a preset with a blank name. Confirm it is refused and no preset is created.
32. Sign in as a second IC user for the same event. Confirm they see none of the first user's presets and cannot delete one by ID.
33. Confirm the same user's presets for a different event do not appear on this event's list.
34. Delete the preset and confirm it disappears from the saved list for that user.
35. As the organizer, revoked IC user, and cross-event IC user, attempt to save and delete a preset. Confirm 403 in every case, and 401 unauthenticated.

## Expected results

- The incident list defaults to active states, excludes Closed incidents, and can include or isolate them on request.
- State, priority, type, responder, started-window, and free-text search all narrow the list, and they combine rather than override one another.
- Free-text search finds incidents by number, title, location, type, responder, active note, and actively attached Field Report; stricken notes and unlinked Field Reports do not match.
- Name Reference chip navigation continues to run normal permission-filtered list search and opens no identity surface.
- Headings sort in both directions; state and priority follow operational order rather than alphabetical order.
- Invalid filter values are refused with 422 instead of silently falling back to a default.
- IC permission is checked before any filter is applied: cross-event, organizer, non-IC department lead, and unauthenticated actors receive 403/401 with no rows, regardless of the search or filters requested.
- No search or filter result crosses events.
- Paging reports the filtered total, keeps filters and sort across page moves, returns to page 1 when the selection changes, refuses out-of-range page sizes, and answers an out-of-range page number with an empty page rather than an error.
- Presets save, reapply, overwrite by name, and delete for their owner only; they never carry paging position, never cross users or events, and never reveal an incident the applying user could not already see.

## Evidence to capture

- Screenshot of the default active list with the result summary, and of the same list with `All states` selected showing the Closed incident.
- Screenshot of a combined search plus filter, with the Reset filters control visible.
- Screenshot or notes showing state and priority sort order matching operational order.
- API responses for the 422 cases and for the 403/401 denial cases.
- Notes confirming a stricken note and an unlinked Field Report produce no search match while their history remains.
- Screenshot of the page controls showing the total match count and current page position.
- Screenshot of the saved preset list, plus notes confirming a second IC user sees none of them.

## Failure notes

- If any search or filter returns an incident from another event or to an actor without IC access, stop testing and file a blocking permission issue (INC-002, NR-011).
- If a Name Reference chip stops running normal search, or opens a profile/detail/management surface, stop testing and file an NR-004/NR-013 regression.
- If stricken notes or unlinked Field Reports become findable through search, stop testing and file a history-versus-current-content issue (INC-013, INC-014).
- If the list stops defaulting to active states, or Closed incidents can no longer be included, file a UI contract 15.1 regression.
- If an unknown filter value silently falls back to a default, file an issue: a filter that quietly ignores input misrepresents what the operator is looking at.
- If an incident appears on two pages or is skipped between them, stop testing and file a sort-stability issue: a paged incident list that loses records is worse than an unpaged one.
- If one user can see, apply, overwrite, or delete another user's preset, stop testing and file a blocking privacy issue.
- If applying a preset returns an incident the user could not otherwise see, stop testing and file a blocking permission issue: presets must never be an authorization path.
