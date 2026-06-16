# Meridian UI Operating Guide

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Purpose: Define how Meridian UI should operate and look across product surfaces without fully specifying individual screens.

---

## 1. Purpose

This guide defines the first Meridian UI operating model. It is intended for designers, developers, maintainers, reviewers, and LLM coding agents working on Meridian.

It does not define every screen in detail. Instead, it establishes the shared rules for navigation, layout, interaction behavior, component usage, role-aware surfaces, offline behavior, kiosk behavior, accessibility, visual treatment, and PR review.

Detailed screen specifications may be created later as separate documents. Those documents must follow this guide unless this guide is intentionally amended.

---

## 2. Product UI Personality

Meridian should feel like a **modern field operations dashboard** with a **command center** orientation.

The interface should be:

- simple and quick to understand;
- versatile enough for power users to work efficiently;
- operational rather than decorative;
- calm under pressure;
- suitable for field, kiosk, laptop, tablet, and mobile use;
- honest about volunteer operations terminology;
- clear about scope, role, permissions, offline state, and sync limitations.

Meridian is not a glossy consumer app. It is field infrastructure for volunteer operations.

---

## 3. Relationship to the Meridian Style Guide

This guide assumes the Meridian visual style guide is the source of truth for brand identity, color palette, typography, and general visual personality.

The current style direction includes:

- primary Meridian Blue: `#0057B8`;
- supporting Cyan: `#16B8E8`;
- supporting Teal: `#49D4C8`;
- neutral Midnight: `#0A1630`;
- neutral Navy: `#15294D`;
- neutral Slate: `#42526B`;
- neutral Steel: `#6E7D94`;
- neutral Cloud: `#E6EBF2`;
- White: `#FFFFFF`;
- primary UI typeface: Inter;
- brand/display typeface: Space Grotesk.

UI implementation should use semantic design tokens rather than raw brand colors directly in component code.

Examples of required semantic token categories:

- `surface`
- `surface-muted`
- `surface-raised`
- `text-primary`
- `text-secondary`
- `text-muted`
- `border-subtle`
- `border-strong`
- `action-primary`
- `action-secondary`
- `state-info`
- `state-success`
- `state-warning`
- `state-critical`
- `state-restricted`
- `department-accent`

Light and dark mode must use the same semantic token names.

---

## 4. Core Design Principles

### 4.1 Field-first clarity

Meridian must be usable by people who are distracted, tired, outside, on a shared workstation, or working during an active event.

Interfaces should prioritize immediate comprehension over cleverness.

### 4.2 Command-center awareness

Users should always understand:

- which organization they are operating in;
- which event they are operating in;
- which department context is active;
- which role or permission level is enabling available actions;
- whether operations are inside an active event window;
- whether sync or offline state affects current actions.

### 4.3 Action-oriented surfaces

Dashboards and operational screens should prioritize what needs attention, what can be acted on, and what has changed.

Meridian should not show metrics only because they are available.

### 4.4 Correctable operational speed

Routine operational changes may save immediately after interaction when that improves speed. The UI must make mistakes easy to correct.

Destructive actions are different. They must always require a dedicated confirmation dialog.

### 4.5 Auditability without clutter

Audit history matters, but it should not overwhelm active work. History should usually live in a panel or drawer.

Incident timelines should hide routine field-change audit entries unless the user expands them.

### 4.6 Consistency for humans and LLMs

Reusable UI patterns must be named and governed. Coding agents must not invent new navigation models, colors, statuses, or component patterns without updating this guide.

---

## 5. Theme Model

### 5.1 Light and dark mode

Light mode is the default. Light and dark mode must be developed in tandem and must work equally well.

Users must be able to:

- choose light mode manually;
- choose dark mode manually;
- follow system preference.

### 5.2 Dark mode behavior

Dark mode should support night operations by reducing harsh contrast intensity while preserving readability.

Dark mode must not rely on pure black backgrounds by default. It should use deep neutral surfaces, visible borders, restrained elevation, and clear focus states.

### 5.3 Department accent colors

Department accent colors are not full theme overrides. They are small accents used to identify department context.

Department accent colors must not replace Meridian’s core semantic tokens. The system should not automatically adjust department accent colors for contrast. Department colors should be selected responsibly and used sparingly so that poor contrast in an accent does not break the UI.

