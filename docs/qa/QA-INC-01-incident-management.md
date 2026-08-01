# QA-INC-01: Incident Management

## Purpose

Verify the Milestone 11 Incident Management System (IMS) Alpha 1 QA gate as a
human reviewer: IC-only incident access, online create/edit with the full
current-field set, operational notes and strikes, Name Reference chips,
linked incidents, Field Report attach/unlink, compact versus full history,
list/detail and IC Field Report cross-links, and IC-lead PDF print.

This script records automated evidence for multi-role fail-closed boundaries,
server command authorization, wrong-event fail-closed reads, incident audit
rows, attachment strike without upload UI, and PDF export policy.

Since M16.20 the IMS surfaces read and write through the node rather than
through a bundled fixture, so the human steps below act on records the server
holds: the list is the page the node returned for the selection in the URL, an
edit is a command followed by a re-read, and a refusal is shown as the node
worded it. What each screen offers follows the capability codes `GET /api/me`
publishes for the department the client is working in, and the server refuses
the request regardless of what the screen rendered. Role-matrix and HTTP/audit
checks remain owned by the automated suites named below.

This script does not add product behavior. It does not require incident
attachment upload UI, richer M11.19 search/filter product work beyond the
existing chip-driven and list filters, PowerSync conflict repair, node sync,
or spreadsheet incident exports (INC-016).

## Requirements covered

- `INC-001` through `INC-015`
- `INC-016` as an explicit non-goal (no spreadsheet incident export)
- `FR-011` through `FR-014`
- `NR-008` through `NR-014`
- `ORG-015` (organizers do not gain incident access)
- Technical spec: Sections 16.1, 16.2, 19.2, 19.4, 19.7, 19.9, and 19.10
- Technical spec: Section 23 Audit Log (incident view/create/update/note/link/
  export and attachment-strike actions)
- Data/API spec: Sections 4.4, 5.2, 6.5, 10.16, 10.17, and 15.4
- UI Implementation Contract: Sections 12.7 and 15 (IMS surfaces)
- UI operating guide IMS / incident workflow expectations referenced by
  Milestone 11
- `CLIENT-023`: the IMS surfaces act on server data, not bundled fixtures
- `CLIENT-006`: what the IMS surfaces offer follows the node's stated authority
- `CLIENT-015`, `CLIENT-018`: incident commands are connected-only and are
  refused at issue time rather than queued
- `CLIENT-019`, `CLIENT-020`: the incident PDF is fetched through a short-lived
  scoped download URL
- `FR-005`, `FR-006`: the IC Field Report list requires `field_reports.view_event`
- Data/API spec: Section 5.1 (`GET /api/events/{event}/incidents`,
  `GET /api/events/{event}/field-reports`) and Section 5.7 (short-lived
  download URLs)
- Meridian Alpha 1 tasks M11.1 through M11.10 (foundations through PDF) and
  M16.20 (client binding), with this script owned by M11.11
- Milestone 11 QA gate: IC-only incident access, create/edit online with the
  full IMS current-field set, link incidents, link Field Reports, review
  history, use existing list/chip search filters, and confirm Name Reference
  chips/search do not expose unauthorized incidents or Field Reports

## Environment

- Repository dependencies installed with approved PHP, Composer, Node.js 24
  LTS, and pnpm 11.x versions.
- A dedicated development/QA database is recommended when retaining server
  automated evidence. `migrate:fresh --seed` must not be used against shared
  or valuable data.
- Laravel available at `http://127.0.0.1:8000` for automated server suites.
- The shared Vue client available at `http://127.0.0.1:5173` in a secure
  browser context (`localhost` / `127.0.0.1` is sufficient).
- Browser DevTools capable of switching the page network condition to Offline.
- At least three terminals: Laravel server (for automated suites), shared
  client dev server, and QA commands.
- Human IMS UI steps run against a reachable node. The client holds a session
  for a user with event-scoped `ic_lead` at the event's configured Incident
  Command department; the local development session installed by
  `VITE_MERIDIAN_INSTALL_LOCAL_FIELD_SESSION` is that user for the seeded Local
  Field Event. Every list, detail, note, link, and PDF in section B onward is a
  server round trip.

## Personas

- Human browser session: a signed-in user holding event-scoped `ic_lead`
  (Incident Command Lead) for Local Field Event / Rangers.
- Automated IC personas (server + client Vitest): `ic_viewer`, `ic_operator`,
  and `ic_lead`, including seeded Development Scenario identities Ingrid
  ICLead (`ingrid.iclead@northwood-collective.test`), Omar ICOperator
  (`omar.icoperator@northwood-collective.test`), and Ivy ICViewer
  (`ivy.icviewer@northwood-collective.test`) where those suites create their own
  grants.
