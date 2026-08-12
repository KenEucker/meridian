# QA-RC-01: Release Candidate QA Index

## Purpose

Decide whether a build is acceptable for field testing. This script is the
release candidate gate itself: it makes the development process section 20
Release Candidate Checklist runnable by naming, for every line, the QA script
or automated evidence that answers it, and it is the one place that lists the
critical QA scripts in the order they run. The RC decision reads this index;
nothing else has to be remembered.

It performs almost nothing itself. Where a checklist line has an owning
script, the step here is to run that script and record its outcome; the only
steps executed directly in this script are the ones no other script carries —
assembling the run, checking the completed checklist, and the two milestone 19
gate checks that have no consolidated script of their own yet.

One line of the section 20 checklist is owned by work that lands after this
index:

- `QA-MOD-01-organization-modules.md` is written by M19.19 and enters section
  A's run table when it lands. Until then the modules line of the milestone 19
  QA gate cannot be answered and no release candidate can be declared.

The second-person install/deployment evidence requirement is
[`QA-RC-02`](QA-RC-02-install-deployment-dry-run.md) (M19.7): the checklist
line ("Install/deployment docs tested by a second person") is carried in
section F, and that script defines the dry run and the evidence form the
second person captures.

## Requirements covered

- Development process section 20 (Release Readiness Checklist) — every line of
  the checklist mapped to its owning evidence.
- Development process section 14, Phase 5 (Release candidate QA) — all
  critical QA scripts pass; no open critical bugs; version metadata correct;
  Electron health panel accurate; deployment bundle reproducible; install docs
  tested by someone other than the author.
- Development plan section 6 (Milestone QA Order) — the run order section A
  follows.
- The milestone 19 QA gate: a second human can follow install/deployment
  instructions, run critical QA scripts, and verify release candidate
  readiness — an organization resolves at both its root path and its
  organization subdomain, with the marketing surface only at the deployment
  root — and an organization with Scheduling, Incident Management, and
  Documents disabled still runs its operational core with no trace of a
  disabled module anywhere in the product. That same second human installs
  Meridian Kiosk from a desktop installer and Meridian Field from its
  internal testing track onto a real device, with every app reporting the
  same root version — the packaged-install half, carried by
  [`QA-PKG-01`](QA-PKG-01-packaged-application-install.md) (M19.25).

The requirement IDs behind each area are covered by the linked scripts and are
not restated here.

## Environment

- A release candidate build: version metadata set per
  `docs/process/versioning-strategy.md`, deployed from the committed
  deployment bundle (`deploy/`) rather than `php artisan serve`, with the
  database migrated and seeded per each linked script's own environment
  section.
- Each linked script runs in the environment its own Environment section
  states; where a script's environment is the development server, that is the
  script's answer and this index does not override it. The build/version,
  security, and sync sections below run against the packaged deployment,
  because packaging is what a release candidate adds.
- The Electron desktop app and the mobile app built from the same root
  version, for the version and health checks.

## Personas

- The release decider — the person answering the checklist, typically the
  product owner.
- The second person — someone other than the author of the install and
  deployment documentation, for the
  [`QA-RC-02`](QA-RC-02-install-deployment-dry-run.md) dry run.
- Every persona a linked script names is that script's own; this index adds
  none.

## Setup data

- The seeded Northwood development scenario, reset per linked script as each
  script's Setup data section requires.
- The completed checklist from the previous release candidate, if one exists,
  for the regression comparison in section F.

## Steps

### A. Assemble the run

1. Confirm every script in the run table below exists in `docs/qa/` and that
   its owning milestone has landed. A script whose owning milestone has not
   landed blocks the release candidate: the table's Status column names the
   scripts written ahead of their milestones and the ones not yet written, and
   an RC cannot be declared while any of them is outstanding.
2. Run the scripts in this order (development plan section 6). Scripts grouped
   on one row may run in any order within the row. Record pass, fail, or
   blocked for each, with a link to the evidence its own Evidence section
   captures.

