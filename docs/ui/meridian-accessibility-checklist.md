# Meridian Accessibility Checklist

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Provide a practical accessibility and field-usability checklist for Meridian UI design, implementation, and review.

---

## 1. Purpose

This checklist turns Meridian's accessibility principles into reviewable criteria. It applies to all UI work, including dashboards, forms, tables, touch surfaces, kiosk mode, IMS, and admin screens.

Meridian should follow WCAG 2.2 or the project-approved successor standard as its baseline.

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
- touch and field usability when relevant.

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
- departments, roles, events, and organization context are named clearly;
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
- Save and Cancel behavior is clear;
- only incident create/edit uses autosave.

---

## 9. Tables and Dense Views

Check that:

- tables use clear column headers;
- row actions are reachable by keyboard;
- sorting and filtering are accessible;
- status cells expose readable text, not only pills or color;
- dense mode preserves labels and focus states;
- horizontal overflow is manageable on smaller screens;
- table-first layouts switch to touch-appropriate layouts on touch surfaces where needed.

---

## 10. Cards and Touch Surfaces

Check that:

- touch targets are practical for field use;
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
- permissions, organization, event, department, role, and kiosk state are respected;
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

---

## 14. Permissions and Restricted Access

Check that:

- default volunteers do not see confusing admin-only actions;
- elevated users receive useful permission explanations where appropriate;
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
- plain text incident notes remain readable and editable.

---

## 17. Manual QA Checklist

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

---

## 18. Open Questions

Future versions should define:

- project-approved WCAG conformance target;
- required automated accessibility tooling;
- screen-reader/browser support matrix;
- minimum manual QA device set;
- exact touch target guidance for field hardware;
- accessibility acceptance criteria for each major component.

