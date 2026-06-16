# Meridian UI Implementation Contract

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Purpose: Define deterministic MVP UI contracts for routes, screens, components, statuses, permissions, widgets, offline behavior, kiosk behavior, and code-generation tasks.

---

## 1. Purpose

This document converts the Meridian UI surface guidance into implementation-ready contracts. It is intended for humans and LLM coding agents that need to generate consistent UI code without reinterpreting the product requirements, technical specification, or UI operating guide.

This document does not replace the product requirements, technical specification, or canonical UI Surface Spec / Operating Guide. It narrows them into MVP contracts for implementation.

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
   - Kiosk and Field Hardware UX Guide;
   - Screen Surface Specification.

If the canonical parent UI document has a different filename in the repository, use the repository filename consistently in cross-references.

---

## 3. MVP Technical Contract

Meridian MVP UI implementation should follow this technical contract:

- Backend framework: Laravel.
- UI posture: server-rendered first.
- Preferred component implementation: Blade components.
- Interactive behavior: Livewire or Alpine may be used when the interaction needs client-side state.
- Offline-first behavior: PowerSync-backed local state and sync-aware UI.
- Install targets: web/PWA, Electron for on-site laptop, and responsive browser surfaces.
- Styling: semantic design tokens, not hard-coded raw brand colors.
- Auth: approved external providers and magic-link/session behavior; no internal username/password auth for MVP.
- Kiosk: trusted workstation context layered on top of authenticated user authority.

Client-side behavior must enhance server-rendered markup rather than replacing it as the only source of meaningful UI state.

---

## 4. Global UI Rules

All authenticated Meridian product screens must follow these rules:

1. Use the Meridian app shell unless intentionally outside the main product flow.
2. Do not use a persistent left sidebar as the primary navigation model.
3. Do not use the top bar as the organization or event switcher.
4. Organization and event switching must happen on Home or a dedicated context-switching surface.
5. Show operating context when it changes what the user can see or do.
6. Use canonical status names exactly.
7. Hide unavailable actions for default volunteers unless the absence would be confusing.
8. Use disabled actions with explanation mainly in admin or high-context surfaces.
9. Destructive actions must use a confirmation dialog.
10. Offline and sync state must appear only where it affects the current work.
11. Routine success should use inline state or toast feedback, not blocking modals.
12. Only incident create/edit uses autosave forms.
13. Normal create/edit forms use explicit Submit or Save and Cancel.
14. Field Reports are submitted and finalized; they are not drafts.
15. Department accent colors identify departments but do not theme entire surfaces.

---

## 5. Surface Mode Contract

Meridian UI must support surface modes. Surface mode is not the same as viewport width.

```ts
surfaceMode: 'desktop' | 'touch' | 'mobile' | 'kiosk' | 'dense'
```

Equivalent PHP enum, config value, or view model naming is acceptable.

### 5.1 Surface Mode Inputs

Surface mode may be derived from:

- viewport size;
- pointer capability;
- known device profile;
- Electron/kiosk configuration;
- trusted workstation state;
- user-selected dense mode;
- current screen type;
- operational window state.

### 5.2 Surface Mode Rules

| Surface mode | Intended use | Layout preference | Control preference |
|---|---|---|---|
| `desktop` | mouse/keyboard laptop or desktop | table-first where useful | dense but accessible controls |
| `touch` | touch-enabled laptop/tablet | card-first or hybrid | larger visible controls |
| `mobile` | phone/PWA | priority feed, cards, single column | thumb/touch-friendly controls |
| `kiosk` | shared trusted workstation | simplified operational dashboard/cards | large labeled actions |
| `dense` | power-user density overlay | compact tables and reduced hints | labels and focus still required |

Dense mode must not remove required accessible names, canonical status text, focus visibility, or critical context.

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

### 8.1 MVP Role Families

| Role family | Scope | Summary |
|---|---|---|
| Volunteer | organization/event/department | Default user who may apply, view assigned work, sign up where eligible, submit Field Reports where permitted |
| Shift Lead | department/event/shift | Runs shift board and operational shift workflows |
| Department Lead | department/event | Manages department volunteers, roles, trainings, shifts, reports, deployments, and equipment workflows |
| Organizer | organization/event | Manages organization/event setup, applications, departments, credentials, policies, and broad readiness |
| IC Viewer | event IC department/team | Read-only IMS access |
| IC Operator | event IC department/team | Create/edit/close IMS incidents and attach Field Reports where permitted |
| IC Lead | event IC department/team | IC operator capabilities plus elevated IC management capabilities |