- Automated denial personas: organizer-only, non-IC department lead, wrong-
  event IC grant, revoked IC grant, unauthenticated, and normal staff.
- Human reviewer observing the shared-client IMS UI and retaining automated
  suite output as multi-role evidence.

The shared client does not yet expose a browser role switcher or real IC login
for IMS. Do not invent Orchid or magic-link steps for `/ims/*` role changes;
retain viewer/operator/non-IC boundaries from the automated suites in
section A.

## Setup data

1. From the repository root, install dependencies if needed:
   ```bash
   corepack pnpm install
   composer --working-dir=apps/server install
   ```
2. Configure local Laravel and shared-client Vite env files:
   ```bash
   corepack pnpm run setup:local
   ```
3. Configure a dedicated local server database, then reset and seed it (needed
   for section A server suites; optional for section B human UI alone):
   ```bash
   php apps/server/artisan migrate:fresh --seed
   ```
4. Start Laravel in one terminal when running server tests:
   ```bash
   php apps/server/artisan serve --host=127.0.0.1 --port=8000
   ```
5. Start the shared client in another terminal:
   ```bash
   corepack pnpm run client:dev -- --host 127.0.0.1
   ```
6. Open `http://127.0.0.1:5173/ims/incidents`. Confirm context shows Local
   Field Organization, Local Field Event, and the Incident Command department,
   and that the Role card names the role carrying `incidents.view`.
7. Create the records the human steps act on, through the product UI itself
   (section D creates the first one). A run needs at least:
   - one active incident with a location, at least one incident type, at least
     one responder, and a note containing `@Blue-Hat` and `#medical`;
   - a second active incident to link to the first;
   - one incident moved to Closed, so the default Active states filter has
     something to hide.
8. Submit at least one Field Report for the event (Staff → Submit Field Report,
   or Incidents → Take Field Report) so the IC Field Report list and the
   incident attach picker have a candidate. Note its FRA number.

These are server records and persist between runs. Reuse them or create fresh
ones; nothing here depends on a particular IMS number.

## Steps

### A. Automated IMS evidence

1. From the repository root, run the shared client IMS suites:
   ```bash
   corepack pnpm --filter @meridian/client exec vitest run \
     src/views/IncidentViews.spec.ts \
     src/components/AutosaveStatus.spec.ts \
     src/downloads/shortLivedDownload.spec.ts
   ```
2. From `apps/server`, run the Incident server suites:
   ```bash
   php artisan test \
     tests/Feature/IncidentSchemaTest.php \
     tests/Feature/IncidentCreationTest.php \
     tests/Feature/IncidentCommandDepartmentSelectionTest.php \
     tests/Feature/IncidentCommandHttpTest.php \
     tests/Feature/IncidentReadHttpTest.php \
     tests/Feature/IncidentSearchHttpTest.php \
     tests/Feature/FieldReportReadHttpTest.php \
     tests/Feature/IncidentTimelineSchemaTest.php \
     tests/Feature/NameReferenceIncidentTest.php \
     tests/Feature/IncidentPdfExportTest.php \
     tests/Unit/FieldReportIncidentNoteCopyTest.php
   ```
3. Confirm all suites pass. In particular, retain output proving:
   - event-scoped incidents, IMS numbering (`INC-YYYY-NNNNNN`), and
     non-destructive incident preservation;
   - IC department designation and event-scoped IC team-grant constraints;
   - `ic_viewer` / `ic_operator` / `ic_lead` read access with organizer,
     non-IC department lead, wrong-event, revoked, unauthenticated, and
     normal staff denial;
   - create/update/note/link/unlink/attachment-strike commands limited to
     permitted IC roles; `ic_viewer` remains read-only for mutations;
   - `incident.viewed` audit on permitted detail reads and no view audit on
     denied reads;
   - append-only notes, note strike with preserved body/reason, Name
     Reference indexing and chip-driven permission-filtered search;
   - priority, types, responders, linked incidents, Field Report
     attach/unlink with copied `Field Report: <title>` notes and strike on
     unlink;
   - `POST /api/commands/strike-incident-attachment` preserves attachment
     rows/metadata, excludes stricken attachments from active reads, and
     writes timeline/audit history (upload UI remains deferred);
   - `ic_lead`-only PDF export with `incident.exported` audit, operator/
     viewer denial, and forbidden direct PDF URL without export audit;
   - the client asks the node for each list page, sends each change as its
     command, re-reads afterwards, and quotes the node's refusals; the incident
     PDF is fetched by asking for a short-lived scoped URL and navigating to
     it; and the event Field Report read requires `field_reports.view_event`,
     refusing an author their own event's list without it.

### B. Incident list, filters, and navigation

