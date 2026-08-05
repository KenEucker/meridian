# QA-FR-02: Field Reports Taken on Behalf of Another Staff Member

## Purpose

Verify that an operator sitting at a radio can write down a report from somebody
in the field who cannot file it themselves, and that doing so gives the operator
nothing else.

The client has had a dictation form since M18.9. It had no requirement behind
it, no server authorization, and a staff picker that offered whoever the
department read happened to return. This script checks the server side: who may
take a report, whose name they may put on it, and — the part that matters most —
what taking one does **not** grant.

A Field Report is an account of something that happened to a person. Somebody
transcribing it over a radio is a witness to the transcription, not to the
event, and Meridian has to keep those two roles apart on the record and in the
authority that follows from it.

## Requirements covered

- `FR-015`: met for the IC roles only at this milestone. An `ic_operator` or `ic_lead` may create a Field Report on behalf of another staff member of the department carrying the event's Incident Command designation; the reporting staff member is recorded as the author and the creating user as the submitter, and both are visible wherever the report is shown. The Department Operator half of FR-015 is M18.10A's — the whole section 4.8A Operator capability set is — so a `department_operator` is refused here, and a department whose radio watch is not the Incident Command console has nobody who can take a report for it yet.
- `FR-016`: a taken report is immutable on the same terms as any other; append authority follows the recorded author, and the submitter gains no append authority and no access they did not already hold.
- `FR-017`: the selectable reporting staff member is limited to staff the creating user is already permitted to see, and selection discloses nobody outside that scope.
- `FR-007`, `FR-008`, `FR-009`: immutability and author-only appends, which a taken report is held to unchanged.
- Requirements section 4.8A (Department Operational Roles), which defines the Department Operator as the department's dispatch and console function.
- Technical spec sections 17.3 and 17.4 (Alpha 1 Field Report schema; immutability and appends).
- Data/API specification section 10.15 (`field_reports`, `field_report_appends`).
- Meridian Alpha 1 task M18.24A.

## Environment

- Development server environment with a migrated, seeded database
- Shared Meridian client (`apps/client`) in Field mode against a reachable node
- Access to `php artisan tinker` for the record and audit checks

## Personas

- **Omar ICOperator** — holds `ic_operator` through a team in the department carrying the event's Incident Command designation, which the seeded scenario puts on **Rangers**; may take a report for a Rangers member
- **Vera Staff** — ordinary Rangers staff; the reporting staff member most of this script names
- **Gabe Gatekeeper** — Gate department; the person Omar must not be able to name, because Gate is not the department his console serves
- **Dana Departmentlead** — Rangers department lead; senior, and still not permitted to take a report
- **Ivy ICViewer** — `ic_viewer`; reads every Field Report in the event and may take none
- **A Department Operator** — a Gate team carrying the Operator designation, used only to confirm it is refused. Create one if the seeded scenario has none: designate a Gate team as Operator from the department administration surface, and put a persona on that team.

## Setup data

- The seeded event, with Rangers and Gate both participating and **Rangers carrying the Incident Command designation**. Incident Command is a designation set per event and defaulted at the organization, landing on an ordinary operational department — not a separate department of console staff. Confirm which department holds it for your event before you start; if it is not Rangers, read "Rangers" below as whichever department does, and "Gate" as any other participating department.
- Vera Staff active in Rangers; Gabe Gatekeeper active in Gate
- No special Field Report data: this script creates what it needs

## Steps

### A. Who may take a report

1. Sign in as **Dana Departmentlead** and open the Field Report create surface. Look for the dictation option.
2. Sign in as **Ivy ICViewer** and do the same.
3. Sign in as the **Department Operator** and do the same.
4. Sign in as **Omar ICOperator** and open the dictation form.

### B. Whose name may go on it (FR-017)

5. As **Omar ICOperator**, open the staff picker and type `Gabe`.
6. Clear the field and read the whole list the picker offers.

### C. Taking a report (FR-015)

7. As **Omar ICOperator**, pick **Vera Staff**, enter a title such as `Radio report: fence line damage` and a body describing what Vera reported, and submit.
8. Read the submitted report on screen.
9. Ask a developer to read the record:

    ```bash
    php apps/server/artisan tinker --execute='$r = App\Models\FieldReport::latest("created_at")->first(); echo $r->staff->preferred_name . " | " . $r->submittedByUser->name . " | taken=" . var_export($r->wasTakenOnBehalf(), true);'
    ```

### D. What taking it did not grant (FR-016)

