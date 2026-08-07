# QA-GOD-03: Orchid Repair Visibility — Acknowledgments, Field Reports, and Incidents

## Purpose

Prove that the three record screens M18.34 adds to the God Mode console show what a repair operator needs and nothing a console permission was never meant to buy.

Two of them are unusual, and the whole point of this script is the difference. **Document Acknowledgments** behaves like the rest of the console: the permission opens it and it spans every organization on the node. **Field Reports** and **Incidents** do not. Their console permission opens a screen, and then the product's own rules — the ones that decide whether a staff member may read a Field Report, and whether they may read an incident — decide what is on it. A technician holding every console permission and no standing in any event reads two empty lists, and that is the correct result rather than a bug.

The line being checked is between a record and its history. The audit trail (`QA-ORG-04`, its God Mode section) carries every incident and Field Report row on the node, because a row saying who reopened an incident is history about a change and repair work needs it. These screens are the records themselves. Record a failure of the audit trail there, not here.

## Requirements covered

- UI implementation contract section 12.9: `orchid.document-acknowledgments`, `orchid.field-reports`, and `orchid.incidents`, and the rule that the last two are gated twice.
- Technical spec sections 22.1, 22.2, and 22.3: Orchid is repair and break-glass tooling; the Alpha 1 screen list; God Mode cannot edit a finalized Field Report body and provides no console append, redaction, or attachment-deletion workflow in Alpha 1.
- `POL-043` through `POL-045`: acknowledgments are immutable and record the document and fragment revision that was accepted.
- `POL-026`: a requirement is asked at an organization or a department, never at a team.
- `FR-004` through `FR-007`, `FR-012`, `FR-015`, `FR-016`: author and submitter visibility, event-wide Incident Command visibility, immutability, photo access under its own capability, and the split between the person whose account it is and the person who typed it in.
- `INC-001`, `INC-007`, `INC-014`: incidents are event-scoped, their history is append-only, and a stricken entry is annotated rather than removed.
- `ORG-015`: organizing does not reach incidents or Field Reports — and console access is not organizing either.

## Environment

- Development or standalone node with a migrated and seeded database (`php artisan migrate:fresh --seed`).
- The God Mode console at `/admin`.
- At least two organizations on the node, each with an event that has Field Reports and incidents. The seeded operational scenario provides one; add a second organization and event so the organization filter has something to exclude.

## Personas

| Persona | Standing | Used for |
|---|---|---|
| Tessa Technician | Every console permission listed below. **No staff profile at all.** | The empty-list checks — the heart of this script |
| Gwen Godmode | Every console permission, and an `ic_viewer` grant on the first organization's event through a team in that event's Incident Command department | Reading records the product would also show her |
| Devin Deptlead | Every console permission, and a `department_lead` grant in the first organization. No Incident Command grant | The FR-006 boundary |
| Reggie Reporter | A staff member who has filed a Field Report, plus the Field Report console permission | Author visibility |

Console permissions used: `platform.index`, `platform.document-acknowledgments`, `platform.field-reports`, `platform.incidents`.

## Setup data

Build this before you start, and note what you built:

- **Acknowledgments.** In organization one, a published policy accepted by at least one staff member; then edit the policy so its revision advances, and have a second staff member accept the new revision. In organization two, a published procedure accepted by somebody. In organization one, a **department-scoped** requirement on one department, accepted by a member of it.
- **Field Reports.** In event one: a report filed by Reggie for himself; a report filed by somebody else entirely; and a report **taken on behalf of** a third staff member by an operator (so its author and its submitter are different people). Add an append to one report, by its author. Attach a photo to one report. In event two, under organization two: one report, so there is something the event boundary must exclude.
- **Incidents.** In event one: an open incident with at least three timeline entries, one of which has been **stricken** with a reason; and a closed incident. In event two: one incident.
- Confirm Gwen's `ic_viewer` grant is scoped to **event one** and that event one's Incident Command department is the one her team sits in.

## Steps

### A. Document Acknowledgments

1. **Open the screen.** Sign in as Tessa and open **Document Acknowledgments** from the Policies & Procedures section of the console navigation. Confirm it opens — this is the screen a console permission *does* fully buy.
2. **Read a row.** Confirm each row names who accepted, the document by title, the kind (policy or procedure), the document revision, the fragment revision, the scope it was asked in, and the node it was accepted on. Confirm the two revisions are separate columns rather than one combined number.
3. **Confirm the version survived the edit.** Find the acceptance recorded before you advanced the policy's revision. Confirm it still names the **earlier** revision, and that the later acceptance names the newer one. An acceptance is an acceptance of a version; if both rows now read the current revision, that is a POL-045 failure and the most important thing on this page.
4. **Confirm it spans organizations.** Confirm acceptances from both organizations are listed together with no filter applied.
5. **Narrow by organization.** Filter to organization one. Confirm organization two's acceptance is gone, and confirm the **department-scoped** acceptance from organization one is still present — narrowing to an organization means all of it, not only what was asked organization-wide.
6. **Narrow by department.** Filter to the department that carries the department-scoped requirement. Confirm only its acceptance remains.
7. **Confirm there is no team filter.** Confirm the filter bar offers organization and department and no team control at all — not a disabled one, not an empty one.
8. **Confirm it writes nothing.** Confirm there is no create, edit, or delete control anywhere on the screen, and no row action that opens one.
9. **Confirm the permission.** Sign in as a user holding `platform.index` only. Confirm the screen is refused and that Document Acknowledgments is absent from the navigation rather than present and failing.

### B. Field Reports — the empty list

