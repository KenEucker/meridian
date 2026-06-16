# Meridian Screen Surface Specification

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define the expected structure, behavior, and state model for Meridian application screens without specifying every route or field.

---

## 1. Purpose

This specification translates the Meridian UI Operating Guide into screen-level rules. It defines how Meridian surfaces should organize context, navigation, actions, data density, empty states, loading states, permission behavior, offline behavior, and review expectations.

This document does not replace the operating guide. When there is ambiguity, the operating guide governs.

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
- reports and review surfaces;
- admin and configuration surfaces.

Every new screen should identify its category during design and review.

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

---

## 7. Department Work Surfaces

Department surfaces must treat departments as first-class navigational spaces.

Department screens should include:

- visible department identity through `DepartmentBadge`;
- event and operations-window context where relevant;
- department-scoped actions;
- role-aware visibility;
- direct access to related rosters, shifts, deployments, reports, and incidents when permitted.

Department accent color may be used as a small identifier. It must not become a department-specific theme.

---

## 8. Roster and Shift Board Surfaces

Roster and shift board screens must adapt to device capability.

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

---

## 13. Admin and Configuration Surfaces

Admin surfaces may expose more complex configuration and permission details than volunteer-facing surfaces.

Admin screens may show disabled controls with explanations when that helps understanding. They must still use canonical statuses, standard components, semantic tokens, and confirmation dialogs for destructive changes.

Admin surfaces must not become a separate design system.

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

---

## 16. Permission Behavior

Screens must be role-aware from the beginning.

Default volunteers should not see administrative complexity. Elevated users may receive more specific restricted-access explanations.

Permission-denied screens should be direct and calm:

- default volunteers: restricted access;
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
- empty, loading, error, and success states.

---

## 18. Open Questions

The following items require future product or implementation decisions:

- exact route map;
- canonical screen inventory;
- detailed per-role home widget assignments;
- full status-to-visual mapping;
- breakpoint-specific layouts;
- report export formats;
- admin configuration screen hierarchy.

