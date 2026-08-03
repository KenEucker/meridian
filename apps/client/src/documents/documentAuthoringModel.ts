// The policy, procedure, and fragment surfaces' data layer (M16.19;
// CLIENT-023, CLIENT-019, CLIENT-020; data/API 11.1 through 11.7, 11.4A, 5.7).
//
// Until this task this module was the document library. Eight documents and two
// fragments were compiled into the client, two `shallowRef`s held them, and the
// rules the node enforces in `DocumentAdminService`, `DocumentProductAccess`,
// `DocumentRenderer`, and `DocumentFragmentReferenceService` were written out a
// second time in the browser: who may maintain which scope, who may see a
// published document, slug shape, the document/fragment revision split, the
// fragment-driven version bump across referencing published documents, the
// refusal to nest fragments, and a hand-rolled Markdown renderer. None of it
// reached a node, so a policy it published was nobody's policy and a version it
// bumped was nobody's version — and each rule kept here could only drift from
// the one that actually governs.
//
// This module is now a translation of the document endpoints. Four choices in
// it are deliberate:
//
//  1. **One read per surface.** `GET /api/organizations/{id}/documents` answers
//     with the caller's maintainable scopes, the Event Info placements a
//     document may take, every document they may see, and every fragment they
//     may maintain. The library page, the featureset embedded in Admin, and the
//     option lists on the authoring form all render off that one response.
//  2. **Authority comes from the response.** `access.can_maintain` is the
//     node's answer for the surface and `canMaintain` on each document is its
//     answer for that row, which is the same answer the commands enforce. The
//     old predicates over a fixture department's role flags are gone; all they
//     could do was disagree with the server (CLIENT-006).
//  3. **The node renders.** `renderedHtml` is `DocumentRenderer`'s output, with
//     fragments resolved, raw HTML stripped, and unsafe links refused
//     (POL-022, POL-034, POL-035). The browser's second renderer resolved only
//     the fragments it happened to hold and stripped nothing, so what a reader
//     saw was never quite the published document.
//  4. **Exports go through the download URL path.** A bearer token cannot ride
//     a browser navigation, so Markdown and PDF exports ask the node for a
//     short-lived URL scoped to that one document and format, then navigate to
//     it (M16.12; CLIENT-019, CLIENT-020). The client no longer assembles an
//     export of its own, so the exported file carries the metadata, resolved
//     fragment text, and branding the node puts in it.
//
// These are connected-only surfaces. Document authoring is not in the closed set
// of offline-writable work (data/API 7.2), so a request made with no node
// reachable fails and says so rather than queueing.

import { meridianCachedJson, meridianJson } from "@/api/meridianApi";
import type { ReadFreshness } from "@/offline/readCache";
import {
  downloadThroughShortLivedUrl,
  shortLivedDownloadEndpoints,
  type ShortLivedDownloadUrl,
} from "@/downloads/shortLivedDownload";

/** Policy and procedure are separate product types, not one type with a flag. */
export type DocumentType = "policy" | "procedure";

/** What an authoring route may open on. */
export type DocumentArtifactKind = DocumentType | "fragment";

export type DocumentScopeType = "organization" | "department" | "team";

export type ProductDocumentState = "draft" | "published" | "archived";

/** One scope the caller may maintain in, as the node named it. */
export interface DocumentScopeOption {
  readonly scopeType: DocumentScopeType;
  readonly scopeId: string;
  readonly label: string;
}

/** One Event Info placement a document may take (data/API 11.4A). */
export interface EventInfoSectionOption {
  readonly value: string;
  readonly label: string;
}

/**
 * A fragment token the node found in the document's Markdown.
 *
 * `fragmentVersion` is the fragment's current version and
 * `fragmentVersionAtLastEdit` is the version present when the document was last
 * saved, so an editor can see that the text underneath moved (data/API 11.6).
 */
export interface DocumentFragmentReference {
  readonly token: string;
  readonly fragmentId: string;
  readonly fragmentName: string | null;
  readonly fragmentSlug: string | null;
  readonly fragmentVersion: number | null;
  readonly fragmentVersionAtLastEdit: number | null;
}