---

## 6. App Shell and Navigation Model

### 6.1 Overall shell

Meridian should use a top bar and, when contextually appropriate, a bottom action bar.

This expectation applies to larger screens as well as mobile and touch surfaces.

The app shell should avoid a persistent left sidebar as the primary navigation model.

### 6.2 Top bar

The top bar must prioritize:

1. the Meridian logo;
2. current department context when inside a department surface;
3. command palette/search access.

The Meridian logo functions as the universal Home control.

The top bar must not be used for organization or event switching. Organization and event switching should happen from the home screen or another dedicated context-switching surface.

### 6.3 Bottom bar

The bottom bar is not required on every screen.

It should appear where it makes sense for the current context, especially on operational or editing surfaces. It should primarily hold current-screen actions, though it may contain a mix of contextual actions and navigation when appropriate.

Primary actions should always appear in the bottom bar once the user is inside an operational or editing surface, but the bottom bar does not have to be the only place those actions appear.

### 6.4 Task-based home

Both mobile and larger screen experiences should use a task-based home screen.

The home screen should guide the user toward the work they are likely to perform, based on organization, event, department assignment, role, active event window, and permissions.

### 6.5 Merged navigation

Users with multiple roles should generally see one merged navigation experience.

Role and context switching must still be obvious. The UI should make clear why a user can see or perform a given action.

### 6.6 Department navigation

Departments are first-class navigational spaces.

Users should only see departments they are assigned to.

Users should be able to quickly jump between assigned departments from the top bar or nearby department context controls.

---

## 7. Organization, Event, and Department Context

### 7.1 Required operating scope

Meridian should generally operate inside a selected organization and event.

The UI must make the current organization/event scope clear. The active event operations window should be visible where relevant, for example: operations active, upcoming, closed, or active until a stated date/time.

### 7.2 Event operations window

If the selected event is within its operations window, the UI should surface operationally relevant content, such as current shifts, check-in status, active incidents, coverage issues, and urgent alerts.

If the event is outside the operations window, the UI should emphasize planning, review, reports, and upcoming responsibilities.

### 7.3 Department identity

Each department may define:

- logo;
- primary accent color;
- icon;
- short label;
- description.

Department branding should appear wherever the department appears, but only as small accents. It must not override the Meridian interface.

If a department has no logo, the UI should generate a default lettermark-style icon using basic colors and initials or letters from separate words in the department name.

### 7.4 IC department treatment

The configured Incident Command department does not receive a unique brand treatment beyond normal department branding.

Incident and Field Report surfaces still use a more serious and restricted interface tone, but that tone comes from the surface type, not a special IC theme.

---

## 8. Role-Aware Home and Dashboard Model

### 8.1 Volunteer home

A regular volunteer should see departments they are assigned to as prominent icons or cards.

If the event is currently within its operations window and the volunteer is signed up for shifts, the volunteer’s schedule should appear below the department icons.

### 8.2 Department lead home

A department lead should see department-relevant dashboard widgets first.

Below those widgets, the department lead should see the same general structure as a volunteer: assigned departments and current event schedule.

### 8.3 Shift lead home

A shift lead should start from the same task-based home model as other users.

Shift lead actions should become available through relevant department, shift, and operational contexts rather than through a wholly separate home experience.

### 8.4 Organizer home

Organizers should see widgets relevant at the organization level, or event-level widgets when an event is within its operations window.

### 8.5 IC lead home

IC leads should see event operations and department-relevant widgets, including active incident counts, high-importance incidents, monitoring/on-scene incidents, and other attention items.

### 8.6 Dashboard widget model

Dashboard widgets are fixed by role for MVP.

Widgets may include:

- metric cards;
- action cards;
- alerts;
- lists;
- charts;
- quiet-state reassurance.

Dashboards should prioritize what needs attention over what merely exists.

Quiet states are desirable. Examples include “No active incidents” or “All scheduled shifts covered.”

On mobile, dashboard widgets should collapse into a single priority feed.

Volunteers may see both department-membership alerts and shift-related alerts.

---

## 9. Command Palette

### 9.1 Purpose

Global search should behave more like a command palette than a generic item search.

The command palette should help users navigate, switch context, and perform allowed actions quickly.