| Order | Area | Scripts | Status |
|---|---|---|---|
| 1 | Repository and boot path | [`QA-BOOT-01`](QA-BOOT-01-fresh-checkout-boots.md) | Landed |
| 2 | Application and onboarding | [`QA-ORG-01`](QA-ORG-01-organization-admin.md), [`QA-ORG-03`](QA-ORG-03-organizer-staff-intake.md), [`QA-STAFF-01`](QA-STAFF-01-staff-admin.md), [`QA-APPLY-01`](QA-APPLY-01-public-event-application.md), [`QA-APP-01`](QA-APP-01-event-application-approval.md), [`QA-APPLY-02`](QA-APPLY-02-applicant-portal.md) | Landed |
| 3 | Policy and procedure | [`QA-POL-01`](QA-POL-01-policy-procedure-fragment-acknowledgment.md), [`QA-POL-02`](QA-POL-02-product-document-authoring-sharing.md) | Landed |
| 4 | Shift signup, training, and credential | [`QA-SHIFT-01`](QA-SHIFT-01-shift-signup-eligibility.md), [`QA-TRAIN-01`](QA-TRAIN-01-training-management.md), [`QA-CRED-01`](QA-CRED-01-credential-eligibility.md) | Landed |
| 5 | Device readiness, authentication, and node setup | [`QA-READY-01`](QA-READY-01-device-readiness.md), [`QA-NODE-01`](QA-NODE-01-first-run-node-setup.md), [`QA-AUTH-01`](QA-AUTH-01-email-magic-link-login.md), [`QA-AUTH-02`](QA-AUTH-02-google-oauth-login.md), [`QA-AUTH-03`](QA-AUTH-03-discord-oauth-login.md), [`QA-AUTH-04`](QA-AUTH-04-on-site-device-sign-in.md) | Landed |
| 6 | Offline Field Report | [`QA-FR-01`](QA-FR-01-offline-field-report.md), [`QA-FR-02`](QA-FR-02-dictated-field-reports.md) | Landed |
| 7 | Department operations and attendance | [`QA-SLB-01`](QA-SLB-01-checkin-checkout-hours.md), [`QA-DEPT-01`](QA-DEPT-01-department-surfaces.md), [`QA-TEAM-01`](QA-TEAM-01-team-admin.md), [`QA-TEAM-02`](QA-TEAM-02-department-self-admin.md), [`QA-TEAM-03`](QA-TEAM-03-team-shift-administration.md), [`QA-ORG-02`](QA-ORG-02-organizer-department-admin.md), [`QA-STAFF-02`](QA-STAFF-02-staff-me-and-event-info.md), [`QA-STAFF-03`](QA-STAFF-03-staff-profile-self-service.md), [`QA-EQUIP-01`](QA-EQUIP-01-equipment-inventory-setup.md), [`QA-EQUIP-02`](QA-EQUIP-02-pooled-tracked-equipment-and-lookup.md), [`QA-KIOSK-01`](QA-KIOSK-01-kiosk-surfaces.md) | Landed |
| 8 | Incident management | [`QA-INC-01`](QA-INC-01-incident-management.md), [`QA-INC-02`](QA-INC-02-incident-search-and-filters.md) | Landed |
| 9 | Central/on-site sync | [`QA-SYNC-01`](QA-SYNC-01-onsite-central-sync.md), [`QA-ELECTRON-01`](QA-ELECTRON-01-health-panel.md) | Landed |
| 10 | Export, reporting, and import | [`QA-EXPORT-01`](QA-EXPORT-01-alpha-1-exports.md), [`QA-IMPORT-01`](QA-IMPORT-01-users-teams-import.md) | Landed |
| 11 | System configuration and diagnostics | [`QA-SYS-01`](QA-SYS-01-system-configuration-and-diagnostics.md) | Landed |
| 12 | Event geography and maps | [`QA-MAP-01`](QA-MAP-01-event-geography-and-maps.md) | Written ahead; milestone 14 has not landed |
| 13 | The Briefing | [`QA-BRF-01`](QA-BRF-01-briefing-notes-hub.md) | Landed |
| 14 | Organization and department branding | [`QA-BRAND-01`](QA-BRAND-01-organization-and-department-branding.md) | Landed |
| 15 | God Mode console content | [`QA-GOD-01`](QA-GOD-01-console-orientation-docs-changelog.md) | Landed |
| 16 | God Mode console visual identity | [`QA-GOD-02`](QA-GOD-02-console-visual-identity.md), [`QA-TOKENS-01`](QA-TOKENS-01-shared-ui-tokens.md) | Landed |
| 17 | Client session and API wiring | [`QA-CLIENT-01`](QA-CLIENT-01-session-and-api-wiring.md), [`QA-SHELL-01`](QA-SHELL-01-field-app-shell-loads.md), [`QA-NAV-01`](QA-NAV-01-command-palette.md) | Landed |
| 18 | Insights | [`QA-INSIGHT-01`](QA-INSIGHT-01-insights-framework.md) | Written ahead; milestone 17 has not landed |
| 19 | Gap closure and offline reads | [`QA-GAP-01`](QA-GAP-01-milestone-18-gap-closure.md), [`QA-GOD-03`](QA-GOD-03-orchid-repair-visibility.md), [`QA-ORG-04`](QA-ORG-04-context-and-organizer-surfaces.md), [`QA-DIR-01`](QA-DIR-01-directory-and-organization-chart.md), [`QA-OFFLINE-01`](QA-OFFLINE-01-offline-reads.md), [`QA-PUBLIC-02`](QA-PUBLIC-02-marketing-surface-and-organization-interest.md) | Landed |
| 20 | Event Horizon | [`QA-HORIZON-01`](QA-HORIZON-01-event-horizon.md) | Landed |
| 21 | Organization modules | `QA-MOD-01-organization-modules.md` | Written by M19.19; not yet landed |
| 22 | Platform landing page | [`QA-PUBLIC-01`](QA-PUBLIC-01-platform-landing-page.md) | Landed |
| 23 | Release candidate | [`QA-RC-02`](QA-RC-02-install-deployment-dry-run.md), [`QA-PKG-01`](QA-PKG-01-packaged-application-install.md), then this script's sections B through F | Landed; this script |