export interface ProductDocument {
  readonly id: string;
  readonly documentType: DocumentType;
  readonly organizationId: string;
  readonly scopeType: DocumentScopeType;
  readonly scopeId: string;
  /** The node's name for the scope, so a row never needs a scope lookup. */
  readonly scopeLabel: string;
  readonly title: string;
  readonly slug: string;
  readonly eventInfoSection: string | null;
  readonly eventInfoSectionLabel: string | null;
  readonly markdownSource: string;
  /** `DocumentRenderer`'s HTML, with fragments resolved and raw HTML stripped. */
  readonly renderedHtml: string;
  readonly state: ProductDocumentState;
  readonly stateLabel: string;
  /** `document_revision.fragment_revision`, formatted by the node. */
  readonly version: string;
  readonly publishedAt: string | null;
  readonly archivedAt: string | null;
  readonly updatedAt: string | null;
  readonly visibilitySummary: string;
  readonly exportFormats: readonly string[];
  /** Whether this caller may edit, publish, or archive this document. */
  readonly canMaintain: boolean;
  readonly fragmentReferences: readonly DocumentFragmentReference[];
}

/** A document referencing a fragment, as the fragment's read reports it. */
export interface ReferencingDocument {
  readonly id: string;
  readonly documentType: DocumentType;
  readonly title: string;
  readonly state: string;
  readonly version: string;
  readonly published: boolean;
}

export interface ProductDocumentFragment {
  readonly id: string;
  readonly organizationId: string;
  readonly scopeType: DocumentScopeType;
  readonly scopeId: string;
  readonly scopeLabel: string;
  readonly name: string;
  readonly slug: string;
  readonly markdownSource: string;
  readonly version: number;
  readonly updatedAt: string | null;
  readonly referencingDocuments: readonly ReferencingDocument[];
}

/** What the caller may do on the surface as a whole, as the node decided it. */
export interface DocumentLibraryAccess {
  readonly canMaintain: boolean;
  readonly scopes: readonly DocumentScopeOption[];
}

/** The whole document library surface in one response. */
export interface DocumentLibrary {
  readonly organizationId: string;
  readonly access: DocumentLibraryAccess;
  readonly eventInfoSections: readonly EventInfoSectionOption[];
  readonly documents: readonly ProductDocument[];
  readonly fragments: readonly ProductDocumentFragment[];
  /** Where this library came from, and whether the browser narrowed it. */
  readonly freshness: ReadFreshness;
}

/**
 * The authoring form, as edited.
 *
 * One shape for all three artifact kinds: a fragment has no Event Info
 * placement and its `title` is the name the command calls `name`, which is a
 * translation this module makes rather than a second form.
 */
export interface DocumentDraft {
  scopeType: DocumentScopeType;
  scopeId: string;
  title: string;
  slug: string;
  eventInfoSection: string | null;
  markdownSource: string;
}

export type DocumentStateFilter = ProductDocumentState | "all";

interface FragmentReferencePayload {
  readonly token: string;
  readonly fragment_id: string;
  readonly fragment_name?: string | null;
  readonly fragment_slug?: string | null;
  readonly fragment_version?: number | null;
  readonly fragment_version_at_last_edit?: number | null;
}

interface DocumentPayload {
  readonly id: string;
  readonly document_type: DocumentType;
  readonly organization_id: string;
  readonly scope_type: DocumentScopeType;
  readonly scope_id: string;
  readonly scope_label?: string;
  readonly title: string;
  readonly slug: string;
  readonly event_info_section?: string | null;
  readonly event_info_section_label?: string | null;
  readonly markdown_source: string;
  readonly rendered_html?: string;
  readonly state: ProductDocumentState;
  readonly state_label?: string;
  readonly version?: string;
  readonly published_at?: string | null;
  readonly archived_at?: string | null;
  readonly updated_at?: string | null;
  readonly visibility_summary?: string;
  readonly export_formats?: string[];
  readonly can_maintain?: boolean;
  readonly fragment_references?: FragmentReferencePayload[];
}

