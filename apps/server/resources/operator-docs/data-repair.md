# Data repair

What God Mode can fix directly, what it deliberately cannot, and how to leave a
trail.

## Before you repair anything

Ask whether the product can do it. Most things an organizer, department lead, or
shift lead needs are in Meridian Admin, and doing them there produces the right
records, the right notifications, and the right audit entries. God Mode bypasses
product rules, which is exactly why it should not be the first tool you reach
for.

Repair here when the product path is blocked, not when it is inconvenient.

## What God Mode may repair directly

- Users
- Teams
- Shifts
- Incidents
- Attendance

Attendance edits record before and after values in the audit log. So do the
other direct edits where it matters.

## What God Mode will not do

These are refusals, not gaps:

- **Field report original body.** A finalized field report body cannot be edited
  from God Mode. Field reports are immutable and corrections are appended. If
  the content is wrong, the correction is an append, made by the author through
  the product.
- **Field report append and redaction workflows.** Not available in God Mode in
  Alpha 1.
- **Attachment redaction or deletion.** Not available in Alpha 1.
- **Deleting operational history.** Meridian does not destroy incidents,
  completed hours, or acknowledgment records. Where something must stop being
  used, the domain has an archive, a strike, or a frozen state instead.

If a request needs one of these, it is a conversation with the project owner,
not a database edit.

## Reasons and audit

Dangerous God Mode actions require a reason. It goes into the audit log next to
your user, the entity, the before and after values, and the timestamp.

Write what a reviewer will need six months from now: what was wrong, who asked,
and what you changed. "Fixed" is not a reason.

## Bulk import from a spreadsheet

**Import Users** and **Import Teams** take a CSV, either uploaded or pasted.
Use them when a list arrives as a spreadsheet and entering it by hand would take
an afternoon.

Both work the same way:

- Column names are matched case-insensitively, order does not matter, and
  unknown columns are ignored. A file missing a required column is refused
  whole; a single bad row is skipped with a reason and the rest still imports.
- **Preview** first. It runs the file and throws the result away, so you see the
  per-row outcome — created, updated, or skipped and why — before anything is
  written.
- Rows match records that already exist: users by email address, teams by
  department and team code. Re-running a corrected file updates rather than
  duplicating, so fixing a bad row and importing again is the normal loop.
- Nothing is removed. A record missing from the file is left alone. If something
  should stop being used, archive it on its own screen.

Users carry an email address and a name and nothing else. A file cannot set a
password, a console permission, a role, or the disabled flag, and an imported
account arrives with no console access and an unverified address — the first
magic-link sign-in still has to establish that the address belongs to someone.
Granting access stays one deliberate act at a time on the user screen.

Teams name their department by organization slug and department code, so a code
reused across organizations still lands in the right place. Every created and
updated record is audited, plus one summary entry per run.

## Common repairs

### A staff member is on the wrong team

Fix the team membership. Remember that a department membership only exists
through a team membership, so removing someone's last team membership in a
department removes them from the department.

### Hours look wrong

Check whether the correction period has closed. Inside it, correct through the
product. After credit calculation freezes, the frozen record stands — a later
policy change does not recalculate it. If a frozen record is genuinely wrong,
record the reason as part of whatever you do next.

### A credential is blocked and should not be

Credential eligibility is calculated, not stored by hand. Fix the input — the
missing training, the missing waiver, the removed shift — and let eligibility
recalculate. Editing the credential state without fixing the input means it will
flip back.

### An organization is missing a designation

The God Mode landing screen reports organizations with no Organizers Department,
no resolvable Incident Command Department, or no active Lead Organizer. Each
item links to the screen that sets it. These are configuration gaps, not data
corruption, and they should be closed before an event rather than during one.

## Do not repair around the event window

During the active event window the on-site node is authoritative for
event-scoped writes and central refuses them. If a central-side repair is
refused, that is the authority rule, not a bug. Either make the change on the
on-site node or wait for the window to close.
