# QA-EQUIP-02: Pooled and Tracked Equipment, Lookup, and Assignment Scope

## Purpose

Verify that equipment handoff works the way a busy desk actually works.

Three things changed and they are separable. Equipment is now either an
individually tracked unit or a pooled quantity, and the checkout surface
presents the two differently instead of drawing one checkbox per unit. A
scanned or typed asset tag resolves straight to a line, so a handheld scanner
acting as a keyboard finishes a handoff without anybody touching the screen. And
a checkout records whether it was issued for a shift or for the event, from
which the desk derives whether it is overdue — a distinction that decides
whether an operator is chasing something or leaving it alone.

The thing this script is most concerned with proving is a negative: that no
stored state was added. Overdue is computed as the clock passes a time, not
written down by anything, and a desk that reads "Checked out" six hours after
the shift ended is the failure this design exists to prevent.

## Requirements covered

- `EQUIP-005`: the five stored states, with overdue, lost, and unknown derived from a stored state plus the shift or event window rather than added as stored states.
- `EQUIP-009`: a checkout records whether it is assigned for a shift or for the event; a shift-assigned checkout references its shift and an event-assigned one references none.
- `EQUIP-010`: equipment is recorded as either individually tracked or pooled.
- `EQUIP-011`: a tracked checkout names the unit; a pooled checkout records the quantity handed out.
- `EQUIP-012`: tracked units are found by entering, scanning, or searching name, asset tag, or serial number, and are never presented as a list of every unit.
- `EQUIP-013`: an exact identifier match adds the item without further selection; a value matching several or none is reported rather than guessed.
- `EQUIP-014`: pooled kinds are a short list with the quantity available for each, and the operator chooses a quantity.
- `EQUIP-015`: lookup offers only equipment inside the operator's authorized department and event scope, discloses nothing outside it, and resolves against the inventory the surface already holds so it works with no connectivity.
- `EQUIP-016`: pooled availability is total less open checkout quantity, derived rather than stored, and a pool is never stored `checked_out`.
- `EQUIP-017`: pooled returns record quantity and condition, and missing or damaged units reduce the pool's serviceable quantity through an audited adjustment carrying a reason.
- `SLB-011`, `SLB-012`: the Logistics Desk equipment handoff and return.
- `SLB-018`: outstanding equipment blocks an off-site mark unless returned or written off.
- `CLIENT-015`: writes go through the command outbox with their idempotency keys.
- UI implementation contract sections 9.6 (equipment state labels), 9.6A (tracking kinds), 12.4 (`department.equipment`, Logistics Window).
- Data/API specification section 10.13 (`equipment_items`, `equipment_checkouts`).
- Meridian Alpha 1 tasks M18.24, M18.24B, M18.24C, M18.24D.

## Environment

- Development server environment with a migrated database and `php artisan migrate:fresh --seed`
- Shared Meridian client (`apps/client`) running against a reachable node
- A browser whose network can be disabled, for the offline lookup step
- Optionally a handheld barcode scanner configured as a keyboard; a keyboard typing the tag followed by Enter exercises the same path

## Personas

- **Sam Shiftlead** — holds the Rangers department's logistics grant; the operator at the desk for most of this script
- **Vera Staff** — checked in on the running shift; the person equipment is handed to
- **Nora Newstaff** — on site, on no running shift; the event-assigned handoff target
- **Gabe Gatekeeper** — Gate department lead and logistics; used only to prove that Gate's asset tags are invisible to a Rangers operator
- **Dana Departmentlead** — Rangers department lead; maintains the inventory

## Setup data

The seeded scenario already carries what this script needs:

- Rangers holds tracked radios `RDO-08` through `RDO-15`, tracked vests `VST-01` through `VST-03`, and two pooled kinds — `Hi-vis vest (pooled)` with a total of 30 and `Water bottle` with 60
- Four of the pooled hi-vis vests are already out with Vera against the running shift, so the pool shows 26 of 30 available
- `RDO-12` is out with Sam against the running Day Patrol shift (shift-assigned)
- `RDO-09` is out with Quinn against the event with no shift (event-assigned) and has been written off as missing
- Gate holds `SCN-01` and `SCN-02`, which a Rangers operator must never see

