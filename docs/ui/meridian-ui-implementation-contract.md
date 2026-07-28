# Meridian UI Implementation Contract

Version: Draft 2
Project: Meridian Volunteer Operations Platform  
Purpose: Define deterministic Alpha 1 UI contracts for routes, screens, components, statuses, permissions, widgets, offline behavior, fixed UI modes, kiosk behavior, and code-generation tasks.

---

## 1. Purpose

This document converts the Meridian UI surface guidance into implementation-ready contracts. It is intended for humans and LLM coding agents that need to generate consistent UI code without reinterpreting the product requirements, technical specification, or UI operating guide.

This document does not replace the product requirements, technical specification, or canonical UI Surface Spec / Operating Guide. It narrows them into Alpha 1 contracts for implementation.

---

## 2. Source-of-Truth Order

When documents conflict, use this order:

1. Product requirements document;
2. Technical specification;
3. Meridian UI Surface Spec / UI Operating Guide;
4. This UI Implementation Contract;
5. Supporting UI documents:
   - Accessibility Checklist;
   - Component Library Specification;
   - Dashboard Widget Specification;
   - IMS Surface Specification;
   - Briefing Surface Specification;
   - Kiosk and Field Hardware UX Guide;
   - Screen Surface Specification.

If the canonical parent UI document has a different filename in the repository, use the repository filename consistently in cross-references.

---

## 3. Alpha 1 Technical Contract

Meridian Alpha 1 UI implementation should follow this technical contract:

- Server/admin application: Laravel, PostgreSQL, Orchid God Mode / repair tooling, and OpenAPI-described APIs.
- Shared client application: one Vue codebase for Meridian Admin, Meridian Field, and Meridian Kiosk product workflows.
- Server-hosted web target: Laravel serves the Admin build of the shared Vue client as Meridian Admin.
- Mobile packaging wrapper: Capacitor packages the Field build of the shared Vue client for iOS and Android as Meridian Field.
- Desktop on-site wrapper: Electron packages and serves the Kiosk build of the shared Vue client locally as Meridian Kiosk.
- Device sync: PowerSync-backed local state and sync-aware UI.
- Offline-first behavior: PowerSync-backed local state and sync-aware UI.
- Install targets: server-hosted web app, Electron for on-site laptop, and Capacitor mobile app.
- Styling: semantic design tokens, not hard-coded raw brand colors.
- Auth: email magic link, Google OAuth, and Discord OAuth; no internal username/password auth for Alpha 1.
- Kiosk: trusted workstation context layered on top of authenticated user authority.

Meridian Admin is the server-hosted Vue product UI. Orchid is God Mode and
repair tooling only. The shared Vue client owns Field, Kiosk, and Admin product
workflows across the fixed deployment targets. Shared component contracts in
this document define behavior and semantics for the product client;
platform-specific capabilities belong behind shell/platform adapters.

---

## 4. Global UI Rules

All authenticated Meridian product screens must follow these rules:

1. Use the Meridian app shell unless intentionally outside the main product flow.
2. Do not use a persistent left sidebar as the primary navigation model.
3. Do not use the top bar as the organization or event switcher.
4. Organization and event switching must happen on Home or a dedicated context-switching surface.
5. Show operating context when it changes what the user can see or do.
6. Use canonical status names exactly.
7. Hide unavailable actions for default staff unless the absence would be confusing.
8. Use disabled actions with explanation mainly in admin or high-context surfaces.
9. Destructive actions must use a confirmation dialog.
10. Offline and sync state must appear only where it affects the current work.
11. Routine success should use inline state or toast feedback, not blocking modals.
12. Only incident create/edit uses autosave forms.
13. Normal create/edit forms use explicit Submit or Save and Cancel.
14. Field Reports are submitted and finalized; they are not drafts.
15. Department accent colors identify departments but do not theme entire surfaces.
16. Build every screen mobile-first, then let it re-flow rather than re-size: a wider viewport must buy visible content, not bigger gaps. See sections 11.5B through 11.5E.
17. A page has one control band. Search and filters live in it, not in bands of their own.
18. Nothing spans the full width by default. Full width is a decision; declare which element grows and let the rest size to their content.
19. Take page padding and band gaps from the density tokens in 10.2 rather than fixed spacing values.
20. Before adding a layout rule for wide screens, measure the page height before and after. If it grew, the rule is wrong.
21. Administration panels that edit identity fields open read-only with an explicit Edit control. Landing mid-form is not a default; Cancel discards the draft and returns to the read-out.

---

## 5. Fixed UI Mode Contract

Meridian UI mode is fixed by deployment target:

| Deployment target | UI mode | Product name | Artifact |
|---|---|---|---|
| Server-hosted web application | `admin` | Meridian Admin | `apps/client/dist/admin` |
| Capacitor mobile application | `field` | Meridian Field | `apps/client/dist/field` |
| Electron desktop/on-site application | `kiosk` | Meridian Kiosk | `apps/client/dist/kiosk` |

UI mode controls shell, route availability posture, navigation framing, session
assumptions, and workflow presentation. It does not grant permissions.

UI mode must not be derived from:

- viewport size;
- pointer capability;
- known device profile;
- network/connectivity state;
- current screen type;
- operational window state;
- authenticated user;
- role or permission grants;
- trusted workstation state;
- user preference.

Meridian must not expose a user-facing UI mode switcher.

### 5.1 Presentation Profile Inputs

Presentation profile is the lower-level adaptation layer. It may be derived
from:

- viewport size;
- pointer capability;
- known device profile;
- fullscreen/embedded presentation;
- safe-area requirements;
- current screen type;
- density preference;
- reduced-motion and other accessibility preferences.

Allowed profile vocabulary includes `compact`, `roomy`, `touch-first`,
`keyboard-first`, `narrow`, `wide`, `fullscreen`, `table-first`,
`card-first`, and `priority-feed`.

### 5.2 Presentation Profile Rules

| Profile concern | Intended use | Layout preference | Control preference |
|---|---|---|---|
| `keyboard-first` | mouse/keyboard laptop or desktop use | table-first where useful | dense but accessible controls |
| `touch-first` | touch-enabled laptop/tablet/phone use | card-first or hybrid | larger visible controls |
| `narrow` | phone or constrained viewport | priority feed, cards, single column | thumb/touch-friendly controls |
| `fullscreen` | on-site unattended/shared display | simplified operational dashboard/cards | large labeled actions |
| `compact` | power-user density overlay | compact tables and reduced hints | labels and focus still required |

Presentation profiles must not remove required accessible names, canonical
status text, focus visibility, or critical context.

## 5A. Mode-by-Surface Capability Matrix

Classification vocabulary:

- `primary`: the mode's main expression of that surface;
- `supported`: available when authorized, with normal mode-specific shell framing;
- `adapted`: available with different layout/session treatment for the mode;
- `summarized`: reduced dashboard/status treatment rather than full workflow;
- `read-only`: visible but not writable from that mode;
- `online-required`: visible/actionable only with server connection;
- `unavailable`: not exposed in that mode.

Authorization still applies to every `primary`, `supported`, `adapted`,
`summarized`, `read-only`, and `online-required` entry.

| Surface / workflow | Field | Kiosk | Admin | Notes |
|---|---|---|---|---|
| Public event application | supported, online-required | supported, online-required | supported, online-required | May be used off-site from a phone or from the server-hosted site. |
| Authentication | supported | supported | supported | Same auth providers; Kiosk may add local re-auth for already provisioned users. |
| Home / context selection | adapted | adapted | primary | Field and Admin select user/event context; Kiosk requires pinned context setup. |
| Staff dashboard | primary | adapted | supported | Kiosk uses shared-workstation framing when pinned. |
| Staff shifts | primary | adapted | supported | Offline check-in/check-out/no-show support depends on synced local data. |
| Readiness / About | supported | supported | supported | Available in every mode. |
| Policy/procedure read | supported | supported | supported | Permission-filtered. |
| Policy/procedure write/maintain | unavailable | unavailable | primary | Admin product UI for normal authoring; God Mode only for repair where needed. |
| Department roster / teams / trainings / shifts / equipment / credits / documents | supported | adapted | primary | Available in every mode when authorized. |
| Department Overview | supported | adapted | primary | Mode-specific shell and density only. |
| Logistics Window | supported | adapted | primary | Offline attendance operations may queue locally. |
| Operations Center | supported | adapted | primary | Kiosk emphasizes current pinned context. |
| Planning Table | supported | adapted | primary | Presentation profile may choose compact/table-first or touch/card-first. |
| Field Report authoring | primary | supported | supported | Field Reports can be created offline and sync later where local store is available. |
| Field Report / IMS review | supported | adapted | primary | Permission-filtered; incident visibility remains IC-rule driven. |
| IMS incident reads | read-only when synced | read-only when synced | read-only when synced | Reads may work offline only for synced authorized data. |
| IMS incident create/mutate | online-required | online-required | online-required | Incident mutation is online-only in every mode. |
| Organizer screens | unavailable | supported | primary | Field mode does not expose organizer screens. |
| Organization/system configuration | unavailable | summarized | primary | Kiosk may show support/setup state; Admin owns normal configuration. |
| Event maps | supported | adapted | primary | Published/permitted map data can sync read-only; sensitive layers must not sync without permission. |
| Kiosk home | unavailable | primary | summarized | Admin can review/support Kiosk setup; it is not Admin's own session home. |
| Kiosk switch / re-auth / safe timeout | unavailable | primary | supported | Admin support/configuration only; no Admin quick switcher. |
| Kiosk context setup/config | unavailable | primary | supported | Organizer, lead organizer, and God Mode authority required. |
| Orchid / God Mode repair tooling | unavailable | unavailable | primary | Orchid remains Admin-only God Mode/repair tooling. |