### 8.2 Important Permission Rules

- Organizer role alone does not grant IMS incident access.
- Department Lead role alone does not grant IMS incident access.
- IC access is granted through the event's configured IC department and IC roles.
- Default volunteers should not see admin-only actions.
- DNS status supersedes all other assignment and approval workflows.
- Department status does not override organization-level DNS, Removed, or Suspended restrictions.

---

## 9. Canonical Status Contract

Use these labels exactly in UI unless the requirements document later changes them.

### 9.1 Organization Volunteer Status

| Canonical label | Notes |
|---|---|
| Applied | User has applied at organization/event entry point |
| Approved | Approved at org level |
| Assigned | Assigned to event or relevant operational scope |
| Active | Active org volunteer not necessarily assigned to future event |
| Inactive | Automatically or manually inactive |
| Emeritus | Inactive but may contribute by request |
| Prospective | Pre-activation state |
| Suspended | Temporarily restricted |
| Removed | Removed and unassignable/unapprovable |
| DNS | Do Not Staff; org-wide and superseding |
| Retired | No longer working |

### 9.2 Department Volunteer Status

| Canonical label |
|---|
| Eligible |
| Invited |
| Active |
| Inactive |
| Emeritus |
| Removed |
| Prospective |

### 9.3 Event Application Status

| Canonical label |
|---|
| Submitted |
| Under Review |
| Approved |
| Rejected |
| Waitlisted |
| Withdrawn |
| Deferred |

### 9.4 Credential Status

| Canonical label |
|---|
| Eligible |
| Issued |
| Blocked |

### 9.5 Shift Attendance Flags

| Canonical label | Notes |
|---|---|
| No Show | Volunteer did not attend expected shift |
| Late | Volunteer was late |
| Honorably Discharged | Valid deshift/removal from shift |
| Dishonorably Discharged | Removal with prejudice |
| Cancelled | Shift or assignment cancelled |

### 9.6 Incident State

| Canonical label |
|---|
| Open |
| On Scene |
| Monitoring |
| On Hold |
| Closed |

### 9.7 Dashboard Attention Scale

Dashboard attention describes UI urgency, not incident seriousness.

| Label | Meaning |
|---|---|
| Routine | Useful information, no action required |
| Attention | User should review soon |
| Warning | Operational issue needs action |
| Critical | Urgent operational issue |
| Restricted | Security-sensitive or high-impact state |

### 9.8 IMS Priority Labels

IMS priority describes incident seriousness and must not be confused with incident state or dashboard attention.

Provisional MVP labels:

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

### 10.1 CSS Custom Property Naming

Initial MVP token names:

```css
:root {
  --m-surface-app: ;
  --m-surface-base: ;
  --m-surface-raised: ;
  --m-surface-overlay: ;

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

Light and dark mode must use the same semantic token names with different values.

---

## 11. Component Contract

### 11.1 StatusPill

Purpose: Render canonical statuses.

Inputs:

- `status`: canonical label or enum value;
- `family`: `organization-volunteer`, `department-volunteer`, `application`, `credential`, `shift-attendance`, `incident-state`;
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

- `department`;
- `showLogo`: boolean;
- `showAccent`: boolean;
- `size`: `sm`, `md`, `lg`.

Rules:

- department accent must remain a small identifier;
- generated fallback may use initials/lettermark;
- accessible name must include department name.

### 11.4 DataTable

Purpose: Table-first operational display for non-touch surfaces.

Required features:

- column headers;
- loading, empty, error states;
- keyboard-reachable row actions;
- filters/sorting where useful;
- responsive fallback or paired card layout.

Suggested Blade API:

```blade
<x-data-table
    :columns="$columns"
    :rows="$rows"
    :surface-mode="$surfaceMode"
    empty-message="No volunteers match these filters."
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

Purpose: Autosave indicator for incident create/edit only.

Allowed states:

- `saved`;
- `saving`;
- `failed`;
- `offline_queued`.

Rules:

- must not interrupt typing;
- failed state must provide a repair path;
- do not use on normal forms or Field Reports.

### 11.11 ConfirmationDialog

Purpose: Confirmation for destructive or high-impact actions.

Required structure:

1. title;
2. explanation;
3. impact statement;
4. specific confirming action;
5. cancel action.

