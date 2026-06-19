# Meridian Screen Surface Specification

Version: Draft 2
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define the expected structure, behavior, and state model for Meridian application screens without specifying every route or field.

---

## 1. Purpose

This specification translates the Meridian UI Operating Guide into screen-level rules. It defines how Meridian surfaces should organize context, navigation, actions, data density, empty states, loading states, permission behavior, offline behavior, and review expectations.

This document does not replace the operating guide. When there is ambiguity, the operating guide governs.

Detailed Alpha 1 route names, screen IDs, component APIs, status enums, permission predicates, dashboard widgets, offline states, and kiosk contracts are defined in `docs/ui/meridian-ui-implementation-contract.md`.

---

## 2. Surface Categories

Meridian screens should generally fit one of these surface categories:

- Home and context selection surfaces;
- operational dashboard surfaces;
- department work surfaces;
- roster and shift board surfaces;
- record list surfaces;
- record detail surfaces;
- create and edit form surfaces;
- IMS surfaces;
- kiosk and shared workstation surfaces;
- policy, procedure, and fragment surfaces;
- reports and review surfaces;
- admin and configuration surfaces.

Every new screen should identify its category during design and review.

Every Alpha 1 screen should map to a stable screen ID and route/view target from `docs/ui/meridian-ui-implementation-contract.md`, or explicitly document the new screen ID when extending the inventory.

---

## 3. Required Screen Context

Screens must show operating context whenever that context affects what the user can see or do.

Relevant context may include:

- organization;
- event;
- operations window;
- department;
- role;
- permission level;
- kiosk or trusted workstation state;
- offline or sync state.

The context may appear in the `AppTopBar`, `ContextBar`, local heading area, status strip, or an inline control. It must not be hidden only in a menu when it changes user decisions.

Screen view models should include the active surface mode:

```ts
surfaceMode: 'desktop' | 'touch' | 'mobile' | 'kiosk' | 'dense'
```

Equivalent PHP/Blade naming is acceptable. Screen width, pointer capability, device configuration, kiosk/trusted workstation state, current screen type, and user-selected dense mode may all influence the active surface mode.

---

## 4. App Shell Use

All authenticated product screens should assume the Meridian app shell unless they are intentionally outside the main application flow.

The app shell must include:

- `AppTopBar` with Meridian Home control;
- command palette access;
- current department context when inside a department surface;
- optional `ContextBar` when organization, event, operations window, role, or sync state needs more room;
- optional `ActionBar` for operational and editing surfaces.

Screens must not introduce a persistent left sidebar as the primary navigation model.

---

## 5. Home and Context Selection Surfaces

Home surfaces are task-based rather than menu-based.

Home screens should answer:

- where am I operating;
- what event is active;
- what work needs attention;
- what departments can I enter;
- what actions are available to my role;
- whether the event is active, upcoming, closed, or in review.

Organization and event switching should happen on home or a dedicated context-switching surface, not in the top bar.

---

## 6. Dashboard Surfaces

Dashboard screens should prioritize attention and action.

Dashboard surfaces may use:

- metric cards;
- action cards;
- alerts;
- short lists;
- charts when they clarify readiness or change;
- quiet-state cards.

Dashboards must not show metrics only because the data exists. Every widget should support a user decision, reassurance, or action.

On mobile, dashboard widgets should collapse into a single priority feed.

Organizer dashboards must not expose IMS incidents, restricted Field Reports, active incident counts, serious incidents, on-scene incidents, monitoring incidents, or IMS-specific alerts unless the user also has IC team-granted authority for the event's configured IC department.

---

## 7. Department Work Surfaces

Department surfaces must treat departments as first-class navigational spaces.

Department screens should include:

- visible department identity through `DepartmentBadge`;
- event and operations-window context where relevant;
- department-scoped actions;
- role-aware visibility;
- direct access to related rosters, shifts, deployments, exports, department documents, and IMS surfaces only when permitted.

Department accent color may be used as a small identifier. It must not become a department-specific theme.

---

## 8. Roster and Shift Board Surfaces

Roster and shift board screens must adapt to device capability.

Use `surfaceMode` rather than viewport width alone to choose table-first, card-first, mobile, kiosk, or dense treatments.

On non-touch desktop and laptop devices:

- use table-first layouts;
- support scanning, sorting, filtering, and row-level actions;
- keep forms and tables flat and utilitarian;
- favor dense information where useful.

On touch-enabled, mobile, and kiosk devices:

- use card-first layouts;
- increase spacing and control size;
- preserve visible labels;
- keep primary actions in the `ActionBar` or directly attached to the relevant card.

Dense mode may reduce spacing and optional hints. It must not remove required context or accessibility.

---

## 9. Record List Surfaces

Record lists should be optimized for scanning and triage.

Required elements:

- clear page title;
- active scope;
- filters where useful;
- canonical status names;
- restrained `StatusPill` use;
- empty, loading, error, and permission states;
- row or card action patterns appropriate to device type.

Unavailable actions should usually be hidden. In high-context admin surfaces, disabled actions may appear with concise explanation.

---

## 10. Record Detail Surfaces

Record detail screens should separate current state from history.