Every script in the table is critical: the development process phase 5 line
"all critical QA scripts pass" means this table, whole. A script that fails
and is judged acceptable anyway is not re-labelled non-critical — it is a
documented failure under section F, with the judgement and its reasons
recorded.

### B. Build/version

Checklist lines answered by [`QA-BOOT-01`](QA-BOOT-01-fresh-checkout-boots.md)
(root version and server metadata), [`QA-SHELL-01`](QA-SHELL-01-field-app-shell-loads.md)
(the Settings Versions section), and [`QA-ELECTRON-01`](QA-ELECTRON-01-health-panel.md)
(desktop wrapper version, server version, and config schema version on the
health panel), plus the deployment bundle smoke test
(`scripts/deploy/validate-deployment-bundle.mjs`) for image tags.

3. Confirm the root `package.json` Meridian version is set to the release
   candidate version.
4. Confirm the server, mobile, and Electron versions read from the root
   Meridian version, as the three scripts above surface them.
5. Confirm the config schema version is set and shown on the Electron health
   panel.
6. Confirm the Docker image tags in the deployment bundle carry the release
   candidate version and the bundle smoke test passes against the candidate.

### C. Security/event-mode

Checklist lines answered by [`QA-READY-01`](QA-READY-01-device-readiness.md)
(event-mode fail-closed, local encryption, device signing),
[`QA-NODE-01`](QA-NODE-01-first-run-node-setup.md) (setup refusals, secret
safeguards and generation), and [`QA-OFFLINE-01`](QA-OFFLINE-01-offline-reads.md)
(the offline read set the event-mode check gates on).

7. Confirm dev auth is disabled outside development.
8. Confirm default or placeholder secrets are refused in production/event mode
   and `meridian:secrets --generate` covers the two secrets Meridian owns.
9. Confirm HTTPS validation passes in event mode and a node failing it refuses
   to serve.
10. Confirm offline read set availability validation passes in event mode.
11. Confirm local encryption validation and device signing validation pass.

### D. Core workflows

Each checklist line's owning script; the line passes when the script passed in
section A's run.

12. Application approval — [`QA-APP-01`](QA-APP-01-event-application-approval.md).
13. Department/team assignment — [`QA-ORG-02`](QA-ORG-02-organizer-department-admin.md),
    [`QA-TEAM-02`](QA-TEAM-02-department-self-admin.md),
    [`QA-TEAM-03`](QA-TEAM-03-team-shift-administration.md).
14. Shift signup eligibility — [`QA-SHIFT-01`](QA-SHIFT-01-shift-signup-eligibility.md).
15. Credential eligibility export — [`QA-CRED-01`](QA-CRED-01-credential-eligibility.md)
    section G and [`QA-EXPORT-01`](QA-EXPORT-01-alpha-1-exports.md).
16. Shift Lead Board — [`QA-SLB-01`](QA-SLB-01-checkin-checkout-hours.md).
17. Field report offline flow — [`QA-FR-01`](QA-FR-01-offline-field-report.md).
18. Incident online flow — [`QA-INC-01`](QA-INC-01-incident-management.md).
19. Attendance creates hours — [`QA-SLB-01`](QA-SLB-01-checkin-checkout-hours.md).
20. Credits calculate after freeze — [`QA-GAP-01`](QA-GAP-01-milestone-18-gap-closure.md)
    and [`QA-DEPT-01`](QA-DEPT-01-department-surfaces.md) (credit review).
