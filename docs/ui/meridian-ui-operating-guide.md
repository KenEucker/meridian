# Meridian UI Operating Guide

Version: Draft 2  
Project: Meridian Volunteer Operations Platform  
Purpose: Define how Meridian UI should operate and look across product surfaces without fully specifying individual screens.  
Updated for: Policies & Procedures, reusable fragments, acknowledgments, document exports, and Alpha 1 technical constraints.

---

## 1. Purpose

This guide defines the Meridian UI operating model. It is intended for designers, developers, maintainers, reviewers, and LLM coding agents working on Meridian.

It does not define every screen in detail. Instead, it establishes shared rules for navigation, layout, interaction behavior, component usage, role-aware surfaces, offline behavior, kiosk behavior, accessibility, visual treatment, document governance, and PR review.

Detailed screen specifications may be created later as separate documents. Those documents must follow this guide unless this guide is intentionally amended.

---

## 2. Source Alignment

This version is aligned to:

- Meridian Requirements Document, Draft v0.3.
- Meridian Technical Specification, Draft 0.2.
- Meridian visual style guide.
- Prior UI operating decisions captured during discovery.

This guide intentionally does not replace requirements or technical architecture. If this guide conflicts with accepted requirements or technical specification, the conflict must be resolved by updating the relevant source document and then updating this guide.

### 2.1 Important Alpha 1 alignment notes

Policies and Procedures are now first-class Alpha 1 product areas. The UI must support policy documents, procedure documents, reusable fragments, policy/procedure acknowledgments, and exports.

Policy and procedure documents are separate product/domain types. They may share UI patterns, but the UI must not collapse them into one generic “document” concept where the distinction matters.

Fragments are reusable Markdown text objects referenced by policy/procedure documents. Fragment references render inline when viewing and remain visible as references when editing.

Policy/procedure acknowledgments happen during signup or training for Alpha 1. They are not direct shift-signup gates or credential gates.

There is a product/technical timing tension around policy/procedure packet assembly: requirements include manually assembled packets, while the technical specification treats packet assembly as post-Alpha 1. Until resolved, the UI should treat packet assembly as feature-gated and prioritize individual document export.

---

## 3. Product UI Personality

Meridian should feel like a **modern field operations dashboard** with a **command center** orientation.

The interface should be:

- simple and quick to understand;
- versatile enough for power users to work efficiently;
- operational rather than decorative;
- calm under pressure;
- suitable for field, kiosk, laptop, tablet, and mobile use;
- honest about volunteer operations terminology;
- clear about scope, role, permissions, offline state, and sync limitations;
- capable of presenting governance content without making it feel like HR software.

Meridian is not a glossy consumer app. It is field infrastructure for volunteer operations.

---

## 4. Relationship to the Meridian Style Guide

The Meridian visual style guide is the source of truth for brand identity, color palette, typography, and general visual personality.

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

UI implementation must use semantic design tokens rather than raw brand colors directly in component code.

Examples of required semantic token categories:

- `surface`
- `surface-muted`
- `surface-raised`
- `surface-inset`
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
- `document-policy`
- `document-procedure`
- `document-fragment`

Light and dark mode must use the same semantic token names.

---

## 5. Core Design Principles

### 5.1 Field-first clarity

Meridian must be usable by people who are distracted, tired, outside, on a shared workstation, or working during an active event.

Interfaces should prioritize immediate comprehension over cleverness.

### 5.2 Command-center awareness

Users should always understand:

- which organization they are operating in;
- which event they are operating in;
- which department context is active;
- which team context is relevant, when applicable;
- which role or permission level is enabling available actions;
- whether operations are inside an active event window;
- whether sync or offline state affects current actions.

### 5.3 Action-oriented surfaces

Dashboards and operational screens should prioritize what needs attention, what can be acted on, and what has changed.

Meridian should not show metrics only because they are available.

### 5.4 Correctable operational speed

Routine operational changes may save immediately after interaction when that improves speed. The UI must make mistakes easy to correct.

Destructive actions are different. They must always require a dedicated confirmation dialog.

### 5.5 Auditability without clutter

Audit history matters, but it should not overwhelm active work. History should usually live in a panel or drawer.

Incident timelines should hide routine field-change audit entries unless the user expands them.

Policy/procedure version history, acknowledgment history, export history, and fragment-driven version bumps should be visible to authorized maintainers without cluttering staff reading surfaces.

### 5.6 Governance without HR framing

Policies, procedures, waivers, trainings, and acknowledgments should be presented as staff governance and operational readiness, not as employee compliance management.

Use direct operational language. Avoid HR/personnel-file framing.

### 5.7 Consistency for humans and LLMs

Reusable UI patterns must be named and governed. Coding agents must not invent new navigation models, colors, statuses, or component patterns without updating this guide.

---

## 6. Theme Model

### 6.1 Light and dark mode

Light mode is the default. Light and dark mode must be developed in tandem and must work equally well.

Users must be able to:

- choose light mode manually;
- choose dark mode manually;
- follow system preference.

### 6.2 Dark mode behavior

Dark mode should support night operations by reducing harsh contrast intensity while preserving readability.

Dark mode must not rely on pure black backgrounds by default. It should use deep neutral surfaces, visible borders, restrained elevation, and clear focus states.

### 6.3 Department accent colors

Department accent colors are not full theme overrides. They are small accents used to identify department context.

