# QA-POL-01: Policy, Procedure, Fragment, Acknowledgment, and Export

## Purpose

Verify the Milestone 6 policy/procedure gate: an authorized maintainer can create a reusable Markdown fragment, author separate policy and procedure documents that reference it, publish and review their rendered content, understand the published-document impact of changing the fragment, record a connected acknowledgment through the current domain-service path, and export each document as Markdown and PDF.

This scenario uses Orchid for the implemented document and fragment administration surfaces. Acknowledgment requirements and acceptance currently have no browser UI or API command surface, so their human verification uses the documented Laravel domain services from `apps/server`. Do not treat that domain-service verification as a substitute for the future signup/training acknowledgment UI.

## Requirements covered

- `POL-001` through `POL-004`
- `POL-014` through `POL-016`, `POL-019` through `POL-029`, and `POL-031` through `POL-045`
- `POL-046` and `POL-047`
- Technical spec sections 21.3 through 21.12 and 22.2
- Data/API spec sections 11.2 through 11.11
- UI implementation contract sections 11.15 through 11.17, 12.9, and 17
- Accessibility checklist sections 2 through 5, 8, 14, 17, and 18
- Meridian Alpha 1 tasks M6.1 through M6.12

## Environment

- Fresh checkout or task branch with server dependencies installed.
- Laravel app migrated and seeded with the development scenario:
  ```bash
  cd apps/server
  php artisan migrate:fresh --seed
  ```
- Development web server running at `http://127.0.0.1:8000`.
- Orchid available at `http://127.0.0.1:8000/admin`.
- A queue worker available to process the database-backed fragment-version-bump job:
  ```bash
  cd apps/server
  php artisan queue:work --once
  ```
- Shell access from `apps/server` for tinker verification commands and an installed PDF reader for the exported PDF.

## Personas

- Document maintainer: seeded Olive Organizer with the three document-admin permissions below.
- Acknowledging staff member: seeded Vera Staff, whose linked staff profile is used for the acknowledgment record.
- Restricted admin: authenticated user with `platform.index` but without any document-admin permission.

## Setup data

- Organization: `Idaho Burners` (`idaho-burners`).
- Department: `Rangers` (`RANGERS`) for the department-scoped procedure acknowledgment requirement.
- Fragment: **QA Radio Callout**, slug `qa-radio-callout`, organization scope, Markdown `Use the radio **only when needed**.`
- Policy: **QA Radio Safety Policy**, slug `qa-radio-safety-policy`, organization scope.
- Procedure: **QA Radio Check Procedure**, slug `qa-radio-check-procedure`, department scope (`Rangers`).
- Publish/archival reason: `QA approval for policy and procedure workflow.`
- Grant Olive the current Orchid resource permissions before signing in:
  ```bash
  cd apps/server
  php artisan tinker --execute='$user = App\Models\User::query()->where("email", "olive.organizer@idaho-burners.test")->firstOrFail(); $user->forceFill(["permissions" => array_merge($user->permissions ?? [], ["platform.index" => true, "platform.policy-documents" => true, "platform.procedure-documents" => true, "platform.document-fragments" => true])])->save();'
  ```
- Use the seeded development password `password` when the local Orchid login uses the seeded account credentials.

## Steps

### A. Verify document administration access and create a reusable fragment

1. Sign in to Orchid as Olive Organizer and open **Policies & Procedures > Document Fragments** (`/admin/document-fragments`).
2. Confirm the list shows name, slug, scope, version, organization, and last edit columns, then choose **Create**.
3. Create **QA Radio Callout** using the organization scope and the fragment setup data. Save it.
4. Reopen the fragment and confirm its version is `1`, that no Draft, Published, or Archived state control is present, and that no referencing documents are listed yet.
5. Attempt to save the fragment with `{{fragment:another-fragment}}` in its Markdown source. Confirm the save is blocked with a broken/nested-fragment validation error and that the fragment source and version remain unchanged.
6. Sign out and sign in as the restricted admin. Attempt to open `/admin/document-fragments`, `/admin/policy-documents`, and `/admin/procedure-documents`.
7. Confirm all three routes are denied. Sign back in as Olive before continuing.

### B. Author, validate, publish, and view separate policy and procedure documents

8. Open **Policies & Procedures > Policy Documents** (`/admin/policy-documents`) and choose **Create**.
9. Create the organization-scoped policy using the policy setup data, Draft state, and this Markdown source:
   ```markdown
   # Radio safety

   {{fragment:qa-radio-callout}}

   <script>window.meridianQaUnsafe = true;</script>
   ```
