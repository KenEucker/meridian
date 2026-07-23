import { shallowRef } from "vue";

import {
  FIXTURE_ORGANIZER_DEFAULT_TEAM_ID,
  FIXTURE_ORGANIZER_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
  FIXTURE_RANGERS_DIRT_TEAM_ID,
  FIXTURE_RANGERS_DEFAULT_TEAM_ID,
  fixtureDepartmentAccesses,
  fixtureDepartmentById,
  fixtureDepartmentHasOrganizerDepartmentAccess,
  selectedFixtureDepartment,
  type FixtureDepartmentAccess,
} from "@/department-teams/fixtureDepartmentAccess";

export type DocumentArtifactKind = "policy" | "procedure" | "fragment";
export type DocumentScopeType = "organization" | "department" | "team";
export type ProductDocumentState = "draft" | "published" | "archived";

export interface ProductDocument {
  readonly id: string;
  readonly kind: Exclude<DocumentArtifactKind, "fragment">;
  readonly organizationId: string;
  readonly scopeType: DocumentScopeType;
  readonly scopeId: string;
  readonly title: string;
  readonly slug: string;
  readonly markdownSource: string;
  readonly state: ProductDocumentState;
  readonly documentRevision: number;
  readonly fragmentRevision: number;
  readonly publishedAt: string | null;
  readonly archivedAt: string | null;
  readonly updatedAt: string;
}

export interface DocumentFragment {
  readonly id: string;
  readonly organizationId: string;
  readonly scopeType: DocumentScopeType;
  readonly scopeId: string;
  readonly name: string;
  readonly slug: string;
  readonly markdownSource: string;
  readonly version: number;
  readonly updatedAt: string;
}

export interface DocumentDraft {
  kind: DocumentArtifactKind;
  scopeType: DocumentScopeType;
  scopeId: string;
  title: string;
  slug: string;
  markdownSource: string;
}

export interface DocumentAuthoringSession {
  readonly surface: "organizer" | "department";
  readonly organizationId: string;
  readonly organizationLabel: string;
  readonly eventId: string;
  readonly eventLabel: string;
  readonly department: FixtureDepartmentAccess;
}

export interface ScopeOption {
  readonly type: DocumentScopeType;
  readonly id: string;
  readonly label: string;
}

const organizationId = "11111111-1111-4111-8111-111111111111";
const organizationLabel = "Signal Camp (development)";

const INITIAL_DOCUMENTS: ProductDocument[] = [
  {
    id: "44444444-4444-4444-8444-444444444401",
    kind: "policy",
    organizationId,
    scopeType: "organization",
    scopeId: organizationId,
    title: "Volunteer Conduct",
    slug: "volunteer-conduct",
    markdownSource:
      "# Volunteer Conduct\n\n{{fragment:shared-conduct}}\n\nStaff are expected to keep commitments visible and ask for help early.",
    state: "published",
    documentRevision: 1,
    fragmentRevision: 0,
    publishedAt: "2026-07-01T16:00:00.000Z",
    archivedAt: null,
    updatedAt: "2026-07-01T16:00:00.000Z",
  },
  {
    id: "44444444-4444-4444-8444-444444444402",
    kind: "procedure",
    organizationId,
    scopeType: "department",
    scopeId: FIXTURE_RANGERS_DEPARTMENT_ID,
    title: "Radio Checkout",
    slug: "radio-checkout",
    markdownSource:
      "# Radio Checkout\n\n1. Confirm the staff member and shift.\n2. Record the radio number.\n3. Ask the staff member to test before leaving Logistics.",
    state: "draft",
    documentRevision: 1,
    fragmentRevision: 0,
    publishedAt: null,
    archivedAt: null,
    updatedAt: "2026-07-02T18:30:00.000Z",
  },
  {
    id: "44444444-4444-4444-8444-444444444403",
    kind: "policy",
    organizationId,
    scopeType: "team",
    scopeId: FIXTURE_RANGERS_DIRT_TEAM_ID,
    title: "Dirt Team Radio Policy",
    slug: "dirt-team-radio-policy",
    markdownSource:
      "# Dirt Team Radio Policy\n\n{{fragment:radio-language}}\n\nUse the team channel for patrol coordination.",
    state: "published",
    documentRevision: 2,
    fragmentRevision: 1,
    publishedAt: "2026-07-03T15:15:00.000Z",
    archivedAt: null,
    updatedAt: "2026-07-03T15:15:00.000Z",
  },
];

