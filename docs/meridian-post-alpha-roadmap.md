# Meridian Post-Alpha Roadmap

## 1. Purpose

This document records where Alpha 1 ends and calls out the features on the
roadmap beyond `0.1.0`. It deliberately does not design them. Each entry names
its scope in a paragraph and points at the documents that govern it; the design
work happens through the normal change-control process when an entry is picked
up, exactly as `docs/process/meridian-alpha-1-development-plan.md` section 8
requires: any PR implementing deferred or new behavior must first update the
relevant source documents.

No entry here carries a version number beyond `0.1.0`. Assigning features to
`0.x` minors is a decision to make when an entry is picked up, not before.

## 2. Where Alpha 1 Ends

As of 2026-08-16 the traceability matrix
(`docs/process/traceability-matrix.md`) holds 468 rows: 298 Complete, 150 In
progress (usable), 9 In progress (domain), and 11 Not started. Every "In
progress (usable)" row is exercisable end to end by the persona it serves; its
remaining scope is named in the row and owned by a task or by an entry below.

The Not-started and domain-only rows reduce to a short list of undeveloped
scope, all of it already specified in the governing documents:

- **Event Geography and Maps** — requirements section 7.17, technical spec
  section 21A, data/API section 10.18, development plan Milestone 14. Fully
  specified, never implemented.
- **Notes and The Briefing (Alpha 1 slice)** — BRF-001 through BRF-008C2,
  technical spec section 21B, data/API section 11A, development plan
  Milestone 15. Fully specified, never implemented.
- **Full Briefing types** — BRF-008D through BRF-028: AAR, Directions, Action
  Plans, and Notices. Specified and explicitly deferred past Alpha 1.
- **FR-013** — appended Field Report content propagating to already-linked
  incidents.
- **POL-030** — policy/procedure packet assembly, explicitly post-Alpha 1
  under technical spec section 21.12.
- **Module gating for background work** — issue
  `docs/issues/006-module-gating-for-background-work.md`: scheduled jobs and
  export generation do not yet skip organizations whose owning module is
  inactive.
- **Domain-only slices** — organization/department status administration
  surfaces (STAT-001, STAT-002, STAT-004, STAT-005), lead shift assignment and
  removal surfaces (SHIFT-013, SHIFT-015), the full offline read set, and
  device-trust key lifecycles. The domain layer exists and is tested; the
  surfaces and enforcement are what remain.

Everything else in the requirements document's Alpha scope is delivered,
QA-scripted, and traceable.

## 3. The 0.1.0 Promotion

`docs/process/versioning-strategy.md` already defines the gate: beta starts
when a maintainer manually bumps the root version to `0.1.0`. What changes at
that bump is that releases become real. The promotion consists of:

1. A manual PR bumping the root `package.json` to `0.1.0`, regenerating the
   committed artifacts that carry the root version in the same commit.
2. Teaching `.github/workflows/production-version-bump.yml` to push the
   `v<version>` tag after each post-merge bump, so every successful merge into
   `production` produces a true GitHub release through
   `.github/workflows/release-artifacts.yml` — installers, images, and
   deployment bundle attached, exactly as a manually tagged release is built
   today.
3. Honoring patch-versus-minor classification during beta, which the
   versioning strategy anticipates and currently defers.

The versioning strategy document remains the source of truth for these
mechanics and is updated by the promotion PR itself.

## 4. Roadmap Features

### 4.1 Event Geography and Maps

The complete Milestone 14 scope: placement department designation, map and
asset models, camps and map locations, import, permissions, publish/archive,
operations-window locking, the Event Map screen, kiosk and dashboard maps,
optional IMS and operational location references, and offline map sync.
`QA-MAP-01` already exists for it. Specified; carried forward unchanged.

### 4.2 Notes and The Briefing (Alpha 1 slice)

The complete Milestone 15 scope: the immutable Note model, Notes/Briefing
permissions, create-note and add-note-to-briefing commands, Note read APIs,
the Briefing hub with type shells, Notes UI, the Orchid scaffold, and sync.
`QA-BRF-01` already exists for it. Specified; carried forward unchanged.

### 4.3 Full Briefing Types

The post-Alpha half of The Briefing already specified in the glossary
(requirements 3.38 through 3.41) and BRF-008D through BRF-028: Submission and
Final AARs with the fixed ICS section template, Directions, Action Plans with
surface banners, and Notices.

### 4.4 Notes Expansion: Personnel, Shift, and Individual AARs

New scope extending the Notes domain beyond what is specified today:

- **Personnel notes** — notes attached to a staff member. HR/personnel notes
  are currently an explicit MVP exclusion (requirements 6.4); this entry
  lifts that exclusion deliberately, which means specifying visibility,
  retention, and audit posture before anything else.
- **Shift notes** — notes attached to a shift.
- **Individual AARs** — AAR submissions from individual staff, alongside the
  department- and team-scoped Submission AARs that 3.38 already defines.

Together with 4.2 and 4.3 this delivers Notes at personnel, shift, and AAR
(team and individual) scope.

### 4.5 Radio Traffic Module

New scope with no governing spec section yet. A module for the radio desk:

- **Current caller display** — who is on the air now, in front of the
  operator.
- **Transcription** — capturing radio traffic as text.
- **Convert to IMS entry** — turning a transcribed exchange into a new
  incident without retyping it.

The natural anchor is the Department Operator role (requirements 4.8A), which
already anticipates that further dispatch duties may attach to it. The module
catalogue is fixed at eight by MOD-001 and the plan's explicit non-goal of a
plugin system, so this arrives as a deliberate catalogue change through the
requirements and technical spec, not as an extension point.

### 4.6 Android and Apple App Store Publishing

Native app-store distribution is currently an explicit exclusion (plan
section 8); Alpha 1 proves the internal path only — a signed build on
TestFlight and the Play internal track (technical spec 26.5). This entry
lifts the exclusion: public Play Store and App Store listings, store metadata
and review compliance, and a release cadence that keeps store builds derived
from the root version. Signing infrastructure (M19.22) and the iOS archive
runbook (M19.24) already exist; the open engineering question is automating
the macOS signing environment the workflow does not have.

### 4.7 Alpha 1 Gap Closure

The carried items from section 2 that are neither features nor promotions:
FR-013 append propagation, POL-030 packet assembly, issue 006 background-work
module gating, and the domain-only status/shift/offline/device-trust slices.
Each already names its governing sections; they close through ordinary tasks
against the traceability matrix.

## 5. Exclusions That Remain Exclusions

The deferred list in development plan section 8 — SMS and push notifications,
staff self check-in/out, GPS collection, configurable forms, offline incident
creation, the generic plugin system, full multi-node, and the rest — stays
excluded. Nothing on it is on this roadmap until a future revision of this
document deliberately lifts it, with the source documents updated first.

## 6. Change Control

This document calls features out; it does not authorize building them. An
entry is picked up by updating the requirements document, technical spec,
data/API spec, and UI documents through the normal process, then planning
tasks that cite those sections — the same rule every Alpha 1 task followed.