Mode-specific route guards and navigation tests must prove that:

- Field builds do not expose organizer screens, Kiosk session controls, or
  Orchid/God Mode repair tooling;
- Kiosk builds expose Kiosk setup/support when context is missing and normal
  pinned-context surfaces after setup;
- Admin builds expose Admin product workflows and support Kiosk configuration
  without adding an Admin quick switcher;
- route availability never replaces server authorization.

---

## 6. App Shell Contract

### 6.1 AppTopBar

Purpose: Global shell bar for authenticated product surfaces.

Required content:

- Meridian Home control;
- command palette trigger;
- active department context when inside a department surface;
- current user/session affordance where appropriate.

Forbidden behavior:

- must not be the primary organization switcher;
- must not be the primary event switcher;
- must not become a full navigation sidebar replacement.

Suggested Blade API:

```blade
<x-app-top-bar
    :home-url="route('home')"
    :department="$department ?? null"
    :user="$currentUser"
    :command-palette-enabled="true"
/>
```

### 6.2 ContextBar

Purpose: Show current operating scope when it affects the user's decisions.

Supported context:

- organization;
- event;
- operations window;
- department;
- active role;
- permission level;
- kiosk/trusted workstation state;
- offline/sync state.

Suggested Blade API:

```blade
<x-context-bar
    :organization="$organization"
    :event="$event ?? null"
    :department="$department ?? null"
    :role-context="$roleContext"
    :sync-state="$syncState"
    :kiosk-state="$kioskState ?? null"
/>
```

### 6.3 ActionBar

Purpose: Bottom contextual action surface for operational and editing screens.

Rules:

- hold the primary current-screen action;
- include secondary actions only where useful;
- respect safe areas on mobile/kiosk;
- avoid covering required content;
- stay reachable on touch devices.

Suggested Blade API:

```blade
<x-action-bar>
    <x-button variant="secondary" href="{{ $cancelUrl }}">Cancel</x-button>
    <x-button variant="primary" type="submit">Submit</x-button>
</x-action-bar>
```

---

## 7. Navigation and Command Palette Contract

### 7.0 Primary Navigation Menus

The shell carries two menus.

**Staff** holds the pages that belong to the person rather than to a workflow.
Me is always first. Event Info sits immediately next to it whenever the
interface is locked to an event, so the staff-facing event answers are one tap
from the personal page. Members also get Documents, Shifts, Trainings, and My
Field Reports here, because they have no workflow to reach them from; leads stop
after Me and Event Info, since those pages are reached from inside the Admin and
Planning workflows they already work out of.

**Workflows** holds only hubs someone works out of for a stretch of the event.
Me is not a workflow and must not appear here.

When the two menus together hold fewer than ten items, the shell renders them as
one menu labeled **Menu**, ordered staff pages first and then workflows. A short
list split across two dropdowns makes the reader guess which one holds the page;
the split only earns its keep once the combined list is long enough to scan
poorly. No Alpha 1 role reaches ten items yet — the fullest, a department lead
with every department capability, reaches nine — so the split is currently a
rule waiting on a role that needs it rather than behavior anyone sees.

### 7.1 Command Palette Shortcuts

Required shortcuts:

- `Ctrl+K` on Windows/Linux;
- `Cmd+K` on macOS;
- `/` only when the user is not typing in a field.

### 7.2 Command Palette Filtering

Results must be filtered by:

- authenticated user;
- organization;
- event;
- department;
- role;
- permission;
- kiosk/trusted workstation state;
- operations window where applicable.

IMS results must not appear unless the user has the required IC role for the event's configured IC department.

Name Reference searches and clicked Name Reference chips use normal permission-filtered search. They must search for the reference text without the `@` prefix and must not open a dedicated Name Reference profile/detail route.

Suggested result model:

```php
[
    'id' => 'dept.rangers.shift-board',
    'type' => 'navigation',
    'label' => 'Rangers Shift Board',
    'description' => 'Open the current Ranger shift board',
    'url' => route('events.departments.shift-board', [$event, $department]),
    'shortcut' => null,
    'permission' => 'shift_board.view',
]
```

---

## 8. Canonical Role Contract

### 8.1 Alpha 1 Effective Permission Levels

| Effective level | Scope | Summary |
|---|---|---|
| Staff | organization/event/department | Default user who may apply, view assigned work, sign up where eligible, submit Field Reports where permitted |
| Shift Lead | department/event/shift | Runs shift board and operational shift workflows |
| Department Lead | department/event | Manages department staff, teams, trainings, shifts, exports, deployments, and equipment workflows |
| Organizer | organization/event | Manages organization/event setup, applications, departments, credentials, policies, and broad readiness |
| IC Viewer | event IC department/team | Read-only IMS access |
| IC Operator | event IC department/team | Create/edit/close IMS incidents and attach Field Reports where permitted |
| IC Lead | event IC department/team | IC operator capabilities plus elevated IC management capabilities |
| God Mode | node-global | Trusted repair/configuration authority for Orchid, sync conflicts, node config, and dangerous admin actions |

### 8.2 Important Permission Rules

- Organizer role alone does not grant IMS incident access.
- Department Lead role alone does not grant IMS incident access.
- IC access is granted through team membership inside the event's configured IC department.
- Default staff should not see admin-only actions.
- DNS status supersedes all other assignment and approval workflows.
- Organization-level Do Not Staff supersedes all department status.
- System authority should come through organization, department, or team membership; direct user roles are reserved for god-mode/admin repair needs.

---

## 9. Canonical Status Contract

Use these labels exactly in UI unless the requirements document later changes them.

### 9.1 Staff Organization Status

| Canonical label | Notes |
|---|---|
| Prospective | Pre-activation state |
| Active | Active staff in the organization, subject to department/team/training/waiver rules |
| Inactive | Not currently active but not blocked from future participation |
| Emeritus | No regular duty expectation but may advise or contribute by request |
| Retired | No longer working |
| Do Not Staff | Organization-wide blocking status; permanent unless changed by organizers |

### 9.2 Department Staff Status

| Canonical label |
|---|
| Prospective |
| Active |
| Inactive |
| Ineligible |
| Emeritus |
| Retired |

### 9.3 Event Application Status

| Canonical label |
|---|
| Submitted |
| Approved |
| Rejected |
| Deferred |
| Withdrawn |
| Auto-rejected due to DNS |

### 9.4 Credential Status

| Canonical label |
|---|
| Eligible |
| Blocked |
| Revoked |

### 9.5 Shift Attendance Flags

| Canonical label | Notes |
|---|---|
| Late | Staff was late |
| Checked In | Staff has started actual shift participation |
| Checked Out | Staff has ended actual shift participation |
| No Show | Staff did not attend expected shift |

### 9.6 Equipment State

| Canonical label |
|---|
| Available |
| Checked out |
| Returned |
| Missing |
| Damaged |

### 9.7 Policy/Procedure Document State

| Canonical label |
|---|
| Draft |
| Published |
| Archived |

Fragments do not have Draft, Published, or Archived states in Alpha 1.

### 9.8 Incident State

| Canonical label |
|---|
| Open |
| On Scene |
| Monitoring |
| On Hold |
| Closed |

### 9.9 Dashboard Attention Scale

Dashboard attention describes UI urgency, not incident seriousness.

| Label | Meaning |
|---|---|
| Routine | Useful information, no action required |
| Attention | User should review soon |
| Warning | Operational issue needs action |
| Critical | Urgent operational issue |
| Restricted | Security-sensitive or high-impact state |

### 9.10 IMS Priority Labels

IMS priority describes incident seriousness and must not be confused with incident state or dashboard attention.

Provisional Alpha 1 labels:

| Label | Meaning |
|---|---|
| Routine | Low operational seriousness |
| Important | Requires meaningful IC awareness or follow-up |
| Serious | Significant operational concern |
| Critical | Urgent or high-impact incident |

These labels are product-reviewable. Until changed, use them consistently.

---

## 10. Design Token Contract

Use semantic tokens. Do not hard-code raw brand colors in components when semantic tokens exist.

### 10.0 Settable versus derived tokens

The token set splits three ways, and the split is the contract.

**Organization-settable (10 values).** An organization branding profile supplies the four platform colors and the six neutrals (BRAND-006). These are the only colors an organization chooses:

| Token | Branding field |
| --- | --- |
| `--m-platform-primary` | primary |
| `--m-platform-secondary` | secondary |
| `--m-platform-tertiary` | tertiary |
| `--m-platform-accent` | accent |
| `--m-surface-app` | canvas |
| `--m-surface-base` | surface |
| `--m-text-primary` | foreground |
| `--m-text-muted` | muted foreground |
| `--m-border-default` | border |
| `--m-focus-ring` | focus |

**Department-settable (2 values).** A department branding profile supplies `--m-department-accent` and `--m-department-surface`, and nothing else (BRAND-009, BRAND-011). `--m-department-surface` is applied only on department-scoped surfaces (section 10.3) and is not emitted at all while the organization has department overrides switched off (BRAND-013).

