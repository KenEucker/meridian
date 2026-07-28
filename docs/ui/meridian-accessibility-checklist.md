# Meridian Accessibility Checklist

Version: Draft 2
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Provide a practical accessibility and field-usability checklist for Meridian UI design, implementation, and review.

---

## 1. Purpose

This checklist turns Meridian's accessibility principles into reviewable criteria. It applies to all UI work, including dashboards, forms, tables, touch surfaces, Meridian Kiosk, IMS, policy/procedure documents, and admin screens.

Meridian should follow WCAG 2.2 AA unless the project approves a successor target.

When this document conflicts with the canonical Meridian UI Operating Guide, the parent guide governs. Deterministic Alpha 1 route, component, status, permission, widget, offline, Kiosk, UI mode, and presentation profile contracts are defined in `docs/ui/meridian-ui-implementation-contract.md`.

---

## 2. Required Review Summary

Every UI PR must be reviewed for:

- keyboard navigation;
- visible focus states;
- visible labels or accessible labels;
- reduced motion;
- non-color-only state communication;
- form error summaries;
- permission and disabled/hidden action clarity;
- light and dark mode contrast;
- touch and field usability when relevant;
- policy/procedure document rendering, fragment references, and acknowledgment placement when relevant;
- correct behavior for the active `uiMode` and `presentationProfile`.

Fixed UI mode and presentation profile must be treated as explicit implementation inputs:

```ts
uiMode: 'admin' | 'field' | 'kiosk'
presentationProfile: 'keyboard-first' | 'touch-first' | 'narrow' | 'wide' | 'fullscreen' | 'compact' | 'roomy' | 'table-first' | 'card-first' | 'priority-feed'
```

`uiMode` is fixed by deployment target. Screen width, pointer capability,
device profile, current screen type, and density/accessibility preferences may
influence `presentationProfile`.

---

## 3. Keyboard Navigation

Check that:

- every interactive element is reachable without a mouse;
- tab order follows visual and task order;
- focus is not trapped except inside intentional modal surfaces;
- dialogs, drawers, and command palette surfaces return focus after close;
- table actions, row actions, and card actions are keyboard operable;
- shortcuts do not conflict with typing in fields;
- `/` opens the command palette only when the user is not typing in a field;
- `Esc` dismisses overlays where appropriate.

---

## 4. Focus States

Check that:

- every interactive element has a visible focus indicator;
- focus indicators work in light mode;
- focus indicators work in dark mode;
- focus is visible on buttons, links, tabs, menus, cards, rows, form controls, and icon buttons;
- focus style is not communicated by color alone;
- focus is not hidden under sticky bars or overlays.

---

## 5. Labels and Names

Check that:

- controls have visible text labels whenever practical;
- icon-only controls have accessible labels;
- labels match the purpose of the control;
- form labels are programmatically associated with fields;
- status labels use canonical system names;
- departments, teams, effective roles, events, and organization context are named clearly;
- dense mode does not remove required accessible names.

---

## 6. Color and Contrast

Check that:

- text meets approved contrast requirements;
- controls and boundaries remain readable in light and dark mode;
- state is not conveyed by color alone;
- department accent colors are not required to read text or state;
- restricted and destructive states use more than color, such as iconography, labels, borders, or wording;
- focus indicators have sufficient contrast against their immediate background.

### 6.1 Branding profiles

Color is customizable per organization and, within limits, per department. Contrast is not.

Check that:

- every submitted branding color combination is validated server-side against WCAG 2.1 AA before it is stored — 4.5:1 for normal text, 3:1 for large text, 3:1 for non-text user interface and graphical indicators (BRAND-014);
- a rejected submission names the failing color pair, the measured ratio, and the required ratio (BRAND-015);
- nothing is silently adjusted, auto-corrected, or auto-derived to make a failing combination pass — invalid combinations are refused, not repaired (BRAND-016);
- the branding administration surface shows the preview and the validation result **before** the change is saved (BRAND-018);
- canonical status, severity, priority, and restriction remain readable and remain carried by label, icon, and structure under every organization palette and under a department surface background override (BRAND-017);
- a department surface background is checked against the organization's foreground, muted foreground, border, focus, and status values, not only against the department's own accent;
- department-scoped surfaces are the only surfaces taking a department background — IMS surfaces and The Briefing are checked with a department background configured and confirmed unaffected (BRAND-012).

