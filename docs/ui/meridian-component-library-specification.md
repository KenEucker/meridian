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
- the shared Vue client implements offline-capable product workflows;
- Capacitor and Electron package the shared Vue client for mobile and on-site command-center use;
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
- `--m-status-*`, `--m-attention-*`, `--m-department-accent`, and `--m-department-surface`;
- `--m-space-*`, `--m-radius-*`, `--m-shadow-*`, and `--m-text-*`.

Token *values* are branding surface; token *names* are not. An organization branding profile supplies ten values — the four platform colors and the six neutrals canvas, surface, foreground, muted foreground, border, and focus — and a department branding profile supplies two, `--m-department-accent` and `--m-department-surface`. Every other token derives from those and is never independently settable (BRAND-006, BRAND-007, BRAND-009, BRAND-011). The settable/derived split is defined in `docs/ui/meridian-ui-implementation-contract.md` section 10.0.

Components must therefore keep consuming semantic names and must not read branding values directly. A component that reached for an organization's stored accent instead of `--m-action-destructive-bg` would keep working under Meridian's defaults and break the first time an organization chose a different palette.

`--m-department-surface` is applied by surface scope, not by component (UI implementation contract section 10.3). Components inherit it; they do not decide whether it applies.

Branding values are validated server-side against WCAG 2.1 AA before they can be stored, and failing combinations are rejected rather than repaired (BRAND-014 through BRAND-016). Components should not add compensating contrast logic of their own.

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

Name Reference chip clicks should invoke normal permission-filtered search for the reference text without the `@` prefix. `CommandPalette` results must not expose unavailable IMS records or route to a dedicated Name Reference profile/detail page.

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

Required behavior:

- leading glyph precedence is logo, then icon, then generated lettermark from the department name (BRAND-010);
- the lettermark is decorative and never carries the accessible name;
- the accessible name includes the full department name even when the visible text is a short label;
- sizes `sm`, `md`, and `lg` are supported and change glyph and type size only, not what is shown;
- the accent renders on any surface the badge appears on, including surfaces that do not take a department background, and is omitted when the organization has department overrides switched off (BRAND-013).

Department accent must not become a full component theme. The badge never applies a department surface background — that is a surface-scope decision, not a component one.

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

Metadata chips for `#tags` and Name References are not status or severity components. Name Reference chips must be visually distinct from tags and must not imply user mentions, notifications, or volunteer profile links.

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

## 6A. Map Surface Components

Map components are composed from existing primitives and semantic tokens. They must not introduce a separate design system, must be permission-aware, and must support touch and dark/night operation.

### 6A.1 `MapSurface`

Renders a published event map (placement or topographic) with permitted camps and map locations.

Required behavior:

- shows only `published` maps to operational users; draft/archived require map edit/admin permission;
- supports point/line/polygon features and both local placement coordinates and geospatial coordinates;
- read-only on view surfaces; map editing happens only on permitted management surfaces before the operations window;
- shows empty, loading, error, and stale/offline states;
- shows locked-state messaging once the event operations window begins;
- no arbitrary dropped pins; no floating action buttons unless explicitly allowed.

### 6A.2 `MapSelector`

Lets permitted users switch between an event's maps when more than one exists. Hidden when only one map is available.

### 6A.3 `MapLayerToggles`

Toggles map layers where useful. Sensitive layers appear only for users with permission to view them.

### 6A.4 `MapSearchPanel`

Scoped search/filter for the map surface only. Must not feed the global command palette and must not surface camp names or operational locations to users without map permissions.

### 6A.5 `CampLocationDetail`

Drawer or card showing permitted details for a selected camp or map location. For MVP a camp shows only name and location; other operational details appear only where permitted.

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

Components that change density or layout must accept or derive the shared fixed
UI mode plus presentation profile contract:

```ts
uiMode: 'admin' | 'field' | 'kiosk'
presentationProfile: 'keyboard-first' | 'touch-first' | 'narrow' | 'wide' | 'fullscreen' | 'compact' | 'roomy' | 'table-first' | 'card-first' | 'priority-feed'
```

Equivalent PHP enum, string, or view-model naming is acceptable.
`uiMode` is fixed by deployment target and must not be derived from viewport,
device, user, role, permission, connectivity, or trusted-workstation state.
Screen width, pointer capability, device configuration, current screen type, and
density/accessibility preferences may influence `presentationProfile`.