10. **Open the screen as a technician.** Sign in as Tessa and open **Field Reports** from the God Mode section. Confirm the screen opens and the list is **empty**, despite the node holding several reports. Confirm the screen says why in its own description rather than leaving an unexplained blank.
11. **Confirm a typed address does the same.** Copy a report's console address (take it from Gwen's session in step 13, or from the database). As Tessa, paste it in. Confirm it is refused — not an empty page, a refusal.
12. **Confirm the department lead boundary.** Sign in as Devin, who is senior, obviously responsible, and holds no Incident Command grant. Confirm Field Reports opens and is empty. This is the case FR-006 names explicitly and the one a console screen is most likely to get wrong.

### C. Field Reports — what a permitted reader sees

13. **Read an event's reports.** Sign in as Gwen. Confirm Field Reports lists **every report of event one** and **no report of event two**. Confirm the columns name the FRA number, title, event, department, author, and whether it was taken by somebody else.
14. **Confirm author and taker are not conflated.** Find the report taken on behalf of a third staff member. Confirm the **Author** column names the staff member whose account it is, and the **Taken by** column names the operator who typed it in. Confirm a report somebody filed for themselves shows nothing in Taken by rather than repeating the author.
15. **Read a report.** Open one. Confirm the original title and body are shown, and that the appends appear beneath them attributed and timestamped rather than merged into the body.
16. **Confirm nothing is editable.** Confirm every field is read-only and that there is no save, append, redact, or delete control anywhere on the entry — including for a report Gwen could append to in the product, if she authored one.
17. **Confirm photos are counted and not served.** On the report with a photo, confirm the entry states how many photos are attached and does not display, link, or name them. Downloading one answers to its own capability, and Alpha 1 offers no console redaction or deletion.
18. **Read an author's own report.** Sign in as Reggie. Confirm Field Reports lists **his own** report and nothing else on the node — not the one filed by somebody else in the same event.
19. **Narrow by scope.** As Gwen, confirm the filter bar offers organization, department, and team. Filter by organization and confirm reports outside it disappear.

### D. Incidents

20. **Open the screen as a technician.** As Tessa, open **Incidents**. Confirm it opens and is **empty**. Confirm a typed incident address is refused rather than served.
21. **Read an event's incidents.** As Gwen, confirm Incidents lists event one's incidents — the open one and the closed one — and **not** event two's.
22. **Confirm the filter bar.** Confirm it offers an **organization** control and **no** department or team control. An incident carries neither, and an inert control that never narrows anything is worse than an absent one.
23. **Narrow by organization.** Give Gwen an Incident Command grant on event two as well, reload, and confirm both events' incidents are listed. Filter to organization one and confirm organization two's incident disappears.
24. **Read an incident.** Open the open incident. Confirm its number, status, priority, types, event, start, close state, who opened it, location, assigned staff, and its full history are shown.
25. **Confirm the stricken entry is present and marked.** In the history, confirm the stricken timeline entry appears, is annotated as stricken with its reason, and that its original body is still readable. A strike annotates history; it does not remove it, and a repair screen is exactly where somebody needs to read what was withdrawn.
26. **Confirm nothing is editable.** Confirm every field is read-only and there is no control that would change a status, a priority, an assignment, or the history. Technical spec 22.3 permits God Mode to repair an incident; it has to happen through a path that records the change in the incident's own history, and this screen is not one.
27. **Confirm the permissions.** Sign in as a user holding `platform.index` only. Confirm both Field Reports and Incidents are refused and absent from the navigation.

## Expected results

- Document Acknowledgments opens on its console permission, spans every organization, names both revisions, keeps an earlier acceptance at its earlier revision, narrows by organization (departments included) and by department, offers no team filter, and writes nothing.
- Field Reports and Incidents open on their console permissions and then show a reader only what the product would: nothing at all for a console operator with no staff standing, nothing for a department lead outside Incident Command, an author's own reports for their author, and one event's records for somebody holding Incident Command standing in that event.
- A typed address into either screen is refused for a record the reader may not see. The list filter is not the rule; the rule is applied on the entry too.
- No screen in this script offers a write of any kind, and a Field Report photo is counted rather than served.
- An incident's history is complete, including entries that were stricken, each marked as such with its reason.
- Every screen is refused, and absent from the navigation, for a user holding only `platform.index`.

## Evidence to capture

- The Field Reports and Incidents screens as Tessa, empty, with the description that explains why.
- The refusal page from a typed Field Report address as Tessa.
- Field Reports as Gwen, showing event one's reports only, with the Author and Taken by columns distinguishable on the taken-on-behalf row.
- A Field Report entry showing the original body, an append, and the photo count.
- Document Acknowledgments showing two acceptances of the same document at different revisions.
- Document Acknowledgments narrowed to organization one, with the department-scoped row still present.
- An incident entry showing the stricken timeline entry, marked and readable.
- The console navigation as a `platform.index`-only user, showing none of the three entries.

## Failure notes

Record for each failure: the screen, the persona and the exact permissions and grants they held, the record involved, what was shown, and what should have been.

Two kinds of failure are worth separating and stating plainly, because they are opposite mistakes:

- **A screen showed too much.** A console operator with no staff standing saw a Field Report or an incident; a department lead saw somebody else's report; a typed address served a record the list had excluded; a photo was displayed or named. These are disclosure failures against `FR-005`, `FR-006`, or `ORG-015` and should be raised immediately rather than filed.
- **A screen showed too little or wrote something.** A permitted reader could not see a record the product shows them; a stricken entry was missing from a history; an acknowledgment's revision moved when the document did; any control on any of the three screens changed a record.

An empty Field Report or Incident list for an operator with no staff standing is **not** a failure. It is the result this script exists to confirm.