Recommended structure:

- context and record title;
- current status and primary metadata;
- tag and Name Reference metadata where relevant;
- primary actions in the `ActionBar`;
- main content;
- related records;
- `HistoryDrawer` for audit and timeline details.

Destructive actions must route through `ConfirmationDialog`.

---

## 11. Create and Edit Surfaces

Long forms should generally remain single-page.

Required form behavior:

- clear labels;
- required fields marked explicitly;
- predictable error placement;
- top-level error summary;
- submit-time validation;
- blur validation where useful;
- explicit Save and Cancel for normal forms.

The incident create/edit screen is the only autosaving form. Routine operational changes may save immediately when the result is easy to correct.

Field Reports are submitted, not saved as drafts. Field Report submission surfaces use Submit and Cancel, finalize on submit, do not autosave, and do not expose normal in-place editing after submission. Corrections, where allowed, are append-only and audit-aware.

Field Report entry must remain plain text. It must not show Name Reference autocomplete, context menus, or suggestions of existing references. Submitted Field Report views may highlight Name References when rendered.

Policy and procedure document editors use explicit Save/Publish/Archive actions. Document viewers render sanitized Markdown with referenced fragment text inline. Fragment editors show referencing documents before saving changes that will bump published document versions.

Policy/procedure acknowledgments occur during signup or training only in Alpha 1, require server connection, and must not be presented as direct shift-signup or credential-eligibility gates.

---

## 12. Reports and Review Surfaces

Reports and review screens should emphasize reliable interpretation over visual flourish.

They may use:

- tables;
- summary cards;
- simple bar or line charts;
- export controls;
- filters and date ranges;
- audit-aware drill-downs.

Reports should make event, organization, and date scope impossible to miss.

Policy/procedure exports are a specialized document export surface. They must show document type, title, version, scope, and export timestamp, and they must render referenced fragment text inline.

---

## 13. Admin and Configuration Surfaces

Admin surfaces may expose more complex configuration and permission details than staff-facing surfaces.

Admin screens may show disabled controls with explanations when that helps understanding. They must still use canonical statuses, standard components, semantic tokens, and confirmation dialogs for destructive changes.

Admin surfaces must not become a separate design system.

Orchid admin and god-mode surfaces must include policy documents, procedure documents, document fragments, document acknowledgments, node configuration, audit, and sync conflict review where authorized. Fragment edit screens must warn when changing a fragment will bump published referencing document versions.

---

## 14. Loading, Empty, Error, and Success States

Every screen must define state behavior.

Loading states should preserve layout where possible.

Empty states should be utilitarian. They should explain what is absent and provide the next allowed action when appropriate.

Error states should state what failed, whether the user's work is preserved, and what can be done next.

Success states should be brief. Routine operational success may be shown through inline state change rather than a modal.

---

## 15. Offline and Sync Surface Rules

Offline and sync state should appear only where it matters.

Screens affected by offline state must:

- show a contextual `OfflineBanner` or local status indicator;
- disable or hide unavailable actions;
- indicate queued local actions when useful to the user who performed them;
- avoid interruptive sync failure dialogs unless the current action cannot continue.

Advanced sync repair belongs in advanced mode only.

Incident creation and editing are online-only in Alpha 1. Field Report creation, Field Report photo attachment sync, check-in, check-out, and mark no-show may be offline writes. Policy/procedure acknowledgments are online-only in Alpha 1.

Name References remain text-first during offline use. Source text syncs through the existing Incident note and Field Report sync behavior, and any local Name Reference index or highlight state must remain rebuildable from source text and bounded by the same permissions.

---

## 16. Permission Behavior

Screens must be role-aware from the beginning.

Default staff should not see administrative complexity. Elevated users may receive more specific restricted-access explanations.

Organizer role alone does not grant access to IMS incidents or restricted IMS surfaces. Incident records, incident dashboards, and restricted Field Report review surfaces require appropriate IC team membership for the event's configured IC department.

Name References inherit visibility from their source Incident notes and Field Reports. Rendering, chips, clicks, and search results must not reveal unavailable records.

Permission-denied screens should be direct and calm:

- default staff: restricted access;
- elevated users: role or permission required when useful;
- kiosk mode: return to safe kiosk surface when appropriate.

---

## 17. Surface Review Checklist

Every new or changed screen should be reviewed for:

- correct parent guide alignment;
- context clarity;
- app shell consistency;
- command palette integration where applicable;
- bottom action bar behavior;
- destructive action confirmation;
- keyboard navigation;
- visible focus states;
- light and dark mode;
- reduced motion;
- touch behavior where relevant;
- offline and sync behavior where relevant;
- canonical status language;
- policy/procedure document rendering, fragment references, and acknowledgment placement where relevant;
- empty, loading, error, and success states.
- route/screen ID alignment with the implementation contract;
- `surfaceMode` behavior.

---

## 18. Open Questions

The following items require future product or implementation decisions:

- detailed per-role home widget assignments;
- full status-to-visual mapping;
- breakpoint-specific layouts;
- report export formats;
- admin configuration screen hierarchy.
- exact policy/procedure editor fragment-token interaction details.
