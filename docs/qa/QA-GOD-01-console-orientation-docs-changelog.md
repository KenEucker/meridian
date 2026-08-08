# QA-GOD-01: God Mode Console Orientation, Documentation, and Changelog

## Purpose

Prove that the God Mode console is about Meridian rather than about the administrative framework it is built on: that its landing screen orients a technician in Meridian and points at what needs their attention, that technician documentation and a Meridian changelog are readable inside the console with no network access, and that no console navigation entry links to framework documentation, the framework changelog, or the framework version.

This script covers the whole of Milestone 15B. A reviewer following it end to end exercises the orientation summary, all three attention groups in both degraded and healthy states, the read-only guarantee, the Documentation page and its exposure boundary, the packaged changelog, and central-node-only refresh with its degradation paths.

The console's *appearance* is out of scope here. Meridian's logo, favicon, palette, typography, spacing, and footer license are Milestone 15C and are covered by [`QA-GOD-02`](QA-GOD-02-console-visual-identity.md). Record an appearance failure there, not here.

## Requirements covered

`GOD-001` through `GOD-028`.

Supporting references: technical spec sections 22.1, 22.5, 22.6, 22.7, 22.8, 25.3, 26.2, and 26.3; `ORG-002`, `ORG-005` through `ORG-008`; the versioning strategy.

## Environment

- A development or standalone node for steps 1–12.
- A **central** node and a paired **on-site** node for steps 13–16.
- A node or browser that can be taken offline for step 11.
- A build packaged with `corepack pnpm run release:package`, or at minimum `corepack pnpm run docs:package` and `corepack pnpm run changelog:generate`, so technician documentation and the changelog data file are present.

## Personas

| Persona | Role | Used for |
|---|---|---|
| Gwen Godmode | God Mode user with console permissions | Every step |
| Nora Nopermission | Signed-in user with `platform.index` only | Step 12, the permission boundary |

## Setup data

Deliberately broken, so the attention list has something to say. Build this before you start:

- Organization **Northwood Collective** with at least one active department, but **no** Organizers Department and **no** default Incident Command Department set.
- Organization **Empty Collective** with **no** departments at all.
- Event **Emberfall 2026** under Northwood Collective with **no** assigned departments and no Incident Command override.
- No staff member holding the Lead Organizer role through an active team membership in Northwood Collective.
- At least one sync conflict in `open` status.
- On the on-site node: node role `onsite`, not yet paired with central.

Keep a note of what you broke. Step 10 asks you to fix it.

## Steps