Department accent colors must not replace Meridian’s core semantic tokens. The system should not automatically adjust department accent colors for contrast. Department colors should be selected responsibly and used sparingly so that poor contrast in an accent does not break the UI.

### 6.4 Document type accents

Policy documents, procedure documents, and fragments may use subtle document-type accents to improve recognition.

Document type accents must be secondary to scope, state, and permission clarity. They must not resemble urgency or incident severity colors.

---

## 7. App Shell and Navigation Model

### 7.1 Overall shell

Meridian should use a top bar and, when contextually appropriate, a bottom action bar.

This expectation applies to larger screens as well as mobile and touch surfaces.

The app shell should avoid a persistent left sidebar as the primary navigation model.

### 7.2 Top bar

The top bar must prioritize:

1. the Meridian logo;
2. current department context when inside a department surface;
3. command palette/search access.

The Meridian logo functions as the universal Home control.

The top bar must not be used for organization or event switching. Organization and event switching should happen from the home screen or another dedicated context-switching surface.

### 7.3 Bottom bar

The bottom bar is not required on every screen.

It should appear where it makes sense for the current context, especially on operational or editing surfaces. It should primarily hold current-screen actions, though it may contain a mix of contextual actions and navigation when appropriate.

Primary actions should always appear in the bottom bar once the user is inside an operational or editing surface, but the bottom bar does not have to be the only place those actions appear.

### 7.4 Task-based home

Both mobile and larger screen experiences should use a task-based home screen.

The home screen should guide the user toward the work they are likely to perform, based on organization, event, department assignment, team assignment, role, active event window, and permissions.

### 7.5 Merged navigation

Users with multiple roles should generally see one merged navigation experience.

Role and context switching must still be obvious. The UI should make clear why a user can see or perform a given action.

### 7.6 Department navigation

Departments are first-class navigational spaces.

Users should only see departments they are assigned to.

Users should be able to quickly jump between assigned departments from the top bar or nearby department context controls.

### 7.7 Policies & Procedures navigation

Policies & Procedures should appear as a first-class task area for users who have visible published documents.

The entry point should be available from:

- the task-based home screen when visible documents exist;
- department contexts when department/team documents are visible;
- signup and training flows when acknowledgment is required;
- command palette navigation for all users with visible documents.

Maintainer actions for policy/procedure documents and fragments should appear only for users whose organizer, department lead, or team lead scope grants authority.

---

## 8. Organization, Event, Department, and Team Context

### 8.1 Required operating scope

Meridian should generally operate inside a selected organization and event.

The UI must make the current organization/event scope clear. The active event operations window should be visible where relevant, for example: operations active, upcoming, closed, or active until a stated date/time.

### 8.2 Event operations window

If the selected event is within its operations window, the UI should surface operationally relevant content, such as current shifts, check-in status, active incidents, coverage issues, and urgent alerts.

If the event is outside the operations window, the UI should emphasize planning, review, reports, policies/procedures, training, signup, and upcoming responsibilities.

### 8.3 Department identity

Each department may define:

- logo;
- primary accent color;
- icon;
- short label;
- description.

Department branding should appear wherever the department appears, but only as small accents. It must not override the Meridian interface.

If a department has no logo, the UI should generate a default lettermark-style icon using basic colors and initials or letters from separate words in the department name.

### 8.4 Team context

Teams replace the earlier concept of roles in the operational model.

Team context should be visible when it affects:

- shift eligibility;
- required trainings;
- system authority;
- policy/procedure scope;
- leadership responsibility;
- Incident Command role grants.

Team labels should preserve historical names where records refer to past work.

### 8.5 IC department treatment

The configured Incident Command department does not receive a unique brand treatment beyond normal department branding.

Incident and Field Report surfaces still use a more serious and restricted interface tone, but that tone comes from the surface type, not a special IC theme.

---

## 9. Role-Aware Home and Dashboard Model

### 9.1 Staff home

A regular staff member should see departments they are assigned to as prominent icons or cards.

If the event is currently within its operations window and the staff member is signed up for shifts, the staff member's schedule should appear below the department icons.

Staff should also see required policy/procedure acknowledgments where relevant to signup or training. These should be presented as readiness or onboarding work, not as shift or credential blockers.

### 9.2 Department lead home

A department lead should see department-relevant dashboard widgets first.

Below those widgets, the department lead should see the same general structure as a staff member: assigned departments and current event schedule.

Department leads may also see department-scoped policy/procedure maintenance tasks, fragment warnings, draft documents, and required acknowledgment configuration where applicable.

### 9.3 Team lead home

A team lead should see team-scoped responsibilities inside the relevant department context.

Team-scoped policy/procedure authoring and fragment maintenance should appear as contextual management actions, not as a separate global admin system.

### 9.4 Shift lead home

A shift lead should start from the same task-based home model as other users.

Shift lead actions should become available through relevant department, team, shift, and operational contexts rather than through a wholly separate home experience.

### 9.5 Organizer home

Organizers should see widgets relevant at the organization level, or event-level widgets when an event is within its operations window.

Organizer dashboards may include organization-scoped policy/procedure document status, draft/published/archive counts, required acknowledgment status summaries, and fragment-change warnings.

### 9.6 IC lead home

IC leads should see event operations and department-relevant widgets, including active incident counts, high-importance incidents, monitoring/on-scene incidents, and other attention items.