### 9.2 Shortcuts

The default keyboard shortcut is:

- `Ctrl+K` on Windows/Linux;
- `Cmd+K` on macOS.

The `/` key should also open the command palette when the user is not typing in a field.

### 9.3 Content

The command palette should include actions and navigation.

IC roles may also see records, such as incidents, field reports, or other IC-visible records.

Command palette results should be grouped by type.

Unavailable actions should be hidden.

### 9.4 Permissions

Command palette results must respect the user’s permissions, current organization, current event, current department, and role context.

### 9.5 Kiosk mode

The command palette should be available in kiosk mode, but only with actions and navigation appropriate to the authenticated user and trusted workstation state.

### 9.6 Shortcut display

Command palette actions should show keyboard shortcuts where available, except in dense mode.

---

## 10. Kiosk and Shared Workstation Behavior

### 10.1 Default kiosk surface

The on-site laptop should default to a kiosk dashboard.

Kiosk mode should hide admin and navigation complexity by default.

### 10.2 Authentication

On-site shared workstations should support PIN-like re-authentication.

Central access should use provider login and magic link authentication.

### 10.3 Self check-in

Volunteers should not self check-in or self check-out unless they are department leads or shift leads.

### 10.4 Field assumptions

Kiosk mode should assume:

- touchscreen laptops;
- gloves;
- dust;
- glare;
- night operations;
- distracted users;
- shared trusted devices;
- fast user switching.

### 10.5 Touch layout

Touch-enabled devices, including deployed on-site laptops, should receive touch-appropriate layouts automatically.

---

## 11. Action Model

### 11.1 Action placement

Primary actions should appear in the bottom action bar on operational and editing surfaces.

They may also appear inline or near related content when that improves comprehension.

### 11.2 Destructive actions

Destructive actions must always require a dedicated confirmation dialog.

Destructive actions include, but are not limited to:

- deleting;
- removing;
- blocking;
- DNS-related changes;
- destructive incident changes;
- actions that strike or invalidate historical records;
- any action that cannot be easily corrected.

### 11.3 Confirmation dialog format

Confirmation dialogs must follow a standard structure:

1. clear title;
2. concise explanation;
3. impact statement;
4. destructive or confirming action;
5. cancel action.

The confirming action must use a specific verb, not a vague label such as “OK.”

### 11.4 Routine operational changes

Routine operational actions do not need confirmation every time.

Examples include:

- check-in;
- check-out;
- deployment changes;
- equipment return;
- attaching a Field Report;
- routine state changes that are easy to correct.

These may save immediately after interaction, but the UI must provide an easy way to fix mistakes.

### 11.5 Floating action buttons

Floating action buttons should be avoided.

Meridian should rely on clear action bars, inline actions, command palette actions, and contextual controls.

### 11.6 Autosave

The incident create/edit form is the only autosaving form.

The incident create/edit form must autosave every change.

Other forms should generally use explicit Save/Cancel behavior, except for routine operational interactions that save immediately after the interaction and remain easy to correct.

---

## 12. Layout and Density

### 12.1 Non-touch devices

On non-touch desktop and laptop devices, rosters and shift boards should be table-first.

Tables should support efficient scanning, sorting, filtering, status recognition, and bulk or row-level action patterns where appropriate.

### 12.2 Touch-enabled devices

On touch-enabled devices, rosters and shift boards should become card-first automatically.

Touch cards should use larger controls, clear labels, and enough spacing to reduce mis-taps in field conditions.

### 12.3 Dense mode

Dense mode should be available for power users as a per-screen toggle.

Dense mode may reduce spacing, hide nonessential helper text, and hide visible keyboard shortcut hints.

Dense mode must not remove required labels, destroy keyboard accessibility, or hide critical state information.

### 12.4 Empty states

Empty states should be utilitarian by default.

A teach mode toggle may expose more explanatory guidance for users who need help learning the system.

---

## 13. Forms and Validation

### 13.1 Form structure

Long forms should generally be single-page.

Forms should use consistent field layout, clear labels, predictable error placement, and a top-level error summary.

### 13.2 Validation timing

Validation should happen on blur where it makes sense.

Submit-time validation must exist everywhere.

As-you-type validation should be used sparingly and only when it clearly helps.

