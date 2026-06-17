# Meridian Component Library Specification

Version: Draft 2
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define the framework-neutral component system Meridian implementations should share.

---

## 1. Purpose

This specification defines the required Meridian component library concepts, component responsibilities, variants, accessibility requirements, and governance rules.

Component names are framework-neutral. They may be implemented in Blade, Livewire, Vue, React, or another approved stack, but their behavior and semantics should remain consistent.

When this document conflicts with the canonical Meridian UI Operating Guide, the parent guide governs. Deterministic Alpha 1 route, status, permission, widget, offline, kiosk, and component implementation contracts are defined in `docs/ui/meridian-ui-implementation-contract.md`.

For Alpha 1, Meridian UI spans multiple runtimes:

- Laravel/Orchid implements trusted admin and god-mode surfaces;
- Vue/Capacitor implements the offline-capable field application;
- Electron wraps the local Meridian web UI for on-site command-center use;
- required context, status, permission, and offline information must not exist only in client-side state;
- PowerSync/offline state should be passed through documented view-model inputs, not ad hoc component checks;
- framework-neutral component names remain the design contract, while each runtime should expose stable local component APIs.

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

Initial Alpha 1 token names are defined in `docs/ui/meridian-ui-implementation-contract.md`. Component CSS must use those semantic names, including:

- `--m-surface-app`, `--m-surface-base`, `--m-surface-raised`, `--m-surface-overlay`;
- `--m-text-primary`, `--m-text-secondary`, `--m-text-muted`, `--m-text-inverse`;
- `--m-border-default`, `--m-border-strong`, `--m-border-subtle`;
- `--m-action-primary-bg`, `--m-action-secondary-bg`, `--m-action-destructive-bg`;
- `--m-focus-ring`;
- `--m-status-*`, `--m-attention-*`, and `--m-department-accent`;
- `--m-space-*`, `--m-radius-*`, `--m-shadow-*`, and `--m-text-*`.

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

IMS command results must not appear unless the user has the required IC role for the event's configured IC department.

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

Use `SeverityIndicator` for dashboard attention or IMS priority only. Use `StatusPill` for workflow/status values.

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

Used only for online incident create/edit autosave.

Required behavior:

- shows saved, saving, failed, or blocked-offline state;
- does not interrupt typing;
- gives repair path when current work cannot continue.

Incident creation and editing require server connection in Alpha 1, so this component must not imply that offline incident creation has been queued.

### 7.4 `DocumentViewer`

Renders policy/procedure documents.

Required behavior:

- shows document type, title, scope, and version;
- renders sanitized Markdown;
- renders referenced fragment text inline as normal document text;
- supports offline reading from synced PowerSync data when the document is visible to the active user;
- keeps scope and version perceivable without overwhelming the document content.

### 7.5 `FragmentReference`

Represents a reusable document fragment inside authoring surfaces.

Required behavior:

- displays human-friendly fragment name and current fragment version;
- remains visible as a reference while editing;
- exposes broken-reference state in a way that blocks publishing;
- does not support nested fragments.

### 7.6 `AcknowledgmentControl`

Captures policy/procedure acknowledgment during signup or training.

Required behavior:

- requires server connection in Alpha 1;
- clearly names the document and version being acknowledged;
- records explicit user action;
- does not appear as a direct shift-signup or credential gate.

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

Components that change density or layout must accept or derive the shared surface mode contract:

```ts
surfaceMode: 'desktop' | 'touch' | 'mobile' | 'kiosk' | 'dense'
```

Equivalent PHP enum, string, or view-model naming is acceptable. Screen width, pointer capability, device configuration, trusted workstation state, current screen type, and user-selected dense mode may all influence the active surface mode.

---

## 12. Component API Quick Contracts

These compact contracts are the minimum shape future code-generation tasks should preserve. More detailed examples live in `docs/ui/meridian-ui-implementation-contract.md`.