Policy/procedure content relevant to incident escalation may appear as quick reference links, but IMS must remain focused on incident operations.

### 9.7 Dashboard widget model

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

Staff may see both department-membership alerts and shift-related alerts.

Policy/procedure widgets should focus on required reading, acknowledgments, draft/publish work, fragment impacts, and export actions. They should not dominate active event operational dashboards unless there is a required action.

---

## 10. Command Palette

### 10.1 Purpose

Global search should behave more like a command palette than a generic item search.

The command palette should help users navigate, switch context, and perform allowed actions quickly.

### 10.2 Shortcuts

The default keyboard shortcut is:

- `Ctrl+K` on Windows/Linux;
- `Cmd+K` on macOS.

The `/` key should also open the command palette when the user is not typing in a field.

### 10.3 Content

The command palette should include actions and navigation.

IC roles may also see records, such as incidents, field reports, or other IC-visible records.

For permitted IMS records, search may match Name Reference text with or without the `@` operator where supported by the search implementation. Clicking a Name Reference chip runs normal search for the reference text without the `@` prefix and must not open a dedicated profile/detail page.

Policy/procedure visible documents may appear as navigation results by title for users with visibility. Full-text command palette search inside policy/procedure bodies or fragments is not part of Alpha 1.

Command palette results should be grouped by type.

Unavailable actions should be hidden.

### 10.4 Permissions

Command palette results must respect the user’s permissions, current organization, current event, current department, current team where applicable, and role context.

### 10.5 Kiosk mode

The command palette should be available in kiosk mode, but only with actions and navigation appropriate to the authenticated user and trusted workstation state.

### 10.6 Shortcut display

Command palette actions should show keyboard shortcuts where available, except in dense mode.

---

## 11. Kiosk and Shared Workstation Behavior

### 11.1 Default kiosk surface

The on-site laptop should default to a kiosk dashboard.

Kiosk mode should hide admin and navigation complexity by default.

### 11.2 Authentication

On-site shared workstations should support PIN-like re-authentication.

Central access should use provider login and magic link authentication.

### 11.3 Self check-in

Staff should not self check-in or self check-out unless they are department leads or shift leads.

### 11.4 Field assumptions

Kiosk mode should assume:

- touchscreen laptops;
- gloves;
- dust;
- glare;
- night operations;
- distracted users;
- shared trusted devices;
- fast user switching.

### 11.5 Touch layout

Touch-enabled devices, including deployed on-site laptops, should receive touch-appropriate layouts automatically.

### 11.6 Policies & Procedures on shared workstations

Policy/procedure viewing may be available on shared workstations when the active user has access.

Acknowledgment flows on shared workstations must make the active user highly visible. Acknowledgment actions must not be available when the workstation is locked or when the active user is ambiguous.

---

## 12. Action Model

### 12.1 Action placement

Primary actions should appear in the bottom action bar on operational and editing surfaces.

They may also appear inline or near related content when that improves comprehension.

### 12.2 Destructive actions

Destructive actions must always require a dedicated confirmation dialog.

Destructive actions include, but are not limited to:

- deleting;
- removing;
- blocking;
- DNS-related changes;
- destructive incident changes;
- archiving published policy/procedure documents;
- changing fragments that affect published documents;
- actions that strike or invalidate historical records;
- any action that cannot be easily corrected.

### 12.3 Confirmation dialog format

Confirmation dialogs must follow a standard structure:

1. clear title;
2. concise explanation;
3. impact statement;
4. destructive or confirming action;
5. cancel action.

The confirming action must use a specific verb, not a vague label such as “OK.”

### 12.4 Routine operational changes

Routine operational actions do not need confirmation every time.

Examples include:

- check-in;
- check-out;
- deployment changes;
- equipment return;
- attaching a Field Report;
- routine state changes that are easy to correct.

These may save immediately after interaction, but the UI must provide an easy way to fix mistakes.

### 12.5 Floating action buttons

Floating action buttons should be avoided.

Meridian should rely on clear action bars, inline actions, command palette actions, and contextual controls.

### 12.6 Autosave

The incident create/edit form is the only autosaving form.

The incident create/edit form must autosave every change.

Other forms should generally use explicit Save/Cancel behavior, except for routine operational interactions that save immediately after the interaction and remain easy to correct.

Policy/procedure Markdown authoring, fragment editing, and acknowledgment forms must not autosave unless this guide is amended. Use explicit Save, Publish, Archive, Export, or Acknowledge actions.

---

## 13. Layout and Density

### 13.1 Non-touch devices

On non-touch desktop and laptop devices, rosters and shift boards should be table-first.

Tables should support efficient scanning, sorting, filtering, status recognition, and bulk or row-level action patterns where appropriate.

### 13.2 Touch-enabled devices

On touch-enabled devices, rosters and shift boards should become card-first automatically.

Touch cards should use larger controls, clear labels, and enough spacing to reduce mis-taps in field conditions.

### 13.3 Dense mode

Dense mode should be available for power users as a per-screen toggle.

Dense mode may reduce spacing, hide nonessential helper text, and hide visible keyboard shortcut hints.

Dense mode must not remove required labels, destroy keyboard accessibility, or hide critical state information.

### 13.4 Empty states

Empty states should be utilitarian by default.

A teach mode toggle may expose more explanatory guidance for users who need help learning the system.

### 13.5 Document layout

Policy/procedure reading surfaces should prioritize readability over density.