---

## 12. Component API Quick Contracts

These compact contracts are the minimum shape future code-generation tasks should preserve. More detailed examples live in `docs/ui/meridian-ui-implementation-contract.md`.

| Component | Purpose | Inputs | Slots/content | Required behavior |
|---|---|---|---|---|
| `AppTopBar` | Global shell bar | `homeUrl`, `department`, `user`, `commandPaletteEnabled`, `uiMode`, `presentationProfile` | optional user/session controls | Home logo, command palette trigger, department context; never org/event switcher. |
| `ContextBar` | Operating scope display | `organization`, `event`, `department`, `roleContext`, `syncState`, `kioskState`, `uiMode`, `presentationProfile` | optional extra context | Shows scope only where it affects decisions; collapses without hiding required state. |
| `ActionBar` | Bottom current-screen actions | `uiMode`, `presentationProfile`, `sticky`, `safeArea`, `disabledReason` | primary and secondary actions | Reachable on touch/Kiosk presentation profiles, respects safe areas, does not cover required content. |
| `CommandPalette` | Command/navigation overlay | `results`, `roleContext`, `scope`, `kioskState`, `uiMode`, `presentationProfile` | grouped result rows | `Ctrl+K`, `Cmd+K`, `/`; filters by permissions and hides unauthorized IMS results. |
| `DepartmentBadge` | Department identity | `department`, `showLogo`, `showAccent`, `size` | optional label override | Glyph precedence logo → icon → lettermark; small accent only; accessible name includes the full department name; never applies a department surface background. |
| `StatusPill` | Canonical status | `family`, `status`, `size`, `icon` | none | Visible text matches canonical label; state is not color-only. |
| `SeverityIndicator` | Attention or IMS priority | `kind`, `value`, `label` | optional description | Keeps dashboard attention distinct from IMS priority and incident state. |
| `DataTable` | Dense record list | `columns`, `rows`, `presentationProfile`, `emptyMessage`, `permissions` | filters/actions | Headers, keyboard row actions, loading/empty/error states, paired touch fallback. |
| `TouchCard` | Touch record/task card | `record`, `status`, `actions`, `presentationProfile` | summary/details/actions | Large labeled actions, no hover-only controls, inline correction where appropriate. |
| `MetricCard` | Operational metric | `label`, `value`, `scope`, `attention`, `freshness`, `href` | optional detail | Must support decision, action, or reassurance; shows freshness when stale risk matters. |
| `PriorityFeed` | Mobile/compact dashboard feed | `items`, `roleContext`, `presentationProfile` | feed item template | Orders by attention and role relevance; preserves source context and quiet states. |
| `Field` | Form field wrapper | `name`, `label`, `required`, `error`, `hint` | form control | Programmatic label/error association and required marker. |
| `FormSummary` | Blocking validation summary | `errors`, `heading`, `focusOnMount` | optional actions | Lists errors and links/moves focus to fields where possible. |
| `AutosaveStatus` | Incident autosave state | `state`, `lastSavedAt`, `repairHref` | optional message | Allowed only for online incident create/edit; states are `saved`, `saving`, `failed`, `blocked_offline`. |
| `DocumentViewer` | Rendered policy/procedure document | `document`, `resolvedFragments`, `scope`, `version`, `uiMode`, `presentationProfile` | document body/actions | Sanitized Markdown, fragments inline, type/scope/version visible. |
| `FragmentReference` | Authoring-time fragment token | `fragment`, `version`, `state` | optional controls | Shows reference/version; broken references block publish; no nested fragments. |
| `AcknowledgmentControl` | Signup/training acknowledgment | `document`, `version`, `scope`, `onlineState` | confirmation text/action | Online-only in Alpha 1; explicit user action; not a shift/credential gate. |
| `ConfirmationDialog` | Destructive/high-impact confirmation | `title`, `impact`, `confirmLabel`, `variant` | explanation/actions | Focus-trapped modal; confirming action uses a specific verb. |
| `HistoryDrawer` | Audit/history panel | `entries`, `defaultExpanded`, `presentationProfile` | timeline rows | Keyboard operable; hides routine field-change entries by default. |
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