## Steps

### A. Inventory setup carries the tracking kind (EQUIP-010)

1. Sign in as **Dana Departmentlead** and open the Rangers `department.equipment` page.
2. Read the Tracking column.
3. Add a new item, leaving Tracking as **Tracked**. Confirm the form offers Asset tag and Serial number and no quantity.
4. Change Tracking to **Pooled**. Confirm the asset tag and serial number fields disappear and a Pool quantity field appears.
5. Add a pooled kind called `Glow stick` with a quantity of 100.
6. Paste this CSV into Bulk import and submit, then submit the identical CSV a second time:

   ```text
   name,tracking,quantity_total,asset_tag
   Handheld radio,pooled,40,
   Radio 21,individual,,RDO-21
   ```

### B. The checkout surface presents the two kinds differently (EQUIP-012, EQUIP-014)

7. Sign in as **Sam Shiftlead** and open the Rangers Logistics Window.
8. Find **Vera Staff** and open her workspace, then press **Check out equipment**.
9. Read the dialog. Count the controls.
10. Set the `Water bottle` quantity to 2 and confirm.

### C. A scanned tag completes a handoff (EQUIP-013)

11. Open the checkout dialog again for **Nora Newstaff**.
12. With focus in the lookup field, type `RDO-13` and press Enter (or scan the tag).
13. Type `RDO-9999` and press Enter.
14. Type `SCN-01` — a real Gate asset tag — and press Enter.
15. Type `radio` and read what comes back.
16. Remove any staged line you do not want, then confirm the handoff.

### D. Assignment scope and the derived readings (EQUIP-005, EQUIP-009)

17. Read Vera's open equipment list. Note the scope pill on each line and the due time.
18. Read Nora's line, handed over with no shift.
19. Ask a developer to move the Day Patrol shift's end time into the past:

    ```bash
    php apps/server/artisan tinker --execute='App\Models\Shift::where("title","Day Patrol")->update(["ends_at" => now()->subHour()]);'
    ```

20. Reload the Logistics Window and read Vera's shift-assigned line and Nora's event-assigned line again.
21. Inspect the database and confirm nothing was written:

    ```bash
    php apps/server/artisan tinker --execute='echo implode(",", Schema::getColumnListing("equipment_checkouts"));'
    ```

### E. Pooled returns, in parts and with a write-off (EQUIP-016, EQUIP-017)

22. Open **Check out** for Vera. In the Return equipment section, find the pooled hi-vis line.
23. Set the returned quantity to 2, leave the condition as Returned, and confirm.
24. Reopen the return. Set the quantity to 2, choose **Damaged**, and enter the reason `Torn on the fence line`.
25. Reload the Rangers `department.equipment` page and read the hi-vis pool's row.
26. Ask a developer to read the audit trail:

    ```bash
    php apps/server/artisan tinker --execute='App\Models\AuditEvent::where("action","equipment_pool.adjusted")->latest()->get(["reason","before_json","after_json"])->each(fn($e) => print_r($e->toArray()));'
    ```

### F. Lookup with no node (EQUIP-015)

27. With the Logistics Window loaded, disable the browser's network.
28. Reload the page. It should open on the stored copy and say so.
29. Open the checkout dialog and scan or type `RDO-14`.
30. Type `SCN-02`, another Gate tag.

## Expected results

**A. Inventory setup**

- Every pre-existing item reads **Tracked**; the two seeded pooled kinds read **Pooled**.
- A pooled row's State column reads its availability — `26 of 30 available` for the hi-vis pool — rather than a state word, because a pool is never Checked out (EQUIP-016; UI contract 9.6).
- The form shows asset tag and serial number for a tracked item and pool quantity for a pooled one, and never both.
- The first import reports `Imported 2 item(s); updated 0; skipped 1`. The second reports `Imported 0; updated 1; skipped 1`: the pooled row is matched by name and updated, the tracked row is skipped as a duplicate asset tag.
- There is exactly one `Handheld radio` pool in Rangers afterwards, not two.