const INITIAL_FRAGMENTS: DocumentFragment[] = [
  {
    id: "55555555-5555-4555-8555-555555555501",
    organizationId,
    scopeType: "organization",
    scopeId: organizationId,
    name: "Shared Conduct",
    slug: "shared-conduct",
    markdownSource: "Treat people, radios, vehicles, and camp spaces with care.",
    version: 1,
    updatedAt: "2026-07-01T15:30:00.000Z",
  },
  {
    id: "55555555-5555-4555-8555-555555555502",
    organizationId,
    scopeType: "team",
    scopeId: FIXTURE_RANGERS_DIRT_TEAM_ID,
    name: "Radio Language",
    slug: "radio-language",
    markdownSource: "Use plain language and confirm urgent calls.",
    version: 2,
    updatedAt: "2026-07-03T14:50:00.000Z",
  },
];

const documents = shallowRef<ProductDocument[]>([...INITIAL_DOCUMENTS]);
const fragments = shallowRef<DocumentFragment[]>([...INITIAL_FRAGMENTS]);

export function resolveDocumentAuthoringSession(
  surface: "organizer" | "department",
  departmentId?: string | null,
): DocumentAuthoringSession {
  const department =
    surface === "organizer"
      ? (fixtureDepartmentById(FIXTURE_ORGANIZER_DEPARTMENT_ID) ??
        fixtureDepartmentAccesses[0]!)
      : (fixtureDepartmentById(departmentId) ?? selectedFixtureDepartment.value);

  return {
    surface,
    organizationId,
    organizationLabel,
    eventId: department.eventId,
    eventLabel: department.eventLabel,
    department,
  };
}

export function canAccessDocumentSurface(
  session: DocumentAuthoringSession,
): boolean {
  return (
    session.surface === "organizer" ||
    session.department.isDepartmentLead ||
    session.department.teams.some((team) => team.isTeamLead || team.isMember)
  );
}

export function canMaintainDocuments(session: DocumentAuthoringSession): boolean {
  return maintainableScopes(session).length > 0;
}

export function maintainableScopes(
  session: DocumentAuthoringSession,
): ScopeOption[] {
  if (
    session.surface === "organizer" &&
    fixtureDepartmentHasOrganizerDepartmentAccess(session.department)
  ) {
    return [
      {
        type: "organization",
        id: session.organizationId,
        label: `Organization: ${session.organizationLabel}`,
      },
    ];
  }

  const scopes: ScopeOption[] = [];

  if (session.department.isDepartmentLead) {
    scopes.push({
      type: "department",
      id: session.department.departmentId,
      label: `Department: ${session.department.departmentLabel}`,
    });
  }

  for (const team of session.department.teams) {
    if (team.isTeamLead) {
      scopes.push({
        type: "team",
        id: team.teamId,
        label: `Team: ${team.teamLabel}`,
      });
    }
  }

  return scopes;
}

export function visibleDocuments(
  session: DocumentAuthoringSession,
  state: ProductDocumentState | "all" = "all",
): ProductDocument[] {
  return documents.value
    .filter((document) => document.organizationId === session.organizationId)
    .filter((document) => state === "all" || document.state === state)
    .filter((document) => canViewDocument(session, document))
    .slice()
    .sort((left, right) => left.title.localeCompare(right.title));
}

export function visibleFragments(
  session: DocumentAuthoringSession,
): DocumentFragment[] {
  return fragments.value
    .filter((fragment) => fragment.organizationId === session.organizationId)
    .filter((fragment) => canMaintainScope(session, fragment.scopeType, fragment.scopeId))
    .slice()
    .sort((left, right) => left.name.localeCompare(right.name));
}

export function getDocument(
  session: DocumentAuthoringSession,
  id: string,
): ProductDocument | null {
  return (
    documents.value.find((document) => document.id === id && canViewDocument(session, document)) ??
    null
  );
}

export function getFragment(
  session: DocumentAuthoringSession,
  id: string,
): DocumentFragment | null {
  return (
    fragments.value.find(
      (fragment) =>
        fragment.id === id &&
        canMaintainScope(session, fragment.scopeType, fragment.scopeId),
    ) ?? null
  );
}

