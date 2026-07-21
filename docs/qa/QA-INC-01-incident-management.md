# QA-INC-01: Incident Management

## Purpose

Verify the Milestone 11 Incident Management System (IMS) Alpha 1 QA gate as a
human reviewer: IC-only incident access, online create/edit with the full
current-field set, operational notes and strikes, Name Reference chips,
linked incidents, Field Report attach/unlink, compact versus full history,
list/detail and IC Field Report cross-links, and IC-lead PDF print.

This script records automated evidence for multi-role fail-closed boundaries,
server command authorization, wrong-event fail-closed reads, incident audit
rows, attachment strike without upload UI, and PDF export policy. The shared
client IMS surfaces still use a development local fixture until auth/event
selection owns the real IC context; human browser steps therefore exercise the
product-shaped local playground as IC Lead, while role-matrix and HTTP/audit
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
- Meridian Alpha 1 tasks M11.1 through M11.10 (foundations through PDF), with
  this script owned by M11.11
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
- Human IMS UI steps use the shared-client development IMS fixture (Local
  Field Organization / Local Field Event / Incident Command Lead). Those
  screens are in-memory for create/list/edit/notes/links; they do not yet
  call Laravel incident HTTP APIs. Print PDF on that local session is also
  client-rendered.

## Personas

- Human browser session: development IMS fixture installed as `ic_lead`
  (Incident Command Lead) for Local Field Event / Rangers.
- Automated IC personas (server + client Vitest): `ic_viewer`, `ic_operator`,
  and `ic_lead`, including seeded Development Scenario identities Ingrid
  ICLead (`ingrid.iclead@idaho-burners.test`), Omar ICOperator
  (`omar.icoperator@idaho-burners.test`), and Ivy ICViewer
  (`ivy.icviewer@idaho-burners.test`) where those suites create their own
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
   Field Organization, Local Field Event, and Incident Command Lead.
7. Note the fixture incidents used by human steps:
   - `INC-2027-000042` Medical assist near Gate A (On Scene / Serious / Gate A)
   - `INC-2027-000041` Radio relay check (Monitoring / Routine / Ranger HQ)
   - `INC-2027-000040` Closed supply handoff (Closed; hidden by default Active
     states filter)
8. Note the fixture Field Reports used by human attach steps (initially not
   linked):
   - `FRA-2027-000124` Supply cart movement
   - `FRA-2027-000123` Medical observation near Gate A
   - `FRA-2027-000122` Radio relay notes

Clear site data or reload before a fresh run if a prior local create/edit left
in-memory fixture mutations in the current tab.

## Steps

### A. Automated IMS evidence

1. From the repository root, run the shared client IMS suites:
   ```bash
   corepack pnpm --filter @meridian/client exec vitest run \
     src/views/IncidentViews.spec.ts \
     src/ims/incidentReadModel.spec.ts \
     src/ims/downloadIncidentPdf.spec.ts \
     src/components/AutosaveStatus.spec.ts
   ```
2. From `apps/server`, run the Incident server suites:
   ```bash
   php artisan test \
     tests/Feature/IncidentSchemaTest.php \
     tests/Feature/IncidentCreationTest.php \
     tests/Feature/IncidentCommandDepartmentSelectionTest.php \
     tests/Feature/IncidentCommandHttpTest.php \
     tests/Feature/IncidentReadHttpTest.php \
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
     viewer denial, and forbidden direct PDF URL without export audit.

### B. Incident list, filters, and navigation

4. With the browser online, open `/ims/incidents` (or Home → Incident
   Management → Incidents).
5. Confirm the list shows the two Active-state fixture incidents with IMS
   number, title, state, priority, types, location, and last update. Confirm
   Closed supply handoff is absent under the default Active states filter.
6. Change the State filter to include Closed (or All) and confirm Closed
   supply handoff appears. Restore Active states afterward if desired.
7. Confirm Priority filters and column-header sorting change the visible
   order without exposing non-fixture events.
8. Confirm Home and IC Field Reports links are present. Open IC Field
   Reports (`/ims/field-reports`), confirm a Home link and an Incidents
   cross-link, then return to Incidents.

### C. Detail read, Name References, notes, and history

9. Open Medical assist near Gate A. Confirm detail shows current state,
   Local Field Event / Rangers context, location, creator, responders,
   types, priority, linked Radio relay check, initial opened/timeline
   content, `#medical` tag treatment, and Name Reference chips `@Blue-Hat`
   and `@Gate_A` that are visually distinct from tags.
