# QA-NAV-01: Command Palette

## Purpose

Verify that the global command palette opens on the three shortcuts the UI
implementation contract requires, offers only destinations and actions the
signed-in user may reach, hides IMS results from anybody without Incident
Command standing, carries no camp or map place, and is keyboard-operable from
opening to dismissal with focus returned to where it started.

The palette is a second way into pages somebody can already reach. The point of
this script is that it stays exactly that: everything checked below is a
comparison between what the palette offers and what the shell menus and the home
directory already offer to the same person.

## Requirements covered

- UI implementation contract section 6.1: the command palette trigger in the top
  bar, and the rule that the top bar is not the organization or event switcher.
- UI implementation contract section 7.1: `Ctrl+K`, `Cmd+K`, and `/` only when
  the user is not typing in a field.
- UI implementation contract section 7.2: results filtered by authenticated
  user, organization, event, department, role, permission, kiosk/trusted
  workstation state, and the operations window; IMS results withheld without the
  required IC role for the event's configured Incident Command department.
- `MAP-018`: camps and map locations are not added to the global command
  palette. `MAP-019`: map search stays in the scoped panel on the map surface.
- `CLIENT-004`, `CLIENT-005`, `CLIENT-006`: navigation derives from the session
  response's capability codes, unavailable destinations are absent rather than
  disabled, and the server refuses regardless of what the client rendered.
- `UI-011`, `UI-019`, `UI-020`: readiness and About in every UI mode; a Kiosk
  requires a pinned context and enters setup without one.
- Operating guide sections 10.1 through 10.6 and 18.1; kiosk and field hardware
  guide section 11; accessibility checklist section 11.

## Environment

- Development server environment with a migrated and seeded database
  (`php artisan migrate:fresh --seed`).
- Shared Meridian client (`apps/client`), Admin or Field artifact.
- For section E, the Kiosk artifact against a shared workstation the node holds.

## Personas

From the seeded operational scenario:

- Dana Departmentlead — department lead for Rangers, holding every department
  capability the scenario grants.
- Vera Staff — regular staff holding no roles at all.
- Olive Organizer — organizer, holding no Incident Command standing.
- Omar ICOperator — IC operator for the event's Incident Command department.
- Gabe Gatekeeper — Gate department lead, so that one persona holds standing in
  more than one department.

All sign in with the documented development password.

## Setup data

- The seeded scenario as it comes from `migrate:fresh --seed`. No extra records
  are required.
- For section E, one shared workstation provisioned and pinned from God Mode,
  and one login code issued to a named person for that workstation.

## Steps

### A. Opening and dismissing

1. Sign in as Dana Departmentlead and open Home.
2. Confirm the top bar carries a search control labelled for pages and actions,
   showing the `Ctrl K` hint on a desktop-width window.
3. Press `Ctrl+K` (or `Cmd+K` on macOS). Confirm the palette opens and the text
   cursor is already in its input without clicking.
4. Press `Esc`. Confirm the palette closes and keyboard focus is back on the
   search control in the top bar — press `Enter` and confirm it reopens.
5. Close the palette. With focus anywhere that is not a form field, press `/`
   and confirm the palette opens.
6. Close it, open **Logistics**, put the cursor in the desk's search field, and
   type a `/`. Confirm the slash is typed into the field and the palette does
   **not** open. Repeat inside a Field Report body on **My Field Reports →
   Create**.
7. Reopen the palette and press `Esc` from the input. Confirm it closes rather
   than only clearing the query.

### B. What it offers, and what it does not

8. As Dana, open the palette without typing. Confirm every entry on Home appears
   in it, under the same headings Home uses — You, Context, Workflows,
   Department pages, Organization pages, Device — with an **Actions** group
   last.
9. Confirm the Actions group offers switching to each of Dana's other
   departments and signing out, and offers nothing else.
10. Type `logist`. Confirm the list narrows to Logistics. Type
    `zzz not a page`. Confirm the palette says nothing matches and names what
    was typed rather than showing an empty box.