interface ReferencingDocumentPayload {
  readonly id: string;
  readonly document_type: DocumentType;
  readonly title: string;
  readonly state: string;
  readonly version?: string;
  readonly published?: boolean;
}

interface FragmentPayload {
  readonly id: string;
  readonly organization_id: string;
  readonly scope_type: DocumentScopeType;
  readonly scope_id: string;
  readonly scope_label?: string;
  readonly name: string;
  readonly slug: string;
  readonly markdown_source: string;
  readonly version: number;
  readonly updated_at?: string | null;
  readonly referencing_documents?: ReferencingDocumentPayload[];
}

interface DocumentIndexPayload {
  readonly organization_id?: string;
  readonly access?: {
    readonly can_maintain?: boolean;
    readonly scopes?: {
      readonly scope_type: DocumentScopeType;
      readonly scope_id: string;
      readonly label: string;
    }[];
  };
  readonly event_info_sections?: EventInfoSectionOption[];
  readonly documents?: DocumentPayload[];
  readonly fragments?: FragmentPayload[];
}

function toDocument(payload: DocumentPayload): ProductDocument {
  return {
    id: payload.id,
    documentType: payload.document_type,
    organizationId: payload.organization_id,
    scopeType: payload.scope_type,
    scopeId: payload.scope_id,
    scopeLabel: payload.scope_label ?? "",
    title: payload.title,
    slug: payload.slug,
    eventInfoSection: payload.event_info_section ?? null,
    eventInfoSectionLabel: payload.event_info_section_label ?? null,
    markdownSource: payload.markdown_source,
    renderedHtml: payload.rendered_html ?? "",
    state: payload.state,
    stateLabel: payload.state_label ?? payload.state,
    version: payload.version ?? "",
    publishedAt: payload.published_at ?? null,
    archivedAt: payload.archived_at ?? null,
    updatedAt: payload.updated_at ?? null,
    visibilitySummary: payload.visibility_summary ?? "",
    exportFormats: payload.export_formats ?? [],
    canMaintain: payload.can_maintain ?? false,
    fragmentReferences: (payload.fragment_references ?? []).map((reference) => ({
      token: reference.token,
      fragmentId: reference.fragment_id,
      fragmentName: reference.fragment_name ?? null,
      fragmentSlug: reference.fragment_slug ?? null,
      fragmentVersion: reference.fragment_version ?? null,
      fragmentVersionAtLastEdit: reference.fragment_version_at_last_edit ?? null,
    })),
  };
}

function toFragment(payload: FragmentPayload): ProductDocumentFragment {
  return {
    id: payload.id,
    organizationId: payload.organization_id,
    scopeType: payload.scope_type,
    scopeId: payload.scope_id,
    scopeLabel: payload.scope_label ?? "",
    name: payload.name,
    slug: payload.slug,
    markdownSource: payload.markdown_source,
    version: payload.version,
    updatedAt: payload.updated_at ?? null,
    referencingDocuments: (payload.referencing_documents ?? []).map(
      (document) => ({
        id: document.id,
        documentType: document.document_type,
        title: document.title,
        state: document.state,
        version: document.version ?? "",
        published: document.published ?? false,
      }),
    ),
  };
}

/**
 * The submitted form, trimmed.
 *
 * The server trims too, so this changes nothing it stores. It changes what a
 * whitespace-only title does: sent as typed it passes `required` and comes back
 * as a domain refusal, which reads oddly next to a field that visibly has
 * something in it.
 */
function toDocumentAttributes(
  organizationId: string,
  draft: DocumentDraft,
): Record<string, unknown> {
  return {
    organization_id: organizationId,
    scope_type: draft.scopeType,
    scope_id: draft.scopeId,
    title: draft.title.trim(),
    slug: draft.slug.trim(),
    // Always sent, including as null: omitting the key leaves the current
    // placement alone, which is not what clearing the field means (11.4A).
    event_info_section: draft.eventInfoSection,
    markdown_source: draft.markdownSource,
  };
}

function toFragmentAttributes(
  organizationId: string,
  draft: DocumentDraft,
): Record<string, unknown> {
  return {
    organization_id: organizationId,
    scope_type: draft.scopeType,
    scope_id: draft.scopeId,
    name: draft.title.trim(),
    slug: draft.slug.trim(),
    markdown_source: draft.markdownSource,
  };
}