Document lists may use compact tables for maintainers and cards/lists for staff. Document bodies should use a readable line length, clear headings, and restrained metadata.

---

## 14. Forms and Validation

### 14.1 Form structure

Long forms should generally be single-page.

Forms should use consistent field layout, clear labels, predictable error placement, and a top-level error summary.

### 14.2 Validation timing

Validation should happen on blur where it makes sense.

Submit-time validation must exist everywhere.

As-you-type validation should be used sparingly and only when it clearly helps.

### 14.3 Required fields

Required fields should be marked explicitly but not glaringly.

### 14.4 Field Reports and Incident forms

Field Reports should carry IMS seriousness.

Field Report and Incident forms should use the same general form language, while IMS surfaces remain visually more serious and restricted.

### 14.5 Incident notes

Incident notes are edited as plain text.

Markdown formatting may be supported while editing, but formatting should not render until after submission.

Incident notes and Field Report text may render Name References as visually distinct inline `@name` markers after submission. Name References must not visually imply Meridian user mentions, notifications, volunteer profile links, or identity records.

### 14.6 Policy/procedure forms

Policy/procedure document forms should be plain, structured, and reviewable.

Required fields include at minimum:

- document type: Policy or Procedure;
- title;
- scope: Organization, Department, or Team;
- state: Draft, Published, or Archived;
- Markdown body;
- fragment references where used.

Policy/procedure forms should validate broken fragment references before publish.

Policy/procedure forms should present state transitions explicitly. Publishing and archiving are not routine autosave events.

### 14.7 Fragment forms

Fragment forms should be simple and explicit.

Required fields include at minimum:

- fragment name;
- scope: Organization, Department, or Team;
- Markdown body.

Fragment edit forms must show current version and which published documents reference the fragment before the edit is saved.

If editing a fragment will bump versions for published documents, the UI must show that impact before save.

---

## 15. Tables, Cards, and Dashboard Components

### 15.1 Tables

Tables are preferred for dense operational views on non-touch devices.

Tables should be restrained, functional, and not overly decorative.

### 15.2 Cards

Cards are preferred for dashboards, touch layouts, mobile layouts, and high-level summaries.

Cards may use rounded corners and soft depth, especially outside forms and tables.

### 15.3 Forms and tables visual style

Forms and tables should be flatter and more utilitarian than dashboards or overview cards.

### 15.4 Charts

Charts may be used in dashboards where they clarify attention or operational readiness.

IMS screens should favor lists, timelines, and tables over charts or visual widgets.

Policy/procedure surfaces should generally avoid charts unless showing maintainer/admin summaries. Staff-facing document reading surfaces should not use charts as decoration.

---

## 16. Status, Severity, and State Language

### 16.1 Canonical names

UI labels should use exact system status names from the requirements and technical specification.

The UI should not replace canonical system names with friendlier labels.

### 16.2 Serious states

Serious states require special visual treatment.

Examples include:

- DNS;
- Removed;
- Suspended;
- Blocked;
- Dishonorably Discharged;
- Deleted/Stricken;
- Revoked;
- Archived where the user might confuse archived content with active policy.

Special treatment may include stronger borders, restricted-state iconography, restrained critical color, locked affordances, or stronger typography.

### 16.3 Color usage

Color should be reserved primarily for urgency and state.

Department identity may use color only as small accents.

### 16.4 Severity and attention scale

Meridian should define a standard severity or attention scale for dashboard widgets and alerts.

The scale should distinguish routine information, attention needed, warning, critical, and restricted/security-sensitive states.

### 16.5 IMS priority labels

IMS priority labels should be visually distinct from normal statuses.

Priority labels should not be confused with incident state, staff status, credential status, or shift attendance flags.

### 16.6 Status visuals

The guide does not need to exhaustively map every status to a visual treatment in this version.

Status and state visuals should be defined generally and consistently, with future detailed mappings added when needed.

### 16.7 Policy/procedure document states

Policy/procedure document state labels must use canonical names:

- Draft
- Published
- Archived

There is no Active state.

Fragments do not have Draft, Published, or Archived states for Alpha 1. Fragment UI must not imply a lifecycle state that does not exist.

---

## 17. Offline and Sync UI Rules

### 17.1 Offline banner

Offline banners should be subtle.

They should appear inside affected organization/event contexts rather than globally everywhere.

### 17.2 Affected actions

When offline or partially connected, unavailable actions should be disabled or hidden.

Unavailable actions should not pretend to be usable.

### 17.3 Connectivity distinctions

The UI should distinguish between relevant connectivity and sync states by affecting available actions and status indicators.

States may include:

- offline but usable;
- local node reachable;
- central unreachable;
- sync conflict;
- sync failed;
- server connection required.

These states should be visible only where they matter.

### 17.4 Sync failure behavior

Sync failures should never interrupt the user unless the current action cannot continue.

Sync repair belongs in advanced mode only.

Queued actions should be visible to the user who performed them when appropriate.

### 17.5 Policy/procedure offline rules

Published policy/procedure documents visible to the active user should be available offline where synced.

Published fragments referenced by visible synced documents should be available offline where synced.

Draft and archived documents/fragments should only sync to users allowed to edit or maintain them.

Policy/procedure acknowledgments are not creatable offline in Alpha 1. When a user reaches an acknowledgment action without server connection, the UI should clearly disable the action and state that server connection is required.

