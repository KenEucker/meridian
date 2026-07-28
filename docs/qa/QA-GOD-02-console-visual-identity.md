# QA-GOD-02: God Mode Console Visual Identity

## Purpose

Prove that the God Mode console looks like Meridian rather than like a default installation of the administrative framework it is built on: that its palette, typography, and spacing come from Meridian's shared design tokens, that it carries Meridian's logo and favicon, that its footer states Meridian's own license, copyright range, and build version with no framework equivalent anywhere, that authentication and node first-run setup match it, that an organization branding profile cannot change it, and that none of the restyling costs contrast or focus visibility.

This script covers the whole of Milestone 15C. The console's *content* — the orientation summary, the attention list, Documentation, and Changelog — is Milestone 15B and belongs to `QA-GOD-01`. Follow that script for what the console says; follow this one for how it looks.

## Requirements covered

`GOD-029` through `GOD-038`.

Supporting references: `BRAND-003`; UI style guide sections 4 and 5; UI implementation contract section 10; component library specification section 3; accessibility checklist section 6; technical spec sections 22.1 and 26.3; [the console override inventory](../process/god-mode-console-override-inventory.md).

## Environment

- A development or standalone node with the console reachable at `/admin`.
- A browser whose operating system theme can be switched between light and dark, so both console themes can be checked.
- A browser window that can be narrowed below 992 pixels, which is where the console navigation collapses.
- A contrast measuring tool — browser dev tools' accessibility pane, or any eyedropper-based checker.
- Keyboard-only navigation for the focus steps. A pointing device is not enough.

## Personas

| Persona | Role | Used for |
|---|---|---|
| Gwen Godmode | God Mode user with console permissions | Every step |
| Olive Organizer | Organization administrator who can edit branding | Step 10, the branding isolation check |

## Setup data

- Organization **Deep Harbor Collective** with a **complete branding profile**: display name, uploaded logo, and a palette that is visibly nothing like Meridian's — a saturated blue or magenta primary, a cool grey canvas.
- At least one console screen with a populated table (Users, Departments, or Sync Conflicts) and one with a form (Node Configuration or an organization edit screen), so tables and forms can be inspected rather than imagined.
- The node **not** yet set up, or a second node available at first run, for step 9.

## Steps

1. **Open the console.** Sign in as Gwen and go to `/admin`. Confirm the workspace is Meridian's warm sand canvas with cards on the lighter surface, not the framework's cool grey. Confirm the navigation column is Meridian's slate primary color rather than the framework's near-black, and that body text is Inter rather than the browser's default system stack.
2. **Confirm the logo.** In the expanded navigation, confirm the Meridian mark appears beside the product name **Meridian** with **God Mode** beneath it. Confirm the framework's house icon and the bare application name are gone.
3. **Confirm the compact mark.** Narrow the browser window below 992 pixels so the navigation collapses. Confirm the mark is still shown and that the product name is no longer displayed. Then confirm with a screen reader or the accessibility inspector that the brand link still has the accessible name *Meridian* — the name is hidden visually, not removed.
4. **Confirm the favicon.** Check the browser tab on the console, on `/admin/login`, on `/login`, and on `/setup`. Confirm each shows the Meridian favicon. Confirm the framework's own icon (`/vendor/orchid/favicon.svg`) is served on none of them.
5. **Read the footer.** Scroll to the bottom of a console screen. Confirm it states that **Meridian is published under the AGPL-3.0-or-later license**, a copyright line beginning at **2026**, and **Meridian version** followed by the version from the root `package.json`. Confirm the license matches what the repository's `LICENSE` file actually contains.
6. **Confirm the framework footer is gone.** On the same screen and on `/admin/login`, confirm none of the following appear anywhere: the MIT license, a 2016-to-present copyright range, the framework version number, the "Crafted with … by Alexandr Chernyaev" credit, or links to `orchid.software`, the framework's GitHub, its discussions, its design guide, or its OpenCollective page.
7. **Inspect a table.** Open a console screen with a populated table. Confirm column headings are small, uppercase, and muted rather than full-size body text; that row spacing is comfortable rather than cramped; and that row dividers are visible without being heavy.
8. **Inspect a form.** Open a console screen with a form. Confirm labels, inputs, help text, and buttons all use the Meridian type scale and spacing, that inputs have Meridian's rounded corners rather than the framework's, and that the primary action button is Meridian's slate rather than the framework's blue. Find or create a **disabled** control and confirm its label is still readable — a disabled control that has faded to unreadable grey is a failure.
9. **Confirm authentication and setup match.** Sign out. Confirm `/login`, the magic-link "check your email" page, the sign-out confirmation page, and `/setup` all carry the Meridian mark, the same canvas and surface colors, the same typography, and the same footer as the console. Confirm none of them looks like a bare unstyled HTML page and none looks like the framework.
10. **Confirm branding isolation.** Sign in as Olive and confirm Deep Harbor Collective's branding is live in Meridian Admin — its palette and logo have replaced Meridian's in the product shell. Now return to `/admin` as Gwen. Confirm the console is *unchanged*: still Meridian's palette, still the Meridian mark, no trace of Deep Harbor Collective's name, logo, or colors. Confirm the same on `/login` and `/setup`. View the console's page source and confirm no `branding/{organization}/tokens.css` stylesheet is linked and no `data-organization-branding` attribute is present.
11. **Check contrast in light mode.** With the operating system in light mode, measure these pairs on a console screen and confirm each meets its threshold: body text on the canvas and on a card (4.5:1); muted text such as a table heading or help text (4.5:1); a link (4.5:1); an input border against its surface (3:1); a navigation label against the navigation (4.5:1); the active navigation item's label against its pill (4.5:1); a primary button's label against its fill (4.5:1); a destructive button's label against its fill (4.5:1).
12. **Check contrast in dark mode.** Switch the operating system to dark mode and reload. Confirm the console follows into Meridian's dark palette rather than staying half-light. Repeat every measurement from step 11.
13. **Check focus visibility.** Using only the keyboard, tab through a console screen from the navigation into the workspace and through a form. Confirm every focusable element — navigation links, buttons, inputs, selects, table row actions, pagination — shows a clearly visible focus ring. Confirm the ring is visible against the navigation's colored background as well as against the workspace, and that focus is never lost with no visible indicator anywhere on screen. Repeat in dark mode.
14. **Check status legibility.** Find a screen showing a badge, an alert, and status text — the landing screen's attention list is the easiest. Confirm each state is readable in both themes, and that each is carried by wording and shape as well as by color: an alert reads as a warning from its text, not only from its tint.
15. **Confirm no vendor views were overridden.** Open [the console override inventory](../process/god-mode-console-override-inventory.md) and confirm its vendor view overrides table matches reality — that `apps/server/resources/views/vendor/platform/` either does not exist or contains only files listed there with a stated reason.