21. Required exports — [`QA-EXPORT-01`](QA-EXPORT-01-alpha-1-exports.md).

### E. Sync

22. Central/on-site pairing, event data sync to on-site, on-site operations
    syncing back, and the conflict queue —
    [`QA-SYNC-01`](QA-SYNC-01-onsite-central-sync.md).
23. Electron health shows sync state —
    [`QA-ELECTRON-01`](QA-ELECTRON-01-health-panel.md).

### F. Milestone 19 gate and human QA closure

The two milestone 19 gate checks that have no consolidated script yet run
directly here; the rest of this section closes the checklist.

24. Organization addressing: confirm a seeded organization resolves at its
    deployment-root path and at its organization-slug subdomain with the same
    content and the slug segment omitted from paths, an unknown subdomain is
    not found, and the marketing surface renders only at the deployment root.
    (Built by M19.8 through M19.10, which added feature tests and no script of
    their own, so the human pass stays here. In development the subdomain form
    is `http://northwood.localhost:<port>` against the seeded Northwood
    scenario; on a deployment it needs the wildcard DNS record and
    `Caddyfile.wildcard` proxy configuration from the deployment bundle —
    see `deploy/dns/README.md` and `deploy/caddy/README.md`.)
25. Modules: run `QA-MOD-01-organization-modules.md` (M19.19), which carries
    the modules half of the milestone 19 gate — an organization with
    Scheduling, Incident Management, and Documents disabled still intakes
    staff, runs status, checks people in and out, records hours, and
    calculates credits, with no navigation entry, API route, replicated
    record, or export for a disabled module anywhere.
26. Confirm every critical QA script in section A passed, and every failure is
    documented with what failed, the evidence, and the judgement made about
    it.
27. Confirm known critical issues are triaged: each has an owner and a
    decision — fix before release, or accept with reasons recorded.
28. Install/deployment dry run: run
    [`QA-RC-02`](QA-RC-02-install-deployment-dry-run.md). A second person —
    someone other than the author of the docs — follows the install and
    deployment documentation from a clean machine, and the evidence record
    that script defines is attached here whole.
29. Packaged application install: run
    [`QA-PKG-01`](QA-PKG-01-packaged-application-install.md) (M19.25). The
    same second person installs each desktop installer on its own OS and
    Meridian Field from its internal tracks and the direct APK, verifies the
    Settings Versions metadata, the Kiosk fullscreen launch and health panel,
    and Field operating against an on-site node, and records the first-run
    trust warnings against the install document. Where the critical scripts
    above can be exercised from an installed application rather than a
    development client, the milestone 19 gate expects them run that way.
30. Complete a fresh copy of the development process section 20 Release
    Candidate Checklist, checking each box only from the evidence gathered
    above, and attach it to the release candidate record.

## Expected results

- Every script in section A's run table has landed, run, and passed, or its
  failure is documented with evidence and a recorded judgement.
- Every line of the section 20 checklist is checked from evidence, not from
  memory; no line is checked while its owning script is unlanded or unrun.
- All three milestone 19 gate checks (addressing, modules, and the packaged
  application install) pass.
- The second person completed the install/deployment dry run without the
  author's help, and their evidence is attached, along with the
  [`QA-PKG-01`](QA-PKG-01-packaged-application-install.md) packaged-install
  evidence from the same person.
- No open critical bug is untriaged.

## Evidence to capture

- The completed section 20 checklist copy, dated and attributed.
- The section A run table with per-script outcomes and links to each script's
  own captured evidence.
- The second person's install/deployment evidence record, in the form
  [`QA-RC-02`](QA-RC-02-install-deployment-dry-run.md)'s Evidence to capture
  section defines.
- Version screenshots: the client Settings Versions section and the Electron
  health panel showing the candidate versions.
- The triage list of known issues with owners and decisions.

## Failure notes

- A failed critical script does not by itself unmake the release candidate,
  but an undocumented one does: the checklist line "Failed QA scripts
  documented" is only satisfiable when every failure carries evidence and a
  judgement. Record what failed, attach the script's own failure notes, and
  route fixes through the normal process.
- A script whose owning milestone has not landed is blocked, not failed;
  record it as blocking the RC rather than running it against surfaces that do
  not exist.
- If the checklist and a linked script disagree about what a line means, the
  script wins for its own scope and the disagreement is a documentation bug to
  file against this index.