Policy/procedure authoring, fragment editing, publishing, archiving, and export actions require server connection unless a later guide explicitly allows offline authoring.

### 17.6 Active event window governance locks

During the active event window, policy/procedure document edits and fragment edits are blocked.

The UI must show these as locked/unavailable rather than allowing edits that will later fail.

Fragment changes during an active event are disallowed. The UI should explain that this prevents silent document version bumps during operations.

---

## 18. Permissions and Visibility

### 18.1 Hidden unavailable actions

Unavailable actions should generally be hidden.

The UI should avoid presenting actions that a user cannot perform, unless showing them disabled with explanation is specifically useful in a high-context admin surface.

### 18.2 Permission denied pages

Permission-denied behavior depends on the user type.

For elevated users, the page may explain which role is required.

For default staff, the page should simply indicate that access is restricted.

### 18.3 Operational terminology

Staff-facing UI should not hide operational or administrative terminology where that terminology is accurate and useful.

Meridian should not over-simplify language in a way that makes operations less clear.

### 18.4 Audit/history placement

Audit and history should usually be tucked into a history panel or drawer rather than displayed inline by default.

### 18.5 Policy/procedure visibility

Policy/procedure documents must follow their scope.

Organization-scoped published documents are visible to everyone in the organization.

Department-scoped published documents are visible to members of the department.

Team-scoped published documents are visible to members of the team, with leadership visibility according to department/team leadership scope.

Policy/procedure documents should not be generally public-facing before login except as part of staff signup.

Organizers cannot edit department-scoped or team-scoped policy/procedure documents by default.

Maintainer actions should be visible only to users with scope-appropriate authority:

- organizers for organization-scoped documents/fragments;
- department leads for department-scoped documents/fragments;
- team leads for team-scoped documents/fragments.

---

## 19. Incident Management UI Rules

### 19.1 Visual tone

IMS screens should feel more serious and restricted than normal staff and shift surfaces.

They should use minimal color variation outside of status, priority, and restricted-state indicators.

### 19.2 Surface patterns

IMS should favor:

- lists;
- timelines;
- tables;
- plain text notes;
- metadata chips for tags and Name References;
- restrained status indicators;
- clear audit access.

IMS should avoid unnecessary charts and decorative visual widgets.

### 19.3 Autosave

The incident create/edit screen is a single autosaving form.

Every change must autosave.

### 19.4 Timeline behavior

Incident timelines should focus on meaningful operational entries.

Routine field-change audit entries should be hidden unless expanded.

Incident-level Name Reference chips should appear near existing incident tags or metadata when extracted from incident notes or attached Field Reports. They should be visually distinct from `#tags`, and clicking one should run normal permission-filtered search.

### 19.5 Speed of actions

Incident actions should not be deliberately slower than normal operational actions.

The seriousness of IMS should come from clarity, permissions, auditability, and visual restraint, not artificial friction.

### 19.6 Policy/procedure cross-links

IMS may link to relevant published procedure documents, such as incident escalation procedures, if visible to the active user.

IMS must not become a document browser. Procedure links should be contextual and operationally useful.

---

## 20. Policies, Procedures, and Fragments UI Rules

### 20.1 Product posture

Policies and Procedures are governance and operational reference content.

They should feel like part of Meridian’s volunteer operations platform, not like a generic document management system and not like HR compliance software.

The UI must support:

- policy documents;
- procedure documents;
- reusable document fragments;
- fragment references inside documents;
- document acknowledgments during signup or training;
- PDF export;
- Markdown export;
- offline viewing of synced visible published documents;
- maintainer authoring for authorized organizers, department leads, and team leads.

### 20.2 Information architecture

The user-facing area should be named **Policies & Procedures**.

Documents should be distinguishable by type:

- Policy
- Procedure

Policy and Procedure are separate types. Do not use a single generic “Document” label when the user needs to know which kind of document they are viewing or maintaining.

Fragments are maintainer-facing reusable content. Regular staff should not browse fragments as separate content unless a future feature specifically requires it.

### 20.3 Staff document list

The staff-facing document list should show visible published documents only.

The list should support title search only for Alpha 1.

The list should make these attributes clear without visual clutter:

- title;
- type: Policy or Procedure;
- scope: Organization, Department, or Team;
- relevant department/team, if scoped below organization;
- acknowledgment status, if required;
- updated or published date where useful.

Full-text body search is not included in Alpha 1.

### 20.4 Maintainer document list

Maintainers may see Draft, Published, and Archived documents they are allowed to maintain.

Maintainer lists should support filtering by:

- type;
- state;
- scope;
- department/team where applicable;
- acknowledgment requirement where applicable.

Maintainer lists may use table-first layout on non-touch devices.

### 20.5 Document viewer

The document viewer should render Markdown as readable content.

Fragment references must render inline as normal document text.

The viewer should show document metadata subtly, preferably near the bottom or in a metadata drawer:

- document type;
- title;
- scope;
- version;
- state when relevant;
- last published/updated timestamp where useful.

The viewer does not need to show a special notice that the document includes automatically updated fragments.

### 20.6 Document version display

Policy/procedure versions use the format:

```text
document_revision.fragment_revision
```

Examples:

```text
1.00
1.01
2.00
```

The version should be visible but not visually dominant on staff reading surfaces.

Maintainer surfaces should make version more prominent when it affects acknowledgment history, export history, or fragment-driven changes.