**Derived.** Everything else — `--m-surface-raised`, `--m-surface-overlay`, `--m-text-secondary`, `--m-text-inverse`, `--m-border-strong`, `--m-border-subtle`, every `--m-action-*`, every `--m-status-*`, every `--m-attention-*`, and chart series tokens — resolves from the settable values. Derived tokens are never independently settable (BRAND-007). A branding profile that could set `--m-status-danger` directly would be able to make danger look like success, which is exactly what section 9 exists to prevent.

Meridian's own default values for the settable tokens are in `packages/ui-tokens/tokens.css` and in style guide sections 4.1 and 4.2. They render when no organization branding profile exists, and they render permanently on pre-authentication surfaces, Orchid, and desktop chrome (BRAND-003).

Every submitted branding value is validated server-side against WCAG 2.1 AA before it is stored — 4.5:1 normal text, 3:1 large text, 3:1 non-text indicators — and a failing submission is rejected naming the failing pair, the measured ratio, and the required ratio (BRAND-014, BRAND-015). Nothing is auto-corrected or auto-derived to make a failing value pass (BRAND-016). Typography is not settable (BRAND-024).

### 10.1 CSS Custom Property Naming

Initial Alpha 1 token names:

```css
:root {
  --m-surface-app: ;
  --m-surface-base: ;
  --m-surface-raised: ;
  --m-surface-overlay: ;

  --m-platform-primary: ;
  --m-platform-secondary: ;
  --m-platform-tertiary: ;
  --m-platform-accent: ;

  --m-text-primary: ;
  --m-text-secondary: ;
  --m-text-muted: ;
  --m-text-inverse: ;

  --m-border-default: ;
  --m-border-strong: ;
  --m-border-subtle: ;

  --m-action-primary-bg: ;
  --m-action-primary-text: ;
  --m-action-secondary-bg: ;
  --m-action-secondary-text: ;
  --m-action-destructive-bg: ;
  --m-action-destructive-text: ;

  --m-focus-ring: ;

  --m-status-neutral: ;
  --m-status-success: ;
  --m-status-warning: ;
  --m-status-danger: ;
  --m-status-restricted: ;

  --m-attention-routine: ;
  --m-attention-attention: ;
  --m-attention-warning: ;
  --m-attention-critical: ;
  --m-attention-restricted: ;

  --m-department-accent: ;
  --m-department-surface: ;

  --m-space-1: ;
  --m-space-2: ;
  --m-space-3: ;
  --m-space-4: ;
  --m-space-6: ;
  --m-space-8: ;

  --m-radius-sm: ;
  --m-radius-md: ;
  --m-radius-lg: ;
  --m-radius-pill: ;

  --m-shadow-sm: ;
  --m-shadow-md: ;
  --m-shadow-overlay: ;

  --m-font-body: ;
  --m-font-heading: ;
  --m-text-xs: ;
  --m-text-sm: ;
  --m-text-md: ;
  --m-text-lg: ;
  --m-text-xl: ;
}
```

Creation actions use `--m-action-primary-*`. Search/filter actions and page navigation buttons below headings use `--m-action-secondary-*`, matching highlighted navigation. Delete, archive, remove, strike, and other destructive actions use `--m-action-destructive-*` consistently.

Light and dark mode must use the same semantic token names with different values.

### 10.2 Layout Tokens

```css
:root {
  --m-content-staff: ;
  --m-content-workflow: ;
  --m-measure: ;
  --m-tile-min: ;
  --m-tile-min-wide: ;
  --m-region-min: ;

  --m-pad-block: ;
  --m-pad-inline: ;
  --m-stack-gap: ;
}
```

`--m-content-staff` and `--m-content-workflow` are page container widths. Both
fill the phone inside the shell's padding and then keep growing with the
viewport rather than stopping at a fixed measure. Pages must use these rather
than hard-coding a `rem` cap: a capped container leaves a large display showing
a narrow strip of content with several screens of scrolling underneath it.

`--m-measure` is the longest line of prose we render. Layout width and reading
width are different limits: a paragraph, document body, or lede is capped at the
measure even when its container is a wall panel wide.

`--m-tile-min`, `--m-tile-min-wide`, and `--m-region-min` are the narrowest a
tile or region may get before a grid drops a column. Use the wide value where
tiles carry prose or four or more labelled fields.

`--m-pad-block`, `--m-pad-inline`, and `--m-stack-gap` are the density axis
described in section 11.5C. Components use these for their own padding and for
the gaps between page bands instead of fixed `--m-space-*` values, so density is
a property of the viewport rather than something each screen re-decides.

### 10.3 Branding Scope Attributes

Branding reaches the DOM through two attributes rather than through per-component props, so a surface declares what it is and the token layer decides what that means.

```html
<html data-organization-branding="applied|default">
  <main data-department-surface="dept-uuid">
```

- `data-organization-branding` is set on the document root. `applied` means an organization palette replaced the defaults; `default` means Meridian's own values are rendering. Pre-authentication surfaces, Orchid, and desktop chrome are always `default` (BRAND-003).
- `data-department-surface` is set on a **department-scoped surface only** and carries the department id whose background applies. It must not be set on incident/IMS surfaces, The Briefing, or organization-level and cross-department surfaces (BRAND-012), and it must not be set at all while the organization has department overrides switched off (BRAND-013). Absence of the attribute is how a surface says "organization surface color", which is the default a new screen inherits without doing anything.

`--m-department-accent` is not scoped this way. An accent is a small identifier that appears wherever the department appears, including on surfaces that must not take the background — a `DepartmentBadge` inside The Briefing still shows the department's accent.

---

## 11. Component Contract

### 11.1 StatusPill

Purpose: Render canonical statuses.

Inputs:

- `status`: canonical label or enum value;
- `family`: `organization-staff`, `department-staff`, `application`, `credential`, `shift-attendance`, `incident-state`;
- `size`: `sm` or `md`;
- `icon`: `auto`, `none`, or explicit icon key.

Rules:

- visible text must match canonical status label;
- do not replace canonical labels with friendlier text;
- accessible name must match visible status;
- state cannot be communicated by color alone.

Suggested Blade API:

```blade
<x-status-pill family="incident-state" status="Open" size="sm" />
```

### 11.2 SeverityIndicator

Purpose: Render dashboard attention or IMS priority.

Inputs:

- `kind`: `attention` or `ims-priority`;
- `value`: canonical attention or priority label;
- `label`: optional visible label override only when it preserves canonical meaning.

Rules:

- IMS priority must be visually and textually distinct from normal status;
- use labels, iconography, and structure, not color alone.

### 11.3 DepartmentBadge

Purpose: Identify department without theming the surface.

Inputs:

- `department`: at minimum `id`, `name`, and optionally `shortLabel`, `icon`, `logoUrl`, `accentColor`;
- `showLogo`: boolean, default `true`;
- `showAccent`: boolean, default `true`;
- `size`: `sm`, `md`, `lg`.

Rules:

- department accent must remain a small identifier and must not become a full component theme;
- content precedence for the leading glyph is logo, then icon, then generated lettermark;
- the lettermark is generated from initials or letters from separate words in the department name (BRAND-010) and is decorative — it is never the accessible name;
- visible text may be the short label, but the accessible name must include the full department name;
- the badge renders the accent even on surfaces that do not take a department background (section 10.3), and renders without an accent when the organization has department overrides switched off (BRAND-013);
- the badge does not set `data-department-surface`.

### 11.4 DataTable

Purpose: Table-first operational display for non-touch surfaces.

Required features:

- column headers;
- loading, empty, error states;
- keyboard-reachable row actions;
- filters/sorting where useful;
- responsive fallback or paired card layout.

Filters and search belong in the page's `ControlBar` (11.5D), not in a band of
their own above the table. A table is primary content: it keeps full width and
is not paired into a region (11.5E) unless the block beside it is a peer of
similar weight.

Suggested Blade API:

```blade
<x-data-table
    :columns="$columns"
    :rows="$rows"
    :presentation-profile="$presentationProfile"
    empty-message="No staff match these filters."
/>
```

### 11.5 TouchCard

Purpose: Touch-first representation of a record or task.

Required features:

- visible labels;
- clear status area;
- large actions;
- inline corrective actions where appropriate;
- no hover-only controls.

### 11.5A StaffPageShell and staff card lists

Purpose: Page template for staff-facing informational surfaces — the pages most
people read on a phone between shifts.

Applies to `staff.field-reports`, `event.info`, and the reader view of
`department.documents`, `department.shifts`, and `department.trainings`. A page
that has both a lead view and a reader view chooses per viewer: lead authority
keeps the wide workflow shell, and the same page without that authority uses
this one.

Required behavior:

- container width from `--m-content-staff`, which fills the phone inside the
  shell's own padding and keeps growing with the viewport;
- no horizontal scrolling at any width;
- records rendered as cards with every field labelled, not as a table that
  scrolls its own headers off screen;
- cards laid out with `ContentGrid`: one column on a phone, a column per tile
  width beyond that (see 11.5B);
- prose bounded by `--m-measure` rather than by the container;
- the header stacking on a phone and putting actions beside the title from
  64rem, which is a row of vertical space back on every staff page;
- tap targets of at least 44px, including disclosure controls and the whole card
  title block when a card links out;
- primary actions full-width on a phone, inline once there is room;
- one shared empty state per list, stating what is absent.

The lead view keeps `DataTable`: a lead comparing coverage across teams needs
the width, and a table is the right shape for that comparison. The reader view
must not be the lead table with columns hidden.

