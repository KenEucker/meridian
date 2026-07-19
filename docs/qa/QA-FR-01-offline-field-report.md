# QA-FR-01: Offline Field Report

## Purpose

Verify the Milestone 9 Field Report workflow as a human reviewer: an
authorized field author can finalize a titled report with up to two photos
while offline, see a durable local submitted state immediately, reconnect and
receive an FRA number while photos upload separately, and continue to see the
original title and body unchanged.

This script also records automated evidence for append-only corrections, Name
Reference indexing, IC visibility, non-IC denial, and `ic_lead`-only photo
download. Those boundaries do not yet have reviewer-facing IC or append UI, so
the script does not invent those later Milestone 11 surfaces.

## Requirements covered

- `FR-001` through `FR-009`
- `NR-001` through `NR-007`
- `NR-011` through `NR-014`
- Technical spec: Section 17 Field Reports
- Technical spec: Section 18 Field Report Photos and Attachments
- Technical spec: Section 23 Audit Log
- Technical spec: Sections 27.1 and 28 Alpha 1 acceptance and non-goals
- Data/API spec: Sections 7.1 through 7.3, 10.15, 10.17, 15.3, and 15.4A
- UI Implementation Contract: Sections 14 and 16
- Meridian Alpha 1 tasks M9.1 through M9.9
- Milestone 9 QA gate: offline titled report submission, FRA assignment,
  immutable original content, IC-only event-wide visibility, and permitted
  Name Reference derivation without author-facing identity behavior

## Environment

- A dedicated development/QA database. The setup below runs
  `migrate:fresh --seed` and must not be used against shared or valuable data.
- Repository dependencies installed with approved PHP, Composer, Node.js 24
  LTS, and pnpm 11.x versions.
- Laravel available at `http://127.0.0.1:8000`.
- The shared Vue client available at `http://127.0.0.1:5173` in a secure browser
  context (`localhost` / `127.0.0.1` is sufficient).
- Browser DevTools capable of switching the page network condition to Offline.
- Two repository image fixtures:
  `meridian-signal-camp-base-logo.png` and
  `meridian-signal-camp-wordmark.webp`.
- At least three terminals: Laravel server, shared client dev server, and QA commands.

The local Field API used here is a development-only bearer-token seam. Keep it
disabled outside local development. PowerSync transport, signed operation
envelopes, node countersigning, and node-to-node blob sync remain owned by
later sync tasks.

## Personas

- Field author: local fixture user `local-field@meridian.test` / Local Field
  Author on the trusted Local Field Device.
- IC lead: event-scoped `ic_lead`, permitted to view event Field Reports,
  preview photos, and download photos.
- IC operator and IC viewer: permitted to view event Field Reports and preview
  photos, but not download photos.
- Non-IC department lead, organizer, lead organizer, and shift lead: denied
  another author's Field Report by default.
- Human reviewer observing the field UI and automated role-boundary evidence.

## Setup data

1. From the repository root, install dependencies if needed:
   ```bash
   corepack pnpm install
   composer --working-dir=apps/server install
   ```
2. Configure a dedicated local server database, then reset and seed it:
   ```bash
   cd apps/server
   php artisan migrate:fresh --seed
   php artisan meridian:seed-local-field-fixture
   ```
3. In `apps/server/.env`, set these local-only values:
   ```dotenv
   MERIDIAN_LOCAL_FIELD_API_ENABLED=true
   MERIDIAN_LOCAL_FIELD_API_TOKEN=local-field-dev-token
   MERIDIAN_LOCAL_FIELD_API_USER_ID=22222222-2222-4222-8222-222222222222
   MERIDIAN_CORS_ALLOWED_ORIGINS=http://127.0.0.1:5173,http://localhost:5173
   ```
4. Clear cached Laravel configuration:
   ```bash
   php artisan config:clear
   ```