### 20.7 Document editor

The document editor should be a Markdown source editor with preview, not a rich text editor.

The supported authoring model is Markdown text plus fragment reference tokens.

The editor must show fragment references as references during editing, not as silently expanded inline text.

For each fragment reference, the editor should show:

- human-friendly fragment name;
- referenced fragment version;
- scope;
- broken/missing status if invalid.

Broken fragment references must block publish.

### 20.8 Fragment insertion

Fragment insertion should be deliberate and discoverable.

The fragment picker should show only fragments that are valid for the current document scope.

The picker should show:

- fragment name;
- scope;
- current version;
- short preview or first line where useful.

Internally, references should use UUIDs. The UI should show human-friendly names.

### 20.9 Fragment manager

Fragment manager surfaces are maintainer-facing.

A fragment list should show:

- fragment name;
- scope;
- version;
- last updated timestamp;
- number of referencing published documents where available.

Fragment detail should show which documents reference the fragment before editing.

When editing a fragment affects published documents, the UI must warn:

```text
Editing this fragment will bump versions for N published documents.
```

The warning must appear before save. The save action should use a specific verb such as **Save Fragment Changes**.

### 20.10 Fragment editing

Fragments support Markdown only.

Fragments may not reference other fragments.

Nested fragments are prohibited.

Fragment edit forms should validate and prevent nested fragment references before save.

Fragments do not have Draft, Published, or Archived states in Alpha 1.

### 20.11 Acknowledgment surfaces

Policy/procedure acknowledgments occur only during staff signup or training for Alpha 1.

Acknowledgment UI must show:

- document title;
- document type;
- document version being acknowledged;
- scope;
- readable rendered content;
- the active user;
- the acknowledgment action.

Acknowledgment copy should be direct and operational. It should not use legalistic language unless the document itself requires it.

Acknowledgment creation requires server connection. If the server is unavailable, the acknowledgment action must be disabled with a clear explanation.

Acknowledgment UI must not imply that acknowledgment is a direct shift-signup gate or credential-eligibility gate for Alpha 1.

Staff may see acknowledgment status for required documents.

### 20.12 Export surfaces

PDF and Markdown export actions are server-generated.

Export UI must communicate that exports render fragment text inline.

Exports should include:

- document type;
- document title;
- document version;
- scope;
- export timestamp.

Export/print events are audit events.

Policy/procedure packet assembly should remain feature-gated until the requirements/technical timing conflict is resolved. If packet export is implemented in Alpha 1, it should be a simple manual ordered selection flow and must not include staff acknowledgment status.

### 20.13 Orchid/admin behavior

Orchid is the trusted admin/god-mode data administration interface. User-facing policy/procedure workflows should be separate from Orchid where they are normal organizer, department lead, or team lead tasks.

Orchid policy/procedure screens should support:

- list search/filtering;
- rendered preview with fragments inline;
- clear scope display;
- document state display;
- fragment reference visibility;
- document acknowledgment records;
- export/print events where useful.

Dangerous document actions require reason/comment.

Orchid does not need separate document-version or fragment-version CRUD screens in Alpha 1.

### 20.14 Active event behavior

Policy/procedure document edits and fragment edits are blocked during the active event window.

The UI should make locked governance content clear. This is not an error state; it is an event-authority safeguard.

Acknowledgments collected on-site during signup or training may sync back to central.

---

## 21. Iconography and Labels

### 21.1 Icon usage

Meridian should use icons heavily.

Icons support fast recognition in field and command-center contexts.

### 21.2 Labels

Icons should generally have visible text labels.

Exceptions are allowed in:

- dense mode;
- power-user contexts;
- small-screen contexts where space is constrained.

Even when visible labels are hidden, accessible labels must remain available to assistive technology.

### 21.3 Document icons

Policy, Procedure, Fragment, Acknowledgment, PDF Export, and Markdown Export may each have recognizable icons.

These icons must not replace visible labels except in dense/power-user or constrained small-screen contexts.

---

## 22. Accessibility and Field Usability

### 22.1 Baseline

Meridian should follow WCAG 2.2 or the project-approved successor standard as its accessibility baseline.

### 22.2 Touch targets

Meridian does not define a larger formal minimum touch target than WCAG.

However, kiosk and touch surfaces should be designed with real field conditions in mind.

### 22.3 Keyboard navigation

Every UI PR must review keyboard navigation.

Interactive elements must be reachable, operable, and understandable without a mouse.

### 22.4 Focus states

Visible focus states are required.

Focus indicators must work in both light and dark mode.

### 22.5 Reduced motion

Reduced-motion support should be included from the beginning.

Motion should not be required to understand state changes.

### 22.6 Field conditions

Kiosk and operational surfaces should assume dust, glare, gloves, night operations, distraction, and shared workstations.

### 22.7 Document accessibility

Policy/procedure reading surfaces must be accessible.

Markdown rendering must preserve semantic headings, lists, links, blockquotes, and code blocks.

Document viewers must support keyboard scrolling/navigation, readable line length, visible focus for links/actions, and sufficient contrast in both light and dark mode.

Acknowledgment controls must be keyboard-operable and must not rely on color alone.

---

## 23. Required Framework-Neutral Components

Component names in this guide are framework-neutral. They do not assume Laravel, Vue, React, Blade, Livewire, or any specific implementation technology.

The following components are mandatory concepts in the Meridian UI system.