---

## 7. Motion and Animation

Check that:

- reduced-motion preference is respected;
- motion is not required to understand state changes;
- loading indicators have non-motion alternatives where needed;
- transitions do not delay operational work;
- critical alerts do not rely only on animation.

---

## 8. Forms and Validation

Check that:

- each form has a clear title or purpose;
- required fields are marked explicitly;
- errors appear near the affected field;
- a top-level error summary appears for blocking validation failures;
- error text explains how to fix the problem;
- submit-time validation exists;
- blur validation does not steal focus or interrupt typing;
- screen readers can identify invalid fields;
- Save/Submit and Cancel behavior is clear;
- Field Reports use Submit and Cancel, finalize on submit, and do not expose drafts or normal in-place editing after submission;
- only incident create/edit uses autosave.
- incident create/edit clearly blocks when server connection is unavailable;
- policy/procedure acknowledgments require server connection and clearly identify the document and version being acknowledged.

---

## 9. Tables and Dense Views

Check that:

- tables use clear column headers;
- row actions are reachable by keyboard;
- sorting and filtering are accessible;
- status cells expose readable text, not only pills or color;
- dense mode preserves labels and focus states;
- horizontal overflow is manageable on smaller screens;
- table-first layouts switch to touch-appropriate layouts on touch surfaces where needed;
- `presentationProfile` is used to decide table-first, card-first, fullscreen, narrow, or compact treatment rather than viewport width alone.

---

## 10. Cards and Touch Surfaces

Check that:

- touch targets are practical for field use;
- exact Alpha 1 touch target dimensions remain an open product/design decision; until then, changed touch surfaces must be manually checked for reliable tap accuracy on expected hardware;
- controls are spaced to reduce mis-taps;
- visible labels remain available;
- card status is readable without relying on color;
- primary actions are easy to find;
- corrective actions are available for routine immediate saves;
- card layouts remain readable in glare, night operations, and distracted use.

---

## 11. Command Palette

Check that:

- `Ctrl+K`, `Cmd+K`, and `/` behavior works as specified;
- results are grouped by type;
- unavailable actions are hidden;
- permissions, organization, event, department, effective role, team authority, and kiosk state are respected;
- IMS results are hidden unless the user has IC team-granted authority for the event's configured IC department;
- keyboard navigation through results is clear;
- shortcuts are visible except in dense mode;
- focus returns to the triggering context after dismissal.

---

## 12. Dialogs, Drawers, and Overlays

Check that:

- dialogs have accessible names;
- focus moves into modals when opened;
- focus stays inside modal dialogs until closed;
- drawers are keyboard operable;
- overlays can be dismissed intentionally;
- destructive confirmation dialogs include title, explanation, impact statement, specific confirming action, and cancel action;
- overlays do not hide offline, permission, or unsaved-work state.

---

## 13. Offline and Sync Accessibility

Check that:

- offline state is visible where it affects work;
- unavailable actions are disabled or hidden honestly;
- disabled actions have explanation where needed;
- queued local actions are readable when shown;
- sync failures do not interrupt unless the current action cannot continue;
- sync state is not communicated only by color or animation.
- offline-capable actions distinguish queued state from server-required blocked state.

---

## 14. Permissions and Restricted Access

Check that:

- default staff do not see confusing admin-only actions;
- elevated users receive useful permission explanations where appropriate;
- organizer role alone does not grant access to IMS incidents or restricted IMS surfaces;
- IC access depends on team-granted IC authority within the event's configured IC department;
- restricted pages have clear titles and next steps;
- hidden actions do not break keyboard flow;
- disabled controls explain why they are disabled when explanation is useful.

