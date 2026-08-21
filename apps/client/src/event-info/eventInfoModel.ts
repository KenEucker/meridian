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
// Served offline since 2026-08-20 from the read set's `event_info` section,
// and the three choices above survive the trip: the section's rows are
// `EventInfoService::sectionsFor`'s answer verbatim — the node's assembly, the
// node's render, the node's empty descriptions — composed for this caller at
// refresh time and stored whole (technical spec 9.3; POL-022). A device with
// no node in reach shows the published guidance it was handed, disclosed as
// the stored copy it is; a device that was never handed this event's info
// still fails and says so. Writing stays out of scope: there is nothing to
// write here.

import { meridianCachedJson } from "@/api/meridianApi";
import type { OfflineReadProjection } from "@/offline/offlineReadProjection";
import type { ReadFreshness } from "@/offline/readFreshness";

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
  /** Whether this page came from the node or from the stored set. */
  readonly freshness: ReadFreshness;
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

/** `event_info`, as `EventInfoSections` writes it: the endpoint's own sections. */
interface StoredEventInfoRow {
  readonly id: string;
  readonly event_id: string;
  readonly sections: EventInfoSectionPayload[];
}

/** `events`, as `RegularStaffSections` writes it — the fields this page names. */
interface StoredEventRow {
  readonly id: string;
  readonly organization_id: string | null;
  readonly name: string | null;
  readonly slug: string | null;
  readonly status: string | null;
  readonly timezone: string | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
}

/**
 * Event Info from what this device holds: the node's own assembly and render,
 * carried whole in the `event_info` section, with the event block read from
 * the core `events` section the same set carries. Null for an event this
 * caller's set does not cover — the seam then reports the unreachable node
 * rather than a page of empty sections.
 */
function storedEventInfo(
  eventId: string,
): OfflineReadProjection<EventInfoPayload> {
  return (source) => {
    const row = source
      .section<StoredEventInfoRow>("event_info")
      .find((entry) => entry.event_id === eventId);

    if (row === undefined) {
      return null;
    }

    const event = source
      .section<StoredEventRow>("events")
      .find((entry) => entry.id === eventId);

    return {
      data: {
        event:
          event === undefined
            ? { id: eventId }
            : {
                id: event.id,
                organization_id: event.organization_id,
                slug: event.slug,
                name: event.name ?? "",
                timezone: event.timezone,
                starts_at: event.starts_at,
                ends_at: event.ends_at,
                status: event.status,
              },
        sections: row.sections,
      },
    };
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
  const { data: result, freshness } =
    await meridianCachedJson<EventInfoPayload>(
      `/api/events/${encodeURIComponent(eventId)}/info`,
      { offline: storedEventInfo(eventId) },
    );

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
    freshness,
  };
}