4. With the browser online, open `/ims/incidents` (or Home → Incident
   Management → Incidents).
5. Confirm the list shows the event's active incidents with IMS number, title,
   state, priority, types, location, and last update, and that the summary line
   counts the whole filtered result rather than the rows on the page. Confirm
   the Closed incident is absent under the default Active states filter.
6. Change the State filter to include Closed (or All) and confirm the Closed
   incident appears. Restore Active states afterward if desired.
7. Confirm Priority filters and column-header sorting change the visible
   order without exposing another event's incidents. Confirm the Types heading
   is plain text rather than a sort control, because the node offers no such
   sort. Save the current selection as a named preset, reload the page, apply
   the preset from the dropdown, and confirm the URL and the list both follow
   it; then delete the preset.
8. Confirm Home and IC Field Reports links are present. Open IC Field
   Reports (`/ims/field-reports`), confirm a Home link and an Incidents
   cross-link, then return to Incidents.

### C. Detail read, Name References, notes, and history

9. Open the incident carrying the `@Blue-Hat` note. Confirm detail shows
   current state, event and IC department context, location, creator,
   responders, types, priority, any linked incident, the opened/timeline
   content, `#medical` tag treatment, and a Name Reference chip `@Blue-Hat`
   visually distinct from tags.
10. Confirm Edit and Print PDF controls are available for the lead session.
    (Viewer/operator control absence is retained from section A.)
11. Click `@Blue-Hat`. Confirm navigation returns to the incident list with
    permission-filtered search for that reference text and does not open a
    Name Reference profile/detail page. Return to the incident detail.
12. Append a plain-text operational note that includes `@QA-Runner`. Confirm
    the note appears in the timeline with actor and timestamp, and that a
    Name Reference chip for the new marker appears.
13. Strike that note, provide a non-blank reason when prompted, confirm
    compact history hides the stricken note, and confirm full history shows
    the original note text struck through with the reason. Confirm a blank
    note body and a blank strike reason are rejected.
14. Edit a current field such as priority on the detail/edit path so a
    routine field-update history entry exists. Confirm compact history hides
    routine field-update noise while full history shows it.

### D. Create and edit autosave

15. Open `/ims/incidents/create`. Confirm the blank autosave form shows no
    IMS number until the first valid title autosaves, then shows the IMS
    number the node assigned.
16. Edit title, state, started timestamp, free-text location, priority,
    incident types, and involved Rangers/responders. Confirm the State and
    Priority options are the node's vocabularies, that the Responder picker
    offers the event's Incident Command department roster, that the Incident
    types picker offers the organization's configured unarchived types and
    creates none — a name matching nothing is reported as unconfigured rather
    than offered as a new type — that autosave status is visible, that a
    refused save shows the node's own sentence, and that Closed status does
    not block further edits when a Closed incident is opened for edit.
17. Confirm priority, state, and type labels remain visually and textually
    distinct on list and detail after edits.

### E. Linked incidents and Field Reports

18. On an open incident edit surface, link the second incident, confirm it
    appears on both sides, then unlink it and re-link it. Confirm the add
    picker searches the event through the node — type part of the other
    incident's number or title and confirm it is found — and that
    already-linked candidates are hidden while the relationship remains
    preserved history rather than a merge.
19. Confirm the picker does not offer the current incident as a self-link
    candidate. (Explicit duplicate/self/cross-event rejection messages and
    shared-tag-first candidate ordering remain section A evidence.)
20. Attach the Field Report submitted in setup. Confirm it appears in current
    attached Field Reports and as a copied timeline note headed
    `Field Report: <title>` with the Field Report author listed, then unlink it
    and confirm the copied note is struck while removal history remains
    visible.
21. From the Incidents list, open IC Field Reports. Confirm sortable headings,
    related-incident state/priority filters, and a linked/not-linked status
    filter. Open a Field Report from the list and, after re-attaching one,
    from an attached Field Report row on incident detail. Confirm the
    read-only IC detail shows FRA number, title, author, body, and related-
    incident links.

### F. Offline create/edit gate and PDF print

22. On create or edit, switch DevTools network to Offline. Confirm current-
    field autosave mutation is blocked with a server-connection explanation
    and without queued-offline language, that nothing appears in the outbox,
    and that typed form values remain on the screen. Confirm adding a note is
    refused the same way. Restore Online.
23. As the lead session, open an incident detail and select Print PDF. Confirm
    the client asks for a download URL and then navigates to it, and that the
    PDF the node returns includes IMS number, title, state, priority, types,
    responders, location, timeline notes, linked incidents, attached Field
    Reports when present, and an export timestamp. Confirm the server recorded
    an `incident.exported` audit row for this export.