---

## 15. Kiosk and Shared Workstation Accessibility

Check that:

- kiosk screens work with touch;
- controls remain usable with gloves where practical;
- text is readable at expected workstation distance;
- user switching and re-authentication paths are clear;
- trusted workstation state is visually distinct from individual user authority;
- self check-in restrictions are enforced and understandable;
- admin complexity is hidden by default;
- command palette results are kiosk-appropriate.

---

## 16. IMS Accessibility

Check that:

- incident priority and status are visually and textually distinct;
- serious and restricted states are readable without color alone;
- timelines expose meaningful operational entries clearly;
- routine audit entries can be expanded without cluttering the main view;
- autosave status is perceivable without interrupting note entry;
- plain text incident notes remain readable and editable;
- Field Report original body is not editable after submission;
- Field Report corrections, where allowed, are append-only and audit-aware;
- Name References are distinguishable from tags, ordinary links, and user/profile mentions without relying only on color.

---

## 17. Policy and Procedure Accessibility

Check that:

- document type, title, scope, and version are perceivable;
- rendered Markdown has semantic headings, lists, links, and code blocks;
- fragment text rendered inline is readable as normal document text;
- editor views expose fragment references, names, versions, and broken-reference errors;
- acknowledgment controls identify the document and version being acknowledged;
- acknowledgment controls are keyboard operable and do not rely on color alone;
- PDF/Markdown export controls have accessible names and permission states.

---

## 17A. Event Map Accessibility

Check that:

- the map selector, layer toggles, scoped search/filter, and camp/location detail drawer are keyboard operable and have accessible names;
- camps and map locations are reachable without relying on pointer-only interaction, and have an accessible list/alternative where a purely visual map would exclude keyboard and screen-reader users;
- map features and layers do not rely on color alone to convey type, sensitivity, or status;
- map and feature contrast meets requirements in light and dark/night operation modes;
- touch targets on map controls meet touch sizing requirements;
- locked operations-window state and stale/offline map state are perceivable, not color-only;
- restricted/sensitive layers and camp data are not exposed to users without permission.

---

## 18. Manual QA Checklist

Before merging UI work, reviewers should manually verify:

- keyboard-only task completion;
- screen-reader labels for changed controls where practical;
- visible focus in light and dark mode;
- reduced-motion behavior;
- mobile layout;
- touch layout when relevant;
- kiosk touchscreen behavior when relevant;
- error, empty, loading, and success states;
- destructive action confirmation;
- offline state behavior when relevant.

Minimum Alpha 1 manual QA matrix for UI changes:

| Area | Required check |
|---|---|
| Keyboard | Complete the changed primary task without a mouse. |
| Focus | Focus indicator remains visible in light and dark mode and is not hidden by sticky bars. |
| Forms | Blocking validation shows field errors and a top-level summary. |
| Screen reader labels | New or changed controls expose useful accessible names. |
| UI mode / presentation | Verify affected `admin`, `field`, or `kiosk` modes plus relevant presentation profiles. |
| Permissions | Verify default staff denial and elevated-user explanation where applicable. |
| Offline/sync | Verify contextual state and queued/failed behavior where relevant. |
| Documents | Verify policy/procedure render, fragment reference, and acknowledgment accessibility where relevant. |
| Branding | With a non-default organization palette and a department background configured, verify status, severity, priority, and restriction remain readable and label/icon-carried, and that a failing color combination is refused with its measured ratio. |

Automated accessibility tooling is not yet configured in this documentation set. Until CI tooling is selected, PRs must include manual accessibility review notes.

---

## 19. Open Questions

Future versions should define:

- exact automated accessibility tooling and CI command;
- screen-reader/browser support matrix;
- minimum manual QA device set;
- exact touch target guidance for field hardware;
- accessibility acceptance criteria for each major component.
