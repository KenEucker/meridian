# Meridian IMS Surface Specification

Version: Draft 2
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define the surface rules for Meridian Incident Management System screens.

---

## 1. Purpose

This specification defines the UI expectations for Meridian IMS surfaces, including incident lists, incident detail, incident create/edit, field reports, timelines, notes, priority labels, permissions, audit access, and offline behavior.

IMS screens should feel more serious and restricted than normal staff and shift surfaces, but they must remain fast, clear, and usable.

When this document conflicts with the canonical Meridian UI Operating Guide, the parent guide governs. Deterministic Alpha 1 IMS routes, statuses, priority labels, Field Report lifecycle, permission predicates, and offline rules are defined in `docs/ui/meridian-ui-implementation-contract.md`.

---

## 2. IMS Design Posture

IMS surfaces should communicate:

- restricted operational context;
- clear accountability;
- accurate canonical status;
- priority and attention;
- quick correction of routine mistakes;
- auditability without clutter.

Organizer role alone does not grant access to IMS incidents or restricted IMS surfaces. Incident records, incident dashboards, and restricted Field Report review surfaces require appropriate IC team membership for the event's configured IC department.

The seriousness of IMS should come from clarity, permissions, auditability, and visual restraint, not artificial friction.

---

## 3. Visual Tone

IMS surfaces should use:

- minimal color variation;
- restrained status indicators;
- clear priority labels;
- visible borders and structure;
- plain text-first layouts;
- limited decorative treatment.

IMS should avoid:

- unnecessary charts;
- decorative visual widgets;
- playful illustrations;
- overly bright full-surface alerts;
- department-specific theme overrides.

---

## 4. Required IMS Surface Types

IMS may include:

- incident dashboard;
- incident list;
- incident detail;
- incident create/edit;
- field report submission;
- field report detail;
- incident timeline;
- incident history drawer;
- restricted access surface.

Each surface must identify organization, event, relevant department context, role, and permissions where they affect the work.

---

## 5. Incident Dashboard

The IMS dashboard should prioritize attention items.

Appropriate content includes:

- active incident counts;
- serious incidents;
- monitoring incidents;
- on-scene incidents;
- unresolved field reports;
- quiet state when there are no active incidents.

Charts should be avoided unless they directly improve operational readiness.

---

## 6. Incident List

Incident lists should favor scanning and triage.

Required content:

- incident identifier or title;
- canonical incident status;
- priority label;
- location or area when available;
- last meaningful update;
- assignment or owner where applicable;
- restricted-state indicator where applicable.

On keyboard-first profiles, incident lists should be table-first. On
touch-first and Kiosk fullscreen profiles, they should become card-first.

Use the shared `uiMode` plus `presentationProfile` contract to select
table-first, card-first, narrow, fullscreen, or compact treatments:

```ts
uiMode: 'admin' | 'field' | 'kiosk'
presentationProfile: 'keyboard-first' | 'touch-first' | 'narrow' | 'wide' | 'fullscreen' | 'compact' | 'roomy' | 'table-first' | 'card-first' | 'priority-feed'
```

---

## 7. Incident Detail

Incident detail should separate current operational state from historical audit data.

Recommended structure:

- incident title and identifier;
- canonical status;
- priority label;
- context summary;
- primary actions in `ActionBar`;
- tag and Name Reference metadata where present;
- current notes and operational details;
- linked camp/operational map location details where the incident references one and the user is permitted;
- related field reports;
- meaningful timeline entries;
- `HistoryDrawer` for audit and routine field-change entries.

Routine audit entries should be hidden unless expanded.

When an incident references a camp, the detail surface may show useful camp location details to permitted IC roles. Incident map/location visibility follows existing IMS permissions, and the free-text location/area remains the primary location display.

---

## 8. Incident Create and Edit

Incident create/edit is the only autosaving form in Meridian.

Required behavior:

- every change autosaves;
- autosave status is visible but not interruptive;
- failed autosave is visible;
- offline state blocks create/edit and preserves the current local form state where technically practical;
- validation remains clear;
- destructive changes require confirmation.

Incident notes are edited as plain text. Markdown formatting may be supported while editing, but formatting should not render until after submission. Incident body/history entries are append-only after posting.

Incident create/edit may offer an optional camp/operational map location selector for users permitted to view map data. The selector chooses from known camps/map locations only; it must be optional, must never block incident creation, must not replace the free-text location/summary fields, and must not introduce arbitrary dropped pins. If the user lacks map permissions or no published map exists, the selector is hidden.

Incident creation and editing require server connection in Alpha 1. IMS surfaces must not imply that offline incident creation has been queued.

---

## 9. Field Reports

Field Reports should carry IMS seriousness.

Field Report submission forms should:

- use the same general form language as incident forms;
- avoid decorative treatment;
- require a title and body text;
- show event and department context where relevant;
- use explicit Submit and Cancel;
- finalize the Field Report on submit;
- avoid autosave and drafts;
- avoid normal in-place edit behavior after submission;
- allow authors to view their own submitted Field Reports, including title.

Field Report title and body entry remain plain text. Body entry must not provide Name Reference autocomplete, context menus, or suggestions of existing references. Submitted Field Report body text may highlight Name References where the renderer supports it. Titles are not parsed for Name References.

Field Reports have one required title and one unstructured body text field and must not gain a map/location selector, dropped pins, coordinates, or camp selector for MVP.

Corrections must be append-only, audit-aware, or represented as follow-up notes where allowed. IC users may attach Field Reports to incidents when permitted. Attaching or unlinking a Field Report is audit-aware and should appear in the incident timeline.

When a Field Report is attached to an incident, the copied incident timeline
note should include the Field Report title, author, and body.