The confirming button must use a specific verb, such as `Delete incident`, `Remove volunteer`, or `Block credential`.

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

---

## 12. MVP Route and Screen Inventory

Route names are implementation targets and may be adapted to Laravel conventions, but screen IDs should remain stable.

### 12.1 Public and Auth Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `public.apply` | `public.events.apply` | Volunteer event application | Public or authenticated applicant |
| `auth.login` | `login` | Provider/magic-link login entry | Public |
| `auth.magic-link-sent` | `auth.magic-link.sent` | Login code sent confirmation | Public |
| `auth.provider-callback` | framework route | External provider callback | Public/system |

### 12.2 Home and Context Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `home` | `home` | Task-based home and context entry | Authenticated |
| `context.organizations` | `organizations.index` | Select organization | Authenticated with memberships |
| `context.events` | `organizations.events.index` | Select event within organization | Authenticated with org access |
| `context.departments` | `events.departments.index` | Enter available department spaces | Authenticated with event/dept access |

### 12.3 Volunteer Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `volunteer.dashboard` | `volunteer.dashboard` | Volunteer task dashboard | Authenticated volunteer |
| `volunteer.shifts` | `volunteer.shifts.index` | My shifts | Volunteer with event access |
| `volunteer.shift-detail` | `volunteer.shifts.show` | Shift details | Assigned/eligible volunteer |
| `volunteer.field-reports` | `volunteer.field-reports.index` | My Field Reports | Authenticated author |
| `volunteer.field-report-create` | `volunteer.field-reports.create` | Submit Field Report | Volunteer with FR permission |
| `volunteer.field-report-detail` | `volunteer.field-reports.show` | View submitted Field Report | Author or permitted reviewer |

### 12.4 Department Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `department.dashboard` | `events.departments.show` | Department operational home | Department member/lead as permitted |
| `department.roster` | `events.departments.roster` | Department roster | Department lead or permitted shift lead |
| `department.roles` | `events.departments.roles.index` | Manage roles | Department lead |
| `department.trainings` | `events.departments.trainings.index` | Manage trainings | Department lead |
| `department.shifts` | `events.departments.shifts.index` | Manage/view shifts | Department lead or permitted role |
| `department.shift-create` | `events.departments.shifts.create` | Create shift | Department lead |
| `department.shift-edit` | `events.departments.shifts.edit` | Edit shift | Department lead with time restrictions |
| `department.deployments` | `events.departments.deployments.index` | Manage deployments | Department/shift lead |
| `department.equipment` | `events.departments.equipment.index` | Equipment workflows | Department/shift lead as permitted |
| `department.credits` | `events.departments.credits.index` | Credit review/export | Department lead / organizer as permitted |

### 12.5 Shift Board Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `shift-board.current` | `events.departments.shift-board.current` | Current shift board | Shift lead/department lead |
| `shift-board.check-in` | `events.departments.shift-board.check-in` | Staff-mediated check-in | Shift lead/department lead |
| `shift-board.check-out` | `events.departments.shift-board.check-out` | Staff-mediated check-out | Shift lead/department lead |
| `shift-board.deployment-update` | `events.departments.shift-board.deployments.update` | Move volunteer/deployment | Shift lead/department lead |
| `shift-board.equipment-update` | `events.departments.shift-board.equipment.update` | Check equipment in/out | Shift lead/department lead |

### 12.6 Organizer Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `organizer.dashboard` | `organizer.dashboard` | Org/event readiness dashboard | Organizer |
| `organizer.applications` | `organizer.applications.index` | Review applications | Organizer |
| `organizer.application-detail` | `organizer.applications.show` | Application review detail | Organizer |
| `organizer.volunteers` | `organizer.volunteers.index` | Org volunteer administration | Organizer |
| `organizer.events` | `organizer.events.index` | Event administration | Organizer |
| `organizer.departments` | `organizer.departments.index` | Department administration | Organizer |
| `organizer.credentials` | `organizer.credentials.index` | Event credential administration | Organizer |
| `organizer.credit-policy` | `organizer.credit-policy.edit` | Credit policy configuration | Organizer |
| `organizer.audit` | `organizer.audit.index` | Audit review | Organizer/elevated |

Organizer screens must not display IMS incidents unless the user also has IC permissions.

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

### 12.8 Kiosk Screens