### 11.5B ContentGrid and large-display scaling

Purpose: turn extra width into more visible content instead of more empty space,
across both page templates.

`ContentGrid` is the tiling primitive. Peer blocks — record cards, page
sections, summaries — are one column on a phone and gain a column per
`--m-tile-min` (or `--m-tile-min-wide`) of available width. It uses `auto-fill`,
not `auto-fit`: a two-record list must render two normal tiles with space beside
them, not two tiles stretched across a television.

Rules:

- a stack of peer cards or sections tiles; a single stream of prose does not;
- full-width blocks — headings, toolbars, filters, tables — stay full width and
  sit between tiled groups rather than inside them;
- rows stretch to equal height by default; blocks whose natural height varies a
  lot, such as sections that may be empty, opt out;
- containers grow with the viewport, prose does not.

Beyond 2560px the root font size steps up (18px, then 20px at 3200px, then 23px
at 3840px), which scales every rem-based token — type, spacing, controls, and
tile widths — together. Scaling starts at 2560px rather than 1920px because what
drives legible type is viewing distance, which CSS cannot measure; a 1920px
panel is far more often a desk monitor than a wall, and inflating those would
cost density users already have.

Bigger type costs vertical space, so it is paired with grids that add columns at
the same widths: height falls by the column count faster than the type grows.
The target is that a page's normal content fits one screen on a large display.
That is a design goal, not a guarantee — a list of two hundred records will
still scroll, and no layout rule can prevent that.

### 11.5C The Density Axis

The two axes are not equally scarce. On a wide display horizontal space is
abundant and vertical space is the entire problem: everything a page pushes down
is something the reader has to scroll to. Every rule in 11.5C through 11.5E
follows from that.

As width grows, block padding and stack gaps tighten while inline padding grows,
through `--m-pad-block`, `--m-pad-inline`, and `--m-stack-gap`. A band gets
shorter and roomier at the same time. Components take their own padding and the
gaps between page bands from these tokens rather than fixed spacing values.

Three corollaries, which apply to any new surface:

1. **Re-flow rather than re-size.** A wide screen should not show the same
   layout with bigger gaps. Stacked one-line strings share a baseline row;
   blocks that sat above each other sit beside each other. Nothing is hidden at
   any width — the same content is rearranged.
2. **Nothing spans the full width by default.** Full width is a decision, not a
   fallback. A search box, a filter, a heading string, and a summary line each
   claim the width they need; leftover width goes to whichever element was
   declared as the one that grows.
3. **Page grids use `align-content: start`.** Leftover height collects at the
   end of the page rather than being shared out as gaps between unrelated bands.

Page headings follow this directly. `WorkflowPageHeading` stacks on a phone,
puts actions beside the title from 48rem, shares one baseline row between
department, title, and description from 90rem, and moves summary cards up beside
the title from 120rem. When cards move into a side column they are forced into a
single row of content-sized columns: a card grid that wraps inside a side column
is taller than the full-width row it replaced, which would make the rule cost
height rather than save it.

### 11.5D ControlBar and ControlField

Purpose: one band for a page's search, filters, and list-level actions.

The pattern this replaces is a page stacking several sibling forms, each with
its own border, padding, and background. Three of those is three bands of chrome
above the data and, on a wide display, three near-empty rows.

Rules:

- a page has **one** control band; search, filters, presets, and list actions go
  in it;
- children stay separate `form` elements where they submit separately — the bar
  is a layout container, not a merge of unrelated forms;
- a group of related controls carries `data-control-group` so it wraps as one
  unit rather than splitting across rows;
- exactly one group may carry `data-control-group="grow"`, and it absorbs
  leftover width. A growing group must not wrap internally: it gives width back
  by compressing its field, because wrapping would make the whole bar row as
  tall as that group;
- end-aligned actions go in the `end` slot;
- `variant="bare"` where the bar already sits inside a card or section.

`ControlField` is one labelled control. Past a phone the label sits beside its
control rather than above it — a row of height back per control — and the field
is sized by `width`, a content class (`sm`, `md`, `lg`, `grow`) rather than a
pixel value. A State select does not need the same width as a search box, and
neither needs a seventh of a 1900px screen.

Toolbars that already use a `label` wrapping its own text and control adopt the
bar by changing their wrapper element; `ControlBar` styles that idiom too. New
work should prefer `ControlField`.

### 11.5E Page Regions

Purpose: let a page declare which blocks are peers, so they sit side by side
when there is room.

Wrap peer blocks in `ContentGrid` with `min="region"`. Regions use `auto-fit`
where record tiles use `auto-fill`, and the difference is deliberate: a record
list is unbounded data, so tiles keep a predictable size rather than stretching
to fill whatever came back, while a page's regions are a small fixed set the
author chose and should share the width they are given.

Reading order is preserved — a region grid reads left to right, top to bottom,
so a documented content order (Department Overview's exceptions, working staff,
assignments, summaries) still holds.

Pair blocks only when they are peers of similar weight:

- **do** pair setup panels, a chart with the detail it drives, and two embedded
  featuresets;
- **do not** pair a wide table with a narrow panel. Halving a table's width
  makes it taller, and the pairing can cost more height than it saves. Measure
  before and after; if the page got taller, the blocks were not peers.

Primary content keeps the width it needs. The document library's policy table
stays full width beside nothing, because it is the point of that section.

Two applications worth copying:

- **Editing beside its record.** The incident edit form becomes two panel
  columns past ~1500px, and past 120rem the timeline moves alongside it, so
  changing a field and reading what it recorded stay on one screen. A wrapper
  that only groups panels for narrow layouts uses `display: contents` at the
  wide breakpoint, so its children join the outer grid instead of forming a
  nested block on a different rhythm.
- **A chart beside the detail it drives.** Planning pairs the shift chart with
  the shift detail, because picking a bar to read its staffing should not push
  the answer below the fold.

### 11.5F HeroCenterLayout

Purpose: hold a summary block in the middle of the page with its peer cards
around it.

Use it where every card answers the same question the summary frames — Event
Info's six sections all answer "what do I need to know before I arrive". A plain
grid puts the summary on top and pushes the reader down through the answers;
centring it makes the relationship visible and keeps the summary on screen while
the cards are read.

Rules:

- one column on a phone with the hero first, because a centre cell means nothing
  in a single column;
- a normal tile grid in between, hero as the first tile;
- three columns past ~1500px, hero in the middle spanning rows, cards flowing
  around it with `grid-auto-flow: row dense` so the layout does not depend on
  the card count;
- the hero is a peer of the cards, not the page header. Pages using it keep
  their `h1` in the page shell and put the summary content in the hero, so
  nothing is duplicated between them.

### 11.6 MetricCard

Purpose: Show a meaningful operational metric.

Inputs:

- `label`;
- `value`;
- `scope`;
- `attention`;
- `freshness`;
- `href` or action.

Rules:

- do not use decorative metrics;
- the metric must help a user decide, act, or feel reassured.

### 11.7 PriorityFeed

Purpose: Mobile/compact ordered feed of role-relevant items.

Rules:

- order by attention and role relevance;
- preserve source context;
- group low-priority items where useful;
- include quiet states when reassuring.

### 11.8 Field

Purpose: Standard form field wrapper.

Required content:

- visible label;
- required marker when applicable;
- hint/description when useful;
- error region;
- accessible association.

Suggested Blade API:

```blade
<x-field name="legal_name" label="Legal name" required :error="$errors->first('legal_name')">
    <input id="legal_name" name="legal_name" required>
</x-field>
```

### 11.9 FormSummary

Purpose: Top-level blocking validation summary.

Rules:

- appears for blocking validation failures;
- lists errors;
- links or moves focus to fields when possible;
- is keyboard and screen-reader accessible.

### 11.10 AutosaveStatus

Purpose: Autosave indicator for online incident create/edit only.

Allowed states:

- `saved`;
- `saving`;
- `failed`;
- `blocked_offline`.

Rules:

- must not interrupt typing;
- failed state must provide a repair path;
- do not use on normal forms or Field Reports;
- incident creation and edit require server connection in Alpha 1, so offline state blocks editing rather than queuing incident creation.

### 11.11 ConfirmationDialog

Purpose: Confirmation for destructive or high-impact actions.

Required structure:

1. title;
2. explanation;
3. impact statement;
4. specific confirming action;
5. cancel action.

The confirming button must use a specific verb, such as `Delete incident`, `Remove staff`, or `Block credential`.

### 11.12 HistoryDrawer

Purpose: Show audit history without replacing the current task.

Rules:

- routine field-change entries may be hidden by default;
- operationally meaningful entries should remain visible;
- drawer must be keyboard operable and dismissible;
- opening the drawer must not discard unsaved work.

### 11.13 OfflineBanner

Purpose: Contextual offline/sync state.

Allowed states:

- `online`;
- `offline_usable`;
- `local_node_reachable`;
- `central_unreachable`;
- `sync_queued`;
- `sync_conflict`;
- `sync_failed`.

Rules:

- show only where the state affects the current work;
- distinguish local node from central connectivity;
- avoid interruptive dialogs unless the current action cannot continue.

### 11.14 Toast

Purpose: Brief feedback for routine results.

Rules:

- do not use for destructive confirmations;
- do not use for blocking errors;
- do not use for essential information that disappears before action.

### 11.15 DocumentViewer

Purpose: Render policy/procedure documents with referenced fragments inline.

Rules:

