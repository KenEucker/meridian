// The staff-facing Event Info surface's data layer (M16.19; CLIENT-023;
// data/API 11.4A; UI contract 12.3).
//
// Until this task this module assembled Event Info in the browser. It held the
// six section keys and their empty descriptions, filtered the client's fixture
// documents down to the published ones a fixture department could see, and
// sorted them by scope breadth and title — which is `EventInfoService`'s
// assembly rule, written a second time against data no node had answered for.
// A section that looked empty was empty of fixtures, not empty of guidance.
//
// This module is now a translation of `GET /api/events/{event}/info`. Three
// choices in it are deliberate:
//
//  1. **The node assembles.** Section order, per-section document order, the
//     published-only rule, and the empty description naming each gap all come
//     back on the response. Event Info grants no visibility of its own
//     (11.4A), and the one place that decides what a staff member may read is
//     the node.
//  2. **The node renders.** Each document arrives as `rendered_html` with its
//     fragments resolved, so what a staff member reads before their first shift
//     is the published document rather than the browser's approximation of it.
//  3. **The event is the response's.** Its name, window, and timezone come from
//     the read rather than from a planning fixture, so a page that answers "how
//     do I get there" is not describing a different event than the one it names.
//
// This is a connected-only read. Event Info is not in the closed set of
// offline-writable work (data/API 7.2), and a request made with no node
// reachable fails and says so rather than showing an event with no guidance.

import { meridianCachedJson } from "@/api/meridianApi";

export interface EventInfoDocument {
  readonly id: string;
  readonly documentType: "policy" | "procedure";
  readonly title: string;
  readonly slug: string;
  readonly scopeType: string;
  readonly scopeLabel: string;
  readonly version: string;
  /** `DocumentRenderer`'s HTML, with fragments resolved. */
  readonly renderedHtml: string;
  readonly publishedAt: string | null;
  readonly updatedAt: string | null;
}

export interface EventInfoSectionView {
  readonly section: string;
  readonly label: string;
  readonly documents: readonly EventInfoDocument[];
  /** Null when the section has content; otherwise the node's name for the gap. */
  readonly emptyDescription: string | null;
}

export interface EventInfoEvent {
  readonly id: string;
  readonly organizationId: string | null;
  /** The event's own slug, for the addresses built from one (APP-017). */
  readonly slug: string | null;
  readonly name: string;
  readonly timezone: string | null;
  readonly startsAt: string | null;
  readonly endsAt: string | null;
  readonly status: string | null;
}

export interface EventInfoView {
  readonly event: EventInfoEvent;
  readonly sections: readonly EventInfoSectionView[];
  readonly documentCount: number;
}

interface EventInfoDocumentPayload {
  readonly id: string;
  readonly document_type: "policy" | "procedure";
  readonly title: string;
  readonly slug?: string;
  readonly scope_type?: string;
  readonly scope_label?: string;
  readonly version?: string;
  readonly rendered_html?: string;
  readonly published_at?: string | null;
  readonly updated_at?: string | null;
}

interface EventInfoSectionPayload {
  readonly section: string;
  readonly label?: string | null;
  readonly documents?: EventInfoDocumentPayload[];
  readonly empty_description?: string | null;
}

interface EventInfoPayload {
  readonly event?: {
    readonly id: string;
    readonly organization_id?: string | null;
    readonly slug?: string | null;
    readonly name?: string;
    readonly timezone?: string | null;
    readonly starts_at?: string | null;
    readonly ends_at?: string | null;
    readonly status?: string | null;
  };
  readonly sections?: EventInfoSectionPayload[];
}

function toDocument(payload: EventInfoDocumentPayload): EventInfoDocument {
  return {
    id: payload.id,
    documentType: payload.document_type,
    title: payload.title,
    slug: payload.slug ?? "",
    scopeType: payload.scope_type ?? "",
    scopeLabel: payload.scope_label ?? "",
    version: payload.version ?? "",
    renderedHtml: payload.rendered_html ?? "",
    publishedAt: payload.published_at ?? null,
    updatedAt: payload.updated_at ?? null,
  };
}

/**
 * Read Event Info for one event.
 *
 * Sections are taken in the order the node sent them. `section_order` on the
 * response says the same thing, and re-sorting by it here would only give the
 * client a second chance to disagree with the order it was handed (11.4A).
 */
export async function getEventInfo(eventId: string): Promise<EventInfoView> {
  const result = (
    await meridianCachedJson<EventInfoPayload>(
      `/api/events/${encodeURIComponent(eventId)}/info`,
    )
  ).data;

  const sections = (result.sections ?? []).map<EventInfoSectionView>(
    (section) => ({
      section: section.section,
      label: section.label ?? section.section,
      documents: (section.documents ?? []).map(toDocument),
      emptyDescription: section.empty_description ?? null,
    }),
  );

  return {
    event: {
      id: result.event?.id ?? eventId,
      organizationId: result.event?.organization_id ?? null,
      slug: result.event?.slug ?? null,
      name: result.event?.name ?? "",
      timezone: result.event?.timezone ?? null,
      startsAt: result.event?.starts_at ?? null,
      endsAt: result.event?.ends_at ?? null,
      status: result.event?.status ?? null,
    },
    sections,
    documentCount: sections.reduce(
      (total, section) => total + section.documents.length,
      0,
    ),
  };
}
