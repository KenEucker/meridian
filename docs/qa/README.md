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
current deployment/location move affordance. The full
`QA-SLB-01-checkin-checkout-hours.md` script remains deferred to M10.11.

1. Start the field app in development mode.
2. Open **Current shift board** from the home surface.
3. Confirm the board shows the Local Field Event, Rangers department, Dirt team,
   Ranger Dirt Day Shift, roster count, and checked-in count.
4. Confirm Local Field Author and Sam Shiftlead appear under Checked-in staff,
   Vera Staff remains Scheduled in the roster, and Ari Ranger appears as an
   eligible staff member who can be added to the roster. Confirm Local Field
   Author is assigned to Gate 1, Sam Shiftlead is assigned to Perimeter North,
   and Vera Staff is Unassigned.
5. Add Ari Ranger to the roster. Confirm the roster count increases by one, Ari
   Ranger appears as Scheduled, and the eligible-staff list is empty.
6. Move Vera Staff to HQ Runner. Confirm the deployment status says Vera Staff
   moved to HQ Runner and the roster shows HQ Runner for Vera.
7. Confirm no check-in, check-out, no-show, hours, equipment, field report
   shortcut, or incident shortcut controls are present.
