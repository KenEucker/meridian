# QA-POL-02: Product Document Authoring and Sharing

## Purpose

Verify the M11.15 product path for policy, procedure, and fragment maintainer work in Meridian Admin, outside Orchid/God Mode.

## Requirements covered

- `POL-001` through `POL-047`
- `CLIENT-023`, `CLIENT-019`, `CLIENT-020`: the surfaces read and write through their endpoints, and exports go through short-lived download URLs
- Requirements sections 3.8, 3.15, and 7.10
- Technical spec section 21
- Data/API spec sections 11.1 through 11.7, 11.4A, and 5.7
- UI implementation contract sections 12.3, 12.4, 12.6, and 17.3
- Meridian Alpha 1 tasks M11.15 and M16.19

## Environment

- Shared client running in Admin mode.
- Laravel app migrated and seeded.
- Product API available under `/api`.
- Browser with keyboard access, network inspection, and download inspection available.
- A way to stop the node or disconnect the client from it, for the connected-only check.

## Personas

- Organizer or lead organizer: can maintain organization-scoped documents/fragments.
- Department lead or department administration: can maintain department-scoped documents/fragments.
- Team lead: can maintain documents/fragments for led teams.
- Staff-only member: can view published visible documents but cannot maintain drafts/fragments.

## Setup data

- Organization: Signal Camp development fixture.
- Department lead context: Rangers.
- Team lead context: Rangers Dirt.
- Staff-only context: Gate Credentials.
- Fragment slug: `qa-product-guidance`.
- Policy title: `QA Product Conduct Policy`.
- Procedure title: `QA Product Radio Procedure`.

## Steps

1. Open Meridian Admin as an organizer and navigate from Home to **Documents**.
2. Confirm the page shows separate policy/procedure rows, a Fragments section, state filtering, and New Policy/New Procedure/New Fragment actions.
3. Create the fragment `QA Product Guidance` with slug `qa-product-guidance`; confirm the preview renders Markdown and no Draft/Published/Archived control is shown.
4. Create `QA Product Conduct Policy` in organization scope with Markdown that references `{{fragment:qa-product-guidance}}`; save it.
5. Confirm the edit screen shows the saved rendered preview, visibility review, document type `Policy`, version, scope, the fragments it references, Export Markdown, Export PDF, and Share entry points.
6. Publish the policy. Confirm a reason is required before the publish is sent, then confirm the list shows `Published`, the expected visibility summary, and version `1.00`.
7. With the network panel open, use Export Markdown. Confirm the client first requests a short-lived URL from `POST /api/policy-documents/{id}/export/markdown/download-url` and then navigates to the URL it was issued, with no bearer token in that link.
8. Confirm the exported content includes type, title, version, scope, export timestamp, and inline fragment text rather than the author-facing token.
9. Switch to the Rangers department lead context and open Department **Documents**.
10. Confirm the Scope selector offers only the scopes this persona may maintain, and that they came from the document read rather than from every scope in the organization.
11. Create `QA Product Radio Procedure` in department scope, save, publish, and confirm it is labeled `Procedure` separately from policies.
12. Confirm the department lead cannot create or move a document into organization scope, and that the refusal shown is the server's own sentence.
13. Change the state filter and confirm the client re-requests `GET /api/organizations/{organization}/documents?state=...` rather than filtering the list it already holds.
14. Switch to the Rangers Dirt team lead context and create or edit a team-scoped fragment.
15. Confirm the fragment edit review lists referencing documents and the published reference impact count before saving a Markdown change.
16. Save a fragment Markdown change and confirm published referencing document versions bump their fragment revision while draft documents remain drafts.
17. Switch to the Gate staff-only context and open Department **Documents**.
18. Confirm only published visible documents are listed, fragment maintenance is hidden, and New Policy/New Procedure/New Fragment actions are not shown.
19. Find a published document this persona can read but not maintain and confirm the row offers no Edit, Publish, or Archive control.
20. Attempt direct navigation to a maintainer create/edit URL as staff-only and confirm the page fails closed without the form.
21. Stop the node, reload the document library, and attempt an edit. Restart the node and use the retry control.
22. Keyboard through the list, filters, editor fields, save, the publish/archive reason prompt, and export/share controls. Confirm focus is visible, labels are clear, and state/visibility are not color-only.

## Expected results

- MVP document maintainer work is reachable from normal Meridian Admin product surfaces, not only Orchid.
- Policy and Procedure remain separate document types and labels.
- Scope-specific maintainer access is enforced for organization, department, and team scopes.
- Staff-only users see only published documents visible to their scope and do not see fragment or draft maintainer controls.
- Editors are Markdown-only, and the preview is the server's render of the saved document with fragment text inline rather than a browser-side approximation of unsaved text.
- Publish/archive actions are explicit, visible only on documents the server says this caller may maintain, require a reason, and are reflected in list state after the surface re-reads.
- Every list column — state, scope, version, Event Info placement, visibility summary — is the server's own wording rather than a label the client keeps.
- Export/share entry points are present only for permitted maintainers. An export is fetched by requesting a short-lived URL and then navigating to it, the link carries no bearer token, and the file includes required metadata and resolved fragment text.
- Fragment edits show published reference impact before save and produce expected version bump behavior.
- With the node unreachable, the library states that it could not be loaded and offers a retry, and no edit is queued or reported as saved. Document authoring is connected-only work.

## Evidence to capture

- Screenshot of organizer Documents list with policy/procedure rows and fragment section.
- Screenshot of policy editor showing preview, visibility review, and export/share entry points.
- Screenshot of department/team-scoped authoring.
- Screenshot of staff-only restricted/no-maintainer state.
- Exported Markdown sample showing metadata and inline fragment text.
- Network trace of one export showing the download-url request followed by the navigation, with no credential in the issued link.
- Screenshot of the document library with the node unreachable, showing the stated failure and the retry control.
- Notes from keyboard/focus review.

## Failure notes

- Record the current route, selected fixture/persona, document scope, and whether the failure occurred in product UI, product API, or export download.
- If export succeeds without required metadata or exposes `{{fragment:...}}`, treat it as a release-blocking document export failure.
- If an export link carries a bearer token, or the client fetches the file without first asking for a short-lived URL, stop testing and file a blocking `CLIENT-019` issue.
- If the library renders documents while the node is unreachable, or reports a save that never reached it, stop testing and file a blocking M16.19 issue.
- If a row offers Edit, Publish, or Archive on a document the server refuses to update, file a `CLIENT-006` issue: the row must offer what the command would accept.