export function saveDocumentDraft(
  session: DocumentAuthoringSession,
  artifactId: string | null,
  draft: DocumentDraft,
): ProductDocument | DocumentFragment {
  assertCanMaintainScope(session, draft.scopeType, draft.scopeId);
  const title = draft.title.trim();
  const slug = draft.slug.trim();
  const markdownSource = draft.markdownSource.trim();

  if (title === "" || slug === "" || markdownSource === "") {
    throw new Error("Title, slug, and Markdown are required.");
  }

  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(slug)) {
    throw new Error("Slug must use lowercase letters, numbers, and hyphens.");
  }

  if (draft.kind === "fragment") {
    return saveFragment(session, artifactId, {
      name: title,
      slug,
      markdownSource,
      scopeType: draft.scopeType,
      scopeId: draft.scopeId,
    });
  }

  const now = new Date().toISOString();
  const existing =
    artifactId === null
      ? null
      : documents.value.find((document) => document.id === artifactId) ?? null;

  if (existing !== null && !canMaintainScope(session, existing.scopeType, existing.scopeId)) {
    throw new Error("You do not have permission to edit this document.");
  }

  const changedPublishedContent =
    existing !== null &&
    existing.state === "published" &&
    (existing.title !== title ||
      existing.slug !== slug ||
      existing.markdownSource !== markdownSource ||
      existing.scopeType !== draft.scopeType ||
      existing.scopeId !== draft.scopeId);

  const saved: ProductDocument = {
    id: existing?.id ?? crypto.randomUUID(),
    kind: draft.kind,
    organizationId: session.organizationId,
    scopeType: draft.scopeType,
    scopeId: draft.scopeId,
    title,
    slug,
    markdownSource,
    state: existing?.state ?? "draft",
    documentRevision: changedPublishedContent
      ? existing.documentRevision + 1
      : (existing?.documentRevision ?? 1),
    fragmentRevision: changedPublishedContent ? 0 : (existing?.fragmentRevision ?? 0),
    publishedAt: existing?.publishedAt ?? null,
    archivedAt: existing?.archivedAt ?? null,
    updatedAt: now,
  };

  documents.value =
    existing === null
      ? [...documents.value, saved]
      : documents.value.map((document) => (document.id === saved.id ? saved : document));

  return saved;
}

export function publishDocument(
  session: DocumentAuthoringSession,
  id: string,
): ProductDocument {
  return transitionDocument(session, id, "published");
}

export function archiveDocument(
  session: DocumentAuthoringSession,
  id: string,
): ProductDocument {
  return transitionDocument(session, id, "archived");
}

