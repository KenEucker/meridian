# QA-TRAIN-01: Product Training Management

## Purpose

Verify the M11.16 product path for department/organizer training creation, in-person/online delivery, prerequisite/expiration setup, scheduled-attendance signup and roster (including shift-listed signup for event trainings), the staff-facing training page, manual completion recording by authorized trainers/leads, and completion spreadsheet import in Meridian Admin, outside Orchid/God Mode.

Since M16.16 every one of those surfaces reads from the node rather than from bundled fixtures, so this script also checks that what is on screen is what the node holds, that authority comes from the node's answer, and that an unreachable node is reported rather than shown as an empty department.

## Requirements covered

- `TRAIN-001` through `TRAIN-006`, `TRAIN-009`, `TRAIN-010`
- `CLIENT-023`: training surfaces act on server data, not bundled fixtures
- `CLIENT-006`: what a training surface offers follows the node's stated authority
- Requirements sections 3.8, 5.6, and 7.6
- Technical spec sections 15.2 and 22.2
- Data/API spec sections 5.1 and 5.2: department training reads and training commands
- Data/API spec section 7.2: training management is not offline-writable work
- Data/API spec section 10.8: `trainings`
- UI implementation contract section 12.4 `department.trainings`
- Meridian Alpha 1 tasks M11.16 and M16.16

## Environment

- Shared client running in Admin mode against a reachable node.
- Laravel app migrated and seeded.
- Product API available under `/api`, including `/api/departments/{department}/trainings`, `/api/departments/{department}/trainings/{training}`, and the `/api/commands/*-training*` commands.
- Browser with keyboard access available.

## Personas

- Organizer or lead organizer: can create and maintain trainings for any organization department.
- Department lead or department administration: can create and maintain their own department's trainings, rosters, completions, and imports.
- Team lead: authorized trainer for their team's trainings; can record completions but not create trainings.
- Staff-only member: can view department trainings and sign up for scheduled sessions but cannot manage trainings or record completions.

## Setup data

- Organization: Signal Camp development fixture.
- Department lead context: Rangers.
- Team lead context: DPW Bikes.
- Staff-only context: Gate.
- Training names: `QA Orientation`, `QA Radio Certification` (annual, scheduled, capacity 2), `QA Radio Theory Online` (online with URL).
- An active event the department participates in (for shift-listed signup).
- Completion CSV: rows with an `email` header, one known staff email, one unknown email, one bad date.
- At least one training created directly on the server (seed, tinker, or Orchid) that the client has never been told about, to confirm the list is the node's.
- A training id belonging to a different department, for the not-found check.

## Steps

1. Open Meridian Admin as the Rangers department lead and navigate from Home to **Trainings**.
2. Confirm the list shows trainings with schedule, expiration, prerequisites, signup counts, your-status column, and a **New training** action.
2a. Confirm the training created outside the client is in the list without any client-side setup, and that archived trainings are present and marked **Archived** rather than hidden from the lead.
3. Create `QA Orientation` with no schedule and no expiration; confirm it saves and appears in the list as a one-off training with no signup roster (TRAIN-001).
4. Create `QA Radio Certification` as an in-person training with `expires after days` 365, a scheduled session start/end, a location, capacity 2, a time commitment, and an "After this training" description (TRAIN-002, TRAIN-009, TRAIN-010).
4a. Create `QA Radio Theory Online` as an online training; confirm a training URL is required, capacity is rejected, and no signup controls appear anywhere for it (TRAIN-009).
4b. When `QA Radio Certification` is created with the event selected, confirm a linked shift `Training: QA Radio Certification` exists for the training's team (or department default team) and that its prerequisites appear as shift training requirements (TRAIN-009).
4c. Open each training's page as a staff member and confirm it shows delivery, schedule or training URL, location, time commitment, expiration, prerequisites with your completion state, signup state, and the after-training/provisions/unlocked-shifts information (TRAIN-010).
5. Edit `QA Radio Certification` and set `QA Orientation` as a prerequisite; confirm a prerequisite cycle (orientation requiring certification) is rejected (TRAIN-004).
6. As a Rangers staff member without the orientation completion, attempt signup for `QA Radio Certification` and confirm signup is blocked with a prerequisite message.
7. Record the staff member's `QA Orientation` completion as the department lead; confirm the completion date is captured and no expiration is set (TRAIN-003, TRAIN-005).
8. As the staff member, sign up for `QA Radio Certification`; confirm the signup count increments and a cancel action appears.
9. Sign a second and third staff member up (lead may add from the roster panel) and confirm capacity 2 blocks the third signup.
10. As the department lead, open the training edit screen and confirm the roster lists signed-up staff with completion status and record-completion actions.
11. Record a completion from the roster and confirm the completions table shows the completion date and a derived expiration one year later (TRAIN-002, TRAIN-003).
12. Paste the completion CSV into **Import completions from spreadsheet** and import; confirm per-row results show one imported row and skipped rows with reasons for the unknown email and bad date (TRAIN-006).
12a. Edit a training, and while the form is open archive the same training from a second session or from Orchid. Save the form and confirm the screen reports what the node answered rather than showing a stale success.
12b. Navigate directly to the edit and detail URLs for the training belonging to another department, and for a training id that does not exist.
12c. Stop the node (or disconnect the device) and reload the Trainings route, then attempt a signup and an archive. Restart the node and reload to confirm the surface recovers.
13. Switch to the DPW Bikes team lead context and confirm the team lead can record completions for the Bikes team training but sees no **New training** action.
13a. As that team lead, open the Bikes team training's edit URL and confirm the page states that the training's details are not editable without department authority, while still showing the roster and completions the lead may record against.
14. Switch to the Gate staff-only context and confirm trainings are listed with signup available for scheduled sessions, while manage, roster, completion, and import controls are absent.
15. As Gate staff, attempt direct navigation to the training create URL and confirm the page fails closed without the form.
16. As an organizer, create a training for a department the organizer does not lead and confirm it saves (organizer training creation).
17. Archive a training as the department lead; confirm staff no longer see it, the lead sees it marked archived, and restore brings it back.
18. Keyboard through the list, form, roster, completion, and import controls. Confirm focus is visible and status text is not color-only.

