# Meridian Component Library Specification

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define the framework-neutral component system Meridian implementations should share.

---

## 1. Purpose

This specification defines the required Meridian component library concepts, component responsibilities, variants, accessibility requirements, and governance rules.

Component names are framework-neutral. They may be implemented in Blade, Livewire, Vue, React, or another approved stack, but their behavior and semantics should remain consistent.

---

## 2. Component Principles

Meridian components should be:

- operational;
- accessible;
- responsive to touch and non-touch contexts;
- token-driven;
- role-aware where needed;
- honest about status, permission, and sync state;
- restrained in visual treatment.

Components must not hard-code raw brand colors when semantic design tokens are available.

---

## 3. Token Requirements

The component library must expose semantic tokens for:

- surfaces;
- text;
- borders;
- actions;
- status and severity;
- restricted states;
- focus indicators;
- department accents;
- shadows and elevation;
- spacing;
- radius;
- typography.

Light and dark mode must use the same semantic token names.

---

## 4. Required Foundation Components

### 4.1 `AppTopBar`

Primary app shell bar.

Required responsibilities:

- Meridian logo/Home control;
- current department context when applicable;
- command palette trigger;
- compact layout behavior;
- keyboard and screen-reader accessible controls.

The top bar must not become the organization or event switcher.

### 4.2 `ContextBar`

Contextual scope display.

May show:

- organization;
- event;
- operations window;
- department;
- active role;
- sync state;
- kiosk state.

The `ContextBar` should be concise and may collapse on small screens.

### 4.3 `ActionBar`

Bottom contextual action surface.

Required behavior:

- holds primary current-screen action;
- supports secondary actions where appropriate;
- remains reachable on touch devices;
- avoids covering required content;
- respects safe areas on mobile and kiosk devices.

### 4.4 `CommandPalette`

Keyboard-accessible command and navigation surface.

Required shortcuts:

- `Ctrl+K` on Windows and Linux;
- `Cmd+K` on macOS;
- `/` when the user is not typing in a field.

Results must respect organization, event, department, role, permissions, and kiosk state.

---

## 5. Identity and Status Components

### 5.1 `DepartmentBadge`

Displays department identity.

Supported content:

- logo;
- icon;
- short label;
- generated lettermark fallback;
- small department accent.

Department accent must not become a full component theme.

### 5.2 `StatusPill`

Displays canonical status names.

Required behavior:

- text-forward;
- restrained color;
- icon or shape support when needed;
- accessible name matching visible status;
- no replacement of canonical labels with friendlier text.

### 5.3 `SeverityIndicator`

Displays dashboard attention level or IMS priority where approved.

Severity indicators must distinguish:

- routine information;
- attention needed;
- warning;
- critical;
- restricted or security-sensitive state.

IMS priority labels must not be confused with normal statuses.

---

## 6. Data Display Components

### 6.1 `DataTable`

Dense tabular display for non-touch operational views.

Required capabilities:

- column headers;
- row labels where needed;
- sorting;
- filtering where useful;
- loading state;
- empty state;
- row-level actions;
- keyboard navigation;
- responsive fallback or paired touch layout.

### 6.2 `TouchCard`

Touch-friendly card pattern for mobile, kiosk, and touchscreen contexts.

Required behavior:

- larger controls;
- visible labels;
- clear status area;
- enough spacing to reduce mis-taps;
- support for inline corrective actions.

### 6.3 `MetricCard`

Dashboard metric display.

Required behavior:

- short label;
- value;
- status, trend, or attention state only when meaningful;
- optional action target;
- quiet-state support.

Metric cards must not be used for decorative statistics.

### 6.4 `PriorityFeed`

Mobile and compact dashboard feed.

Required behavior:

- orders items by urgency and role relevance;
- supports quiet states;
- groups items when helpful;
- preserves source context for each item.

---

## 7. Form Components

### 7.1 `Field`

Base field wrapper.

Required content:

- visible label;
- required marker when applicable;
- description or hint when useful;
- error message area;
- accessible associations.

### 7.2 `FormSummary`

Top-level validation summary.

Required behavior:

- lists blocking errors;
- links or moves focus to fields where possible;
- works with keyboard and screen readers.

### 7.3 `AutosaveStatus`

Used only for incident create/edit autosave.

Required behavior:

- shows saved, saving, failed, or offline queued state;
- does not interrupt typing;
- gives repair path when current work cannot continue.

---

## 8. Feedback and Overlay Components

### 8.1 `ConfirmationDialog`

Required for destructive actions.

Required structure:

1. clear title;
2. concise explanation;
3. impact statement;
4. specific confirming action;
5. cancel action.

The confirming button must use a specific verb.

### 8.2 `HistoryDrawer`

Panel or drawer for audit history and record timelines.

Required behavior:

- opens without replacing current task context;
- supports keyboard dismissal;
- can hide routine field-change entries by default;
- preserves meaningful operational entries.

### 8.3 `OfflineBanner`

Contextual offline and sync status banner.

Required behavior:

- appears only in affected scope;
- distinguishes relevant connectivity states;
- avoids interrupting work unless the action cannot continue.

### 8.4 `Toast`

Brief feedback for routine results.

Toasts must not be used for destructive confirmations, blocking errors, or essential information that disappears before the user can act.

---

## 9. Button and Control Components

### 9.1 Button Variants

Required hierarchy:

1. Primary;
2. Secondary;
3. Tertiary;
4. Destructive.

Primary buttons should be scarce. Destructive buttons must route through `ConfirmationDialog`.

### 9.2 Icon Buttons

Icon buttons require accessible labels.

Visible labels are preferred, except in dense mode, power-user contexts, and constrained small-screen layouts.

### 9.3 Segmented Controls and Toggles

Use segmented controls for mode choices and toggles or checkboxes for binary settings.

Controls must expose selected state through more than color.

---

## 10. Accessibility Requirements

All components must support:

- keyboard operation;
- visible focus state;
- accessible name and role;
- sufficient contrast in light and dark mode;
- reduced-motion preference;
- non-color-only state communication.

Reusable components should include accessibility tests or documented manual QA expectations.

---

## 11. Responsive and Touch Behavior

Components must account for:

- mobile viewports;
- tablet viewports;
- desktop viewports;
- touch-enabled laptops;
- kiosk workstations;
- dense mode.

Touch adaptations should be based on capability and surface context, not screen width alone.

---

## 12. Governance

New reusable components must be added to this specification or the operating guide before becoming common patterns.

New components must define:

- purpose;
- allowed variants;
- accessibility behavior;
- responsive behavior;
- state model;
- relationship to existing components.

Components that duplicate existing patterns should be rejected during review unless there is a clear product reason.

---

## 13. Open Questions

Future versions should define:

- exact token names and CSS variable contract;
- component API examples for the chosen frontend stack;
- screenshot examples;
- Storybook or equivalent documentation expectations;
- automated accessibility test coverage;
- full status and severity visual mapping.

