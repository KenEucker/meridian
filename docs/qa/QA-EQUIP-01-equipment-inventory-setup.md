# QA-EQUIP-01: Equipment Inventory Setup and Import

## Purpose

Verify that department logistics and department administration can build a department equipment inventory — item by item and by bulk CSV import — from the normal Meridian Admin product UI and API path before operations start, and that the resulting inventory feeds the existing Logistics Window checkout/check-in workflow without inventory setup being able to take over checkout state.

Since M16.17 the `department.equipment` page reads from the node rather than from bundled fixtures, so this script also checks that what is on screen is what the node holds, that authority comes from the node's answer, that a refusal is shown as the node worded it, and that an unreachable node is reported rather than shown as a department with no equipment.

## Requirements covered

- `EQUIP-001`: MVP equipment tracking is visible/manual.
- `EQUIP-002`, `EQUIP-003`: checkout to and check-in from individual staff members (fed by, not performed by, this surface).
- `EQUIP-004`: Department Logistics may check equipment in/out.
- `EQUIP-005`: MVP equipment states are Available, Checked out, Returned, Missing, and Damaged.
- `EQUIP-006`: department-to-department allotments are out of scope for MVP, so this surface must never move equipment between departments.
- `EQUIP-007`: equipment may be checked out before, during, or after a shift, and full inventory custody chains are out of scope for MVP.
- `EQUIP-009`: a shift-assigned checkout is told apart from an event-assigned one by the shift it names.
- `CLIENT-023`: the equipment surface acts on server data, not bundled fixtures.
- `CLIENT-006`: what the equipment surface offers follows the node's stated authority.
- Requirements section 3.18 (Equipment) and 7.13 (Equipment requirements); section 3.13 check-in/check-out context.
- Technical spec section 20.2 (supported Alpha 1 operations) and 22.2 (Orchid stays repair tooling).
- Data/API spec section 10.13 (`equipment_items`) and section 7.2 (inventory setup is not offline-writable work).
- UI implementation contract section 9.6 (canonical equipment state labels) and 12.4 `department.equipment`.
- Meridian Alpha 1 tasks M11.18 and M16.17.

## Documented inventory rules (enforced by `EquipmentInventoryService`)

- Inventory is department-scoped and event-independent; an item may optionally be scoped to an event in the same organization.
- New equipment is always created Available.
- Inventory setup can set only Available, Missing, or Damaged. Checked out and Returned are produced by the Logistics checkout/check-in commands.
- No state change and no archive is allowed while an item has an open checkout.
- Asset tags are unique among equipment in a department, so a re-run of the same CSV skips rows instead of duplicating equipment.
- Archiving is soft (`archived_at`) and preserves checkout history; nothing is deleted.
- Equipment with no department stays Orchid/God Mode repair tooling and is not manageable from the product path.

## Environment

- Development server environment with migrated database and `DevelopmentScenarioSeeder` (or equivalent personas)
- Shared Meridian Admin client (`apps/client` admin mode) running against a reachable node
- For API checks, authenticated requests against `GET /api/departments/{department}/equipment` and the commands `create-equipment-item`, `update-equipment-item`, `archive-equipment-item`, `restore-equipment-item`, `import-equipment-inventory`

## Personas

- Department logistics member (`department_logistics` grant on a team in the target department)
- Department lead (or department administration) with `department.administer` for the same department
- Department member with neither logistics nor lead authority (must fail closed)
- Department logistics member for a different department (cross-department denial)

## Setup data

- Organization with an active department such as `Rangers` (code `RANGERS`) with a default team
- An event belonging to the same organization
- A second department such as `Gate` with its own logistics member
- At least one equipment item created directly on the server (seed, tinker, or Orchid) that the client has never been told about, to confirm the list is the node's
- A CSV file or pasted text such as:

  ```text
  name,asset_tag,serial_number,notes
  Radio 12,RAD-012,SN-0012,ignored column
  Radio 13,RAD-013,SN-0013,
  ,RAD-014,SN-0014,
  Radio 15,RAD-012,SN-0015,
  Vest 1,,,
  ```

## Steps