## Expected results

- MVP training management is reachable from normal Meridian Admin product surfaces, not only Orchid.
- Department leads/administration manage their own department's trainings; organizers manage any department's trainings; other department leads fail closed.
- Trainings support one-off, annual (expiring), and scheduled-session shapes with optional team scope, location, and capacity.
- Trainings are marked in-person or online: online trainings require a training URL and never take signups; event-bound in-person scheduled trainings are listed as a `Training: <name>` shift and signed up for like other shifts.
- Every training has a staff-visible training page covering when/where it is available, the time commitment, and what follows completion.
- Prerequisites can be set and removed, reject cycles, and block scheduled-session signup until complete.
- Staff can sign up for and cancel scheduled trainings; capacity is enforced; rosters are visible only to authorized trainers/leads.
- Completions carry completion dates and derive expiration from the training's expiry window.
- Spreadsheet import records completions by staff email with per-row imported/skipped results and never aborts on a bad row.
- Archived trainings are hidden from staff, preserved for managers, and restorable.
- Every training, roster row, completion row, and prerequisite shown is one the node holds for this department, and a training created outside the client appears on the next load.
- Whether the page offers to create, edit, record, or import follows the authority the node stated on the read, not a role the client decided for itself; a caller who may record completions but not manage the department is told the training's details are not editable rather than shown a form.
- The prerequisite list distinguishes prerequisites this reader has completed from those still outstanding, and matches the reason signup is refused.
- A refusal from the node is shown as the node worded it — prerequisite, capacity, duplicate signup, cycle, and import errors alike — and nothing on the page moves as though the change had been accepted.
- Every signup, cancellation, completion, import, archive, and restore is followed by a re-read, so no row is patched in place.
- A training in another department, or one that does not exist, reports "Training not found for this department." rather than an empty form.
- With the node unreachable the surface reports that it could not be read and offers a retry path; it does not show a department with no trainings, and it does not queue the signup or archive for later (data/API 7.2).

## Evidence to capture

- Screenshot of the department lead trainings list with schedule/expiration/prerequisite columns.
- Screenshot of the training edit screen showing prerequisites and scheduled session fields.
- Screenshot of the roster with record-completion actions and the completions table showing derived expiration.
- Screenshot of CSV import results with imported and skipped rows.
- Screenshot of the staff-only view showing signup without manage controls.
- Screenshot of the staff training page showing prerequisites split into completed and outstanding.
- Screenshot of a refused signup showing the node's prerequisite or capacity message.
- Screenshot of the surface with the node unreachable.
- Screenshot or notes for the not-found state on a foreign or unknown training id.
- Screenshot of the team lead's non-editable training edit page with the roster still present.
- Notes from keyboard/focus review.

## Failure notes

- Record the current route, selected fixture/persona, training id, and whether the failure occurred in product UI or product API.
- If a staff-only user can create trainings, see another staff member's roster, or record completions, treat it as a release-blocking permission failure.
- If import silently drops rows without a per-row reason, treat it as an import correctness failure.
- If the list shows trainings, roster rows, or completions the node does not hold, or omits ones it does, stop testing and file a blocking CLIENT-023 issue.
- If the surface shows a department with no trainings rather than an error while the node is unreachable, file a CLIENT-023 issue: a department with no trainings and one that could not be read must not look the same.
- If a signup, completion, or archive appears to succeed while the node is unreachable, stop testing and file an offline-scope issue against data/API 7.2.
- If a refused signup or import is reported in the client's own wording instead of the node's, file a CLIENT-006 issue.