/** The read path for one document type. */
function documentPath(documentType: DocumentType, id: string): string {
  return documentType === "policy"
    ? `/api/policy-documents/${encodeURIComponent(id)}`
    : `/api/procedure-documents/${encodeURIComponent(id)}`;
}

/**
 * Read the whole document surface for one organization.
 *
 * The state filter and the title search go to the node whenever there is one.
 * The list is then the answer to the question that was asked rather than a view
 * of a wider one, and which documents a caller may see at all — published in
 * their scopes, plus anything they maintain — stays the node's decision
 * (POL-055).
 *
 * With no node in reach, the browser narrows the copy it holds and says so
 * (M18.9). Searching a stored list finds only what is in it, which is why this
 * is the fallback rather than the mechanism; but a library sitting complete on
 * the screen that answers a typed word with "unable to load" is worse than one
 * that answers from what it has and admits what that covers.
 */
export async function getOrganizationDocuments(
  organizationId: string,
  state: DocumentStateFilter = "all",
  search = "",
): Promise<DocumentLibrary> {
  const parameters = new URLSearchParams();

  if (state !== "all") {
    parameters.set("state", state);
  }

  if (search !== "") {
    parameters.set("q", search);
  }

  const endpoint = `/api/organizations/${encodeURIComponent(organizationId)}/documents`;
  const withPath = (values: URLSearchParams): string => {
    const query = values.toString();

    return query === "" ? endpoint : `${endpoint}?${query}`;
  };

  /*
   * The fallback drops the search and keeps every other filter (M18.9).
   *
   * A surface that always asks for one state — the staff library asks for
   * published and nothing else — has that state in every copy it stored, so the
   * broad copy to fall back on is "the same question without the search term"
   * rather than the bare endpoint, which this device may never have read.
   */
  const unsearched = new URLSearchParams(parameters);

  unsearched.delete("q");

  const read = await meridianCachedJson<DocumentIndexPayload>(
    withPath(parameters),
    { fallbackPath: withPath(unsearched) },
  );
  const result = read.data;

  const scopes = (result.access?.scopes ?? []).map((scope) => ({
    scopeType: scope.scope_type,
    scopeId: scope.scope_id,
    label: scope.label,
  }));

  return {
    freshness: read.freshness,
    organizationId: result.organization_id ?? organizationId,
    access: {
      canMaintain: result.access?.can_maintain ?? false,
      scopes,
    },
    eventInfoSections: result.event_info_sections ?? [],
    documents: narrowDocuments(
      (result.documents ?? []).map(toDocument),
      read.freshness.narrowed === true ? search : "",
    ),
    fragments: (result.fragments ?? []).map(toFragment),
  };
}

/**
 * Apply the title search the node would have applied.
 *
 * Only reached when a searched read fell back to the stored copy of the same
 * question without the search term. A case-insensitive substring, which is what
 * the node's `q` does for a title; nothing here tries to reproduce a fuller
 * server-side search, because a browser guessing at ranking would make the
 * offline answer differ from the online one in ways nobody could predict.
 */
function narrowDocuments(
  documents: readonly ProductDocument[],
  search: string,
): readonly ProductDocument[] {
  const needle = search.trim().toLowerCase();

  return needle === ""
    ? documents
    : documents.filter((document) =>
        document.title.toLowerCase().includes(needle),
      );
}

/**
 * Read one document for the edit form.
 *
 * A document outside the caller's visibility is the node's refusal rather than
 * a row missing from a list, so the form can say what happened instead of
 * rendering empty.
 */
export async function getDocument(
  documentType: DocumentType,
  documentId: string,
): Promise<ProductDocument> {
  return toDocument(
    (await meridianCachedJson<DocumentPayload>(documentPath(documentType, documentId))).data,
  );
}

