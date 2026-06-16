# Meridian Kiosk and Field Hardware UX Guide

Version: Draft 1  
Project: Meridian Volunteer Operations Platform  
Parent guide: `docs/ui/meridian-ui-operating-guide.md`  
Purpose: Define Meridian UX expectations for kiosk mode, shared workstations, touch laptops, and field hardware.

---

## 1. Purpose

This guide defines how Meridian should behave on trusted shared workstations and field devices. It builds on the UI Operating Guide's kiosk, touch, accessibility, action, offline, and permission rules.

Kiosk mode is not a separate product. It is a constrained Meridian operating context.

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
- support command palette access with kiosk-safe results.

---

## 4. Authentication and Re-authentication

Kiosk and shared workstation authentication should support PIN-like re-authentication for on-site use.

Expected behavior:

- switching users is quick;
- privileged actions require appropriate re-authentication;
- session state is clear;
- the current user is visible when actions are user-attributed;
- timeout behavior returns to a safe kiosk surface;
- central access continues to use provider login and magic link authentication.

The UI should make the difference between trusted workstation state and individual user authority clear.

---

## 5. Self Check-in Rules

Volunteers should not self check-in or self check-out unless they are department leads or shift leads.

Kiosk check-in surfaces must:

- enforce role rules;
- avoid presenting unavailable self-service actions to default volunteers;
- provide staff-mediated check-in flows where appropriate;
- make corrective actions available to authorized users.

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

Kiosk mode must make relevant connectivity state visible without creating noise.

Kiosk surfaces should distinguish:

- offline but usable;
- local node reachable;
- central unreachable;
- sync conflict;
- sync failed.

Unavailable actions should be hidden or disabled honestly. Queued actions should be visible to the user or role that needs to trust them.

Sync repair belongs in advanced mode only.

---

## 11. Command Palette in Kiosk Mode

The command palette should be available in kiosk mode.

Kiosk command palette results must be limited by:

- authenticated user;
- trusted workstation state;
- organization;
- event;
- department;
- role;
- permissions;
- operational window.

Admin routes and sensitive records should not appear unless the current user and workstation state allow them.

---

## 12. Hardware-Aware Interaction

Kiosk and field hardware may include:

- touchscreen laptop;
- external keyboard;
- barcode or QR scanner;
- printer;
- badge reader;
- local network node;
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
- PIN-like re-authentication paths;
- self check-in restrictions;
- offline and queued action behavior;
- accidental action recovery;
- keyboard operation with attached keyboard.

---

## 16. Open Questions

Future versions should define:

- supported kiosk hardware profiles;
- timeout durations;
- PIN policy;
- scanner and printer interaction details;
- exact touch target recommendations;
- local node status language;
- kiosk dashboard widget inventory.