10. Save the policy. Confirm the policy list shows **QA Radio Safety Policy** as **Draft**, organization scope, and version `1.00`.
11. Reopen the saved policy. Confirm the Fragment references table shows `{{fragment:qa-radio-callout}}`, **QA Radio Callout**, its current version, and organization scope.
12. Confirm the rendered preview displays the fragment sentence inline as normal document content, exposes **Policy**, title, scope, version, and state, and does not execute or render the raw `<script>` source as active/visible HTML.
13. In a separate new Procedure Document, attempt to save a document with `{{fragment:missing-qa-fragment}}`. Confirm the Markdown field has a broken-reference error and no procedure record is created.
14. Create **QA Radio Check Procedure** with organization `Idaho Burners`, department scope target `Rangers`, Draft state, and this Markdown source:
   ```markdown
   # Radio check procedure

   Confirm the assigned radio is powered on.

   {{fragment:qa-radio-callout}}
   ```
15. Save the procedure. Confirm it appears only in **Procedure Documents**, is labeled **Procedure**, and is not listed with policy documents.
16. Attempt to change the saved Draft policy to Published without a reason. Confirm the state change is blocked with a reason validation error and the policy remains Draft.
17. Reopen each document, change its state to **Published**, enter the publish reason from setup data, and save. Confirm each list shows **Published**, version `1.00`, and the editor still offers the rendered preview and fragment-reference details.
18. From `apps/server`, verify document creation, publication, source actor, fragment reference, and audit history:
   ```bash
   php artisan tinker --execute='$policy = App\Models\PolicyDocument::query()->where("slug", "qa-radio-safety-policy")->with("fragmentReferences.fragment")->firstOrFail(); $procedure = App\Models\ProcedureDocument::query()->where("slug", "qa-radio-check-procedure")->with("fragmentReferences.fragment")->firstOrFail(); print(json_encode(["policy" => ["state" => $policy->state, "version" => $policy->version(), "created_by" => $policy->createdBy?->email, "references" => $policy->fragmentReferences->map(fn ($r) => ["token" => $r->token, "fragment" => $r->fragment?->slug, "version_at_last_edit" => $r->fragment_version_at_last_edit])->values()], "procedure" => ["state" => $procedure->state, "version" => $procedure->version(), "created_by" => $procedure->createdBy?->email, "references" => $procedure->fragmentReferences->map(fn ($r) => ["token" => $r->token, "fragment" => $r->fragment?->slug, "version_at_last_edit" => $r->fragment_version_at_last_edit])->values()]], JSON_PRETTY_PRINT).PHP_EOL); App\Models\AuditEvent::query()->whereIn("entity_id", [$policy->id, $procedure->id])->orderBy("created_at")->get(["action", "entity_id", "actor_user_id", "reason", "source_context"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
   ```
19. Confirm both documents are Published at version `1.00`, each reference resolves to the fragment at version `1`, and the audit events include creation and publication attributed to Olive with Orchid source context and the publish reason.

### C. Review fragment impact, current rendering, and version bump behavior

20. Open **QA Radio Callout**. Confirm its Referencing documents table lists both the policy and procedure with their document types, scopes, **Published** state, and versions.
21. Confirm the pre-save warning has the accessible heading **Published document impact** and says that changing the fragment will bump versions for **2 published documents**.
22. Change the fragment Markdown source to `Use the radio **only when needed**; state your call sign first.` and save it.
23. Run the queued job once from `apps/server`:
   ```bash
   php artisan queue:work --once
   ```
24. Reopen both documents. Confirm each preview immediately shows the new fragment text inline, neither exposes the `{{fragment:...}}` token as reader content, and each document version is now `1.01`.
25. Verify the fragment version, document versions, and one bump ledger row for each published document:
   ```bash
   php artisan tinker --execute='$fragment = App\Models\DocumentFragment::query()->where("slug", "qa-radio-callout")->firstOrFail(); $policy = App\Models\PolicyDocument::query()->where("slug", "qa-radio-safety-policy")->firstOrFail(); $procedure = App\Models\ProcedureDocument::query()->where("slug", "qa-radio-check-procedure")->firstOrFail(); print(json_encode(["fragment_version" => $fragment->version, "policy_version" => $policy->version(), "procedure_version" => $procedure->version(), "bump_rows" => App\Models\DocumentFragmentVersionBump::query()->where("fragment_id", $fragment->id)->where("fragment_version", $fragment->version)->get(["document_type", "document_id", "fragment_version"])], JSON_PRETTY_PRINT).PHP_EOL);'
   ```
26. Confirm the fragment is version `2`, both published documents are `1.01`, and there are exactly two bump rows. Confirm no separate warning claims a document must be republished before the updated fragment is visible.