| Screen ID | Route name | Purpose | Access |
|---|---|---|---|
| `kiosk.home` | `kiosk.home` | Trusted workstation dashboard | Trusted workstation / authenticated user |
| `kiosk.switch-user` | `kiosk.switch-user` | Fast user switching | Trusted workstation |
| `kiosk.reauth` | `kiosk.reauth` | Re-auth for privileged action | Trusted workstation/authenticated user |
| `kiosk.shift-board` | `kiosk.shift-board` | Kiosk-safe shift board entry | Authorized shift/department lead |
| `kiosk.safe-timeout` | `kiosk.safe-timeout` | Safe timeout surface | Trusted workstation |

---

## 13. Dashboard Widget Inventory

### 13.1 Volunteer Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `volunteer.current_shift` | Current Shift | user/event | assigned or checked-in volunteer | No current shift | View shift |
| `volunteer.upcoming_shifts` | Upcoming Shifts | user/event | authenticated volunteer | No upcoming shifts | View my shifts |
| `volunteer.assigned_departments` | Assigned Departments | user/org/event | authenticated volunteer | No assigned departments | View departments |
| `volunteer.shift_alerts` | Shift Alerts | user/event | authenticated volunteer | No shift alerts | View alert source |
| `volunteer.quiet_state` | Nothing Needs Action | user/event | authenticated volunteer | calm reassurance | None |

### 13.2 Department Lead Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `dept.coverage_issues` | Coverage Issues | department/event | department lead | All scheduled shifts covered | Open shifts |
| `dept.shift_readiness` | Shift Readiness | department/event | department lead | Department shifts ready | Review shifts |
| `dept.checkin_status` | Check-in Status | department/event | department/shift lead | No check-in issues | Open shift board |
| `dept.unresolved_reports` | Reports Needing Review | department/event | department lead with report permission | No reports awaiting review | Review reports |
| `dept.equipment_returns` | Equipment Returns | department/event | department/shift lead | No equipment returns pending | Open equipment |

### 13.3 Shift Lead Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `shift.current_roster` | Current Roster | shift/department/event | shift lead | No current roster | Open shift board |
| `shift.late_missing` | Late or Missing Volunteers | shift/department/event | shift lead | No late or missing volunteers | Review check-in |
| `shift.deployment_needs` | Deployment Needs | shift/department/event | shift lead | Deployments look okay | Manage deployments |
| `shift.equipment_status` | Equipment Status | shift/department/event | shift lead | Equipment accounted for | Review equipment |

### 13.4 Organizer Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `org.event_readiness` | Event Readiness | org/event | organizer | Event readiness looks okay | Review readiness |
| `org.cross_dept_coverage` | Cross-Department Coverage | org/event | organizer | No cross-department coverage issues | Review coverage |
| `org.application_review` | Applications to Review | org/event | organizer | No applications awaiting review | Review applications |
| `org.planning_tasks` | Planning Tasks | org/event | organizer | No planning tasks due | View tasks |
| `org.operations_window` | Operations Window | org/event | organizer | Event outside operations window | View event |

Organizer widgets must not surface IMS incidents, restricted Field Reports, active incident counts, or incident priority alerts unless the user also has IC permissions.

### 13.5 IC Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `ic.active_incidents` | Active Incidents | event/IC department | IC viewer/operator/lead | No active incidents | Open incidents |
| `ic.serious_incidents` | Serious Incidents | event/IC department | IC viewer/operator/lead | No serious incidents | Review incidents |
| `ic.on_scene` | On Scene | event/IC department | IC viewer/operator/lead | No incidents on scene | Open incidents |
| `ic.monitoring` | Monitoring | event/IC department | IC viewer/operator/lead | No monitoring incidents | Open incidents |
| `ic.unresolved_field_reports` | Field Reports to Review | event/IC department | IC viewer/operator/lead | No Field Reports awaiting IC review | Review Field Reports |

### 13.6 Kiosk Widgets

| Widget ID | Title | Scope | Permissions | Quiet state | Primary action |
|---|---|---|---|---|---|
| `kiosk.current_tasks` | Current Operational Tasks | event/kiosk | trusted workstation + user permissions | No current kiosk tasks | Open task |
| `kiosk.staff_checkin` | Staff-Mediated Check-in | event/department | shift/department lead | No check-ins pending | Start check-in |
| `kiosk.equipment_returns` | Equipment Returns | event/department | shift/department lead | No returns pending | Open returns |
| `kiosk.node_status` | Local Node Status | kiosk/event | trusted workstation | Local node reachable | View status if permitted |
| `kiosk.switch_user` | Current User | kiosk | trusted workstation | User visible | Switch user |