10. Still signed in as **Omar ICOperator**, open the report you just took. Look for an Append control.
11. Attempt the append through the API directly:

    ```bash
    curl -X POST "$MERIDIAN_URL/api/commands/append-field-report" -H "Authorization: Bearer $OPERATOR_TOKEN" -H 'Content-Type: application/json' -d '{"field_report_id":"<id>","body":"Adding a detail."}'
    ```

12. Sign in as **Vera Staff**, open your own Field Report list, find the report, and append to it.
13. As **Omar ICOperator**, attempt to attach a photograph to the report.

### E. Scope refusal on the server (FR-017)

14. Ask a developer to attempt a submission naming **Gabe Gatekeeper** as the author, from Omar's device, bypassing the picker entirely — the point is that the server refuses rather than the form declining to offer it.

### F. Both people stay visible (FR-015)

15. Sign in as **Omar ICOperator** and open the IMS Field Report list.
16. Find the taken report and read its row and its detail.

## Expected results

**A. Who may take a report**

- Dana Departmentlead is not offered dictation, and a direct navigation to it is refused. A department lead is senior and may still not file under one of their staff members' names.
- Ivy ICViewer is refused. Reading every Field Report in the event is not authority to create one for somebody else.
- The Department Operator is refused too — **and this one is not a rule.** FR-015 names the Department Operator alongside the IC roles and the authority belongs in their hands; it is withheld because the section 4.8A Operator capability set is M18.10A's to deliver and has not shipped. Record it as a refusal you expect today, not as correct behaviour, and expect it to reverse when M18.10A lands.
- Omar ICOperator reaches the form.
- A caller with no taking authority who requests the directory endpoint receives a **403 with a stated reason**, not an empty list. An empty picker is indistinguishable from an event with nobody in it and tells an operator nothing they can act on. Note that the refusal text currently names Department Operator authority as one of the ways in; that wording predates this boundary and is not a promise that the role works.

**B. Scope**

- Omar's picker offers Rangers staff and **nothing** for `Gabe`. Gabe does not appear greyed out, disabled, or with a note; he is simply not there.
- Cleared, the picker lists Rangers and only Rangers. Omar's console serves the department carrying the Incident Command designation, and taking a report is a console function — requirements 4.8A, "another staff member of the department". The designation adds incident authority on top of that console; it does not widen whose accounts it may write down. Gate's own radio watch will reach Gate through Gate's Operator designation, once M18.10A delivers it.

**C. Taking a report**

- The report submits and appears in Omar's own list.
- The body carries the attribution line naming who filled it out and on whose behalf, as part of the immutable body rather than beside it.
- The record shows **Vera Staff** as the author (`staff_id`) and **Omar** as the submitter (`submitted_by_user_id`), and `wasTakenOnBehalf()` is `true`. Neither identifier was rewritten to look like the other.

**D. What it did not grant**

- Omar is offered **no Append control** on the report he just took.
- The direct API append is refused. Append authority follows the recorded author (FR-009, FR-016), and an operator who spent a shift at a radio must not end up able to add to every account they transcribed.
- Vera Staff finds the report in her own list and **can** append to it. It is her account.
- Omar's photo upload is refused: he was at a radio, not at the scene. If he has a photograph, it is his own observation and his own report to file.
- Omar **can** still read the report he typed. Reading what you wrote down is not new access.

**E. Server-side scope**

- The submission naming Gabe Gatekeeper is refused by the node with a message about not being authorized to take a report for them, and **no Field Report row is created**. The picker's scope is a convenience; this refusal is the boundary.
- The refusal for an out-of-scope real staff member and the refusal for an unknown staff id read the same, so neither can be used to find out who exists.

**F. Visibility**

- The IMS list row names the author and marks the report as taken, so a reader can tell it was taken rather than written before they weigh what it says.
- The detail view shows both people. Neither is hidden behind the other.

## Evidence to capture

- Screenshot of Omar ICOperator's picker with `Gabe` typed and no result, and of the cleared picker listing Rangers only
- The refusal the Department Operator receives, noted as the M18.10A boundary rather than as a rule
- The tinker output showing author, submitter, and `taken=true`
- The refused append response for the submitter and the successful append by the author
- The refused out-of-scope submission, with the message and a count showing no row was created
- Screenshot of the IMS list row and detail showing both people

## Failure notes

Record the persona, their roles and the department those roles hang on, the
event, and whether the surface or the server produced the refusal.

Two failures are blocking:

- **The submitter can append.** This is FR-016 exactly, and it is the failure that makes a taken report indistinguishable from one the operator wrote themselves.
- **The picker or a refusal discloses somebody out of scope.** Including a differently worded refusal for a real staff member than for an invented one. A boundary that can be probed is not a boundary.