### D. Verify scoped connected acknowledgment, immutable proof, and boundaries

27. Create an active organization-scoped signup requirement for the published policy, create an active development node for the organization, then acknowledge it as Vera and her linked staff profile:
   ```bash
   php artisan tinker --execute='$organization = App\Models\Organization::query()->where("slug", "idaho-burners")->firstOrFail(); $policy = App\Models\PolicyDocument::query()->where("slug", "qa-radio-safety-policy")->firstOrFail(); $requirement = app(App\Services\Documents\DocumentAcknowledgmentRequirementService::class)->create($organization, App\Models\DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_POLICY, $policy->id, App\Models\DocumentAcknowledgmentRequirement::SCOPE_ORGANIZATION, $organization->id, App\Models\DocumentAcknowledgmentRequirement::CONTEXT_SIGNUP); $user = App\Models\User::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $node = App\Models\Node::factory()->create(["organization_id" => $organization->id]); $acknowledgment = app(App\Services\Documents\DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node, $staff); print(json_encode(["requirement_id" => $requirement->id, "requirement_scope" => $requirement->scope_type, "context" => $requirement->requirement_context, "acknowledgment_id" => $acknowledgment->id, "document_version" => sprintf("%d.%02d", $acknowledgment->document_revision, $acknowledgment->fragment_revision), "staff_id" => $acknowledgment->staff_id, "node_id" => $acknowledgment->accepted_by_node_id], JSON_PRETTY_PRINT).PHP_EOL);'
   ```
28. Confirm the requirement is organization-scoped with `signup` context and that the acknowledgment records the published policy at version `1.01`, the accepting node, Vera's linked staff ID, and a timestamp.
29. Create an active department-scoped training requirement for the published procedure, and acknowledge it as Vera on an active organization node:
   ```bash
   php artisan tinker --execute='$organization = App\Models\Organization::query()->where("slug", "idaho-burners")->firstOrFail(); $department = App\Models\Department::query()->where("organization_id", $organization->id)->where("code", "RANGERS")->firstOrFail(); $procedure = App\Models\ProcedureDocument::query()->where("slug", "qa-radio-check-procedure")->firstOrFail(); $requirement = app(App\Services\Documents\DocumentAcknowledgmentRequirementService::class)->create($organization, App\Models\DocumentAcknowledgmentRequirement::DOCUMENT_TYPE_PROCEDURE, $procedure->id, App\Models\DocumentAcknowledgmentRequirement::SCOPE_DEPARTMENT, $department->id, App\Models\DocumentAcknowledgmentRequirement::CONTEXT_TRAINING); $user = App\Models\User::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail(); $node = App\Models\Node::factory()->create(["organization_id" => $organization->id]); $acknowledgment = app(App\Services\Documents\DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node); print(json_encode(["requirement_scope" => $requirement->scope_type, "scope_id" => $requirement->scope_id, "context" => $requirement->requirement_context, "document_type" => $acknowledgment->document_type, "document_version" => sprintf("%d.%02d", $acknowledgment->document_revision, $acknowledgment->fragment_revision)], JSON_PRETTY_PRINT).PHP_EOL);'
   ```
30. Confirm the second requirement uses only department scope and training context, and that the recorded acknowledgment is a procedure acknowledgment at the document's current version.
31. Verify immutable proof and audit history for the policy acknowledgment:
   ```bash
   php artisan tinker --execute='$policy = App\Models\PolicyDocument::query()->where("slug", "qa-radio-safety-policy")->firstOrFail(); $acknowledgment = App\Models\DocumentAcknowledgment::query()->where("document_type", "policy")->where("document_id", $policy->id)->firstOrFail(); $snapshot = App\Models\DocumentVersionSnapshot::query()->where("document_type", "policy")->where("document_id", $policy->id)->where("document_revision", $acknowledgment->document_revision)->where("fragment_revision", $acknowledgment->fragment_revision)->firstOrFail(); $audit = App\Models\AuditEvent::query()->where("entity_id", $acknowledgment->id)->where("action", "document_acknowledgment.accepted")->firstOrFail(); print(json_encode(["acknowledged_at" => $acknowledgment->acknowledged_at?->toIso8601String(), "source_snapshot" => $snapshot->markdown_source_snapshot, "resolved_snapshot" => $snapshot->resolved_markdown_snapshot, "audit_action" => $audit->action, "audit_actor_user_id" => $audit->actor_user_id, "audit_actor_node_id" => $audit->actor_node_id, "audit_source" => $audit->source_context], JSON_PRETTY_PRINT).PHP_EOL);'
   ```
