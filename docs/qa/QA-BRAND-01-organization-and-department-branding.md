# QA-BRAND-01: Organization and Department Branding

## Purpose

Prove that an organization can present Meridian as its own system, that a department can carry visible identity inside that organization, and that neither can weaken state legibility, accessibility, or the surfaces that must stay Meridian's.

This script covers the whole of Milestone 15A. A reviewer following it end to end exercises the branding schema, the contrast validator, runtime token resolution, logo upload and lettermark fallback, both administration surfaces, identity replacement and its boundaries, the `DepartmentBadge`, department surface scoping, governance and audit, offline rendering, and the state legibility guard.

## Requirements covered

`BRAND-001` through `BRAND-031`, including `BRAND-003A`.

Supporting references: UI style guide section 4; UI operating guide sections 6.3 and 8.3; UI implementation contract sections 10, 10.4, 11.3, and 11.3a; component library specification sections 3 and 5.1; accessibility checklist section 6; data/API specification sections 10.1 and 10.6.

## Environment

- Development or standalone node for steps 1–13.
- Central plus a paired on-site node for step 14 (central authority).
- The Electron desktop/Kiosk application for step 16.
- A device or browser profile that can be taken offline for step 17.
- Both light and dark mode are exercised; the theme toggle is in the user menu.

## Personas

| Persona | Role | Used for |
|---|---|---|
| Olive Organizer | Organizer | Organization branding, logo upload, the department override switch |
| Lena Leadorganizer | Lead Organizer | Confirming the second permitted organization role |
| Dana Departmentlead | Department Lead, Rangers | Department branding |
| Adam Administration | Department Administration, Gate | Confirming the second permitted department role |
| Vera Staff | Regular staff, Rangers | Confirming branding is visible but not editable |
| Ingrid ICLead | IC Department Lead | IMS surfaces and the incident PDF |
| Gwen Godmode | God Mode | Orchid console boundary |

## Setup data

- Organization: **Northwood Collective**, with no branding profile yet.
- Departments: **Rangers** (Dana), **Gate** (Adam), **DPW** (no branding, no logo — used for the lettermark check).
- One event, **Emberfall 2026**, with an active event window that is *not* currently open. Step 14 opens it.
- At least one published policy document that Olive can export.
- At least one open incident that Ingrid can print.
- Two logo files to hand: a PNG under 2 MB, and an SVG (used to confirm it is refused).

## Steps