10. Confirm Edit and Print PDF controls are available for the lead fixture
    session. (Viewer/operator control absence is retained from section A.)
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
    IMS number until the first valid title autosaves, then assigns the next
    fixture IMS number (for example `INC-2027-000043`) and moves to the edit
    route.
16. Edit title, state, started timestamp, free-text location, priority,
    incident types, and involved Rangers/responders. Confirm autosave status
    is visible, blank-title validation is visible, and Closed status does not
    block further edits on Closed supply handoff when opened for edit.
17. Confirm priority, state, and type labels remain visually and textually
    distinct on list and detail after edits.

### E. Linked incidents and Field Reports

18. On an open incident edit surface, unlink the pre-linked Radio relay
    check from Medical assist near Gate A (or the reverse), then re-link it.
    Confirm already-linked candidates are hidden from the add picker while
    the relationship remains preserved history rather than a merge.
19. Confirm the picker does not offer the current incident as a self-link
    candidate. (Explicit duplicate/self/cross-event rejection messages and
    shared-tag-first candidate ordering remain section A evidence.)
20. Attach Medical observation near Gate A (or another fixture Field Report).
    Confirm it appears in current attached Field Reports and as a copied
    timeline note headed `Field Report: <title>` with the Field Report author
    listed, then unlink it and confirm the copied note is struck while
    removal history remains visible.
21. From the Incidents list, open IC Field Reports. Confirm sortable headings,
    related-incident state/priority filters, and a linked/not-linked status
    filter. Open a Field Report from the list and, after re-attaching one,
    from an attached Field Report row on incident detail. Confirm the
    read-only IC detail shows FRA number, title, author, body, and related-
    incident links.

### F. Offline create/edit gate and PDF print

22. On create or edit, switch DevTools network to Offline. Confirm current-
    field autosave mutation is blocked with a server-connection explanation
    and without queued-offline language, while typed form values remain on
    the screen. Restore Online.
23. As the lead fixture session, open an incident detail and select Print
    PDF. Confirm a PDF downloads that includes IMS number, title, state,
    priority, types, responders, location, timeline notes, linked incidents,
    attached Field Reports when present, and an export timestamp.
24. Retain section A evidence that `ic_operator` and `ic_viewer` do not get
    Print PDF, that direct PDF URL requests are forbidden without an export
    audit row for non-lead actors, and that the server records
    `incident.exported` with `format=pdf` for permitted lead exports. The
    local fixture Print PDF path is client-rendered and does not write server
    audit rows by itself.

### G. Attachment strike and deferred surfaces

25. Retain section A evidence for `strike-incident-attachment`: stricken
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
- The local IMS list shows event fixture incidents with IMS number, title,
  state, priority, types, location, and last update; Closed incidents are
  filtered out by default and can be included.
- Incident detail exposes current fields, timeline, tags, and Name Reference
  chips; chip click returns permission-filtered list search without a Name
  Reference profile page.
- Operators/leads (lead fixture in the browser; operator coverage in
  automated suites) can append and strike notes, autosave create/edit
  current fields including priority/types/responders, link/unlink same-event
  incidents, and attach/unlink Field Reports with copied note strike on
  removal.
- Offline create/edit autosave is blocked without queued-offline language.
- IC Lead can print an incident PDF from the detail surface.
- IC Field Reports list/detail cross-links and filters work for the fixture
  data.
- Incident attachment upload UI remains deferred; attachment strike is
  proven by automated suites.
- Viewer/operator/non-IC browser role switching is not required of the human
  path; those boundaries are proven by automated suites.

## Evidence to capture

- Terminal output from the shared-client and server IMS test runs listed in
  section A.
- Screenshot of `/ims/incidents` showing Active fixture rows and default
  Closed filtering.
- Screenshot of Medical assist near Gate A detail with Name Reference chips
  distinct from `#tag` chips.
- Screenshot or notes of chip-driven list search after clicking `@Blue-Hat`.
- Screenshot of create/edit autosave showing the assigned IMS number and
  autosave status.
- Screenshot of an attached Field Report copied timeline note and the struck
  note after unlink.
- Screenshot of compact versus full history after a field edit and a note
  strike.
- Screenshot of offline create/edit blocked messaging without queued-offline
  language.
- Downloaded incident PDF sample from the lead fixture session.
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
- If the only failure is missing incident attachment upload UI, richer
  M11.19 search product UI, live HTTP wiring for the shared-client IMS
  fixture, spreadsheet exports, PowerSync, or node sync, mark that check Not
  Applicable to M11.11 and report scope leakage rather than expanding this
  task.
- If a reviewer cannot switch browser roles and treats that alone as a
  product defect, point them to section A automated role evidence rather than
  inventing an unsupported login path.