export function renderDocumentMarkdown(source: string): string {
  const escaped = escapeHtml(resolveFragmentMarkdown(source));
  return escaped
    .replace(/^# (.*)$/gm, "<h1>$1</h1>")
    .replace(/^## (.*)$/gm, "<h2>$1</h2>")
    .replace(/\n\n/g, "</p><p>")
    .replace(/^/, "<p>")
    .replace(/$/, "</p>")
    .replace(/<p><h/g, "<h")
    .replace(/<\/h([12])><\/p>/g, "</h$1>")
    .replace(/\*\*(.*?)\*\*/g, "<strong>$1</strong>");
}

export function documentVersion(document: ProductDocument): string {
  return `${document.documentRevision}.${String(document.fragmentRevision).padStart(2, "0")}`;
}

export function scopeLabel(
  scopeType: DocumentScopeType,
  scopeId: string,
): string {
  if (scopeType === "organization") {
    return `Organization: ${organizationLabel}`;
  }

  const department = fixtureDepartmentById(scopeId);
  if (scopeType === "department" && department !== null) {
    return `Department: ${department.departmentLabel}`;
  }

  for (const fixtureDepartment of fixtureDepartmentAccesses) {
    const team = fixtureDepartment.teams.find((candidate) => candidate.teamId === scopeId);
    if (team) {
      return `Team: ${team.teamLabel}`;
    }
  }

  return `${scopeType}: ${scopeId}`;
}

export function visibilitySummary(document: ProductDocument): string {
  if (document.state !== "published") {
    return "Visible only to permitted maintainers until published.";
  }

  if (document.scopeType === "organization") {
    return "Published to staff in this organization.";
  }

  if (document.scopeType === "department") {
    return "Published to members of this department.";
  }

  return "Published to members of this team.";
}

export function referencingDocuments(fragment: DocumentFragment): ProductDocument[] {
  const token = `{{fragment:${fragment.slug}}}`;

  return documents.value
    .filter((document) => document.markdownSource.includes(token))
    .sort((left, right) => left.title.localeCompare(right.title));
}

export function documentExportMarkdown(document: ProductDocument): string {
  return [
    `# ${document.title}`,
    "",
    `- Document type: ${document.kind === "policy" ? "Policy" : "Procedure"}`,
    `- Document title: ${document.title}`,
    `- Document version: ${documentVersion(document)}`,
    `- Scope: ${scopeLabel(document.scopeType, document.scopeId)}`,
    `- Exported at: ${new Date().toISOString()}`,
    "",
    "---",
    "",
    resolveFragmentMarkdown(document.markdownSource),
    "",
  ].join("\n");
}

export function resetDocumentAuthoringFixtures(): void {
  documents.value = INITIAL_DOCUMENTS.map((document) => ({ ...document }));
  fragments.value = INITIAL_FRAGMENTS.map((fragment) => ({ ...fragment }));
}

function saveFragment(
  session: DocumentAuthoringSession,
  artifactId: string | null,
  draft: {
    name: string;
    slug: string;
    markdownSource: string;
    scopeType: DocumentScopeType;
    scopeId: string;
  },
): DocumentFragment {
  if (draft.markdownSource.includes("{{fragment:")) {
    throw new Error("Fragments cannot reference other fragments.");
  }

  const now = new Date().toISOString();
  const existing =
    artifactId === null
      ? null
      : fragments.value.find((fragment) => fragment.id === artifactId) ?? null;

  if (existing !== null && !canMaintainScope(session, existing.scopeType, existing.scopeId)) {
    throw new Error("You do not have permission to edit this fragment.");
  }

  const saved: DocumentFragment = {
    id: existing?.id ?? crypto.randomUUID(),
    organizationId: session.organizationId,
    scopeType: draft.scopeType,
    scopeId: draft.scopeId,
    name: draft.name,
    slug: draft.slug,
    markdownSource: draft.markdownSource,
    version:
      existing !== null && existing.markdownSource !== draft.markdownSource
        ? existing.version + 1
        : (existing?.version ?? 1),
    updatedAt: now,
  };

  fragments.value =
    existing === null
      ? [...fragments.value, saved]
      : fragments.value.map((fragment) => (fragment.id === saved.id ? saved : fragment));

  if (existing !== null && existing.markdownSource !== draft.markdownSource) {
    bumpReferencingPublishedDocuments(saved);
  }

  return saved;
}

function transitionDocument(
  session: DocumentAuthoringSession,
  id: string,
  state: ProductDocumentState,
): ProductDocument {
  const existing = documents.value.find((document) => document.id === id) ?? null;

  if (existing === null) {
    throw new Error("Document not found.");
  }

  assertCanMaintainScope(session, existing.scopeType, existing.scopeId);

  const transitioned: ProductDocument = {
    ...existing,
    state,
    publishedAt:
      state === "published" && existing.state !== "published"
        ? new Date().toISOString()
        : existing.publishedAt,
    archivedAt:
      state === "archived" && existing.state !== "archived"
        ? new Date().toISOString()
        : state === "archived"
          ? existing.archivedAt
          : null,
    updatedAt: new Date().toISOString(),
  };

  documents.value = documents.value.map((document) =>
    document.id === id ? transitioned : document,
  );

  return transitioned;
}

function bumpReferencingPublishedDocuments(fragment: DocumentFragment): void {
  const token = `{{fragment:${fragment.slug}}}`;
  documents.value = documents.value.map((document) =>
    document.state === "published" && document.markdownSource.includes(token)
      ? {
          ...document,
          fragmentRevision: document.fragmentRevision + 1,
          updatedAt: new Date().toISOString(),
        }
      : document,
  );
}

function canViewDocument(
  session: DocumentAuthoringSession,
  document: ProductDocument,
): boolean {
  if (canMaintainScope(session, document.scopeType, document.scopeId)) {
    return true;
  }

  if (document.state !== "published") {
    return false;
  }

  if (session.surface === "organizer") {
    return true;
  }

  if (document.scopeType === "organization") {
    return true;
  }

  if (document.scopeType === "department") {
    return document.scopeId === session.department.departmentId;
  }

  return session.department.teams.some(
    (team) => team.teamId === document.scopeId && team.isMember,
  );
}

function canMaintainScope(
  session: DocumentAuthoringSession,
  scopeType: DocumentScopeType,
  scopeId: string,
): boolean {
  return maintainableScopes(session).some(
    (scope) => scope.type === scopeType && scope.id === scopeId,
  );
}

function assertCanMaintainScope(
  session: DocumentAuthoringSession,
  scopeType: DocumentScopeType,
  scopeId: string,
): void {
  if (!canMaintainScope(session, scopeType, scopeId)) {
    throw new Error("You do not have permission to maintain documents for this scope.");
  }
}

function resolveFragmentMarkdown(source: string): string {
  return fragments.value.reduce(
    (resolved, fragment) =>
      resolved.replaceAll(`{{fragment:${fragment.slug}}}`, fragment.markdownSource),
    source,
  );
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;");
}

export const DEVELOPMENT_ORGANIZATION_ID = organizationId;
export const DEVELOPMENT_ORGANIZER_SCOPE_ID = organizationId;
export const DEVELOPMENT_ORGANIZER_TEAM_ID = FIXTURE_ORGANIZER_DEFAULT_TEAM_ID;
export const DEVELOPMENT_RANGERS_DEPARTMENT_ID = FIXTURE_RANGERS_DEPARTMENT_ID;
export const DEVELOPMENT_RANGERS_DEFAULT_TEAM_ID = FIXTURE_RANGERS_DEFAULT_TEAM_ID;