5. From the repository root, create the ignored shared-client development override:
   ```bash
   cp apps/client/.env.development.example apps/client/.env.development.local
   ```
   Confirm its token matches the server:
   ```dotenv
   VITE_MERIDIAN_API_BASE_URL=http://127.0.0.1:8000
   VITE_MERIDIAN_LOCAL_FIELD_API_TOKEN=local-field-dev-token
   ```
6. Start Laravel in one terminal:
   ```bash
   php apps/server/artisan serve --host=127.0.0.1 --port=8000
   ```
7. Start the shared client in another terminal:
   ```bash
   corepack pnpm --filter @meridian/client run dev -- --host 127.0.0.1
   ```
8. Open `http://127.0.0.1:5173/staff/field-reports`. Clear site data first if
   an earlier local Field Report run is present. Confirm the empty state says
   **You have not submitted any Field Reports yet.**
9. Create a known GIF fixture for the rejection check:
   ```bash
   php -r 'file_put_contents(sys_get_temp_dir()."/qa-fr-unsupported.gif", base64_decode("R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=="));'
   ```

## Steps

### A. Automated Field Report evidence

1. From the repository root, run the shared client Field Report tests:
   ```bash
   corepack pnpm --filter @meridian/client run test
   ```
2. From `apps/server`, run the Field Report server suites:
   ```bash
   php artisan test \
     tests/Feature/FieldReportSchemaTest.php \
     tests/Feature/FieldReportAcceptanceTest.php \
     tests/Feature/FieldReportCommandHttpTest.php \
     tests/Feature/FieldReportPolicyTest.php \
     tests/Feature/FieldReportAppendTest.php \
     tests/Feature/NameReferenceFieldReportTest.php \
     tests/Feature/FieldReportPhotoProcessingTest.php \
     tests/Feature/FieldReportPhotoUploadTest.php \
     tests/Feature/FieldReportPhotoDownloadTest.php
   ```
3. Confirm all suites pass. In particular, retain output proving:
   - required title validation, title trimming, duplicate titles, immutable
     title/body, device UUID idempotency, and event-specific FRA numbering;
   - author-only append acceptance and non-author denial without rewriting the
     original title/body;
   - body/append-body Name Reference parsing, title exclusion, case-insensitive
     search, rebuildability, and permission-filtered results;
   - maximum two processed images, GIF rejection, dimension/size limits,
     EXIF removal, checksum verification, and immutable attachment metadata;
   - author and event-scoped IC view access, default non-IC lead denial, and
     denial for revoked or wrong-event IC grants;
   - preview access for permitted viewers, `ic_lead`-only download, signed URL
     enforcement, and expired URL denial.

### B. Cancel, validation, and attachment limits

4. With the browser online, select **Submit Field Report**.
5. Confirm the page shows the **Submit Field Report** heading, Local Field
   Event context, system-set submitted-by text, required **Title** and
   **Report text** controls, optional **Photos**, and **Submit** / **Cancel**
   actions. Confirm there is no Save action, draft state, autosave message,
   category/type, or map/location field.
6. Enter temporary title/body text, select **Cancel**, and confirm the list
   remains empty. Reopen the form and confirm the temporary text was not saved.
7. Confirm **Submit** stays disabled with a blank title or blank body. Confirm
   the title input enforces a 200-character maximum. Oversized server rejection
   is covered by `FieldReportAcceptanceTest`.
8. Select `/tmp/qa-fr-unsupported.gif` (or the operating system's temporary
   directory equivalent). Confirm the form reports that GIF images are not
   supported and does not consume a photo slot.
9. Select `meridian-signal-camp-base-logo.png` and
   `meridian-signal-camp-wordmark.webp`. Confirm both processed previews show
   dimensions and size, the help text reports `0 slots remaining`, and
   **Add photo** is disabled. Confirm a selected photo can be removed and added
   again before submission.

### C. Offline finalize and durable local state

10. In DevTools set the browser network condition to Offline. Keep the Laravel
    process running; the browser must be unable to reach it.