1. **Start unbranded.** Sign in as Vera. Confirm the header shows the Meridian mark and the word *Meridian*, and the browser tab title ends in *Meridian* plus the UI mode.
2. **Open branding.** Sign in as Olive. Branding lives in the Meridian Admin client, not in Orchid: open the workflow menu and choose *Organization pages → Branding* (route `organizer.branding`, path `/organizer/branding`). Confirm ten color controls are offered — primary, secondary, tertiary, accent, canvas, surface, foreground, muted foreground, border, focus — and that there is no control for status, severity, priority, or chart colors.
3. **Fail the contrast check on purpose.** Set *Muted foreground text* to a very light gray (for example `#c9cdd1`) and press **Check contrast**. Confirm the result names the failing pair, shows the measured ratio and the required ratio, and that nothing was saved. Confirm the color you typed is still the color in the field — Meridian must not have adjusted it for you.
4. **Pass the contrast check.** Set a full palette that passes (for example primary `#123a5c`, secondary `#1f5f4b`, tertiary `#6b4f8a`, accent `#8c2f39`, canvas `#eef2f6`, surface `#ffffff`, foreground `#101418`, muted foreground `#565f68`, border `#7c858d`, focus `#1b4f8f`). Confirm the preview panel repaints with those colors, including the primary and destructive button samples, and that the verdict passes.
5. **Set the display name and save.** Enter `Northwood Arts Collective` and save. Confirm the header, the Home control, and the browser tab title now say *Northwood Arts Collective*.
6. **Upload the organization logos.** In the *Logos* section, use the **Full lockup** file control to upload the PNG, then the **Compact mark** control to upload it again. A logo saves as soon as it is chosen — there is no separate save for logos. Confirm the header mark changes. Then upload the SVG and confirm it is refused with a message naming the permitted types.
7. **Replace and remove.** Upload a different PNG as the compact mark and confirm the header updates. Press **Remove logo** on the compact mark and confirm the header falls back — first to the full lockup, and if you remove that too, to a generated lettermark reading `IBC`. Confirm the empty slot in the form shows that same lettermark rather than an empty box.
8. **Check the lettermark rule for a department.** Open any surface showing the **DPW** department badge. Confirm it renders a generated `DP` lettermark — one word yields its first two letters — and that a screen reader (or the element's accessible name) reads the full department name, not the initials.
9. **Set department branding.** Sign in as Dana and open *Department pages → Branding* (route `events.departments.branding`, path `/events/:eventId/departments/:departmentId/branding`). Upload the PNG as the department logo and confirm the department badge picks it up. Confirm the colors section offers only accent and surface background, and that the screen states that text, border, focus, status, severity, priority, and chart colors come from the organization palette. Set accent `#1f5f4b` and background `#eef6f2`, check contrast, and save.
10. **Confirm department surface scoping.** As Dana, visit a Rangers operations surface (overview, logistics, teams, shifts, or equipment) and confirm the content area carries the department background. Then visit an IMS/incident surface, The Briefing, and an organizer-level surface, and confirm **none** of them are tinted. Confirm the Rangers badge still shows its accent on those untinted surfaces.
11. **Confirm the department boundary.** As Dana, attempt to reach Gate's branding surface. Confirm it is refused. As Vera, confirm the branding surfaces are not editable.
12. **Try to hide state.** As Dana, set the Rangers background to the organization's own accent color (`#8c2f39`) and check contrast. Confirm it is refused and the failure names the status/severity indicator pairs it would have hidden.
13. **Switch department overrides off.** As Olive, turn off *Allow departments to set their own accent and background* and save. Confirm Rangers surfaces lose the background but keep the logo and accent on the badge. Sign in as Dana and confirm the branding surface explains the switch, disables the colors section, still shows the stored values, and leaves the department logo control usable. Turn the switch back on as Olive and confirm Rangers gets its background back unchanged.
14. **Confirm the edit freeze and central authority.** As Olive, open the event's active window so it is currently running. Attempt any branding change. Confirm it is refused with a message about the active event window, and that nothing changed. Close the window again. Then, on the **on-site** node, attempt a branding change as Olive and confirm it is refused with a message directing the change to central.
15. **Confirm the identity boundary.** Sign out. Confirm the login screen and the magic-link landing show *Meridian*, not *Northwood Arts Collective*. Request a login link and confirm the email subject and body say *Meridian*. As Gwen, open the Orchid/God Mode console and confirm it shows Meridian's identity and Meridian's default colors. Confirm the desktop installer and packaged application icon still say Meridian (the running window icon is step 16).
16. **Confirm the desktop window icon.** Launch the Electron desktop/Kiosk application against a node that is locked to an event. Confirm the running window and taskbar icon shows the organization's compact mark (or full lockup when there is no compact mark). Then point it at a node with no event and confirm the icon is Meridian's again. Confirm the installer and the packaged application icon are Meridian's in both cases.
17. **Confirm branding survives offline.** On a device that has loaded the app at least once as a signed-in user, disable the network and reload. Confirm the organization name, mark, and palette still render.
18. **Confirm identity in generated output.** As Olive, export a published policy document and confirm the export names *Northwood Arts Collective* as the producer. As Ingrid, print an incident to PDF and confirm the same.
19. **Confirm state legibility.** With the branded palette and the Rangers background both active, review a screen showing several statuses and an IMS priority. Confirm every state is readable, carries a text label and an icon or structure, and is not distinguishable by color alone. Repeat in dark mode and confirm the department background is not applied there and the interface stays legible.
20. **Confirm the audit trail.** As Gwen, review the audit log and confirm entries exist for the branding create, the branding updates, the logo add, the logo replace, and the logo removal, each naming the actor.
21. **Confirm the department mark in the header.** As Dana, look at the header context block. Confirm the Rangers logo uploaded in step 9 renders immediately left of the department name and the event name. Remove the Rangers logo and confirm a generated `RA` lettermark renders in its place rather than a gap.
22. **Confirm the header department switchers.** Still as Dana, confirm the marks of the other departments Dana belongs to render right-adjusted, immediately left of the user menu, and that Rangers — the department Dana is currently in — is **not** among them. Click one and confirm the context switches to that department, that the clicked mark leaves the row, and that Rangers appears in it. Confirm each mark's accessible name names the department it switches to. Confirm the labelled department switch is still in the user menu.
23. **Change the department logo from department details.** As Dana, open *Admin* and confirm the *Department details* panel shows the current logo (or its lettermark). Press **Edit details**, upload a different PNG in the department logo control, and confirm the header mark and the department badge both change without a reload — and without pressing *Save department details*. Confirm the same logo is what the *Branding* surface now shows.
24. **Set a team logo.** As Dana, open *Admin → Teams* and edit the **Dirt** team. Confirm the form offers a team logo and offers **no** team accent and no team background. Upload the PNG and confirm it saves on choose. Confirm the team row in the Teams table and the *Teams you lead* card now show that mark, and that Team Overview for Dirt shows it beside the heading. Confirm a team with no logo — for example Rangers Default — shows a generated lettermark in the same places.
25. **Confirm the team branding boundary.** As Vera (staff, Rangers), confirm the team edit surface is not reachable. Attempt a team logo upload for a Gate team as Dana and confirm it is refused. Confirm a team logo cannot be uploaded into a department or organization slot, and that a department logo cannot be uploaded into a team slot.
26. **Set an event logo.** As Olive, open *Branding* and find the **Event logos** section. Confirm it lists this organization's events and offers a logo for each, and no colors. Upload the PNG for the event this node is locked to. Confirm it saves on choose. Then open the same event in the Orchid console and confirm the Branding block there shows the same logo.
27. **Confirm the event identity replaces the organization's.** On a node locked to that event, reload the app. Confirm the application header mark, the browser tab icon, and — on the desktop/Kiosk build — the running window and taskbar icon all show the **event** logo, not the organization's compact mark. Confirm the header name and the browser tab title now read the **event name**, not *Northwood Arts Collective* and not *Meridian*: the mark and the name identify the same party. Confirm the context block shows the department alone and no longer repeats the event name. Confirm the palette is unchanged, and that a generated PDF export and a system email still name *Northwood Arts Collective* — event identity is chrome only.
28. **Confirm the fallback and the boundary.** Remove the event logo and confirm the mark **and the name** both fall back together: the organization's compact mark beside *Northwood Arts Collective*, then the full lockup, then the `IBC` lettermark. Confirm the event name returns to the context block when the header stops carrying it. Point the node at no event and confirm the organization identity returns. Point a node at an event belonging to a **different** organization and confirm this organization's chrome shows its own mark and never the stranger's.
29. **Confirm the event mark on an unbranded organization.** Using an organization with no display name, palette, or logos, set a logo on its event and lock a node to it. Confirm the header, tab icon, and window icon show the event mark, and that login, the magic-link landing, node first-run setup, and Orchid still show Meridian's.
30. **Confirm the event branding boundary.** As Dana, confirm the *Event logos* section is not offered and an event logo upload is refused. Confirm an event logo cannot be uploaded into an organization, department, or team slot, and that none of those can be uploaded into the event slot.
31. **Confirm the event mark survives offline.** With the event logo set and the node locked to that event, load the app once, disable the network, and reload. Confirm the header and tab icon still show the event mark.

## Expected results

- An organization can set a display name, a ten-color palette, and logo assets, and those replace Meridian's name and mark on the app header, the Home control, the browser tab title, generated PDF exports, and organization-identified system email.
- Meridian's identity survives on login, the magic-link landing and login email, node first-run setup, the Orchid console, and desktop chrome and installers.
- A failing color combination is rejected with the failing pair, the measured ratio, and the required ratio, and the submitted colors come back unchanged. Meridian never silently corrects a color.
- The branding administration surfaces show the resulting appearance and the contrast verdict before a save.
- Only organizers and Lead Organizers can edit organization branding; only department leads and department administration — and organizers — can edit a department's, and only their own department's.
- A department carries a logo, one accent, and one surface background, and nothing else. Text, border, focus, status, severity, priority, and chart colors stay organization-resolved.
- The department background appears only on department-scoped surfaces. IMS surfaces, The Briefing, and organization-level surfaces are unaffected.
- A department with no logo renders a generated lettermark, and the accessible name still carries the full department name.
- Turning department overrides off removes backgrounds organization-wide, keeps logos and accents, and deletes nothing.
- Branding edits are refused during the active event window and refused on a node that is not central.
- Branding renders from cache on an offline device.
- The desktop/Kiosk window and taskbar icon shows the organization mark while the install is locked to an event, and Meridian's mark otherwise. The installer and packaged application icon are always Meridian's.
- No branding profile makes canonical status, severity, priority, or restriction unreadable or color-only, in light or dark mode, with or without a department background.
- Branding create, update, and asset removal are audited.
- The current department's mark renders in the header beside the department and event names, falling back to a lettermark.
- The marks of the user's other departments render right-adjusted beside the user menu and switch department context on click. The current department is never among them, and the labelled switch stays in the user menu.
- The department logo can be changed from department details as well as from the branding surface, and either one updates every surface holding the mark without a reload.
- A team carries a logo and no other branding value, edited under the department's branding authority, and rendered wherever the team is identified on its own. A team with no logo renders a generated lettermark.
- A logo cannot be uploaded into a slot that does not belong to its owner.
- An event carries a logo and no other branding value, edited under organization branding authority.
- On an install locked to an event with a logo, that logo and the event name are the application header identity, the browser tab icon, and the document title, and the logo is the desktop window icon — including for an organization that has set no branding of its own. The mark and the name always identify the same party; neither appears beside the other's.
- The context block shows the department alone while the header carries the event, and the event name returns to it when the header stops.
- The organization's palette, generated PDF exports, and system email are unaffected by event branding.
- An install not locked to an event, or locked to an event with no logo, shows the organization mark. A node locked to another organization's event never leaks that event's mark.
- Meridian's identity still survives on login, the magic-link landing, node first-run setup, the Orchid console, and desktop chrome and installers, with or without an event mark set.
- The event mark renders from cache on an offline device.

## Evidence to capture

- Screenshots of the header, Home control, and browser tab before and after branding.
- A screenshot of the refused contrast check showing the pair and both ratios.
- Screenshots of a department-scoped surface with the background applied, and an IMS surface and The Briefing without it.
- A screenshot of a lettermark badge for a department with no logo.
- A screenshot of the header showing the current department's mark in the context block and the other departments' marks beside the user menu, before and after switching.
- A screenshot of Team Overview and the Teams table showing a team logo, and one showing a team lettermark.
- Screenshots of the header, browser tab, and desktop window icon on an event-locked install, before and after the event logo is set.
- A screenshot of the login screen and the Orchid console after branding is applied.
- The generated policy export and incident PDF showing the organization name.
- A screenshot of the app rendering while offline.
- A screenshot of the desktop window/taskbar icon while locked to an event, and while not.
- Light and dark mode screenshots of a status-heavy screen under the branded palette.
- The audit log rows for branding create/update/asset removal.

## Failure notes

Record for each failure:

- the step number and persona;
- the exact colors submitted and the exact message shown;
- whether anything was stored despite a refusal (this is a BRAND-016 violation and is severe);
- whether any surface listed in step 15 showed organization identity (a BRAND-003 violation);
- whether any surface listed in step 10 took a department background (a BRAND-012 violation);
- whether any status, severity, priority, or restriction became unreadable or color-only (a BRAND-017 violation, and severe);
- environment, node role, event-window state, and build version.