### 13.3 Required fields

Required fields should be marked explicitly but not glaringly.

### 13.4 Field Reports and Incident forms

Field Reports should carry IMS seriousness.

Field Report and Incident forms should use the same general form language, while IMS surfaces remain visually more serious and restricted.

### 13.5 Incident notes

Incident notes are edited as plain text.

Markdown formatting may be supported while editing, but formatting should not render until after submission.

---

## 14. Tables, Cards, and Dashboard Components

### 14.1 Tables

Tables are preferred for dense operational views on non-touch devices.

Tables should be restrained, functional, and not overly decorative.

### 14.2 Cards

Cards are preferred for dashboards, touch layouts, mobile layouts, and high-level summaries.

Cards may use rounded corners and soft depth, especially outside forms and tables.

### 14.3 Forms and tables visual style

Forms and tables should be flatter and more utilitarian than dashboards or overview cards.

### 14.4 Charts

Charts may be used in dashboards where they clarify attention or operational readiness.

IMS screens should favor lists, timelines, and tables over charts or visual widgets.

---

## 15. Status, Severity, and State Language

### 15.1 Canonical names

UI labels should use exact system status names from the requirements and technical specification.

The UI should not replace canonical system names with friendlier labels.

### 15.2 Serious states

Serious states require special visual treatment.

Examples include:

- DNS;
- Removed;
- Suspended;
- Blocked;
- Dishonorably Discharged;
- Deleted/Stricken.

Special treatment may include stronger borders, restricted-state iconography, restrained critical color, locked affordances, or stronger typography.

### 15.3 Color usage

Color should be reserved primarily for urgency and state.

Department identity may use color only as small accents.

### 15.4 Severity and attention scale

Meridian should define a standard severity or attention scale for dashboard widgets and alerts.

The scale should distinguish routine information, attention needed, warning, critical, and restricted/security-sensitive states.

### 15.5 IMS priority labels

IMS priority labels should be visually distinct from normal statuses.

Priority labels should not be confused with incident state, volunteer status, credential status, or shift attendance flags.

### 15.6 Status visuals

The guide does not need to exhaustively map every status to a visual treatment in this version.

Status and state visuals should be defined generally and consistently, with future detailed mappings added when needed.

---

## 16. Offline and Sync UI Rules

### 16.1 Offline banner

Offline banners should be subtle.

They should appear inside affected organization/event contexts rather than globally everywhere.

### 16.2 Affected actions

When offline or partially connected, unavailable actions should be disabled or hidden.

Unavailable actions should not pretend to be usable.

### 16.3 Connectivity distinctions

The UI should distinguish between relevant connectivity and sync states by affecting available actions and status indicators.

States may include:

- offline but usable;
- local node reachable;
- central unreachable;
- sync conflict;
- sync failed.

These states should be visible only where they matter.

### 16.4 Sync failure behavior

Sync failures should never interrupt the user unless the current action cannot continue.

Sync repair belongs in advanced mode only.

Queued actions should be visible to the user who performed them when appropriate.

---

## 17. Permissions and Visibility

### 17.1 Hidden unavailable actions

Unavailable actions should generally be hidden.

The UI should avoid presenting actions that a user cannot perform, unless showing them disabled with explanation is specifically useful in a high-context admin surface.

### 17.2 Permission denied pages

Permission-denied behavior depends on the user type.

For elevated users, the page may explain which role is required.

For default volunteers, the page should simply indicate that access is restricted.

### 17.3 Operational terminology

Volunteer-facing UI should not hide operational or administrative terminology where that terminology is accurate and useful.

Meridian should not over-simplify language in a way that makes operations less clear.

### 17.4 Audit/history placement

Audit and history should usually be tucked into a history panel or drawer rather than displayed inline by default.

---

## 18. Incident Management UI Rules

### 18.1 Visual tone

IMS screens should feel more serious and restricted than normal volunteer and shift surfaces.

They should use minimal color variation outside of status, priority, and restricted-state indicators.

### 18.2 Surface patterns

IMS should favor:

- lists;
- timelines;
- tables;
- plain text notes;
- restrained status indicators;
- clear audit access.

IMS should avoid unnecessary charts and decorative visual widgets.

### 18.3 Autosave

The incident create/edit screen is a single autosaving form.