11. Enter title `QA FR @TitleOnly offline smoke`.
12. Enter this report text, preserving punctuation and casing:
    ```text
    Observed @Blue-Hat near @Gate_A. Follow-up requested from @blue-hat.
    ```
13. Ensure both valid photos are selected, then choose **Submit**.
14. Confirm navigation goes immediately to the submitted report detail even
    though the network is offline.
15. Confirm the detail shows:
    - the exact title and body entered;
    - **Submitted**;
    - **Pending sync**;
    - a `LOCAL-` temporary number and the explanation that an FRA number is
      assigned after sync;
    - a Field Report sync status panel showing queued report text;
    - both local photo previews;
    - a **Retry sync** action when report text, photos, or both are still pending
      or failed;
    - the statement that the original title and body are finalized and cannot
      be edited.
    Photo status may show **Pending upload** immediately, or **Upload failed**
    after the automatic sync attempt cannot reach the server while the browser
    is offline. Either state is acceptable; the report must remain finalized
    and locally durable either way. The local Field fixture does not show
    department/team context.
16. Reload the page while still offline. Confirm the report and local photo
    previews remain available with the same temporary/pending or failed states.
17. Return to **My Field Reports**. Confirm the list shows the title, temporary
    number, **Pending sync**, submitted time, and body excerpt. Confirm no other
    author's reports or incident-link state are shown.

### D. Reconnect, FRA assignment, and separate photo upload

18. Restore the browser network condition to Online.
19. Open the pending report and select **Retry sync**. Confirm the status
    message says **Sync completed.** If the API token or Field session is not
    available, confirm the status panel names that blocked state instead of
    silently doing nothing.
20. Confirm the temporary number is replaced by an event-specific number in
    `FRA-2027-NNNNNN` form and the Sync value changes to **Synced**.
21. Confirm both local photo entries show **Uploaded**. The text record may be
    accepted before its photos; photo upload failure must not revert the report
    to a draft or editable state.
22. From `apps/server`, inspect the accepted server record, derived references,
    and attachments:
    ```bash
    php artisan tinker --execute='$report = App\Models\FieldReport::query()->where("title", "QA FR @TitleOnly offline smoke")->latest("created_at")->firstOrFail(); print(json_encode(["id" => $report->id, "fra_number" => $report->fra_number, "title" => $report->title, "body" => $report->body, "sync_status" => $report->sync_status, "server_received_at" => (string) $report->server_received_at, "references" => $report->nameReferenceTokens()->orderBy("normalized_token")->get(["token", "normalized_token"])->toArray(), "attachments" => $report->attachments()->get(["id", "filename", "mime_type", "byte_size", "checksum", "storage_path"])->toArray()], JSON_PRETTY_PRINT).PHP_EOL);'
    ```
23. Confirm:
    - title and body exactly match the submitted source text;
    - status is `accepted`, an FRA number and server receipt time are present,
      and exactly two attachment records exist;
    - references contain `Blue-Hat` / `blue-hat` and `Gate_A` / `gate_a` only;
    - repeated `@blue-hat` is deduplicated case-insensitively;
    - `TitleOnly` is absent because titles are not parsed;
    - attachment filenames are plain text, checksums are populated, and files
      use the private Field Report attachment path.

### E. Immutability and explicit UI boundaries

24. On report detail, confirm there is no Edit, Save, Delete, Strike, title
    change, or body change action.
25. Refresh and navigate away/back. Confirm the original title/body and FRA
    number remain unchanged.
26. Confirm the submitted body displays `@Blue-Hat`, `@Gate_A`, and
    `@blue-hat` as source text. The author entry/detail path must not show
    autocomplete, existing-reference suggestions, notifications, profile
    links, context menus, a dedicated Name Reference page, or additional
    records revealed by those tokens.