32. Confirm the snapshot retains both source and resolved Markdown for the acknowledged version, the resolved snapshot has the updated inline fragment text rather than its token, and the audit action is `document_acknowledgment.accepted` with user/node attribution.
33. Repeat the policy acknowledgment using the existing requirement and node. Confirm it returns the existing acknowledgment ID without creating a second acknowledgment, version snapshot, or acceptance audit event:
   ```bash
   php artisan tinker --execute='$policy = App\Models\PolicyDocument::query()->where("slug", "qa-radio-safety-policy")->firstOrFail(); $requirement = App\Models\DocumentAcknowledgmentRequirement::query()->where("document_type", "policy")->where("document_id", $policy->id)->where("requirement_context", "signup")->firstOrFail(); $user = App\Models\User::query()->where("email", "vera.staff@idaho-burners.test")->firstOrFail(); $staff = $user->staffProfiles()->firstOrFail(); $node = App\Models\Node::query()->where("organization_id", $policy->organization_id)->firstOrFail(); $before = ["acknowledgments" => App\Models\DocumentAcknowledgment::query()->where("document_type", "policy")->where("document_id", $policy->id)->count(), "snapshots" => App\Models\DocumentVersionSnapshot::query()->where("document_type", "policy")->where("document_id", $policy->id)->count(), "acceptance_audits" => App\Models\AuditEvent::query()->where("action", "document_acknowledgment.accepted")->count()]; $acknowledgment = app(App\Services\Documents\DocumentAcknowledgmentService::class)->acknowledge($requirement, $user, $node, $staff); $after = ["acknowledgments" => App\Models\DocumentAcknowledgment::query()->where("document_type", "policy")->where("document_id", $policy->id)->count(), "snapshots" => App\Models\DocumentVersionSnapshot::query()->where("document_type", "policy")->where("document_id", $policy->id)->count(), "acceptance_audits" => App\Models\AuditEvent::query()->where("action", "document_acknowledgment.accepted")->count()]; print(json_encode(["acknowledgment_id" => $acknowledgment->id, "before" => $before, "after" => $after], JSON_PRETTY_PRINT).PHP_EOL);'
   ```
34. Confirm the before/after counts are identical and the returned ID matches the policy acknowledgment from step 27.
35. Confirm the current Alpha 1 boundary: no acknowledgment control or review screen is expected in Orchid, no offline/queued acknowledgment path exists, and acknowledgments are not presented as direct shift-signup or credential-eligibility gates. Do not test packet assembly, document search, or automatic re-acknowledgment after a future document/fragment edit; they are outside this task's implemented path.

### E. Verify Markdown/PDF exports and accessibility

36. Open the saved policy editor and select **Export Markdown**, then **Export PDF**. Repeat for the saved procedure.
37. Confirm every download is available only to the corresponding permitted document maintainer; as the restricted admin, direct navigation to either document's export URL is denied and does not add an export audit event.
38. Inspect the Markdown files and PDF text. Confirm each includes document type, title, current version, scope, and an export timestamp; the fragment text is inline; and neither output includes `{{fragment:qa-radio-callout}}`. Confirm the PDF does not contain active raw HTML/script output.
39. Verify format-specific export audit events:
   ```bash
   php artisan tinker --execute='$policy = App\Models\PolicyDocument::query()->where("slug", "qa-radio-safety-policy")->firstOrFail(); $procedure = App\Models\ProcedureDocument::query()->where("slug", "qa-radio-check-procedure")->firstOrFail(); App\Models\AuditEvent::query()->whereIn("entity_id", [$policy->id, $procedure->id])->whereIn("action", ["policy_document.exported", "procedure_document.exported"])->orderBy("created_at")->get(["action", "entity_id", "actor_user_id", "source_context", "after_json"])->each(fn ($event) => print($event->toJson(JSON_PRETTY_PRINT).PHP_EOL));'
   ```
40. Confirm there is one audited event for each requested export format, attributed to Olive with Orchid source context and metadata for `markdown` or `pdf`, document type, version, scope, and export time.
41. Complete the applicable accessibility review by keyboard only:
    - tab through document/fragment list links, creation/edit fields, Save, Cancel, and the export links in task order;
    - confirm visible focus, visible/programmatic labels, and non-color-only state labels for Draft/Published/Archived;
    - confirm the document viewer exposes document type, title, scope, version, and state; Markdown headings/lists/links remain semantic; and inline fragment text reads as ordinary document text;
    - confirm the Fragment references and Referencing documents tables retain column headings and readable fragment names/versions;
    - confirm the published-document-impact warning is understandable without color alone; and
    - record that acknowledgment UI and its server-connection state cannot be reviewed in this task because no such UI exists yet.