### 23.1 `AppTopBar`

Primary app shell bar containing the Meridian logo/Home control, current department context, and command palette access.

### 23.2 `ContextBar`

A contextual display of active organization, event, operations window, department, team, role state, or sync state when that information is needed outside the top bar.

### 23.3 `ActionBar`

Contextual bottom bar for operational and editing surfaces. Holds primary current-screen actions and may include secondary contextual actions.

### 23.4 `CommandPalette`

Keyboard-accessible command and navigation surface. Uses `Ctrl+K`, `Cmd+K`, and `/` when not typing in a field.

### 23.5 `DepartmentBadge`

Department identity component using logo, icon, short label, lettermark fallback, and small accent color treatment.

### 23.6 `TeamBadge`

Team identity component used where team membership affects eligibility, authority, document scope, shift context, or IC access.

### 23.7 `StatusPill`

Restrained, text-forward status indicator. Uses canonical system status names.

### 23.8 `DataTable`

Dense, efficient tabular display for non-touch operational views.

### 23.9 `TouchCard`

Touch-friendly card pattern for touchscreen, mobile, and kiosk contexts.

### 23.10 `ConfirmationDialog`

Standard confirmation surface for destructive actions and other high-impact confirmations.

### 23.11 `HistoryDrawer`

Panel or drawer for audit history, field-change entries, and record timeline details that should not be shown inline by default.

### 23.12 `OfflineBanner`

Subtle contextual banner for offline and sync-affected organization/event contexts.

### 23.13 `DocumentList`

List/table pattern for visible policy/procedure documents.

Staff-facing versions show published visible documents only. Maintainer versions may show Draft, Published, and Archived documents according to permissions.

### 23.14 `DocumentViewer`

Readable Markdown rendering component for policy/procedure documents with inline fragment rendering and subtle metadata.

### 23.15 `MarkdownEditor`

Plain Markdown authoring component for policy/procedure documents and fragments. It should support preview but must preserve source editing.

### 23.16 `FragmentReference`

Component for displaying a fragment reference while editing, including name, scope, version, and broken-reference status.

### 23.17 `FragmentPicker`

Maintainer-facing component for selecting valid fragments for a document based on scope.

### 23.18 `VersionBadge`

Subtle version display for policy/procedure documents and fragments.

### 23.19 `AcknowledgmentStatus`

Component showing whether a required policy/procedure document has been acknowledged and which version was acknowledged where relevant.

### 23.20 `ExportAction`

Server-generated export control for PDF and Markdown exports.

---

## 24. Button Hierarchy

Meridian buttons should follow a strict hierarchy:

1. Primary
2. Secondary
3. Tertiary
4. Destructive

Primary buttons should be used sparingly and should represent the main action for the current context.

Destructive buttons must be visually distinct and must route through a `ConfirmationDialog`.

Document authoring actions should use specific verbs:

- Save Draft
- Publish
- Archive
- Restore Draft, if supported
- Save Fragment Changes
- Acknowledge
- Export PDF
- Export Markdown

Avoid vague action labels such as OK, Submit, or Continue when a more specific action is available.

---

## 25. Reusable Pattern Governance

One-off components are allowed when necessary.

Reusable patterns must be added to this guide or a connected UI pattern document before they become common implementation patterns.

Developers and LLM coding agents must not silently create competing patterns for navigation, actions, statuses, layout, alerts, cards, tables, forms, document readers, Markdown editors, fragment references, acknowledgments, exports, or offline/sync behavior.

---

## 26. LLM Development Rules

LLM coding agents working on Meridian UI must follow these rules:

1. Do not invent new colors.
2. Do not invent new statuses.
3. Do not rename canonical statuses.
4. Do not introduce new navigation models.
5. Do not introduce new reusable component patterns without updating the UI guide.
6. Do not hide required context such as organization, event, department, team, role, permission, or sync state when it affects the screen.
7. Do not use floating action buttons.
8. Do not add autosave to normal forms.
9. Do not make destructive actions immediate.
10. Do not create screen-specific visual systems that bypass semantic tokens.
11. Do not use department accent colors as full theme overrides.
12. Do not create separate staff-friendly labels for canonical statuses.
13. Do not interrupt users for sync failures unless the current action cannot continue.
14. Do not make kiosk/field surfaces mouse-only.
15. Do not rely on color alone to communicate state.
16. Do not collapse Policy and Procedure into one generic document type where the distinction matters.
17. Do not render fragment references inline while editing; show the reference and version.
18. Do not show fragment references as raw tokens while viewing; render fragment text inline.
19. Do not add full-text policy/procedure body search for Alpha 1 unless the technical spec is amended.
20. Do not allow policy/procedure acknowledgments offline in Alpha 1.
21. Do not imply policy/procedure acknowledgments directly gate shift signup or credentials in Alpha 1.
22. Do not add Draft/Published/Archived states to fragments in Alpha 1.
23. Do not allow nested fragments.
24. Do not hide active-event governance locks.
25. Do not implement policy/procedure packet assembly unless the requirements/technical timing conflict has been resolved.

When unsure, coding agents should preserve existing patterns and ask for the guide to be amended rather than inventing a new pattern.

---

## 27. UI PR Review Criteria

### 27.1 Required review areas

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

### 27.2 Screenshots

Every UI PR does not automatically require screenshots for every mode, layout, and permission state.