24. Retain section A evidence that `ic_operator` and `ic_viewer` do not get
    Print PDF and that direct PDF URL requests are forbidden without an export
    audit row for non-lead actors.

### G. Attachment strike and deferred surfaces

25. On an incident carrying an attachment, confirm the Attachments panel lists
    it with its filename, type, and size, and that striking it asks for a
    reason and then removes it from the panel. On an incident with none,
    confirm the panel says so. Retain section A evidence that stricken
    attachments remain stored, disappear from active read payloads, and write
    timeline/audit history for IC operators/leads.
26. Confirm the human IMS UI does not present an incident attachment upload
    control. Do not treat missing upload UI as a failure of this script.
27. Confirm this script did not require richer M11.19-only search product
    work beyond existing list filters and Name Reference chip search, did not
    require spreadsheet incident exports, PowerSync conflict repair UI, node
    sync, or Orchid/God Mode as the normal IC workflow.

## Expected results

- Targeted client and server IMS suites pass, including multi-role
  fail-closed reads/mutations, wrong-event denial, note/attachment strike
  preservation, Field Report copy/unlink strike, and `ic_lead`-only PDF
  export audit.
- The IMS list shows the node's incidents with IMS number, title, state,
  priority, types, location, and last update; Closed incidents are filtered
  out by default and can be included; the count is the node's total for the
  selection; and saved presets round-trip through the node.
- Incident detail exposes current fields, timeline, tags, and Name Reference
  chips; chip click returns permission-filtered list search without a Name
  Reference profile page.
- Operators/leads (lead in the browser; operator coverage in
  automated suites) can append and strike notes, autosave create/edit
  current fields including priority/types/responders, link/unlink same-event
  incidents, and attach/unlink Field Reports with copied note strike on
  removal.
- Offline create/edit autosave is blocked without queued-offline language.
- IC Lead can print an incident PDF from the detail surface.
- IC Field Reports list/detail cross-links and filters work against the
  event's Field Reports as the node returns them.
- Incident attachment upload UI remains deferred; attachments are listed and
  struck from the incident detail, and preservation is proven by automated
  suites.
- Every IMS refusal on screen is the node's own sentence, and an unreachable
  node is reported as such rather than shown as an event with no incidents.
- Viewer/operator/non-IC browser role switching is not required of the human
  path; those boundaries are proven by automated suites.

## Evidence to capture

- Terminal output from the shared-client and server IMS test runs listed in
  section A.
- Screenshot of `/ims/incidents` showing active rows, the result count, and
  default Closed filtering.
- Screenshot of an incident detail with Name Reference chips distinct from
  `#tag` chips.
- Screenshot or notes of chip-driven list search after clicking `@Blue-Hat`.
- Screenshot of create/edit autosave showing the assigned IMS number and
  autosave status.
- Screenshot of an attached Field Report copied timeline note and the struck
  note after unlink.
- Screenshot of compact versus full history after a field edit and a note
  strike.
- Screenshot of offline create/edit blocked messaging without queued-offline
  language.
- Downloaded incident PDF sample from the lead session, with the
  `incident.exported` audit row it produced.
- Notes pointing at section A output for role denials, `incident.viewed` /
  `incident.exported` / attachment-strike audits, and strike-incident-
  attachment preservation.

## Failure notes

- Record the failed step, exact UI/error text, operating system/browser,
  commit SHA, Node/pnpm/PHP versions, and whether the section A suites
  passed.
- If a non-IC actor can read or mutate incidents in the automated suites, or
  if `ic_viewer` can create/edit/note/link/print, stop and file a blocking
  INC / technical-spec 16 permission issue.
- If incidents can be hard-deleted, merged away, or lose stricken note/
  attachment history, stop and file a blocking INC-008 / INC-009 / INC-013 /
  INC-014 issue.
- If offline create/edit autosave queues writes or claims offline incident
  mutation support, stop and file a blocking online-only IMS issue.
- If Name Reference chips open a profile/detail page, grant visibility
  beyond IC policy, or treat `#tags` as Name References, stop and file a
  blocking NR issue.
- If Print PDF is available to non-lead roles in automated suites, or if a
  permitted lead export creates no `incident.exported` audit on the server
  path, stop and file a blocking INC-015 issue.
- If an IMS surface renders incidents, Field Reports, responders, incident
  types, states, or priorities that the node did not send, stop and file a
  blocking CLIENT-023 issue.
- If the only failure is missing incident attachment upload UI, richer
  M11.19 search product UI, spreadsheet exports, PowerSync, or node sync, mark
  that check Not Applicable to M11.11 and report scope leakage rather than
  expanding this task.
- If a reviewer cannot switch browser roles and treats that alone as a
  product defect, point them to section A automated role evidence rather than
  inventing an unsupported login path.