## Expected results

- Only authorized Orchid users can access their policy, procedure, and fragment administration surfaces; a restricted user is denied.
- Fragments are reusable Markdown-only content with no lifecycle state, and a version that increases only when their Markdown changes. This scenario exercises an organization-scoped fragment; scope-specific maintainer-role enforcement remains outside the implemented resource-permission path. Fragments cannot reference other fragments.
- Policies and procedures remain separate document types, with distinct lists and editors, documented scope choices, only Draft/Published/Archived states, and a reason required for a publish/archival transition.
- Valid fragment references are persisted and shown to authors with their current fragment name, scope, and version. Broken or malformed references block the document save.
- Saved previews render sanitized Markdown and current fragment text inline, show type/title/scope/version/state metadata, and do not expose reader-facing fragment tokens or active raw HTML.
- Editing a fragment lists every referencing document and warns with the exact count of published references. After the queued job runs, every published referencing document shows new fragment text and increments only its fragment revision; drafts/archived documents are not expected to change.
- Active acknowledgment requirements permit only policy/procedure documents, organization/department scope, and signup/training context. The connected domain action rejects inactive, unpublished, cross-organization, invalid-scope, unlinked-staff, or revoked-node requests without partial history.
- A successful connected acknowledgment records document type/ID/version, requirement scope, timestamp, node, optional linked staff, an immutable proof snapshot, and `document_acknowledgment.accepted` audit history. Repeating an unchanged acceptance is idempotent; later document/fragment changes do not automatically re-require it.
- Markdown and PDF exports are authorized per document resource, generated one document at a time, include required metadata and current inline fragments, and create format-specific audit events. Packet assembly and acknowledgment-status exports are not expected.
- The reviewed document/admin controls satisfy the applicable manual keyboard, focus, label, semantic-rendering, non-color, permission, and table accessibility checks. No acknowledgment UI, offline acknowledgment, or direct shift/credential gate is expected in this slice.

## Evidence to capture

- Screenshot of each policy, procedure, and fragment list showing its separate labels, scope, state/version columns, and the created QA records.
- Screenshots of policy/procedure editors showing the Fragment references table and saved rendered preview.
- Screenshot or note demonstrating the broken-reference and nested-fragment validation errors.
- Screenshot of the fragment editor listing both referencing documents and the exact published-document-impact warning.
- Tinker output for document/reference/audit state before and after publication.
- Tinker output proving the fragment moved from version `1` to `2`, both published documents moved from `1.00` to `1.01`, and two version-bump rows were recorded.
- Tinker output for organization-signup policy and department-training procedure requirements and acknowledgment records.
- Tinker output for the immutable snapshot and `document_acknowledgment.accepted` audit event.
- Tinker output or row counts proving the repeated policy acknowledgment reused the existing record.
- Saved Markdown and PDF export samples for both document types, plus export-audit query output.
- Restricted-admin denial evidence for document screens and export routes.
- Notes from the manual keyboard/accessibility review, including the unimplemented acknowledgment UI boundary.

## Failure notes

- If policy and procedure records share an admin list or are not explicitly labeled by type, stop and file a document-type separation issue.
- If a document or fragment accepts raw HTML as active rendered content, a missing/invalid fragment reference, a nested fragment reference, or a scope target from another organization, stop and file a blocking rendering/validation issue.
- If publishing or archiving succeeds without a reason, or a document exposes an Active state, stop and file a lifecycle issue.
- If fragment edits do not show published-reference impact before save, fail to update rendered content, bump a published document more than once for one fragment version, or alter Draft/Archived documents, stop and file a versioning/history issue.
- If a restricted user can access document administration or export, or a denied export writes an audit event, stop and file a permission/audit issue.
- If an acknowledgment accepts a Draft/Archived document, inactive requirement, unsupported context/scope, cross-organization document/node, revoked node, or staff profile not linked to the user, stop and file a blocking acknowledgment-integrity issue.
- If a successful acknowledgment lacks the immutable version snapshot, version/scope/node/timestamp data, or audit attribution, stop and file an evidence-history issue.
- If repeated acknowledgment creates duplicate rows, snapshots, or audit events, or if a later document/fragment edit mutates an old acknowledgment, stop and file an idempotency/history issue.
- If an acknowledgment appears as an offline queue, a direct shift-signup/credential gate, an acknowledgement-review UI, or packet assembly during this scenario, stop and file a scope-leakage issue. These surfaces are not implemented by M6.12.