Screenshots should be included when visual review is necessary to understand the change, when a reviewer requests them, or when the change affects major layout, visual hierarchy, dark mode, kiosk behavior, or state presentation.

### 27.3 Rejection criteria

A UI PR should be rejected or returned for revision if it introduces:

- new colors not defined by the style guide or semantic tokens;
- new statuses not defined by the requirements/specification;
- renamed canonical statuses;
- new navigation patterns not defined in this guide;
- floating action buttons;
- destructive actions without confirmation;
- hidden role/event/department/team context where that context matters;
- inaccessible icon-only controls;
- motion that cannot be reduced;
- kiosk or shift-board changes that have not been manually checked on touchscreen hardware when applicable;
- policy/procedure authoring that does not show fragment references and versions while editing;
- document viewing that does not render fragments inline;
- acknowledgment actions that are available while offline;
- policy/procedure editing during active event window;
- fragment editing during active event window;
- nested fragments;
- full-text document search in Alpha 1 without a spec update;
- packet assembly implemented without resolving requirements/technical alignment.

### 27.4 Touchscreen QA

Kiosk and shift-board changes require manual QA on a touchscreen device.

Testing should include:

- tap accuracy;
- readability at expected distance;
- glare/night usability where practical;
- touch card layout;
- bottom action bar behavior;
- user switching and re-authentication paths;
- accidental action recovery.

### 27.5 Accessibility QA

Every UI PR requires accessibility review for:

- keyboard navigation;
- visible focus states;
- visible labels or accessible labels;
- reduced motion;
- non-color-only state communication;
- form error summaries;
- permission and disabled/hidden action clarity.

### 27.6 Policies & Procedures QA

Policies & Procedures UI changes must be reviewed for:

- Policy and Procedure type separation;
- canonical document states: Draft, Published, Archived;
- absence of fragment lifecycle states;
- scope visibility: Organization, Department, Team;
- Markdown-only authoring behavior;
- raw HTML disallowed/sanitized behavior where rendered;
- fragment reference validation before publish;
- fragment version visibility while editing;
- inline fragment rendering while viewing;
- document version display using `document_revision.fragment_revision`;
- acknowledgment only during signup or training;
- server connection required for acknowledgment;
- export metadata and inline fragment rendering;
- active-event lock behavior;
- title-only search in Alpha 1.

---

## 28. Glossary

### AcknowledgmentStatus

A component showing whether a required policy/procedure document has been acknowledged and which version was acknowledged where relevant.

### ActionBar

A contextual bottom bar used on operational and editing surfaces to expose primary current-screen actions.

### AppTopBar

The primary top app bar containing the Meridian logo/Home control, current department context, and command palette access.

### Command Palette

A keyboard-accessible command and navigation interface opened by `Ctrl+K`, `Cmd+K`, or `/` when not typing in a field.

### ContextBar

A contextual component showing active organization, event, department, team, role, operations window, or sync state when relevant.

### Department Accent

A small visual use of a department’s configured identity color. It is not a theme override.

### DepartmentBadge

A department identity component that may include a logo, icon, short label, accent, or generated lettermark.

### Dense Mode

A per-screen power-user toggle that reduces spacing and optional hints while preserving accessibility and critical context.

### Document Fragment

A reusable named Markdown text object referenced by policy and procedure documents. Fragments have versions but no Draft/Published/Archived state in Alpha 1.

### DocumentList

A list/table pattern for browsing visible policy/procedure documents.

### DocumentViewer

A Markdown reading component that renders policy/procedure documents with fragment text inline.

### ExportAction

A control for server-generated PDF or Markdown exports.

### Field-first

A design posture that assumes real operating conditions: touchscreens, shared devices, distraction, fatigue, dust, glare, gloves, and night work.

### FragmentPicker

A maintainer-facing component for selecting valid reusable fragments based on document scope.

### FragmentReference

A component showing a referenced fragment while editing, including name, scope, version, and validity.

### HistoryDrawer

A panel or drawer used to show audit and record history without cluttering the main working surface.

### Kiosk Dashboard

The default surface for trusted on-site shared workstations.

### MarkdownEditor

A source-based Markdown editor for policy/procedure documents and fragments.

### Operations Window

The time period when an event is operationally active and Meridian should prioritize live shifts, incidents, check-ins, deployments, and attention items.

### Policy Document

A Markdown governance document describing expectations, rules, agreements, or policy. It may reference reusable fragments.

### Procedure Document

A Markdown governance document describing how operational work should be performed. It may reference reusable fragments.

### Quiet State

A reassuring dashboard state that confirms no current issue exists, such as “No active incidents.”

### StatusPill

A restrained, text-forward component for canonical system status names.

### TeamBadge

A team identity component used when team membership affects eligibility, authority, document scope, or operational context.

### TouchCard

A card pattern optimized for touch-enabled devices, including mobile and on-site touchscreen laptops.

### VersionBadge

A subtle component for showing policy/procedure document versions or fragment versions.

---

## 29. Future Companion Documents

This guide intentionally does not define every screen in detail.

Potential companion documents include:

- Meridian Screen Surface Specification;
- Meridian Policies & Procedures Surface Specification;
- Meridian Component Library Specification;
- Meridian Accessibility Checklist;
- Meridian Kiosk and Field Hardware UX Guide;
- Meridian IMS Surface Specification;
- Meridian Dashboard Widget Specification.

Those future documents should build on this guide rather than replacing it.