Every change must autosave.

### 18.4 Timeline behavior

Incident timelines should focus on meaningful operational entries.

Routine field-change audit entries should be hidden unless expanded.

### 18.5 Speed of actions

Incident actions should not be deliberately slower than normal operational actions.

The seriousness of IMS should come from clarity, permissions, auditability, and visual restraint, not artificial friction.

---

## 19. Iconography and Labels

### 19.1 Icon usage

Meridian should use icons heavily.

Icons support fast recognition in field and command-center contexts.

### 19.2 Labels

Icons should generally have visible text labels.

Exceptions are allowed in:

- dense mode;
- power-user contexts;
- small-screen contexts where space is constrained.

Even when visible labels are hidden, accessible labels must remain available to assistive technology.

---

## 20. Accessibility and Field Usability

### 20.1 Baseline

Meridian should follow WCAG 2.2 or the project-approved successor standard as its accessibility baseline.

### 20.2 Touch targets

Meridian does not define a larger formal minimum touch target than WCAG.

However, kiosk and touch surfaces should be designed with real field conditions in mind.

### 20.3 Keyboard navigation

Every UI PR must review keyboard navigation.

Interactive elements must be reachable, operable, and understandable without a mouse.

### 20.4 Focus states

Visible focus states are required.

Focus indicators must work in both light and dark mode.

### 20.5 Reduced motion

Reduced-motion support should be included from the beginning.

Motion should not be required to understand state changes.

### 20.6 Field conditions

Kiosk and operational surfaces should assume dust, glare, gloves, night operations, distraction, and shared workstations.

---

## 21. Required Framework-Neutral Components

Component names in this guide are framework-neutral. They do not assume Laravel, Vue, React, Blade, Livewire, or any specific implementation technology.

The following components are mandatory concepts in the Meridian UI system:

### 21.1 `AppTopBar`

Primary app shell bar containing the Meridian logo/Home control, current department context, and command palette access.

### 21.2 `ContextBar`

A contextual display of active organization, event, operations window, department, or role state when that information is needed outside the top bar.

### 21.3 `ActionBar`

Contextual bottom bar for operational and editing surfaces. Holds primary current-screen actions and may include secondary contextual actions.

### 21.4 `CommandPalette`

Keyboard-accessible command and navigation surface. Uses `Ctrl+K`, `Cmd+K`, and `/` when not typing in a field.

### 21.5 `DepartmentBadge`

Department identity component using logo, icon, short label, lettermark fallback, and small accent color treatment.

### 21.6 `StatusPill`

Restrained, text-forward status indicator. Uses canonical system status names.

### 21.7 `DataTable`

Dense, efficient tabular display for non-touch operational views.

### 21.8 `TouchCard`

Touch-friendly card pattern for touchscreen, mobile, and kiosk contexts.

### 21.9 `ConfirmationDialog`

Standard confirmation surface for destructive actions and other high-impact confirmations.

### 21.10 `HistoryDrawer`

Panel or drawer for audit history, field-change entries, and record timeline details that should not be shown inline by default.

### 21.11 `OfflineBanner`

Subtle contextual banner for offline and sync-affected organization/event contexts.

---

## 22. Button Hierarchy

Meridian buttons should follow a strict hierarchy:

1. Primary
2. Secondary
3. Tertiary
4. Destructive

Primary buttons should be used sparingly and should represent the main action for the current context.

Destructive buttons must be visually distinct and must route through a `ConfirmationDialog`.

---

## 23. Reusable Pattern Governance

One-off components are allowed when necessary.

Reusable patterns must be added to this guide or a connected UI pattern document before they become common implementation patterns.

Developers and LLM coding agents must not silently create competing patterns for navigation, actions, statuses, layout, alerts, cards, tables, forms, or offline/sync behavior.

---

## 24. LLM Development Rules

LLM coding agents working on Meridian UI must follow these rules:

