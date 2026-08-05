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

- `FR-015`: a Department Operator, `ic_operator`, or `ic_lead` may create a Field Report on behalf of another staff member; the reporting staff member is recorded as the author and the creating user as the submitter, and both are visible wherever the report is shown.
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

- **Omar ICOperator** — holds `ic_operator` for the event's Incident Command Department; may take a report for anybody in the event
- **A Department Operator** — a Rangers team carrying the Operator designation; may take a report for a Rangers member only. Create one if the seeded scenario has none: designate a Rangers team as Operator from the department administration surface, and put a persona on that team.
- **Vera Staff** — ordinary Rangers staff; the reporting staff member most of this script names
- **Gabe Gatekeeper** — Gate department; the person a Rangers Operator must not be able to name
- **Dana Departmentlead** — Rangers department lead; senior, and still not permitted to take a report
- **Ivy ICViewer** — `ic_viewer`; reads every Field Report in the event and may take none

## Setup data

- The seeded event, with Rangers, Gate, and the Incident Command Department all participating
- Vera Staff active in Rangers; Gabe Gatekeeper active in Gate
- No special Field Report data: this script creates what it needs

## Steps

### A. Who may take a report

1. Sign in as **Dana Departmentlead** and open the Field Report create surface. Look for the dictation option.
2. Sign in as **Ivy ICViewer** and do the same.
3. Sign in as the **Department Operator** and open the dictation form.
4. Sign in as **Omar ICOperator** and open the dictation form.

### B. Whose name may go on it (FR-017)

5. As the **Department Operator**, open the staff picker and type `Gabe`.
6. Clear the field and read the whole list the picker offers.
7. As **Omar ICOperator**, open the staff picker and type `Gabe`.

### C. Taking a report (FR-015)

8. As the **Department Operator**, pick **Vera Staff**, enter a title such as `Radio report: fence line damage` and a body describing what Vera reported, and submit.
9. Read the submitted report on screen.
10. Ask a developer to read the record:

    ```bash
    php apps/server/artisan tinker --execute='$r = App\Models\FieldReport::latest("created_at")->first(); echo $r->staff->preferred_name . " | " . $r->submittedByUser->name . " | taken=" . var_export($r->wasTakenOnBehalf(), true);'
    ```

### D. What taking it did not grant (FR-016)

11. Still signed in as the **Department Operator**, open the report you just took. Look for an Append control.
12. Attempt the append through the API directly:

    ```bash
    curl -X POST "$MERIDIAN_URL/api/commands/append-field-report" -H "Authorization: Bearer $OPERATOR_TOKEN" -H 'Content-Type: application/json' -d '{"field_report_id":"<id>","body":"Adding a detail."}'
    ```

13. Sign in as **Vera Staff**, open your own Field Report list, find the report, and append to it.
14. As the **Department Operator**, attempt to attach a photograph to the report.

### E. Scope refusal on the server (FR-017)

15. Ask a developer to attempt a submission naming a Gate staff member as the author, from the Department Operator's device, bypassing the picker entirely — the point is that the server refuses rather than the form declining to offer it.

### F. Both people stay visible (FR-015)

16. Sign in as **Omar ICOperator** and open the IMS Field Report list.
17. Find the taken report and read its row and its detail.

## Expected results

**A. Who may take a report**

- Dana Departmentlead is not offered dictation, and a direct navigation to it is refused. A department lead is senior and may still not file under one of their staff members' names.
- Ivy ICViewer is refused. Reading every Field Report in the event is not authority to create one for somebody else.
- The Department Operator and Omar ICOperator both reach the form.
- A caller with no taking authority who requests the directory endpoint receives a **403 with a stated reason**, not an empty list. An empty picker is indistinguishable from an event with nobody in it and tells an operator nothing they can act on.

**B. Scope**

- The Department Operator's picker offers Rangers staff and **nothing** for `Gabe`. Gabe does not appear greyed out, disabled, or with a note; he is simply not there.
- Omar ICOperator's picker offers Gabe, because Incident Command works the event and already reads every Field Report in it.

**C. Taking a report**

- The report submits and appears in the Operator's own list.
- The body carries the attribution line naming who filled it out and on whose behalf, as part of the immutable body rather than beside it.
- The record shows **Vera Staff** as the author (`staff_id`) and the **Operator** as the submitter (`submitted_by_user_id`), and `wasTakenOnBehalf()` is `true`. Neither identifier was rewritten to look like the other.

**D. What it did not grant**

- The Operator is offered **no Append control** on the report they just took.
- The direct API append is refused. Append authority follows the recorded author (FR-009, FR-016), and an operator who spent a shift at a radio must not end up able to add to every account they transcribed.
- Vera Staff finds the report in her own list and **can** append to it. It is her account.
- The Operator's photo upload is refused: they were at a radio, not at the scene. If they have a photograph, it is their own observation and their own report to file.
- The Operator **can** still read the report they typed. Reading what you wrote down is not new access.

**E. Server-side scope**

- The submission naming a Gate staff member is refused by the node with a message about not being authorized to take a report for them, and **no Field Report row is created**. The picker's scope is a convenience; this refusal is the boundary.
- The refusal for an out-of-scope real staff member and the refusal for an unknown staff id read the same, so neither can be used to find out who exists.

**F. Visibility**

- The IMS list row names the author and marks the report as taken, so a reader can tell it was taken rather than written before they weigh what it says.
- The detail view shows both people. Neither is hidden behind the other.

## Evidence to capture

- Screenshot of the Department Operator's picker with `Gabe` typed and no result
- Screenshot of Omar ICOperator's picker with Gabe present, side by side with the above
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