- show document type, title, scope, and version;
- render sanitized Markdown only;
- render referenced fragment text inline as document text;
- show document scope and version subtly, such as near the bottom of the document;
- do not show a special user-facing warning merely because fragments update automatically.

### 11.16 FragmentReference

Purpose: Author-facing representation of a reusable document fragment reference.

Rules:

- visible in policy/procedure editors as a reference, not expanded prose;
- shows human-friendly fragment name and current fragment version;
- broken references block publishing;
- nested fragment references are not supported.

### 11.17 AcknowledgmentControl

Purpose: Capture policy/procedure acknowledgment during signup or training.

Rules:

- requires server connection in Alpha 1;
- records document type, document ID, and document version;
- must not present acknowledgments as direct shift-signup or credential gates;
- must preserve explicit user action and timestamp.

---

## 12. Alpha 1 Route and Screen Inventory

Route names are implementation targets and may be adapted to Laravel conventions, but screen IDs should remain stable.

### 12.1 Public, Auth, and Signup Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `public.apply` | `public.events.apply` | Staff event application | Public or authenticated applicant |
| `auth.login` | `login` | Provider/magic-link login entry | Public |
| `auth.magic-link-sent` | `auth.magic-link.sent` | Login code sent confirmation | Public |
| `auth.provider-callback` | framework route | External provider callback | Public/system |
| `signup.policy-acknowledgment` | `signup.documents.acknowledge` | Required policy/procedure acknowledgment during signup | Applicant/authenticated user with server connection |

### 12.2 Home and Context Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `home` | `home` | Task-based home and context entry | Authenticated |
| `context.organizations` | `organizations.index` | Select organization | Authenticated with memberships |
| `context.events` | `organizations.events.index` | Select event within organization | Authenticated with org access |
| `context.departments` | `events.departments.index` | Enter available department spaces | Authenticated with event/dept access |

### 12.3 Staff Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `staff.dashboard` | `staff.dashboard` | Staff task dashboard | Authenticated staff |
| `staff.me` | `staff.me` | Staff profile, personal links, and current event/schedule entry points | Authenticated staff |
| `staff.shifts` | `staff.shifts.index` | My shifts | Staff with event access |
| `staff.shift-detail` | `staff.shifts.show` | Shift details | Assigned/eligible staff |
| `event.info` | `events.info` | Staff-safe event information assembled from visible published documents for directions, arrival requirements, packing, food, housing, and event requirements | Staff with event access |
| `staff.field-reports` | `staff.field-reports.index` | My Field Reports | Authenticated author |
| `staff.field-report-create` | `staff.field-reports.create` | Submit Field Report | Staff with FR permission |
| `staff.field-report-detail` | `staff.field-reports.show` | View submitted Field Report | Author or permitted reviewer |
| `staff.documents` | `staff.documents.index` | Policies & Procedures library | Authenticated staff with visible documents |
| `staff.document-detail` | `staff.documents.show` | Rendered policy/procedure document | Authenticated staff with document visibility |
| `staff.document-acknowledgments` | `staff.documents.acknowledgments` | My required document acknowledgments | Authenticated staff |
| `briefing.hub` | `events.briefing` | The Briefing hub (Command-added Notes + shells) | Approved event staff |

### 12.4 Department Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `department.dashboard` | `events.departments.show` | Department operational home | Department member/lead as permitted |
| `department.overview` | `events.departments.overview` | Lead situational awareness for a selected shift | Department lead |
| `department.roster` | `events.departments.roster` | Department staff list | Department administration/planning or permitted lead |
| `department.teams` | `events.departments.teams.index` | Dynamic Admin page: department details and team management for department leads; scoped team details and staff lists for team leads | Department lead or team lead; hidden/fails closed for staff-only members |
| `team.overview` | `events.departments.teams.show` | Team situational awareness: team shifts, roster, current staffing, and drill-through to the owning workflows | Department lead for any department team; team lead for teams they lead; fails closed otherwise |
| `department.trainings` | `events.departments.trainings.index` | Manage trainings | Department lead |
| `department.training-detail` | `events.departments.trainings.show` | Staff-facing training page: delivery (in-person/online), schedule or training URL, time commitment, prerequisites, signup state, and after-training information | Department member; managers additionally reach create/edit |
| `department.shifts` | `events.departments.shifts.index` | Manage/view shifts | Department lead or permitted role |
| `department.shift-create` | `events.departments.shifts.create` | Create shift | Department lead |
| `department.shift-edit` | `events.departments.shifts.edit` | Edit shift | Department lead with time restrictions |
| `department.deployments` | `events.departments.deployments.index` | Manage deployment options | Department operations/administration as permitted |
| `department.equipment` | `events.departments.equipment.index` | View equipment settings/inventory | Department logistics/administration as permitted; God Mode repair tooling is read-only unless an inventory task grants edit |
| `department.credits` | `events.departments.credits.index` | Credit review/export | Department lead / organizer as permitted |
| `department.documents` | `events.departments.documents.index` | Department policy/procedure library and maintainer entry | Department member/lead as permitted |

### 12.5 Department Operations Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `department.overview` | `events.departments.overview` | Switchable-shift situational awareness: exceptions, checked-in staff, assignments, compact equipment/deployment summaries, and drill-throughs | Department lead |
| `department.logistics` | `events.departments.logistics` | Staff-first Logistics Window with offline department-scoped search and staff operational workspace | `department_logistics` |
| `department.operations` | `events.departments.operations` | Operations Center composed from the actor's existing capabilities | `department_operations` for the shell and deployments module |
| `department.planning` | `events.departments.planning` | Identity-free Planning Table comparing plan versus actual by shift/team window | `department_planning` |

Legacy route names `events.departments.shift-board.*` redirect to the corresponding
`events.departments.*` destinations during the redesign transition.

Shifts have exactly one team. Department Overview and Planning Table are
department-scoped by default; optional team or date filters may narrow the view
without changing authorization.

The Admin page is permission-shaped. Department leads see department details and
team create/manage actions. Department details open read-only behind an Edit
control, matching the team details panel beside them: these are
organization-visible identity fields, so editing them is a deliberate act rather
than the state the page opens in. Team leads see only the teams they lead
and the staff assigned to those teams. Staff with both department-lead and
team-lead authority see both sections. Staff without either authority do not see
Admin in the workflow menu and direct access fails closed.

The Department Overview is a lead situational-awareness surface. Event and
department identity appear as compact page context. The selected shift is
prominent and switchable. Content order is: exceptions requiring attention;
summary counts; checked-in staff currently working; full shift assignments;
compact equipment/deployment/readiness summaries; then drill-through links to
owning workflows. Overview actions do not replace Logistics or Operations.

The Staff Me page is the staff-facing profile and personal work hub. Ongoing
event clicks route by role: department leads go to Department Overview, team
leads go to Team Overview for a team they lead, and other staff go to Event Info.
The card states which surface it opens, so the destination is not a surprise.

Team Overview is the team-scoped counterpart to Department Overview, not a
narrowed copy of it. It shows the team's shifts with staffing counts, the team
roster with current attendance, and drill-through links to Admin, Shifts,
Documents, and Event Info. Authorization matches the Admin page: department
administer authority reaches every team in the department, team leads reach only
teams they lead, and everyone else fails closed. A named team the viewer may not
open fails closed rather than redirecting to a team they may, so one team's
roster never renders under another team's URL.

Event Info is assembled from published policy and procedure documents. A
maintainer assigns a document to exactly one Event Info section while authoring
it; nothing is inferred from titles or slugs. The sections and their order are
`directions`, `arrival`, `packing`, `food`, `housing`, and `requirements`. Only
published documents appear, including for the maintainer who wrote them, and
visibility is exactly the existing published-document rule, so Event Info grants
no access of its own and the same section may legitimately differ between two
staff members. Within a section, documents are ordered organization scope first,
then department, then team, then by title. A section with no visible published
document states that plainly and never falls back to placeholder prose, because
staff cannot tell placeholder guidance from published guidance.

The Logistics Window is a staff-first service station. Search for staff, equipment,
and shifts is front and center and works from department-scoped offline cache for
the current event/department. Selecting a staff member opens one continuous
workspace: identity/context; on-site/off-site; active/upcoming/outgoing shift;
check-in/check-out dialog with editable default-now timestamp and equipment
handoff; open equipment with Returned/Missing/Damaged actions; a provisions
extension slot when that domain exists; and future shift signups or eligible
shift-add actions. Marking a staff member on-site makes that person eligible to
be added to a shift; Logistics still performs the actual shift add/check-in.
Going off-site is blocked while the staff member is checked into a shift or has
equipment checked out unless that equipment is returned or marked missing/damaged.

The Operations Center is a high-level operational picture. Users with
`department_operations` always see the deployment/location module. Incident
overview appears only with event-scoped IC capability. Equipment overview appears
only with the relevant equipment visibility capability. Future maintenance/ticket
modules appear only after those domains are specified. The shell never grants
module access by itself and must not reproduce the Logistics staff service
workflow.

The Planning Table compares plan versus actual without individual identities.
Rows are shift/team windows. Columns include capacity target or “No target”,
signed-up/assigned count, checked-in count, no-show count, unscheduled additions,
planned hours, actual hours, and variance/status. Upcoming, active, and completed
shifts are distinguished, and data freshness/offline state is visible.