Linked Incident and Field Report picker lists must show only same-event records
that are not already actively linked to the current incident. Candidate records
that share one or more `#tags` with the current incident should be listed first;
free-text location matches must not affect this prioritization. Within the
shared-tag group and the remaining group, candidates should be ordered by most
recently added first: incident `created_at` for linked Incident candidates and
Field Report submission/acceptance time for Field Report candidates.
Picker search must be able to find same-event Incident and Field Report records
that are already linked to other incidents, provided the record is not already
actively linked to the current incident.

IMS Incident and IC Field Report list pages should expose explicit Home links
and cross-links to each other. Incident list headings should be sortable, with
state and priority filters that exclude Closed incidents by default while still
allowing Closed incidents to be included. IC Field Report list headings should
be sortable; until Field Reports gain their own state/priority fields, Field
Report state and priority filters are based on related incidents.

## 9.1 Name References

Name References are inline `@name` markers in Incident notes and Field Reports.

Rendered Name References should:

- remain readable as the original typed text;
- be visually distinct from `#tags`;
- avoid styling that suggests Meridian user mentions, notifications, volunteer profile links, or identity records;
- use accessible names that identify them as Name References when the distinction is not visually obvious.

Incident-level Name Reference chips should appear near existing tags or metadata where appropriate. Chips include references extracted from incident notes and attached Field Reports.

Clicking a Name Reference chip runs normal permission-filtered search for the reference text without the `@` prefix. It must not open a Name Reference profile, detail page, profile drawer, alias merge UI, or management screen.

---

## 10. Status and Priority

IMS priority labels must be visually distinct from normal statuses.

Priority must not be confused with:

- incident state;
- staff status;
- credential status;
- shift attendance flags;
- department identity.

Incident status labels must use canonical system names.

Dashboard/widget attention, IMS priority, and incident state are separate:

- dashboard attention controls how strongly the UI draws attention;
- IMS priority describes operational seriousness;
- incident state describes workflow state.

Alpha 1 incident states are Open, On Scene, Monitoring, On Hold, and Closed. Provisional Alpha 1 IMS priority labels are Routine, Important, Serious, and Critical; these are product-reviewable.

---

## 11. Actions

IMS actions should be role-aware and permission-aware.

Routine operational actions may be immediate when easy to correct.

Destructive or high-impact IMS actions require `ConfirmationDialog`, including:

- deleting;
- striking or invalidating historical records;
- destructive incident changes;
- restricted-state changes that cannot be easily corrected.

Action labels must use specific verbs.

Field Report original submissions must not expose Edit, Save, or autosave actions after submission.

---

## 12. Timeline and Audit Behavior

Incident timelines should focus on meaningful operational entries.

Show by default:

- incident opened;
- priority changes;
- status changes;
- assignments;
- operational notes;
- field reports attached;
- major location or deployment updates;
- closure or resolution entries.

Hide by default:

- routine field-change audit entries;
- low-signal metadata updates;
- mechanical sync events.

Hidden entries should remain available through expansion or `HistoryDrawer`.

---

## 13. Permissions and Restricted Access

IMS records may be visible only to appropriate IC team-granted authority.

Command palette, search, dashboard widgets, and direct routes must all respect IMS permissions.

IC access is granted through the event's configured IC department and team-granted IC authority such as IC Viewer, IC Operator, and IC Lead. Department Lead or Organizer access alone is insufficient.

Name References inherit source-record visibility. Search results, chips, and rendered highlights must not reveal incidents or Field Reports unavailable to the user.

Restricted access behavior:

- default staff receive simple restricted-access messaging;
- elevated users may see required role information when helpful;
- Meridian Kiosk should return to a safe Kiosk surface when appropriate.

---

## 14. Offline and Sync

IMS offline behavior must be careful and honest.

Affected IMS screens should:

- show relevant local or central connectivity state;
- disable or hide unavailable actions;
- show queued changes when relevant;
- avoid interrupting unless the current action cannot continue;
- expose sync repair only in advanced mode.

Incident autosave failures should be visible without destroying the user's current typing flow.

Incidents require server connection for creation and editing in Alpha 1. Field Report creation may work offline and appears submitted immediately with queued sync state when applicable.

Name References remain text-first and local-first friendly. Source text syncs through the existing Incident note and Field Report sync behavior, and any local/server Name Reference index must remain rebuildable from source text without becoming a separate permission source.

---

## 15. Accessibility

IMS surfaces must meet the same accessibility baseline as all Meridian UI.

IMS-specific checks:

- priority and status are both text-readable;
- restricted state is not color-only;
- timelines are keyboard navigable;
- notes can be edited without mouse;
- autosave state is perceivable;
- dark mode preserves serious-state readability;
- Field Report original body is viewable but not editable after submission;
- Field Report append/correction affordances are audit-aware;
- Name References are distinguishable from tags, ordinary links, and user/profile mentions.

---

## 16. IMS Review Checklist

Review IMS UI changes for:

- parent guide alignment;
- canonical status names;
- priority/status distinction;
- role and permission behavior;
- autosave behavior on incident create/edit;
- Field Report submit/finalize behavior;
- Name Reference rendering, chip, search, and non-identity behavior;
- destructive confirmations;
- audit and history placement;
- offline and sync behavior;
- keyboard navigation;
- visible focus;
- light and dark mode;
- touch and kiosk behavior where relevant;
- `uiMode` and `presentationProfile` behavior.

---

## 17. Open Questions

Future versions should define:

- final product-reviewed IMS priority labels;
- restricted-state visual mapping;
- audit event taxonomy;
- detailed IMS command palette result ordering.