## Expected results

- The console renders Meridian's palette, typography, spacing, logo, and favicon rather than framework defaults, in both light and dark themes.
- The collapsed navigation renders the compact mark and keeps the brand link's accessible name.
- The footer states Meridian's actual license, a copyright range beginning at 2026, and the Meridian build version, and no framework license, copyright range, version, credit, or link appears anywhere in the console.
- Login, the magic-link landing, sign-out, and node first-run setup carry the same identity as the console.
- An organization branding profile that is visibly live in Meridian Admin changes nothing about the console, login, or setup.
- Every measured pair meets WCAG 2.1 AA in both themes: 4.5:1 for text, 3:1 for boundaries and focus indicators.
- Every focusable control shows a visible focus ring, including inside the colored navigation.
- The override inventory matches what is actually in the repository.

## Evidence to capture

- Screenshot of a console screen in light mode showing the navigation, brand lockup, a table, and the footer.
- Screenshot of the same screen in dark mode.
- Screenshot of the collapsed navigation showing the compact mark, plus the accessibility inspector showing the brand link's name.
- Screenshot of the browser tab showing the Meridian favicon on the console and on `/login`.
- Screenshot of the footer, legible enough to read the license, copyright range, and version.
- Side-by-side screenshots of Meridian Admin under Deep Harbor Collective's branding and the console at the same moment.
- Contrast measurements for the pairs in steps 11 and 12, with the measured ratios recorded, not just a pass mark.
- Screenshot of a focused control in the navigation and a focused control in a form, in both themes.

## Failure notes

Record which requirement ID failed, which theme it failed in, and which surface. Light-mode and dark-mode failures are different defects: Meridian's dark neutrals are not covered by the branding validator, so a dark-mode contrast failure is not caught by anything the branding surface runs.

Distinguish three kinds of appearance failure, because they have different fixes. A surface that resolves the *wrong* Meridian token is a bridge defect in `apps/server/public/css/meridian-console.css`. A surface still showing framework styling is a mapping that was never written. A surface showing an organization's branding is a boundary defect and is the most serious of the three — record it against `GOD-035` and `BRAND-003`.

If contrast fails on a pair that the automated `ConsoleContrastTest` covers, the test and the rendered page disagree, which means the stylesheet has drifted from the pairs the test measures. Note the pair name so the test's pair list can be corrected along with the CSS.