1. Do not invent new colors.
2. Do not invent new statuses.
3. Do not rename canonical statuses.
4. Do not introduce new navigation models.
5. Do not introduce new reusable component patterns without updating the UI guide.
6. Do not hide required context such as organization, event, department, role, permission, or sync state when it affects the screen.
7. Do not use floating action buttons.
8. Do not add autosave to normal forms.
9. Do not make destructive actions immediate.
10. Do not create screen-specific visual systems that bypass semantic tokens.
11. Do not use department accent colors as full theme overrides.
12. Do not create separate volunteer-friendly labels for canonical statuses.
13. Do not interrupt users for sync failures unless the current action cannot continue.
14. Do not make kiosk/field surfaces mouse-only.
15. Do not rely on color alone to communicate state.

When unsure, coding agents should preserve existing patterns and ask for the guide to be amended rather than inventing a new pattern.

---

## 25. UI PR Review Criteria

### 25.1 Required review areas

Every UI PR must be reviewed for:

- adherence to this guide;
- keyboard navigation;
- focus states;
- visible labels;
- reduced-motion support;
- light mode behavior;
- dark mode behavior;
- permission behavior;
- loading, empty, error, and success behavior where relevant;
- offline or sync behavior where relevant;
- role/context clarity;
- destructive action confirmation;
- use of canonical status names.

### 25.2 Screenshots

Every UI PR does not automatically require screenshots for every mode, layout, and permission state.

Screenshots should be included when visual review is necessary to understand the change, when a reviewer requests them, or when the change affects major layout, visual hierarchy, dark mode, kiosk behavior, or state presentation.

### 25.3 Rejection criteria

A UI PR should be rejected or returned for revision if it introduces:

- new colors not defined by the style guide or semantic tokens;
- new statuses not defined by the requirements/specification;
- renamed canonical statuses;
- new navigation patterns not defined in this guide;
- floating action buttons;
- destructive actions without confirmation;
- hidden role/event/department context where that context matters;
- inaccessible icon-only controls;
- motion that cannot be reduced;
- kiosk or shift-board changes that have not been manually checked on touchscreen hardware when applicable.

### 25.4 Touchscreen QA

Kiosk and shift-board changes require manual QA on a touchscreen device.

Testing should include:

- tap accuracy;
- readability at expected distance;
- glare/night usability where practical;
- touch card layout;
- bottom action bar behavior;
- user switching and re-authentication paths;
- accidental action recovery.

### 25.5 Accessibility QA

Every UI PR requires accessibility review for:

- keyboard navigation;
- visible focus states;
- visible labels or accessible labels;
- reduced motion;
- non-color-only state communication;
- form error summaries;
- permission and disabled/hidden action clarity.

---

## 26. Glossary

### ActionBar

A contextual bottom bar used on operational and editing surfaces to expose primary current-screen actions.

### AppTopBar

The primary top app bar containing the Meridian logo/Home control, current department context, and command palette access.

### Command Palette

A keyboard-accessible command and navigation interface opened by `Ctrl+K`, `Cmd+K`, or `/` when not typing in a field.

### ContextBar

A contextual component showing active organization, event, department, role, operations window, or sync state when relevant.

### Department Accent

A small visual use of a department’s configured identity color. It is not a theme override.

### DepartmentBadge

A department identity component that may include a logo, icon, short label, accent, or generated lettermark.

### Dense Mode

A per-screen power-user toggle that reduces spacing and optional hints while preserving accessibility and critical context.

### Field-first

A design posture that assumes real operating conditions: touchscreens, shared devices, distraction, fatigue, dust, glare, gloves, and night work.

### HistoryDrawer

A panel or drawer used to show audit and record history without cluttering the main working surface.

### Kiosk Dashboard

The default surface for trusted on-site shared workstations.

### Operations Window

The time period when an event is operationally active and Meridian should prioritize live shifts, incidents, check-ins, deployments, and attention items.

### Quiet State

A reassuring dashboard state that confirms no current issue exists, such as “No active incidents.”

### StatusPill

A restrained, text-forward component for canonical system status names.

### TouchCard

A card pattern optimized for touch-enabled devices, including mobile and on-site touchscreen laptops.

---

## 27. Future Companion Documents

This guide intentionally does not define every screen in detail.

Potential companion documents include:

- Meridian Screen Surface Specification;
- Meridian Component Library Specification;
- Meridian Accessibility Checklist;
- Meridian Kiosk and Field Hardware UX Guide;
- Meridian IMS Surface Specification;
- Meridian Dashboard Widget Specification.

Those future documents should build on this guide rather than replacing it.