### 12.6 Organizer Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `organizer.dashboard` | `organizer.dashboard` | Org/event readiness dashboard | Organizer |
| `organizer.applications` | `organizer.applications.index` | Review applications | Organizer, Staff Coordinator |
| `organizer.application-detail` | `organizer.applications.show` | Application review detail | Organizer, Staff Coordinator |
| `organizer.staff` | `organizer.staff.index` | Org staff administration | Organizer |
| `organizer.events` | `organizer.events.index` | Event administration | Organizer |
| `organizer.departments` | `organizer.departments.index` | Department administration | Organizer |
| `organizer.credentials` | `organizer.credentials.index` | Event credential administration | Organizer |
| `organizer.credit-policy` | `organizer.credit-policy.edit` | Credit policy configuration | Organizer |
| `organizer.policy-documents` | `organizer.policy-documents.index` | Organization policy document administration | Organizer |
| `organizer.procedure-documents` | `organizer.procedure-documents.index` | Organization procedure document administration | Organizer |
| `organizer.document-fragments` | `organizer.document-fragments.index` | Organization reusable fragment administration | Organizer |
| `organizer.document-acknowledgments` | `organizer.document-acknowledgments.index` | Policy/procedure acknowledgment review | Organizer/elevated |
| `organizer.audit` | `organizer.audit.index` | Audit review | Organizer/elevated |

Organizer screens must not display IMS incidents unless the user also has IC team-granted authority.

### 12.7 IMS Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `ims.dashboard` | `ims.dashboard` | IMS attention dashboard | IC viewer/operator/lead |
| `ims.incidents` | `ims.incidents.index` | Incident list | IC viewer/operator/lead |
| `ims.incident-create` | `ims.incidents.create` | Create incident with autosave | IC operator/lead |
| `ims.incident-detail` | `ims.incidents.show` | Incident detail/timeline | IC viewer/operator/lead |
| `ims.incident-edit` | `ims.incidents.edit` | Edit incident with autosave | IC operator/lead |
| `ims.field-reports` | `ims.field-reports.index` | IC Field Report review | IC viewer/operator/lead where permitted |
| `ims.field-report-detail` | `ims.field-reports.show` | Field Report detail/linking | IC viewer/operator/lead where permitted |
| `ims.restricted` | `ims.restricted` | Restricted access state | Users without IC access |

Incident create/edit routes require active server connection in Alpha 1.

### 12.7A Notes and Briefing Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `briefing.hub` | `events.briefing` | Event Briefing hub (Command-added Notes + type sections) | Approved event staff |
| `notes.index` | `events.notes.index` | Notes list (own Notes; Command sees all event Notes) | Note author or Command |
| `notes.create` | `events.notes.create` | Create immutable Note | Department lead, team lead, `ic_lead`, `ic_operator` |
| `notes.detail` | `events.notes.show` | View Note | Author or Command; also via Briefing reference “view original” |
| `briefing.add-note` | `events.briefing.notes.add` | Command add Note to Briefing (reference/link + audience) | `ic_lead`, `ic_operator` |
| `briefing.aar` | `events.briefing.aar.index` | AAR list (shell in Alpha 1) | Per AAR visibility rules |
| `briefing.aar-detail` | `events.briefing.aar.show` | AAR detail (shell in Alpha 1) | Per AAR visibility rules |
| `briefing.directions` | `events.briefing.directions.index` | Directions list (shell in Alpha 1) | Per Direction visibility |
| `briefing.action-plan` | `events.briefing.action-plan` | Action Plan (shell in Alpha 1) | Approved event staff |
| `briefing.notices` | `events.briefing.notices.index` | Notices list (shell in Alpha 1) | Per Notice visibility |

Alpha 1 implements `briefing.hub`, Notes list/create/detail with author+Command visibility, Command `briefing.add-note` (reference/link + `event_staff` or `department_leads_only` audience), hub display of added Notes to permitted viewers, and empty shells for AAR, Directions, Action Plan, and Notices. Note create and add-to-Briefing require server connection.

Orchid Note repair screen ID: `orchid.notes`.

### 12.8 Kiosk Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `kiosk.home` | `kiosk.home` | Trusted workstation dashboard | Trusted workstation / authenticated user |
| `kiosk.switch-user` | `kiosk.switch-user` | Fast user switching | Trusted workstation |
| `kiosk.reauth` | `kiosk.reauth` | Re-auth for privileged action | Trusted workstation/authenticated user |
| `kiosk.shift-board` | `kiosk.shift-board` | Kiosk-safe shift board entry | Authorized shift/department lead |
| `kiosk.safe-timeout` | `kiosk.safe-timeout` | Safe timeout surface | Trusted workstation |

### 12.9 Orchid / God Mode Admin Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `orchid.policy-documents` | Orchid screen | Policy document CRUD, preview, publish/archive | Authorized maintainer/god mode |
| `orchid.procedure-documents` | Orchid screen | Procedure document CRUD, preview, publish/archive | Authorized maintainer/god mode |
| `orchid.document-fragments` | Orchid screen | Fragment CRUD and reference impact warning | Authorized maintainer/god mode |
| `orchid.document-acknowledgments` | Orchid screen | Acknowledgment review | Authorized maintainer/god mode |
| `orchid.events` | Orchid screen | Event administration including IC and Placement department designation | Organizer/god mode |
| `orchid.event-maps` | Orchid screen | Event maps, map assets/packages, camps, and map locations administration | Authorized maintainer/god mode |
| `orchid.notes` | Orchid screen | Note list/detail repair visibility | God mode / authorized repair |
| `orchid.sync-conflicts` | Orchid screen | Sync conflict queue and resolution | God mode |
| `orchid.node-config` | Orchid screen | Node configuration and source display | God mode |

---

## 12.10 Event Application Screen Contract

### 12.10.1 `public.apply`

The event application form is a fixed, non-configurable form. It is not part of a form builder.

Required fields:

- event context (read-only)
- applicant legal name (unless authenticated identity already supplies it)
- applicant email (unless authenticated identity already supplies it)

Optional fixed field:

- **Department interest** — multi-select checklist of eligible event-participating departments.

Department interest UI rules:

- label and helper text must make clear the field is optional and non-binding (interest, not assignment or membership)
- helper text explains that leaving all options unchecked means no preference / open to any
- use a multi-select or checklist-style control consistent with existing form components
- eligible options are non-archived departments in the event organization with an active `event_department_assignments` (or equivalent) row for this event
- hide the entire department interest field when no eligible participating departments exist; submission proceeds normally
- do not show team selection or team interest
- do not show a “No preference” pseudo-option
- do not show a maximum-count validation message
- do not prefill from the applicant’s existing department memberships
- applicants cannot edit department interest after submit in Alpha 1

Available to public and authenticated applicants.

### 12.10.2 `organizer.applications` and `organizer.application-detail`

Organizers and Staff Coordinators with application review permission:

- display submitted department interest when present, labeled as **Department interest** (not assignment)
- when no interests were recorded, show a neutral empty state such as “No department preference” or “Open to any”
- support filtering the application list by department interest in Alpha 1
- do not provide controls to edit department interest during review in Alpha 1
- do not include department interest in Alpha 1 exports

Department lead read-only visibility:

- department leads may view read-only application list/detail for applications that expressed interest in a department they lead, including before organization approval and before department assignment
- read-only department lead views must not expose Approve, Reject, Defer, Assign, or edit-interest actions unless the user also has organizer or Staff Coordinator review permissions
- department lead visibility must not grant access to unrelated applications based only on department lead status
- department lead visibility must not trigger routing, notifications, or assignment side effects

---

## 12.11 Event Map Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `map.view` | `events.map.show` | Event map view with map selector, scoped search/filter, layer toggles, and camp/location detail drawer | Permitted map viewers (leads, Placement dept, organizers/admins, IC where relevant, kiosk where permitted) |
| `map.manage` | `events.map.manage` | Map management: create/import maps, lightweight metadata, camps (name/location), map locations, assets/packages, publish/archive | Placement department leads, organizers/admins, granted map managers, before the operations window |

Event map screen rules:

- only `published` maps are shown on `map.view` for operational users; `draft`/`archived` maps require map edit/admin permission.
- camp names and operational map data are not exposed to users without map permissions; sensitive layers require explicit permission.
- camps and map locations must not appear in the global command palette; map search/filter is scoped to the map surface.
- map editing is online-only; once the event operations window begins, published map geometry and camp/location records are locked and `map.manage` shows locked-state messaging; only an organizer/admin override path may change locked data.
- there are no arbitrary dropped pins and no volunteer map-correction workflow.
- Field Report surfaces must not gain a map/location selector.

Placement department designation is configured on the event admin surface (`organizer.events`) with an organization-level default, and is also available in Orchid (`orchid.events`). IMS incident create/edit (`ims.incident-create`, `ims.incident-edit`) may include an optional camp/location selector, and `ims.incident-detail` may display linked camp/location details where permitted.

---

## 13. Dashboard Widget Inventory

### 13.1 Staff Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `staff.current_shift` | Current Shift | user/event | assigned or checked-in staff | No current shift | View shift |
| `staff.upcoming_shifts` | Upcoming Shifts | user/event | authenticated staff | No upcoming shifts | View my shifts |
| `staff.assigned_departments` | Assigned Departments | user/org/event | authenticated staff | No assigned departments | View departments |
| `staff.shift_alerts` | Shift Alerts | user/event | authenticated staff | No shift alerts | View alert source |
| `staff.document_acknowledgments` | Documents to Acknowledge | user/org/department | authenticated staff | No documents need acknowledgment | Review documents |
| `staff.briefing` | The Briefing | user/event | approved event staff | No Briefing items | Open Briefing hub |
| `staff.quiet_state` | Nothing Needs Action | user/event | authenticated staff | calm reassurance | None |