| Component | Purpose | Inputs | Slots/content | Required behavior |
|---|---|---|---|---|
| `AppTopBar` | Global shell bar | `homeUrl`, `department`, `user`, `commandPaletteEnabled`, `surfaceMode` | optional user/session controls | Home logo, command palette trigger, department context; never org/event switcher. |
| `ContextBar` | Operating scope display | `organization`, `event`, `department`, `roleContext`, `syncState`, `kioskState`, `surfaceMode` | optional extra context | Shows scope only where it affects decisions; collapses without hiding required state. |
| `ActionBar` | Bottom current-screen actions | `surfaceMode`, `sticky`, `safeArea`, `disabledReason` | primary and secondary actions | Reachable on touch/kiosk, respects safe areas, does not cover required content. |
| `CommandPalette` | Command/navigation overlay | `results`, `roleContext`, `scope`, `kioskState`, `surfaceMode` | grouped result rows | `Ctrl+K`, `Cmd+K`, `/`; filters by permissions and hides unauthorized IMS results. |
| `DepartmentBadge` | Department identity | `department`, `showLogo`, `showAccent`, `size` | optional label override | Uses logo/icon/lettermark and small accent; accessible name includes department. |
| `StatusPill` | Canonical status | `family`, `status`, `size`, `icon` | none | Visible text matches canonical label; state is not color-only. |
| `SeverityIndicator` | Attention or IMS priority | `kind`, `value`, `label` | optional description | Keeps dashboard attention distinct from IMS priority and incident state. |
| `DataTable` | Dense record list | `columns`, `rows`, `surfaceMode`, `emptyMessage`, `permissions` | filters/actions | Headers, keyboard row actions, loading/empty/error states, paired touch fallback. |
| `TouchCard` | Touch record/task card | `record`, `status`, `actions`, `surfaceMode` | summary/details/actions | Large labeled actions, no hover-only controls, inline correction where appropriate. |
| `MetricCard` | Operational metric | `label`, `value`, `scope`, `attention`, `freshness`, `href` | optional detail | Must support decision, action, or reassurance; shows freshness when stale risk matters. |
| `PriorityFeed` | Mobile/compact dashboard feed | `items`, `roleContext`, `surfaceMode` | feed item template | Orders by attention and role relevance; preserves source context and quiet states. |
| `Field` | Form field wrapper | `name`, `label`, `required`, `error`, `hint` | form control | Programmatic label/error association and required marker. |
| `FormSummary` | Blocking validation summary | `errors`, `heading`, `focusOnMount` | optional actions | Lists errors and links/moves focus to fields where possible. |
| `AutosaveStatus` | Incident autosave state | `state`, `lastSavedAt`, `repairHref` | optional message | Allowed only for online incident create/edit; states are `saved`, `saving`, `failed`, `blocked_offline`. |
| `DocumentViewer` | Rendered policy/procedure document | `document`, `resolvedFragments`, `scope`, `version`, `surfaceMode` | document body/actions | Sanitized Markdown, fragments inline, type/scope/version visible. |
| `FragmentReference` | Authoring-time fragment token | `fragment`, `version`, `state` | optional controls | Shows reference/version; broken references block publish; no nested fragments. |
| `AcknowledgmentControl` | Signup/training acknowledgment | `document`, `version`, `scope`, `onlineState` | confirmation text/action | Online-only in Alpha 1; explicit user action; not a shift/credential gate. |
| `ConfirmationDialog` | Destructive/high-impact confirmation | `title`, `impact`, `confirmLabel`, `variant` | explanation/actions | Focus-trapped modal; confirming action uses a specific verb. |
| `HistoryDrawer` | Audit/history panel | `entries`, `defaultExpanded`, `surfaceMode` | timeline rows | Keyboard operable; hides routine field-change entries by default. |
| `OfflineBanner` | Contextual sync status | `state`, `scope`, `queuedCount`, `repairHref` | optional detail | Uses approved connectivity labels; appears only where state affects current work. |
| `Toast` | Brief routine feedback | `variant`, `message`, `timeout` | optional action | Not for destructive confirmation, blocking error, or essential disappearing info. |

---

## 13. Governance

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

## 14. Open Questions

Future versions should define:

- screenshot examples;
- Storybook or equivalent documentation expectations;
- automated accessibility test coverage;
- exact Blade component file naming conventions;
- full status and severity visual mapping beyond the Alpha 1 contract.
