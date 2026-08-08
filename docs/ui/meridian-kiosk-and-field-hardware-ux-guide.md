# Meridian Kiosk and Field Hardware UX Guide

Version: Draft 2
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define Meridian UX expectations for Meridian Kiosk, shared workstations, touch laptops, and Field hardware.

---

## 1. Purpose

This guide defines how Meridian should behave on trusted shared workstations and field devices. It builds on the UI Operating Guide's Kiosk, touch, accessibility, action, offline, and permission rules.

Kiosk is the fixed Electron desktop/on-site UI mode and product shell for
Meridian Kiosk. Kiosk pinned context is the constrained shared-workstation
operating context inside that fixed mode.

When this document conflicts with the canonical Meridian UI Operating Guide, the parent guide governs. Deterministic Alpha 1 Kiosk routes, UI modes, presentation profiles, authentication rules, offline labels, and permission behavior are defined in `docs/ui/meridian-ui-implementation-contract.md`.

---

## 2. Field Assumptions

Kiosk and field surfaces should assume:

- touchscreen laptops;
- gloves;
- dust;
- glare;
- night operations;
- distracted users;
- fatigue;
- shared trusted devices;
- fast user switching;
- intermittent connectivity;
- users who may not be regular Meridian operators.

The interface should remain calm, direct, and hard to misuse.

---

## 3. Default Kiosk Surface

The on-site laptop should default to a kiosk dashboard.

The kiosk dashboard should:

- show current organization and event;
- show operations-window state;
- show trusted workstation state;
- prioritize current operational tasks;
- hide admin and navigation complexity by default;
- expose only role-appropriate actions;
- support command palette access with kiosk-safe results;
- include a map by default when the event has a published map and the kiosk/user is permitted to view it;
- surface local node, offline read set, HTTPS/certificate, connected-device, and version health only where relevant to the current user or trusted operator.

The kiosk map is read-only, should work from the offline event map package, should show a stale/offline map status where relevant, and must not show sensitive map layers/locations without permission. Camps and map locations must not appear in the global command palette; map search/filter stays in a scoped panel on the map surface.

---

## 4. Authentication and Re-authentication

For Alpha 1, central authentication continues to use email magic links, Google OAuth, and Discord OAuth. There is no separate Meridian PIN credential.

Shared workstations additionally accept a short login code. A login code is not a central credential and not a password. It is issued by the node, scoped to one user, one event, and one trusted workstation, expires, and is revocable. It signs a known user in to a workstation; it never provisions a new user and never establishes a trusted personal device session.

### 4.1 Where a login code comes from

Two paths produce the same kind of code.

God mode generates one for any known user. This is the prepared path, used before an event or when assisting someone.

A user generates one for themselves on a device where they are already signed in — in practice, their phone, standing at the workstation.

The self-service path exists for the situation that has no other answer. On-site, the node has no route to central. Email is not delivering. No operator is nearby. The staff member has a working session on the phone in their pocket and needs to use the workstation in front of them. Generating a code needs only that the phone can reach the same node.

A user generates a code only for themselves. Generating one on someone else's behalf is God mode's job.

### 4.2 Entering a code

Code entry is a field interaction before it is a security interaction. Assume gloves, dust, glare, and a queue of people waiting.

- the code is human-typable and short enough to carry across a room in someone's head;
- the entry field is touch-sized and does not depend on precise tapping;
- entry does not lock out on a single mistyped character, and says how many attempts remain before it does;
- attempts are rate limited per workstation, so a workstation left unattended cannot be ground through;
- a failed code says the code was not accepted. It does not say whether the code exists, whether it expired, or whom it belongs to.

### 4.3 What a code does not do

A login code does not issue an API token and does not make the workstation a trusted personal device. It establishes a shared workstation session, with the timeout and switching behavior below.

Expected behavior:

- switching users is quick;
- privileged actions require appropriate re-authentication;
- session state is clear;
- the current user is visible when actions are user-attributed;
- timeout behavior returns to a safe kiosk surface;
- central access continues to use provider login and magic link authentication.

Trusted workstation state and individual user authority are separate:

- trusted workstation state can permit kiosk surfaces;
- individual user authority controls actions and record access;
- privileged actions may require re-authentication;
- timeout returns to a safe kiosk surface.

### 4.4 Session behavior

The active user is visible at all times. A shared workstation that is unclear about who is signed in is worse than one that is signed out.

Sessions end after 5 minutes of inactivity or when the user explicitly ends them. Switching users requires ending the current session first — there is no quiet handover.

If the desktop application restarts, the session locks immediately.

Ending a session wipes session data and abandons unsaved form state. Queued commands are not session data. A check-in recorded and queued survives the session ending and syncs when the node is reachable; losing it would mean the workstation quietly discarded operational work.

Warn before a session times out and offer a way to continue. A timeout that lands mid-sentence, in a field, at night, is how people stop trusting the workstation.

---

## 5. Self Check-in Rules

Default staff do not self check-in or self check-out in Alpha 1.

Kiosk check-in surfaces must:

- enforce role rules;
- avoid presenting unavailable self-service actions to default staff;
- provide staff-mediated check-in flows where appropriate;
- make corrective actions available to authorized users;
- allow department leads and shift leads to perform check-in/check-out actions where authorized.

Future self-service check-in must be a deliberate product decision and permission-controlled.

