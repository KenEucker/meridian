# Meridian QA Docs

Human QA scenarios live in this directory and use IDs like `QA-BOOT-01`.

Every `docs/qa/QA-*.md` file must include these sections:

- Purpose
- Requirements covered
- Environment
- Personas
- Setup data
- Steps
- Expected results
- Evidence to capture
- Failure notes

Run the local process checks before opening a PR:

```bash
scripts/process/check.sh
```

On Windows, open Git Bash in the repository and run this command there so the same POSIX script is used across Windows, Linux, and macOS. Do not use Windows PowerShell, `cmd.exe`, or the WSL `bash.exe` shim for this check.

## Alpha 1 Field Report script

| ID | Coverage | Owning task |
|---|---|---|
| [`QA-FR-01-offline-field-report.md`](QA-FR-01-offline-field-report.md) | Offline Field Report submit with title/photos, reconnect/FRA, immutability, IC visibility, photo upload pending state, Name References, and `ic_lead`-only photo download | M9.9 |

## Alpha 1 Shift Lead Board partial smoke

M10.1 covers the current roster and checked-in state. M10.7 adds the local
eligible unscheduled staff add-to-roster affordance. M10.8 adds the local
current deployment/location move affordance. M10.9 adds local equipment
checkout/check-in controls and checked-out equipment state. The full
`QA-SLB-01-checkin-checkout-hours.md` script remains deferred to M10.11.

1. Start the shared client in development mode.
2. Open **Current shift board** from the home surface.
3. Confirm the board shows the Local Field Event, Rangers department, Dirt team,
   Ranger Dirt Day Shift, roster count, and checked-in count.
4. Confirm Local Field Author and Sam Shiftlead appear under Checked-in staff,
   Vera Staff remains Scheduled in the roster, and Ari Ranger appears as an
   eligible staff member who can be added to the roster. Confirm Local Field
   Author is assigned to Gate 1, Sam Shiftlead is assigned to Perimeter North,
   and Vera Staff is Unassigned. Confirm Radio 12 is checked out to Local Field
   Author and the equipment-out summary count is 1.
5. Add Ari Ranger to the roster. Confirm the roster count increases by one, Ari
   Ranger appears as Scheduled, and the eligible-staff list is empty.
6. Move Vera Staff to HQ Runner. Confirm the deployment status says Vera Staff
   moved to HQ Runner and the roster shows HQ Runner for Vera.
7. Check out Safety Vest to Vera Staff. Confirm the equipment status says Safety
   Vest was checked out to Vera Staff and the checked-out equipment list includes
   Safety Vest with visible Checked out state text.
8. Add Radio 14 with asset tag RDO-14 and immediately check it out to Vera Staff.
   Confirm the status says Radio 14 was added and checked out to Vera Staff.
9. In the check-in control, select Vera Staff. Confirm Safety Vest and Radio 14
   are both available for selection, select both, and check them in as Returned.
   Confirm the status says 2 items checked in as Returned and both leave the checked-out
   equipment list.
10. Check in Radio 12 as Returned by selecting Local Field Author. Confirm Radio
   12 leaves the checked-out equipment list.
11. Confirm no staff attendance check-in, staff attendance check-out, no-show,
   hours, field report shortcut, or incident shortcut controls are present.