1. Sign in to Meridian Admin as the department logistics member and open `/events/{eventId}/departments/{departmentId}/equipment` (also reachable as **Equipment** under Department pages on the home screen, and as **Manage Equipment** from the Logistics Window heading).
1a. Confirm the heading names the department as the node names it, and that the item created outside the client is in the list without any client-side setup.
1b. With the browser network panel open, confirm the page issues one `GET /api/departments/{department}/equipment` and that switching the **Show** filter between Active, Archived, and All issues no further request.
2. Add an item named `Radio 20` with asset tag `RAD-020`, scoped to the event. Confirm it is created with state **Available** and appears in the list.
3. Confirm `equipment_item.created` was audited for the new item.
4. Add a second item reusing asset tag `RAD-020`. Confirm it is rejected with a message naming the duplicate asset tag, and that no second item is created.
5. Add an item with an empty name. Confirm it is rejected as required.
6. Edit `Radio 20`, change its name and set its state to **Damaged**. Confirm the change saves and `equipment_item.updated` is audited with before/after values.
7. Confirm the state control offers only Available, Missing, and Damaged — never Checked out or Returned.
8. Paste the setup CSV into **Bulk import**, choose the event scope, and import. Confirm the result reports 3 imported and 2 skipped, that the skipped rows name `Missing name.` and the duplicate asset tag, and that the unknown `notes` column is ignored.
9. Import the same CSV again. Confirm every tagged row is skipped as a duplicate asset tag rather than duplicated, and that only the untagged `Vest 1` is created again.
10. Import a CSV with no `name` header column. Confirm it is rejected with a clear message and nothing is created.
11. Open the **Logistics Window** for the same department. Confirm the newly created equipment is available for checkout, and check one item out to an on-site staff member.
12. Return to **Equipment**. Confirm the checked-out item shows state **Checked out**, offers no Archive action, and names the staff member holding it and the shift it went out on, so the maintainer knows who to ask (EQUIP-009).
13. Attempt to change that item's state and to archive it. Confirm both are refused with a message pointing back to the Logistics Window; confirm a name-only edit still saves.
13a. Edit an item, and while the form is open archive the same item from a second session or from Orchid. Save the form and confirm the screen reports what the node answered rather than showing a stale success.
13b. Stop the node (or disconnect the device) and reload the Equipment route, then attempt an add and an archive. Restart the node and use the retry path to confirm the surface recovers.
14. Return the item in the Logistics Window as Returned, then archive it from Equipment. Confirm the item disappears from the Active filter, remains visible under Archived, and that the underlying record and its checkout history still exist (`equipment_item.archived` audited; no delete).
15. Restore the archived item and confirm it returns to the active inventory with `equipment_item.restored` audited.
16. Attempt to edit an archived item before restoring it. Confirm it is refused.
17. Sign in as the department lead and confirm the same Equipment surface is available and functional for the same department.
18. Sign in as a department member with neither logistics nor lead authority. Confirm the Equipment route shows the node's own 403 sentence with no inventory, no add form, and no import form, and that `GET /api/departments/{department}/equipment` returns 403.
19. Sign in as the logistics member for the other department. Confirm reads and every inventory command against the first department return 403, and that no command accepts moving an item to another department (EQUIP-006).
20. Using an equipment record with no `department_id` (organization- or event-owned spare), confirm the product commands return 403 and that the item remains manageable only in Orchid.

## Expected results

- Department logistics and department administration create, edit, archive, restore, and bulk-import department equipment from Meridian Admin without opening Orchid.
- New equipment always starts Available, and inventory setup can only ever set Available, Missing, or Damaged.
- Checked out and Returned come only from the Logistics checkout/check-in workflow; state changes and archiving are refused while a checkout is open.
- Asset tags stay unique inside a department, making the CSV import re-runnable without duplicating equipment; each row is processed independently with an imported/skipped reason.
- Archiving preserves the equipment record and its checkout history; nothing is deleted.
- The inventory built here is immediately usable from the Logistics Window for checkout and check-in.
- All mutations produce audit events (`equipment_item.created`, `equipment_item.updated`, `equipment_item.archived`, `equipment_item.restored`, `equipment_inventory.imported`).
- Department members without logistics or lead authority, and actors from other departments, fail closed on both reads and commands.
- No surface offers department-to-department allotments, equipment transfer between departments, or a custody chain.
- Every item, state label, and open checkout shown is one the node holds for this department, and an item created outside the client appears on the next load.
- The whole page comes from one read: the heading, the state options, the event scope list, and the rows are the same response, and the Active/Archived/All filter is a view of it rather than a second request.
- Whether the page offers to add, edit, archive, restore, or import follows the authority the node stated on the read, not a role the client decided for itself.
- Every state label on screen is the one the node sent, so UI contract 9.6's canonical labels have one source.
- A checked-out row names the person holding the item and the shift it went out on, rather than reporting only that it is locked.
- A refusal from the node is shown as the node worded it — duplicate asset tag, missing name, state change while checked out, archive while checked out, and CSV errors alike — and nothing on the page moves as though the change had been accepted.
- Every add, edit, archive, restore, and import is followed by a re-read, so no row is patched in place.
- With the node unreachable the surface reports that it could not be read and offers a retry path; it does not show a department with no equipment, and it does not queue the add or archive for later (data/API 7.2).

## Evidence to capture

- Screenshot of the Equipment page showing Available, Checked out, Damaged, and Archived rows.
- Screenshot of the bulk import result table with imported and skipped rows and their reasons.
- Screenshot or notes showing the refused state change/archive for a checked-out item, including the named holder and shift.
- API or tinker output for the audit events listed above and for the preserved archived record.
- Screenshot or notes showing denied access for the non-logistics member and the cross-department logistics member.
- Network-panel notes showing one read on load and one re-read after each write.
- Screenshot of the surface with the node unreachable, including the retry path.

## Failure notes

- If equipment inventory can only be created in Orchid, stop testing and file a blocking M11.18 product-path issue.
- If inventory setup can set Checked out or Returned, or can change state or archive while a checkout is open, stop testing and file an EQUIP-002/EQUIP-003 operational-truth regression.
- If archiving hard-deletes equipment or loses checkout history, stop testing and file a history-preservation issue.
- If any surface allows moving equipment to another department or creating an allotment, stop testing and file an EQUIP-006 scope violation.
- If a non-logistics member or a cross-department actor can read or manage the inventory, stop testing and file a blocking permission issue.
- If the list shows equipment the node does not hold, or omits equipment it does, stop testing and file a blocking CLIENT-023 issue.
- If the surface shows a department with no equipment rather than an error while the node is unreachable, file a CLIENT-023 issue: a department with no equipment and one that could not be read must not look the same.
- If an add, edit, archive, or import appears to succeed while the node is unreachable, stop testing and file an offline-scope issue against data/API 7.2.
- If a refused write is reported in the client's own wording instead of the node's, file a CLIENT-006 issue.
- If a checked-out row reports only that it is locked without naming who holds the item, file an EQUIP-009 issue.