**B. Two presentations**

- The dialog contains **no checkbox per unit**. It has a short pooled list with a quantity field per kind and one lookup field for tracked units.
- No tracked radio is named anywhere in the dialog until it is looked up.
- Confirming sends one `checkout-equipment` command carrying `quantity: 2` for the water bottle kind.

**C. Scanner path**

- `RDO-13` resolves immediately: a line appears in **Handing over**, the lookup field clears, and no selection step is offered. Nothing was clicked.
- `RDO-9999` produces `No equipment available to hand out matches "RDO-9999".` and adds nothing.
- `SCN-01` produces **exactly the same message**, with the same wording, and does not reveal that a Gate item with that tag exists. The two answers must be indistinguishable.
- `radio` returns a short list of tracked radios to choose from and resolves nothing on its own, however many hits there are.
- Confirming sends one `checkout-equipment` command per staged line.

**D. Scope and derived readings**

- Vera's radio line carries a **Shift** pill and a due time equal to the Day Patrol shift's end.
- Nora's line carries an **Event** pill and a due time equal to the event window's close, which is days away.
- After the shift end is moved into the past and the page is reloaded, Vera's line reads **Overdue** and Nora's still reads **Checked out**. Nothing about either record was changed; only the clock passed a time.
- The `equipment_checkouts` column list contains no `overdue`, `unknown`, `presentation_state`, or `assignment_scope` column. The scope is derived from `shift_id` and the reading from the window.
- If the event's window and end date are both cleared, an event-assigned line reads **Unknown** rather than **Checked out** — Meridian saying it cannot tell rather than reporting on time.

**E. Pooled returns**

- After the first partial return the checkout stays open and reads `2 of 4 still out`. The pool's availability rises by 2.
- After the damaged return the checkout closes. The pool's **total** falls by 2, from 30 to 28, and the record's state is still Available — a pool never becomes Damaged (EQUIP-017).
- An `equipment_pool.adjusted` audit entry exists carrying the reason `Torn on the fence line`, a before total of 30, and an after total of 28.
- Two vests returned in good order changed no total; only the two written off did.

**F. Offline lookup**

- The Logistics Window opens on the stored copy with a staleness disclosure naming when it was taken.
- `RDO-14` resolves and adds a line **with the node unreachable**. Lookup reads the inventory the surface already holds (EQUIP-015).
- `SCN-02` returns the same no-match message it returns online. A Gate item was never in the device's copy, so no local search can find one.
- Confirming the handoff while offline is refused where it stands and says so — equipment handoff is connected-only (CLIENT-018) — rather than silently queueing.

## Evidence to capture

- Screenshot of the checkout dialog showing the pooled quantity list and the lookup field, with no per-unit checkboxes
- Screenshot or recording of a scan resolving to a line without a pointer event
- Screenshots of the same open checkout before and after the shift end moves into the past, showing Checked out becoming Overdue with no write between them
- The `equipment_checkouts` column list output
- The `equipment_pool.adjusted` audit entry, with reason and before/after totals
- Screenshot of the offline desk resolving a scanned tag, with the staleness disclosure visible
- The two identical no-match messages for an invented tag and a real out-of-scope tag, side by side

## Failure notes

Record which step failed, the persona, the department and event, the tracking
kind of the equipment involved, and whether a node was reachable.

Three failures matter more than the rest and should be reported as blocking:

- **A stored derived state.** Any column named for overdue, unknown, or lost, or a reading that does not change when only the clock does. This is the failure EQUIP-005 is written to prevent.
- **A disclosure through lookup.** Any answer that lets an operator tell a real out-of-scope asset tag from an invented one — a different message, a different delay, a different shape. Silence has to be uniform to be silence.
- **A pool stored `checked_out`.** A pool with units out must stay Available with a reduced availability. Anything else means a department's whole store room of vests reads as being in one person's hands.