---

## 6. Touch Layout

Touch-enabled devices should receive touch-appropriate layouts automatically.

Touch layouts should:

- prefer `TouchCard` patterns over dense tables;
- keep controls visibly labeled;
- increase spacing;
- avoid tiny row action menus;
- keep primary actions in the `ActionBar` or attached to relevant cards;
- avoid hover-only affordances;
- preserve keyboard access for attached keyboards.

Touch adaptation should consider device capability and operating context, not only viewport width.

Use the fixed UI mode plus presentation profile contract for layout decisions:

```ts
uiMode: 'admin' | 'field' | 'kiosk'
presentationProfile: 'keyboard-first' | 'touch-first' | 'narrow' | 'wide' | 'fullscreen' | 'compact' | 'roomy' | 'table-first' | 'card-first' | 'priority-feed'
```

`uiMode` is fixed by deployment target. Screen width, pointer capability,
device configuration, current screen type, safe areas, and density/accessibility
preferences may influence `presentationProfile`.

---

## 7. Readability

Kiosk screens may be read at greater distance than laptop screens.

Important information should use:

- clear hierarchy;
- short labels;
- restrained but visible status treatment;
- canonical status names;
- strong enough contrast for glare and night use;
- no reliance on color alone.

Avoid dense paragraphs and small explanatory text on kiosk surfaces.

---

## 8. Action Design

Kiosk actions should be fast but correctable.

Routine operational actions may save immediately when easy to correct, including:

- check-in by authorized user;
- check-out by authorized user;
- deployment changes;
- equipment return;
- routine state changes.

Destructive or high-impact actions still require `ConfirmationDialog`.

Primary actions should be placed in a bottom `ActionBar` when the surface is operational or editing-oriented.

---

## 9. Error and Recovery Behavior

Field errors should be direct and recoverable.

Error messages should state:

- what failed;
- whether the user's action was saved, queued, or not applied;
- what the user can do next;
- whether a lead or organizer is needed.

Routine mistakes should have a quick correction path.

---

## 10. Offline and Sync Behavior

Meridian Kiosk must make relevant connectivity state visible without creating noise.

Kiosk surfaces should distinguish:

- Online;
- Offline but usable;
- Local node reachable;
- Central unreachable;
- Queued;
- Sync conflict;
- Sync failed.

Unavailable actions should be hidden or disabled honestly. Queued actions should be visible to the user or role that needs to trust them.

Sync repair belongs in advanced mode only.

Field Report creation, Field Report photo attachment sync, check-in, check-out, and mark no-show may queue offline in Alpha 1. Incident creation/editing and policy/procedure acknowledgments require server connection and should block with a clear explanation when unavailable.

Kiosk devices should receive the event map package offline by default when published and permitted. The kiosk map is read-only; map editing is online-only for MVP, and locked operations-window map data should remain stable offline.

---

## 11. Command Palette in Kiosk Mode

The command palette should be available in Meridian Kiosk.

Kiosk command palette results must be limited by:

- authenticated user;
- trusted workstation state;
- organization;
- event;
- department;
- role;
- permissions;
- operational window.

Admin routes and sensitive records should not appear unless the current user and workstation state allow them. IMS records must not appear unless the user has IC team-granted authority for the event's configured IC department.

---

## 12. Hardware-Aware Interaction

Kiosk and field hardware may include:

- touchscreen laptop;
- external keyboard;
- barcode or QR scanner;
- printer;
- badge reader;
- local network node;
- local discovery and HTTPS/certificate status;
- poor or intermittent internet.

Where hardware integration exists, the UI should expose device status only when it affects current work.

Hardware failure should not block unrelated workflows.

---

## 13. Night Operations

Dark mode must support night operations without using pure black by default.

Night-operation surfaces should:

- reduce harsh contrast intensity;
- preserve legibility;
- keep focus indicators visible;
- avoid bright full-screen alerts unless truly critical;
- keep destructive and restricted states clear.

---

## 14. Privacy on Shared Devices

Shared workstation screens should avoid unnecessary exposure of sensitive information.

Kiosk surfaces should:

- show only necessary personal details;
- time out to a safe view;
- prevent unauthorized browsing into restricted records;
- make the active user visible where actions are attributable;
- avoid leaving sensitive dialogs open after timeout.

---

## 15. Field QA Checklist

Kiosk and field changes require manual QA when applicable.

Check:

- touchscreen operation;
- tap accuracy;
- readability at expected distance;
- glare and night-mode usability where practical;
- touch card layout;
- bottom action bar behavior;
- user switching;
- local trusted-workstation re-authentication paths, if implemented;
- login code entry with gloves and under glare;
- self-service code generation on a phone with no internet reachability;
- code entry failure messaging and per-workstation rate limiting;
- session timeout warning, explicit end, and lock on application restart;
- queued commands surviving a session ending;
- self check-in restrictions;
- offline and queued action behavior;
- accidental action recovery;
- keyboard operation with attached keyboard.

Exact privacy timeout durations are an open product decision and should be configurable, not hard-coded.

---

## 16. Open Questions

Future versions should define:

- supported kiosk hardware profiles;
- timeout durations;
- whether any post-Alpha 1 local re-authentication convenience is needed;
- scanner and printer interaction details;
- exact touch target recommendations;
- supported scanner/printer status language.