11. Sign out and sign in as Vera Staff. Open the palette and confirm it offers
    only Vera's personal pages, the department pages a member reaches, and the
    device pages — and none of Overview, Planning, Logistics, Operations, Admin,
    Equipment, Branding, Credentials, Exports, or any organizer page.
12. Compare that list against Vera's Home. Confirm the two agree exactly: the
    palette must not offer a page Home withholds, and must not withhold one Home
    offers.
13. Still as Vera, type the name of a page from step 8 that Vera cannot reach,
    for example `logistics` and then `audit`. Confirm nothing matches.

### C. IMS results

14. As Olive Organizer — an organizer with no Incident Command standing — open
    the palette and search for `incident`, then for `field report`. Confirm no
    Incidents workspace, no Incident Command dashboard, and no IMS Field Reports
    entry appears. The author's own **My Field Reports** page is a different
    page and may appear.
15. Sign in as Omar ICOperator and repeat. Confirm all three appear, and that
    opening one from the palette lands on the same surface the Incidents
    workspace links to.

### D. Camps and map places

16. As Dana, open the palette and search for `camp`, then for `map`, then for
    the name of any seeded camp or operational location. Confirm nothing
    matches in any case (`MAP-018`).
17. Confirm the same searches from Home's own listing produce nothing, so that
    the palette is not hiding something the rest of navigation shows.

### E. Kiosk

18. Start the Kiosk artifact against a workstation the node holds but has **not**
    pinned. Confirm the palette offers workstation setup, readiness, and health,
    and nothing else — in particular no organizer page, no department page, and
    no Me (`UI-019`, `UI-020`, `UI-011`).
19. Pin the workstation to an event and reload. With the workstation locked,
    confirm the palette offers the way in to the workstation and does not offer
    the shift board or switching users.
20. Sign in at the workstation with a login code and confirm the palette now
    offers the shift board, switching users, and the workstation home.
21. Confirm the palette does not offer ending the session. Ending a session is
    the session bar's control, next to the name it ends.

### F. Keyboard operation

22. As Dana, open the palette and use the down and up arrows. Confirm exactly
    one row is marked as the active one at a time, that the marking is not
    colour alone, and that moving past either end wraps to the other.
23. Press `Home` and then `Enter`. Confirm the palette closes and the first
    entry's page opens.
24. Reopen, search for one of Dana's other departments, and activate the
    **Switch to …** action with `Enter`. Confirm the shell's department context
    changes to that department and the palette's next opening offers that
    department's pages instead.
25. With a screen reader running, confirm the palette announces itself as a
    dialog, that the input is announced as a combobox with a list of results,
    and that moving the arrows announces each active option.

## Expected results

- The palette opens on `Ctrl+K`, `Cmd+K`, and `/`, and `/` is typed as a
  character inside every field rather than opening anything.
- Focus enters the input on open and returns to the control that opened it on
  close.
- Every destination the palette offers is one the same user's Home offers, and
  no destination Home offers is missing from it.
- No entry appears for a page the signed-in user's capabilities do not permit,
  in either direction between two personas.
- IMS entries appear only for a user holding Incident Command standing for the
  event's configured Incident Command department.
- No camp and no operational map location appears anywhere in the palette.
- A Kiosk offers only what the pinned context and the workstation state allow,
  and never a page from a client session on the same machine.
- Arrow keys, `Home`, `End`, `Enter`, and `Esc` all work from the input without
  the pointer, and the active row is distinguishable without relying on colour.

## Evidence to capture

- Screenshots of the palette open for Dana Departmentlead, for Vera Staff, and
  for Olive Organizer, side by side with each persona's Home.
- A screenshot of the `incident` search for Olive and for Omar.
- A screenshot of a `camp` search returning nothing.
- Screenshots of the Kiosk palette in the unpinned, locked, and signed-in
  states.
- A short recording of the keyboard path in section F, including focus
  returning to the trigger after `Esc`.

## Failure notes

Record the persona, the department the shell was in, the exact query typed, the
entry that appeared or failed to appear, and whether the same entry appears on
that persona's Home. A palette entry that Home does not carry, or a Home tile
the palette will not offer, is the finding this script exists for: the two read
from one derivation, so a disagreement between them means something has grown a
second answer.