### 13.2 Department Lead Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `dept.coverage_issues` | Coverage Issues | department/event | department lead | All scheduled shifts covered | Open shifts |
| `dept.shift_readiness` | Shift Readiness | department/event | department lead | Department shifts ready | Review shifts |
| `dept.checkin_status` | Check-in Status | department/event | department logistics | No check-in issues | Open Logistics Window |
| `dept.training_readiness` | Training Readiness | department/event | department lead | Required trainings complete | Review trainings |
| `dept.policy_readiness` | Policy Readiness | department | department lead | Department documents current | Review documents |
| `dept.equipment_returns` | Equipment Returns | department/event | department logistics | No equipment returns pending | Open Logistics Window |
| `dept.event_map` | Event Map | department/event | department lead with map view permission; Placement dept lead gets management access | No published map | Open event map |

### 13.3 Department Operations Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `shift.current_assignments` | Current Assignments | shift/department/event | operational visibility | No current assignments | Open Department Overview |
| `shift.late_missing` | Late or Missing Staff | shift/department/event | department logistics | No late or missing staff | Review check-in |
| `shift.deployment_needs` | Deployment Needs | shift/department/event | department operations | Deployments look okay | Open Operations Center |
| `shift.equipment_status` | Equipment Status | shift/department/event | department logistics | Equipment accounted for | Open Logistics Window |

### 13.4 Organizer Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `org.event_readiness` | Event Readiness | org/event | organizer | Event readiness looks okay | Review readiness |
| `org.cross_dept_coverage` | Cross-Department Coverage | org/event | organizer | No cross-department coverage issues | Review coverage |
| `org.application_review` | Applications to Review | org/event | organizer | No applications awaiting review | Review applications |
| `org.policy_readiness` | Policy Readiness | org/event | organizer | Required documents current | Review policies |
| `org.planning_tasks` | Planning Tasks | org/event | organizer | No planning tasks due | View tasks |
| `org.operations_window` | Operations Window | org/event | organizer | Event outside operations window | View event |

Organizer widgets must not surface IMS incidents, restricted Field Reports, active incident counts, or incident priority alerts unless the user also has IC team-granted authority.

### 13.5 IC Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `ic.active_incidents` | Active Incidents | event/IC department | IC viewer/operator/lead | No active incidents | Open incidents |
| `ic.serious_incidents` | Serious Incidents | event/IC department | IC viewer/operator/lead | No serious incidents | Review incidents |
| `ic.on_scene` | On Scene | event/IC department | IC viewer/operator/lead | No incidents on scene | Open incidents |
| `ic.monitoring` | Monitoring | event/IC department | IC viewer/operator/lead | No monitoring incidents | Open incidents |
| `ic.unresolved_field_reports` | Field Reports to Review | event/IC department | IC viewer/operator/lead | No Field Reports awaiting IC review | Review Field Reports |
| `ic.briefing` | The Briefing | event/IC department | IC viewer/operator/lead | Briefing quiet | Open Briefing hub |

### 13.6 Kiosk Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `kiosk.current_tasks` | Current Operational Tasks | event/kiosk | trusted workstation + user permissions | No current kiosk tasks | Open task |
| `kiosk.staff_checkin` | Staff-Mediated Check-in | event/department | shift/department lead | No check-ins pending | Start check-in |
| `kiosk.equipment_returns` | Equipment Returns | event/department | shift/department lead | No returns pending | Open returns |
| `kiosk.node_status` | Local Node Status | kiosk/event | trusted workstation | Local node reachable | View status if permitted |
| `kiosk.switch_user` | Current User | kiosk | trusted workstation | User visible | Switch user |
| `kiosk.event_map` | Event Map | event/kiosk | trusted workstation + map view permission | No published map | Open event map |

---

## 14. Field Report Contract

### 14.1 Lifecycle

Field Reports follow this Alpha 1 lifecycle:

1. User opens Field Report submission surface.
2. User fills required title, required body text, and optional allowed attachments.
3. User selects Submit or Cancel.
4. On Submit, the Field Report is finalized.
5. The submitted Field Report can be viewed by its author and permitted reviewers.
6. Corrections are append-only and audit-aware; the original title and body are not rewritten.
7. IC users may attach Field Reports to incidents where permitted.
8. Name References in the submitted body may be highlighted after submission. Titles are not parsed for Name References.

### 14.2 Field Report UI Rules

- Use Submit and Cancel, not Save and Cancel.
- Do not autosave Field Reports.
- Do not provide drafts in Alpha 1.
- Do not render Field Reports as private notes.
- Require a title: plain text, trimmed outer whitespace, 1–200 characters after trimming; duplicate titles allowed.
- Show the title on author and permitted-reviewer list and detail surfaces.
- Show event and department/team context.
- Show submitted-by as system-set, not user-editable.
- Attachments are images only.
- Do not show Name Reference autocomplete, context menus, existing-reference suggestions, notifications, linked incident visibility, or cross-record expansion to the author.

### 14.3 Attachment Rules

Alpha 1 attachment rules:

- maximum 2 images;
- image files only;
- common phone image formats accepted;
- no GIFs;
- maximum dimensions after processing: 2560 × 1900;
- maximum size after compression: 5 MB;
- EXIF stripped;
- filenames plain text with Field Report identifier and timestamp;
- stored on server;
- images are not synced to devices;
- preview via server when available.

---

## 15. IMS Incident Contract

### 15.1 Incident Fields

Incident create/edit must support:

- incident number, generated by system;
- state;
- started timestamp;
- summary/title;
- priority label;
- Rangers/responders multi-select;
- incident types multi-select;
- location name;
- location address;
- location details;
- optional camp/operational map location reference, where the user is permitted to view map data, never required and never replacing the free-text location fields;
- attached Field Reports;
- linked incidents;
- notes;
- tags derived from `#hashtags` in notes;
- Name Reference chips derived from incident notes and attached Field Reports;
- history/timeline.

The camp/location selector chooses from known camps/map locations only. It must be optional, must never block incident creation, must not introduce arbitrary dropped pins, and must be hidden when the user lacks map permissions or no published map exists. Incident detail may display linked camp/location details where permitted, following existing IMS permissions.

Linked Incident and attached Field Report picker candidates must exclude records
already actively linked to the current incident. Candidates sharing current
incident `#tags` sort before non-matching candidates, but location text must not
be used for that tag-priority rule. Each group sorts newest added first.
Picker search must include same-event Incident and Field Report records that are
already linked to other incidents, as long as they are not already actively
linked to the current incident.

Incident and IC Field Report list headings must be sortable. Incident lists must
offer state and priority filters, default to active states so Closed incidents
are excluded, and allow Closed incidents to be included. IC Field Report lists
must link back to Incidents, Incident lists must link to IC Field Reports, and
both list surfaces must include a Home link. Because Field Reports do not have
their own Alpha 1 state or priority fields, IC Field Report list state/priority
filters use related incident state/priority. IC Field Report lists must also
offer link-status filtering for linked and not-linked reports.

### 15.2 Autosave

Incident create/edit is the only autosaving form in Meridian Alpha 1, and it requires an active server connection.

Autosave rules:

- every change autosaves where technically possible;
- autosave state must be visible but non-interruptive;
- failed autosave must be visible;
- offline state must block create/edit and preserve the current local form state where technically practical;
- typing in notes must not be interrupted;
- destructive incident actions still require confirmation.

### 15.3 Notes

- Incident notes are plain text while editing.
- Markdown formatting may be supported while editing.
- Markdown must not render until after submission/posting.
- Notes are append-only once submitted.
- Tags may be generated from hashtags in notes.
- Removing a tag pill must not rewrite the original note.
- Name References may be generated from `@name` markers in notes for rendering, chips, and normal search.
- Removing or hiding a Name Reference chip must not rewrite the original note.

### 15.4 Name References

- Supported surfaces are Incident notes and Field Reports only.
- Supported token characters after `@` are letters, numbers, hyphens, and underscores.
- Whitespace or punctuation ends the token.
- Matching and search are case-insensitive.
- Incident-level chips include references from incident notes and attached Field Reports.
- Chips appear near tags or incident metadata where appropriate and are visually distinct from `#tags`.
- Clicking a chip runs normal permission-filtered search for the reference text without `@`.
- Name References must not imply user mentions, notifications, volunteer profile links, alias merge behavior, canonical identity/entity records, or a dedicated detail page.

### 15.5 Timeline and History

Show by default:

- incident opened;
- priority changes;
- state changes;
- assignments;
- operational notes;
- Field Reports attached;
- major location/deployment updates;
- closure or resolution entries.

Hide by default but make available:

- routine field-change audit entries;
- low-signal metadata updates;
- mechanical sync events.

### 15.6 Deletion and Striking

- Incident deletion requires reason.
- Deleted incidents remain as stricken records.
- Incident numbers are not reused.
- Timeline/history remains the source of truth.
- Destructive or high-impact actions require `ConfirmationDialog`.

---

## 16. Offline and Sync Contract

### 16.1 Connectivity State Labels

Use these UI states consistently:

| State | Meaning |
|---|---|
| Online | Central or expected sync target reachable |
| Offline but usable | Local work can continue |
| Local node reachable | On-site node is reachable |
| Central unreachable | Local node may work but central sync is unavailable |
| Queued | Local actions are waiting to sync |
| Sync conflict | Conflict needs handling |
| Sync failed | Sync failed and may require action |