/** Read one fragment. The node refuses a fragment this caller cannot maintain. */
export async function getDocumentFragment(
  fragmentId: string,
): Promise<ProductDocumentFragment> {
  return toFragment(
    (await meridianCachedJson<FragmentPayload>(
      `/api/document-fragments/${encodeURIComponent(fragmentId)}`,
    )).data,
  );
}

/**
 * Create a document, and answer with it as the node saved it.
 *
 * New documents are always created in draft (data/API 11.2); no state is sent,
 * because that is the server's rule rather than a default this form fills in.
 */
export async function createDocument(
  documentType: DocumentType,
  organizationId: string,
  draft: DocumentDraft,
): Promise<ProductDocument> {
  return toDocument(
    await meridianJson<DocumentPayload>(
      `/api/commands/create-${documentType}-document`,
      {
        method: "POST",
        body: JSON.stringify(toDocumentAttributes(organizationId, draft)),
      },
    ),
  );
}

export async function updateDocument(
  documentType: DocumentType,
  documentId: string,
  organizationId: string,
  draft: DocumentDraft,
): Promise<ProductDocument> {
  return toDocument(
    await meridianJson<DocumentPayload>(
      `/api/commands/update-${documentType}-document`,
      {
        method: "POST",
        body: JSON.stringify({
          document_id: documentId,
          ...toDocumentAttributes(organizationId, draft),
        }),
      },
    ),
  );
}

/**
 * Publish or archive a document.
 *
 * The reason is required by the command and is what the audit snapshot records,
 * so the surface asks for it rather than sending a sentence the maintainer did
 * not write.
 */
export async function transitionDocument(
  documentType: DocumentType,
  documentId: string,
  state: "published" | "archived",
  reason: string,
): Promise<ProductDocument> {
  const verb = state === "published" ? "publish" : "archive";

  return toDocument(
    await meridianJson<DocumentPayload>(
      `/api/commands/${verb}-${documentType}-document`,
      {
        method: "POST",
        body: JSON.stringify({ document_id: documentId, reason }),
      },
    ),
  );
}

export async function createDocumentFragment(
  organizationId: string,
  draft: DocumentDraft,
): Promise<ProductDocumentFragment> {
  return toFragment(
    await meridianJson<FragmentPayload>(
      "/api/commands/create-document-fragment",
      {
        method: "POST",
        body: JSON.stringify(toFragmentAttributes(organizationId, draft)),
      },
    ),
  );
}

export async function updateDocumentFragment(
  fragmentId: string,
  organizationId: string,
  draft: DocumentDraft,
): Promise<ProductDocumentFragment> {
  return toFragment(
    await meridianJson<FragmentPayload>(
      "/api/commands/update-document-fragment",
      {
        method: "POST",
        body: JSON.stringify({
          fragment_id: fragmentId,
          ...toFragmentAttributes(organizationId, draft),
        }),
      },
    ),
  );
}

/**
 * Download one document in one format (M16.12; CLIENT-019, CLIENT-020).
 *
 * The format is part of the resource the URL is scoped to, so the URL issued for
 * the Markdown export does not retrieve the PDF. Authorization is decided when
 * the node issues the URL, which is why a refused export throws here rather than
 * opening a tab onto an error page.
 */
export async function exportDocument(
  documentType: DocumentType,
  documentId: string,
  format: string,
): Promise<ShortLivedDownloadUrl> {
  const endpoint =
    documentType === "policy"
      ? shortLivedDownloadEndpoints.policyDocumentExport(documentId, format)
      : shortLivedDownloadEndpoints.procedureDocumentExport(documentId, format);

  return downloadThroughShortLivedUrl(endpoint);
}

/** The label to show for an export format the node offered. */
export function documentExportFormatLabel(format: string): string {
  return format === "markdown"
    ? "Export Markdown"
    : `Export ${format.toUpperCase()}`;
}

/**
 * How many published documents a fragment edit would bump (data/API 11.7).
 *
 * The count is over what the fragment's own read reported, so it describes the
 * documents the node knows reference it rather than the ones this browser can
 * see.
 */
export function publishedReferenceImpact(
  fragment: ProductDocumentFragment | null,
): number {
  return (fragment?.referencingDocuments ?? []).filter(
    (document) => document.published,
  ).length;
}
