# QA-POL-02: Product Document Authoring and Sharing

## Purpose

Verify the M11.15 product path for policy, procedure, and fragment maintainer work in Meridian Admin, outside Orchid/God Mode.

## Requirements covered

- `POL-001` through `POL-047`
- Requirements sections 3.8, 3.15, and 7.10
- Technical spec section 21
- UI implementation contract sections 12.3, 12.4, 12.6, and 17.3
- Meridian Alpha 1 task M11.15

## Environment

- Shared client running in Admin mode.
- Laravel app migrated and seeded.
- Product API available under `/api`.
- Browser with keyboard access and download inspection available.

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
5. Confirm the edit screen shows rendered preview, visibility review, document type `Policy`, version, scope, Export Markdown, Export PDF, and Share entry points.
6. Publish the policy and confirm the list shows `Published`, the expected visibility summary, and version `1.00`.
7. Download/export Markdown and confirm the exported content includes type, title, version, scope, export timestamp, and inline fragment text rather than the author-facing token.
8. Switch to the Rangers department lead context and open Department **Documents**.
9. Create `QA Product Radio Procedure` in department scope, save, publish, and confirm it is labeled `Procedure` separately from policies.
10. Confirm the department lead cannot create or move a document into organization scope.
11. Switch to the Rangers Dirt team lead context and create or edit a team-scoped fragment.
12. Confirm the fragment edit review lists referencing documents and the published reference impact count before saving a Markdown change.
13. Save a fragment Markdown change and confirm published referencing document versions bump their fragment revision while draft documents remain drafts.
14. Switch to the Gate staff-only context and open Department **Documents**.
15. Confirm only published visible documents are listed, fragment maintenance is hidden, and New Policy/New Procedure/New Fragment actions are not shown.
16. Attempt direct navigation to a maintainer create/edit URL as staff-only and confirm the page fails closed without the form.
17. Keyboard through the list, filters, editor fields, save/publish/archive, and export/share controls. Confirm focus is visible, labels are clear, and state/visibility are not color-only.

## Expected results

- MVP document maintainer work is reachable from normal Meridian Admin product surfaces, not only Orchid.
- Policy and Procedure remain separate document types and labels.
- Scope-specific maintainer access is enforced for organization, department, and team scopes.
- Staff-only users see only published documents visible to their scope and do not see fragment or draft maintainer controls.
- Editors are Markdown-only, render preview content with fragment text inline, and show fragment/reference review information.
- Publish/archive actions are explicit, visible on maintainable documents, and reflected in list state.
- Export/share entry points are present only for permitted maintainers; Markdown export includes required metadata and resolved fragment text.
- Fragment edits show published reference impact before save and produce expected version bump behavior.

## Evidence to capture

- Screenshot of organizer Documents list with policy/procedure rows and fragment section.
- Screenshot of policy editor showing preview, visibility review, and export/share entry points.
- Screenshot of department/team-scoped authoring.
- Screenshot of staff-only restricted/no-maintainer state.
- Exported Markdown sample showing metadata and inline fragment text.
- Notes from keyboard/focus review.

## Failure notes

- Record the current route, selected fixture/persona, document scope, and whether the failure occurred in product UI, product API, or export download.
- If export succeeds without required metadata or exposes `{{fragment:...}}`, treat it as a release-blocking document export failure.