1. **Open the console.** Sign in as Gwen and go to `/admin`. Confirm the landing screen is titled **Meridian God Mode** and described as *Repair and break-glass tooling for a Meridian deployment*. Confirm there is no "Welcome to your Orchid application", no framework welcome panel, and no invitation to read framework tutorials.
2. **Read the orientation summary.** Confirm eight headings are present and that each has a plain-English body: organizations and departments; events and the active event window; staff, teams, and roles; shifts and eligibility; operations, attendance, and hours; policies, procedures, and acknowledgments; field reports and incidents; nodes, sync, and authority.
3. **Confirm the boundary statement.** Confirm the summary states that God Mode is repair and break-glass tooling and that normal organizer, department, and staff workflows belong in Meridian Admin. Confirm it is visually prominent rather than buried in a paragraph.
4. **Confirm the three attention groups.** Below the summary, confirm a **Needs attention** region with the groups it has something to report in: *Deployment and configuration readiness*, *Organizational data gaps*, and *Unresolved sync conflicts*. Confirm a group with nothing to report is not rendered as an empty heading.
5. **Read the configuration group.** Confirm it reports the state of this node — for a node that is not paired, an item naming the incomplete pairing. On a node running in event mode without trusted HTTPS, or one that cannot serve the offline read set, confirm those are reported too. Confirm no item displays a secret value: a missing key is reported as missing, never quoted.
6. **Read the data gap group.** Confirm each seeded gap appears once: *Empty Collective has no departments*; *Northwood Collective has no Organizers Department*; *Northwood Collective has no default Incident Command Department*; *Northwood Collective has no active Lead Organizer*; *Emberfall 2026 has no assigned departments*; *Emberfall 2026 has no resolvable Incident Command Department*. Confirm **Empty Collective** reports only the missing-departments item — the downstream gaps are not piled on top of it.
7. **Read the conflict group.** Confirm it states the outstanding conflict count rather than listing every conflict.
8. **Follow every link.** Click through each attention item in turn and confirm it lands on a screen where that item can actually be resolved — Node Configuration for configuration items, the organization or event edit screen or the department/team lists for data gaps, the Sync Conflicts queue for conflicts. Return to the landing screen after each.
9. **Confirm the screen repairs nothing.** Reload the landing screen several times. Confirm nothing changes as a result: no organization gains a designation, no conflict resolves itself, no node config value appears. The list reports; it does not fix.
10. **Reach all-clear.** Now fix the seeded problems: set Northwood Collective' Organizers Department and default Incident Command Department, grant Lead Organizer to a team whose members are active staff of that organization, give Empty Collective a department, assign a department to the event, pair the node if applicable, and resolve the open sync conflict. Reload the landing screen. Confirm it now states explicitly that **nothing needs attention** rather than showing an empty region.
11. **Read documentation offline.** Disconnect the node from the internet. Open **Documentation** from the console navigation. Confirm it opens *inside* the console — not in a new browser tab and not on an external site — and that all six technician documents plus the index are listed: deployment; node setup and pairing; configuration; data repair; sync conflict resolution; break-glass procedures. Open *Sync conflict resolution* and confirm Markdown renders properly: headings, lists, a table, and any fenced code. Type `pairing` into the filter and confirm the list narrows. Type a word that appears only as a heading inside one document (for example `Reasons and audit`) and confirm that document is still found. Type nonsense and confirm the page says no title or heading matches, rather than going blank.
12. **Confirm the documentation boundary.** Confirm the page shows the packaged documentation version beside the running build version. Then confirm that the requirements document, technical specification, data/API specification, UI documentation, QA scripts, architecture decision records, the development plan, the traceability matrix, and issue documents are **not** reachable from this page — there is no link to them, no way to select them, and manually altering the `doc` query parameter to any of their names does not serve them. Sign in as Nora and confirm Documentation and Changelog are refused without their permissions.
13. **Read the changelog offline.** Still offline, on the **on-site** node, open **Changelog**. Confirm it renders completely, grouped by Meridian version with the newest version first, and that each entry shows a pull request title, number, author, and merge date. Confirm chore, docs, and fix entries are all present — the changelog is not filtered by change type. Confirm the page states it is showing the packaged baseline and that only the central node refreshes.
14. **Confirm refresh degradation.** On the **central** node with no source-repository credential configured, open Changelog. Confirm it still renders the full baseline and states that no credential is configured. Take the node offline with a credential configured and reload; confirm it still renders and reports that the source repository could not be reached, along with the time of the last successful refresh, or that none has been recorded.
15. **Confirm a successful refresh.** Restore the central node's network and credential. Reload Changelog. Confirm the page renders immediately without a visible delay, and that on a subsequent reload it reports a successful refresh with a timestamp. Confirm recently merged pull requests that were not in the packaged baseline now appear, and that no entry is duplicated.
16. **Confirm the event-window skip.** Open an event's active window on the central node and reload Changelog. Confirm the page still renders and states that refresh is skipped while an event is in its active window. Close the window again.
17. **Confirm no credential leaks.** Confirm the source-repository credential is nowhere on the Changelog page, nowhere in Node Configuration (which shows *Configured (hidden)* or *Not set* for secrets), and nowhere in the server log after a failed refresh.
18. **Confirm the framework links are gone.** In the console navigation, confirm **Documentation** and **Changelog** point at Meridian's own pages. Confirm no navigation entry links to `orchid.software`, to the framework's changelog on GitHub, or opens in a new browser context, and that the version badged in the navigation is the Meridian build version from the root `package.json` — not the framework's.

## Expected results

- The landing screen presents Meridian's orientation summary and the God-Mode-is-repair-tooling boundary, with no framework welcome content.
- Attention items appear in three groups, each item links to the screen that resolves it, and viewing the screen changes nothing.
- With everything resolved, the screen states all-clear explicitly.
- Documentation renders the packaged technician tree with no network access, filters by title and heading, shows documentation version against build version, and serves nothing outside `docs/technician/`.
- Changelog renders the packaged baseline offline, grouped by Meridian version, unfiltered by change type.
- Refresh happens only on central, only outside the active event window, never blocks rendering, degrades to the baseline on missing network, missing credential, or failure, and never exposes its credential.
- No console navigation entry references framework documentation, the framework changelog, or the framework version.

## Evidence to capture

- Screenshot of the landing screen with all three attention groups populated.
- Screenshot of the landing screen reporting all-clear.
- Screenshot of one attention item and the screen it links to.
- Screenshot of the Documentation page with a rendered table and the version pair visible.
- Screenshot of the Changelog page on an offline on-site node, showing at least two version groups.
- Screenshot of the Changelog page degraded on central, showing the reason and last successful refresh.
- Screenshot of the console navigation showing the Meridian build version badge.
- Server log excerpt from a failed refresh, showing no credential.

## Failure notes

Record which requirement ID failed, the node role and event-window state at the time, and whether the failure was in the check itself or in the link it offered. An attention item that reports the wrong state and an attention item that links to the wrong screen are different defects.

If the Documentation page is empty, confirm the build was packaged: `corepack pnpm run docs:package`. If the Changelog page says nothing is packaged, run `corepack pnpm run changelog:generate`. Neither is a product defect — but a *release* build that ships without them is.

Console appearance failures — logo, favicon, palette, typography, spacing, footer license, copyright range — belong to `QA-GOD-02` and should be recorded there, not here.