### 16.1A Node Connection Scale

The seven states in 16.1 are what the UI *says*. This is what it *shows*: a
four-step scale of notice on the shell's user button and on the dot in the user
dropdown, both of which report the same thing — how the device is doing against
the node it syncs with.

Worst to best:

| Step | Colour token | Covers |
|---|---|---|
| Unknown | `--m-text-muted` | State not yet determined. Startup only. |
| Failing | `--m-status-danger` | Sync conflict; Sync failed; no node reachable |
| Degraded | `--m-status-warning` | Offline but usable; Local node reachable; Central unreachable; Queued |
| Connected | `--m-status-success` | Online |

Rules:

- these are the only four steps; do not add a fifth or re-map a state without
  changing this table;
- render the colours at full strength. Do not blend them toward
  `--m-text-muted`: a scale mixed into the surrounding gray stops being a scale;
- colour never carries the state on its own. Every indicator also exposes the
  canonical 16.1 label as text or accessible name, per the accessibility
  checklist;
- Unknown is startup only, and is reachable only once a connection signal exists
  that has an indeterminate period. The current device-network signal resolves
  synchronously, so today Unknown is defined and testable but not reached at
  runtime. Do not manufacture a gray flash to make it visible.

### 16.2 Offline UI Rules

- Show offline state only where it affects current work.
- Do not imply current central truth when data may be stale.
- Disable or hide unavailable actions honestly.
- Show queued actions where the user or role needs trust in them.
- Sync repair belongs in advanced mode unless current work cannot continue.
- Do not interrupt routine field work with sync noise.
- Incident creation and editing are online-only in Alpha 1.
- Policy/procedure acknowledgments are online-only in Alpha 1.
- Event application submission, including optional department interest, is online-only in Alpha 1.
- Staff profile picture upload, replace, and remove are online-only in Alpha 1.
- Staff profile picture blobs sync lazily as accessed and should show a placeholder or pending image state while unavailable.
- Field Report creation, Field Report photo attachment sync, check-in, check-out, and mark no-show are Alpha 1 offline writes.
- Supported offline writes are available wherever the corresponding surface is available and the device has required synced local data.
- Map editing (maps, camps, map locations, assets, publish/archive, locked-data overrides) is online-only for MVP.
- Published map packages and permitted camp/location data sync down read-only to permitted devices and remain readable offline; surfaces show a stale/offline map status where relevant, and locked operations-window map data remains stable offline.
- Name Reference source text syncs through existing Incident note and Field Report behavior. Any local/server derived index is rebuildable from source text and must not widen offline visibility.
- Offline server rejections and conflicts defer to the God Mode conflict queue. Until that queue exists, product surfaces may fail silently after preserving local queued/sync-failed state needed for later repair.

---

## 17. Policy, Procedure, and Fragment Contract

### 17.1 Document Types

Policy documents and procedure documents are separate domain types and separate persistence models. UI must label them separately as `Policy` and `Procedure`.

### 17.2 Visibility and Scope

Documents and fragments may be scoped to organization, department, or team.

- organization-scoped published documents are visible to everyone in the organization;
- department-scoped published documents are visible to members of that department;
- team-scoped published documents are visible to members of that team;
- documents are not generally public-facing before login except as part of staff signup.

### 17.3 Authoring

- document content is Markdown only in Alpha 1;
- raw HTML is disallowed;
- editors show fragment references and referenced fragment version;
- viewers render fragment text inline;
- broken fragment references block publishing;
- fragment screens show referencing documents before editing;
- fragment edits warn when they will bump versions for published referencing documents.

### 17.4 Version and Acknowledgment UI

- document versions display as `document_revision.fragment_revision`, such as `1.00`;
- acknowledgments may occur only during signup or training in Alpha 1;
- acknowledgments require server connection;
- acknowledgment requirements may use organization or department scope in Alpha 1;
- acknowledgments are not direct shift-signup gates or credential-eligibility gates.

### 17.5 Export UI

Policy/procedure export controls may offer Markdown and PDF export for authorized users. Exports render fragment text inline and include document type, title, version, scope, and export timestamp.

---

## 18. Kiosk and Trusted Workstation Contract

### 18.1 Kiosk Context

Kiosk is the fixed Electron desktop/on-site UI mode and product shell for
Meridian Kiosk.

Kiosk pinned context is the constrained trusted-workstation operating context
inside Meridian Kiosk.

Kiosk screens must show:

- current organization;
- current event;
- operations-window state;
- trusted workstation state;
- current user when actions are attributable;
- relevant offline/local node state.

Kiosk pinned context rules:

- every Kiosk shared workstation must be pinned to one organization and one event before normal operation;
- a Kiosk shared workstation may optionally be pinned to one department;
- if pinned organization/event context is missing, Kiosk enters setup;
- Kiosk must not infer pinned context from viewport, network, authenticated user, last route, or cached event data;
- authorized organizers, lead organizers, and God Mode users may change pinned context from Kiosk setup/support surfaces;
- pinned context constrains shell/scope selection but does not grant the active user authority.

### 18.2 Authentication and Re-authentication

Alpha 1 central authentication continues to use email magic links, Google OAuth, and Discord OAuth.

PIN-like re-authentication, if implemented, is a local trusted-workstation convenience for already-provisioned users. It must not become an independent central credential.

Trusted workstation state and individual user authority are separate:

- trusted workstation state can permit kiosk surfaces;
- individual user authority controls actions and record access;
- privileged actions may require re-authentication;
- timeout returns to a safe kiosk surface.

Kiosk inactivity timeout is 5 minutes for MVP.

When Kiosk times out, unsaved work is abandoned. Saved local queued operations
remain queued locally and sync when available.

### 18.3 Self Check-in

Alpha 1 rule:

- default staff do not self check-in or self check-out;
- Department Logistics may check staff in/out where authorized;
- unavailable self-service actions should not be shown to default staff;
- future self-service check-in must be a deliberate permission-controlled product decision.

---

## 19. Permission-Denied Contract

Permission-denied surfaces must be calm and direct.

### 19.1 Default Staff

Show simple restricted-access messaging. Do not expose internal permission details unless needed.

Example:

```text
Restricted access
You do not have access to this area.
```

### 19.2 Elevated Users

Elevated users may see the missing role or scope when useful.

Example:

```text
Restricted access
This page requires IC Operator or IC Viewer access for the event's configured IC department.
```

### 19.3 Meridian Kiosk

In Meridian Kiosk, permission denial should offer a safe return path.

Example:

```text
This action is not available for the current user.
Return to kiosk home or switch users.
```

---

## 20. Accessibility Contract

Alpha 1 UI changes must be reviewed for:

- keyboard-only operation;
- visible focus states;
- visible or accessible labels;
- non-color-only state communication;
- form error summaries;
- clear required field markers;
- light and dark mode contrast;
- reduced motion support;
- touch usability where relevant;
- kiosk usability where relevant;
- screen-reader labels for changed controls where practical.

Target conformance level: WCAG 2.2 AA unless the project chooses a different approved target.

Automated tooling should be configured separately in CI. If not configured yet, PRs must still include manual accessibility review notes.

---

## 21. LLM Code-Generation Rules

When using this document as input for Codex or another coding agent:

1. Generate product workflows in the shared Vue client for the fixed Admin, Field, and Kiosk artifacts; use Orchid only for God Mode / repair tooling; use Capacitor/Electron only as platform packaging wrappers.
2. Do not invent new statuses.
3. Do not invent new role access for IMS.
4. Do not expose IMS widgets to organizers unless they also have IC team-granted authority.
5. Do not convert Field Reports into drafts.
6. Do not make Field Reports autosaving forms.
7. Do not put organization/event switching in the top bar.
8. Do not add a persistent left sidebar as primary navigation.
9. Use semantic tokens instead of raw color values in reusable components.
10. Use `StatusPill` for canonical statuses and `SeverityIndicator` for attention/priority.
11. Use `ActionBar` for primary operational/editing actions on touch/kiosk surfaces.
12. Use `ConfirmationDialog` for destructive or high-impact actions.
13. Include loading, empty, error, permission, offline, and success states for new screens.
14. Include keyboard and visible focus behavior for new interactive components.
15. Include tests or manual QA notes for permission visibility and offline/sync behavior.
16. Do not model operational shift roles separately from teams.
17. Do not add department Field Report review unless the user also has IC team-granted authority or a later requirement explicitly grants it.
18. Do not create offline incident creation/editing flows for Alpha 1.
19. Do not model policy/procedure packet assembly, full-text document search, team-scoped acknowledgment requirements, or offline acknowledgments in Alpha 1.

---

## 22. PR Acceptance Checklist for UI Implementation

A UI implementation PR satisfies this contract when:

- screen IDs and routes match or intentionally extend the screen inventory;
- components use the defined component contracts or document a justified exception;
- statuses use canonical labels;
- role and permission gates are applied before rendering sensitive actions or records;
- IMS data is visible only to IC roles;
- Field Reports submit/finalize behavior is preserved;
- offline/sync states are represented where relevant;
- kiosk screens distinguish workstation state from user authority;
- accessibility review is documented;
- presentation profiles and fixed UI modes are covered where relevant;
- destructive actions require confirmation;
- tests or QA notes cover the acceptance criteria for the changed surface.