27. Confirm no append UI is claimed by this build. Author-only append,
    non-author denial, immutable append records, timeline ordering, and Name
    Reference indexing are verified by `FieldReportAppendTest` and
    `NameReferenceFieldReportTest` until their owning UI/transport exists.
28. Confirm no IC Field Report review UI is claimed by this build. IC event
    visibility, non-IC denial, preview authorization, and `ic_lead`-only
    downloads are verified by `FieldReportPolicyTest` and
    `FieldReportPhotoDownloadTest` until Milestone 11 adds IMS review surfaces.

### F. Explicit non-goals

29. Confirm this script did not require incident creation/linking, incident
    Name Reference chips, node-to-node blob sync, PowerSync transport,
    downloadable failed-sync export, or append photos.
30. Confirm no photo delete/redact action exists, no image binaries are synced
    down as general device cache data, and no public storage path is exposed.
31. Confirm Field Reports remain independent records with one immutable title,
    one unstructured original body, optional photos, and no GPS, categories,
    configurable form structure, map selector, camp selector, coordinates, or
    dropped-pin workflow.

## Expected results

- The targeted server and mobile automated suites pass.
- Cancel creates no draft; title and body are required; GIF is rejected; two
  valid images fill the fixed photo allowance.
- Offline submit immediately creates a finalized, durable local report with a
  temporary `LOCAL-` number, pending text sync, and separately pending or
  failed photo uploads that remain retryable.
- Reconnect/retry accepts the text once, replaces the local number with an FRA
  number, uploads two photos, and leaves the original title/body immutable.
- The server stores exactly two private attachment records with checksums and
  derives case-insensitive Name References from body text only.
- Authors see their own reports. Event-scoped IC roles may view event reports;
  non-IC leads do not gain visibility. Only `ic_lead` may download photos.
- Name References remain source text and rebuildable derived index rows; they
  do not become identity/profile data or grant visibility.
- Later IMS review, append UI/transport, incident linking, PowerSync transport,
  and node-to-node blob sync are not represented as implemented UI.

## Evidence to capture

- Terminal output from the mobile and targeted server test runs.
- Screenshot of the create form with two valid processed previews and zero
  remaining slots; capture the GIF rejection separately.
- Screenshot of offline detail showing title/body, `LOCAL-` number,
  **Pending sync**, two local photos in pending or failed upload state, and
  immutability copy.
- Screenshot after reload while offline proving durable local state.
- Screenshot after reconnect showing the FRA number, **Synced**, and two
  **Uploaded** photos.
- Tinker JSON showing accepted title/body, FRA number, exactly two attachments,
  `blue-hat` and `gate_a` derived references, and no `titleonly` reference.
- Test output for role visibility and `ic_lead`-only photo download boundaries.

## Failure notes

- Record the failed step, exact UI/error text, operating system/browser,
  commit SHA, Node/pnpm/PHP versions, and whether the server and mobile test
  suites passed.
- For sync failures, record browser network state, Laravel availability, local
  API enabled state, API base URL, whether tokens match, Laravel log output,
  and the detail-page upload error before retrying.
- If offline submit does not produce a durable finalized local report, stop
  and file a blocking FR-001/FR-007 offline issue.
- If acceptance changes title/body, creates a second report on retry, or
  assigns no FRA number, stop and file a blocking FR-003/FR-007 sync issue.
- If a third photo is accepted, a GIF is accepted, EXIF/GPS survives
  processing, or photo failure removes/edits the submitted report, stop and
  file a blocking attachment issue.
- If a non-IC lead can view another author's report, or an IC
  operator/viewer/author can download its photo, stop and file a blocking
  permission issue.
- If title text creates Name Reference rows, Name References reveal
  unauthorized reports, or the author UI implies mentions/profiles/
  notifications, stop and file a blocking NR-007/NR-011/NR-012 issue.
- If a missing later IMS, append, PowerSync, node-sync, export, or incident
  surface is the only failure, mark that check Not Applicable to M9.9 and
  report scope leakage rather than expanding this task.
