import {
  resolveDocumentAuthoringSession,
  visibleDocuments,
  type ProductDocument,
} from "@/documents/documentAuthoringModel";
import {
  EVENT_INFO_SECTIONS,
  eventInfoSectionEmptyDescription,
  eventInfoSectionLabel,
  type EventInfoSection,
} from "@/documents/eventInfoSections";
import {
  fixtureDepartmentById,
  selectedFixtureDepartment,
  type FixtureDepartmentAccess,
} from "@/department-teams/fixtureDepartmentAccess";

/**
 * Staff-facing Event Info assembly (M11.20).
 *
 * Mirrors `App\Services\Documents\EventInfoService`. The rules, in full:
 *
 * 1. Only published documents appear. A maintainer's own draft stays out, so
 *    nobody reads unpublished text as event guidance.
 * 2. Visibility is the existing published-document rule and nothing more, so
 *    the same section can legitimately differ between two staff members.
 * 3. Within a section, order is scope breadth (organization, department, team)
 *    then title: broad guidance before the narrower guidance qualifying it, and
 *    stable between renders.
 * 4. A section with nothing visible states that plainly. It never falls back to
 *    placeholder prose, because staff cannot tell placeholder guidance from
 *    real guidance.
 */
export interface EventInfoDocument {
  readonly id: string;
  readonly kind: ProductDocument["kind"];
  readonly title: string;
  readonly scopeType: ProductDocument["scopeType"];
  readonly scopeId: string;
  readonly markdownSource: string;
  readonly version: string;
  readonly publishedAt: string | null;
  readonly updatedAt: string;
}

export interface EventInfoSectionView {
  readonly section: EventInfoSection;
  readonly label: string;
  readonly documents: readonly EventInfoDocument[];
  /** Null when the section has content; otherwise names the gap. */
  readonly emptyDescription: string | null;
}

export interface EventInfoView {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentLabel: string;
  readonly organizationLabel: string;
  readonly sections: readonly EventInfoSectionView[];
  readonly documentCount: number;
}

const SCOPE_RANK: Readonly<Record<ProductDocument["scopeType"], number>> = {
  organization: 0,
  department: 1,
  team: 2,
};

export function resolveEventInfo(
  eventId?: string | null,
  departmentId?: string | null,
): EventInfoView {
  const department = resolveViewerDepartment(departmentId);
  const session = resolveDocumentAuthoringSession(
    "department",
    department.departmentId,
  );
  const published = visibleDocuments(session, "published");

  const sections = EVENT_INFO_SECTIONS.map<EventInfoSectionView>((section) => {
    const documents = published
      .filter((document) => document.eventInfoSection === section)
      .slice()
      .sort(compareForAssembly)
      .map(toEventInfoDocument);

    return {
      section,
      label: eventInfoSectionLabel(section),
      documents,
      emptyDescription:
        documents.length === 0
          ? eventInfoSectionEmptyDescription(section)
          : null,
    };
  });

  return {
    eventId: eventId ?? department.eventId,
    eventLabel: department.eventLabel,
    departmentLabel: department.departmentLabel,
    organizationLabel: session.organizationLabel,
    sections,
    documentCount: sections.reduce(
      (total, section) => total + section.documents.length,
      0,
    ),
  };
}

function resolveViewerDepartment(
  departmentId: string | null | undefined,
): FixtureDepartmentAccess {
  return (
    fixtureDepartmentById(departmentId) ?? selectedFixtureDepartment.value
  );
}

function compareForAssembly(
  left: ProductDocument,
  right: ProductDocument,
): number {
  const scopeOrder = SCOPE_RANK[left.scopeType] - SCOPE_RANK[right.scopeType];
  if (scopeOrder !== 0) {
    return scopeOrder;
  }

  const titleOrder = left.title.localeCompare(right.title);

  return titleOrder !== 0 ? titleOrder : left.id.localeCompare(right.id);
}

function toEventInfoDocument(document: ProductDocument): EventInfoDocument {
  return {
    id: document.id,
    kind: document.kind,
    title: document.title,
    scopeType: document.scopeType,
    scopeId: document.scopeId,
    markdownSource: document.markdownSource,
    version: `${document.documentRevision}.${String(
      document.fragmentRevision,
    ).padStart(2, "0")}`,
    publishedAt: document.publishedAt,
    updatedAt: document.updatedAt,
  };
}