---

## 14. Field Report Contract

### 14.1 Lifecycle

Field Reports follow this MVP lifecycle:

1. User opens Field Report submission surface.
2. User fills required fields and optional allowed attachments.
3. User selects Submit or Cancel.
4. On Submit, the Field Report is finalized.
5. The submitted Field Report can be viewed by its author and permitted reviewers.
6. Corrections are append-only and audit-aware; the original submission is not rewritten.
7. IC users may attach Field Reports to incidents where permitted.

### 14.2 Field Report UI Rules

- Use Submit and Cancel, not Save and Cancel.
- Do not autosave Field Reports.
- Do not provide drafts in MVP.
- Do not render Field Reports as private notes.
- Show event and department/team context.
- Show submitted-by as system-set, not user-editable.
- Attachments are images only.

### 14.3 Attachment Rules

MVP attachment rules:

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
- attached Field Reports;
- linked incidents;
- notes;
- tags derived from `#hashtags` in notes;
- history/timeline.

### 15.2 Autosave

Incident create/edit is the only autosaving form in Meridian MVP.

Autosave rules:

- every change autosaves where technically possible;
- autosave state must be visible but non-interruptive;
- failed autosave must be visible;
- offline queued changes must be represented where relevant;
- typing in notes must not be interrupted;
- destructive incident actions still require confirmation.

### 15.3 Notes

- Incident notes are plain text while editing.
- Markdown formatting may be supported while editing.
- Markdown must not render until after submission/posting.
- Notes are append-only once submitted.
- Tags may be generated from hashtags in notes.
- Removing a tag pill must not rewrite the original note.

### 15.4 Timeline and History

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

### 15.5 Deletion and Striking

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

### 16.2 Offline UI Rules

- Show offline state only where it affects current work.
- Do not imply current central truth when data may be stale.
- Disable or hide unavailable actions honestly.
- Show queued actions where the user or role needs trust in them.
- Sync repair belongs in advanced mode unless current work cannot continue.
- Do not interrupt routine field work with sync noise.

---

## 17. Kiosk and Trusted Workstation Contract

### 17.1 Kiosk Context

Kiosk mode is a constrained operating context, not a separate product.

Kiosk screens must show:

- current organization;
- current event;
- operations-window state;
- trusted workstation state;
- current user when actions are attributable;
- relevant offline/local node state.

### 17.2 Authentication and Re-authentication

MVP central authentication continues to use approved providers and magic-link/session behavior.

PIN-like re-authentication, if implemented, is a local trusted-workstation convenience for already-provisioned users. It must not become an independent central credential.

Trusted workstation state and individual user authority are separate:

- trusted workstation state can permit kiosk surfaces;
- individual user authority controls actions and record access;
- privileged actions may require re-authentication;
- timeout returns to a safe kiosk surface.

Exact timeout durations are product decisions and should be configured, not hard-coded.

### 17.3 Self Check-in

MVP rule:

- default volunteers do not self check-in or self check-out;
- department leads and shift leads may check volunteers in/out where authorized;
- unavailable self-service actions should not be shown to default volunteers;
- future self-service check-in must be a deliberate permission-controlled product decision.

---

## 18. Permission-Denied Contract

Permission-denied surfaces must be calm and direct.

### 18.1 Default Volunteers

Show simple restricted-access messaging. Do not expose internal permission details unless needed.

Example:

```text
Restricted access
You do not have access to this area.
```

### 18.2 Elevated Users

Elevated users may see the missing role or scope when useful.

Example:

```text
Restricted access
This page requires IC Operator or IC Viewer access for the event's configured IC department.
```

### 18.3 Kiosk Mode

In kiosk mode, permission denial should offer a safe return path.

Example:

```text
This action is not available for the current user.
Return to kiosk home or switch users.
```

---

## 19. Accessibility Contract

MVP UI changes must be reviewed for:

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

## 20. LLM Code-Generation Rules

When using this document as input for Codex or another coding agent:

1. Generate Laravel/Blade-first UI unless a task explicitly requests otherwise.
2. Do not invent new statuses.
3. Do not invent new role access for IMS.
4. Do not expose IMS widgets to organizers unless they also have IC permissions.
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

---

## 21. PR Acceptance Checklist for UI Implementation

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
- mobile/touch/kiosk behavior is considered where relevant;
- destructive actions require confirmation;
- tests or QA notes cover the acceptance criteria for the changed surface.
