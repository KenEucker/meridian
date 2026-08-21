// The IMS surfaces' data layer (M16.20; CLIENT-023, CLIENT-006, CLIENT-015;
// INC-001 through INC-015; data/API 5.1, 5.2; IMS surface specification 8, 9).
//
// Until this task this module *was* the IMS. Three incidents, three Field
// Reports, and three responders were compiled into the client; a dozen `Map`s
// held the notes, links, strikes, and field edits a session made; and the rules
// the node enforces in `IncidentCreationService`, `IncidentUpdateService`,
// `IncidentTimelineService`, `IncidentLinkService`, `IncidentSearchService`, and
// `IncidentListPresetService` were written a second time in the browser —
// incident numbering, the timeline entries a field change produces, the
// same-event link rule, the free-text search, the preset cap. None of it reached
// a node, so an incident opened at a desk was nobody's incident, and every rule
// kept here could only drift from the one that actually governs.
//
// This module is now a translation of the incident endpoints. Five choices in it
// are deliberate:
//
//  1. **One read per surface, and the node answers the question that was
//     asked.** `GET /api/events/{event}/incidents` takes the search, filters,
//     sort, and page as query parameters and answers with the incidents, the
//     applied selection, the filter vocabulary, what a form may assign, the
//     caller's saved presets, and where the reader is in the result. Nothing
//     here re-filters or re-sorts what came back: a list narrowed locally is a
//     different answer than the one the node gave, and the page counts stop
//     meaning anything.
//  2. **Authority comes from the session response** (M16.6; CLIENT-004). The
//     capability codes `GET /api/me` publishes for the department the client is
//     working in decide what the surfaces offer — `incidents.view`,
//     `incidents.create`, `incidents.update`, `incidents.add_note`,
//     `incidents.link_field_report`, `incidents.print`. The old role predicates
//     over a compiled-in `IncidentSessionContext` are gone; all they could do
//     was disagree with the server, which refuses the request either way
//     (CLIENT-006).
//  3. **Every write is a command, and every one of them is connected-only.**
//     Technical spec 19.2 requires an active server connection for incident
//     mutations, so they go through `sendConnectedCommand` and are refused where
//     they stand rather than queued (CLIENT-015, CLIENT-018). The refusal is the
//     command catalog's own sentence.
//  4. **A write is followed by a read.** The commands answer with the record
//     they changed, but the timeline entry a change produced, the chips it moved,
//     and the other incident a link touched are the node's business. Re-reading
//     is one request and removes the whole class of bug where the screen and the
//     node disagree about what just happened.
//  5. **Tags stay client-side, and only tags.** `#tag` chips have no server
//     model — the list search strips a leading `#` and searches the text
//     (NR-010) — so they are parsed here from text the node sent, which is
//     rendering rather than a second copy of a rule. Name Reference chips are
//     the node's `name_reference_chips` and are not re-derived.

import { computed, ref } from "vue";

import { meridianCachedJson, MeridianApiError } from "@/api/meridianApi";
import {
  readViewedIncident,
  recordIncidentView,
} from "@/ims/viewedIncidentCache";
import {
  offlineReadSource,
  unionPendingByDeviceId,
  type OfflineReadProjection,
} from "@/offline/offlineReadProjection";
import type { ReadFreshness } from "@/offline/readFreshness";
import { sendConnectedCommand } from "@/outbox/submitCommand";
import {
  CAPABILITY_FIELD_REPORTS_VIEW_EVENT,
  CAPABILITY_INCIDENTS_ADD_NOTE,
  CAPABILITY_INCIDENTS_CREATE,
  CAPABILITY_INCIDENTS_LINK_FIELD_REPORT,
  CAPABILITY_INCIDENTS_PRINT,
  CAPABILITY_INCIDENTS_UPDATE,
  CAPABILITY_INCIDENTS_VIEW,
} from "@/session/permissionCodes";
import { clientSessionState } from "@/session/clientSession";
import {
  departmentHasCapability,
  selectedSessionDepartment,
  sessionEventContext,
} from "@/session/sessionAccess";
import { sessionOrganizationLabel } from "@/session/sessionContext";

/**
 * A status string as the node spells it.
 *
 * Not a union: `Incident::statuses()` is the vocabulary and it arrives on every
 * list read as `assignable.statuses`. A closed set declared here would be a
 * second copy of it that can only fall behind, and the one thing this client
 * does with a status — print it — degrades gracefully instead.
 */
export type IncidentStatus = string;

export type IncidentPriorityLabel = string;

/** Where the client is working, for the labels the IMS surfaces show. */
export interface IncidentSessionContext {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly organizationLabel: string;
  /** The department the incident capabilities are held in. */
  readonly icDepartmentLabel: string;
  /** The node's own names for the roles that carry `incidents.view` here. */
  readonly roleLabel: string;
}

/** What this caller may do with incidents, as the session response said. */
export interface IncidentAccess {
  readonly canView: boolean;
  readonly canCreate: boolean;
  readonly canUpdate: boolean;
  readonly canAddNote: boolean;
  readonly canLinkFieldReport: boolean;
  readonly canPrint: boolean;
  readonly canViewFieldReports: boolean;
}

export type IncidentListOpenMode = "view" | "edit";

/**
 * One saved incident list selection (M11.19; data/API 5.2).
 *
 * Presets capture what is being looked for, never where the reader had paged
 * to, so applying one always starts at the first page. The field names are the
 * node's own, because a preset is handed straight back to the list endpoint.
 */
export interface IncidentListFilterSelection {
  readonly search: string;
  readonly state: string;
  readonly priority: string;
  readonly type: string;
  readonly responder: string;
  readonly startedFrom: string | null;
  readonly startedTo: string | null;
  readonly sort: string;
  readonly direction: string;
}

export interface IncidentListPreset {
  readonly id: string;
  readonly eventId: string;
  readonly name: string;
  readonly filters: IncidentListFilterSelection;
  /** Ready to hand back to the list endpoint as query parameters. */
  readonly query: Record<string, string>;
}

export const INCIDENT_LIST_PRESET_NAME_MAX_LENGTH = 60;

export const INCIDENT_LIST_PAGE_SIZES: readonly number[] = Object.freeze([
  10, 25, 50, 100,
]);

export const DEFAULT_INCIDENT_LIST_PAGE_SIZE = 25;

export interface IncidentTimelineEntry {
  readonly id: string;
  readonly incidentId: string;
  readonly actorName: string | null;
  readonly entryType: string;
  readonly body: string | null;
  readonly previousValue?: Record<string, string | null>;
  readonly newValue?: Record<string, string | null>;
  readonly reason?: string | null;
  readonly createdAt: string;
  readonly strickenAt?: string | null;
  readonly strickenReason?: string | null;
}

export interface NameReferenceChip {
  readonly token: string;
  readonly normalizedToken: string;
}

export interface IncidentTagChip {
  readonly tag: string;
  readonly normalizedTag: string;
}

export interface IncidentResponder {
  readonly staffId: string;
  readonly displayName: string;
  readonly relationshipLabel: string;
}

/** A responder a form may attach, from the event's IC department roster. */
export interface AssignableResponder {
  readonly staffId: string;
  readonly displayName: string;
  readonly detail: string;
}

export interface LinkedIncidentSummary {
  readonly id: string;
  readonly incidentNumber: string;
  readonly title: string;
  readonly status: IncidentStatus;
}

export interface AttachedFieldReportSummary {
  readonly id: string;
  readonly displayNumber: string;
  readonly title: string;
  readonly authorName: string;
  readonly body: string;
  readonly linkedAt: string | null;
}

/** One file held against the incident, as the detail read reports it. */
export interface IncidentAttachment {
  readonly id: string;
  readonly filename: string;
  readonly mimeType: string;
  readonly byteSize: number;
  readonly createdAt: string | null;
}

export interface FieldReportLinkCandidate {
  readonly id: string;
  readonly eventId: string;
  readonly displayNumber: string;
  readonly title: string;
  readonly authorName: string;
  readonly body: string;
  readonly createdAt: string;
}

export interface ImsFieldReportListItem extends FieldReportLinkCandidate {
  readonly relatedIncidents: readonly {
    readonly id: string;
    readonly incidentNumber: string;
    readonly title: string;
    readonly status: IncidentStatus;
    readonly priorityLabel: IncidentPriorityLabel;
  }[];
}

export interface ImsFieldReportList {
  readonly reports: readonly ImsFieldReportListItem[];
  /** Whether this list came from the node or from what the device holds. */
  readonly freshness: ReadFreshness;
}

export interface ImsIncident {
  readonly id: string;
  readonly eventId: string;
  readonly incidentNumber: string;
  readonly title: string;
  readonly status: IncidentStatus;
  readonly priorityLabel: IncidentPriorityLabel;
  readonly incidentTypeNames: readonly string[];
  readonly responders: readonly IncidentResponder[];
  readonly linkedIncidents: readonly LinkedIncidentSummary[];
  readonly attachedFieldReports: readonly AttachedFieldReportSummary[];
  readonly attachments: readonly IncidentAttachment[];
  readonly startedAt: string;
  readonly locationName: string | null;
  readonly locationAddress: string | null;
  readonly locationDetails: string | null;
  readonly createdByName: string | null;
  readonly createdAt: string;
  readonly updatedAt: string;
  readonly closedAt: string | null;
  readonly nameReferenceChips: readonly NameReferenceChip[];
  /** Parsed from the node's own text; see the module header. */
  readonly tagChips: readonly IncidentTagChip[];
  readonly timelineEntries: readonly IncidentTimelineEntry[];
}

export interface IncidentAutosaveForm {
  title: string;
  status: IncidentStatus;
  priorityLabel: IncidentPriorityLabel;
  incidentTypeNames: string[];
  responderStaffIds: string[];
  startedAt: string;
  locationName: string;
  locationAddress: string;
  locationDetails: string;
}

/** What a reader may narrow the list by, as the node published it. */
export interface IncidentListFilterOptions {
  readonly states: readonly string[];
  readonly priorities: readonly string[];
  readonly sorts: readonly string[];
  readonly types: readonly string[];
  readonly responders: readonly { staffId: string; displayName: string }[];
  readonly maxPerPage: number;
}

/** What an authoring form may put on an incident, as the node published it. */
export interface IncidentAssignableOptions {
  readonly statuses: readonly string[];
  readonly priorities: readonly string[];
  readonly types: readonly string[];
  readonly responders: readonly AssignableResponder[];
}

export interface IncidentListPagination {
  readonly page: number;
  readonly perPage: number;
  readonly total: number;
  readonly totalPages: number;
  readonly hasMore: boolean;
}

/** The whole incident list surface in one response. */
export interface IncidentList {
  readonly eventId: string;
  readonly filters: IncidentListFilterSelection;
  readonly filterOptions: IncidentListFilterOptions;
  readonly assignable: IncidentAssignableOptions;
  readonly pagination: IncidentListPagination;
  readonly presets: readonly IncidentListPreset[];
  readonly incidents: readonly ImsIncident[];
  /** Whether this list came from the node or from the stored preload. */
  readonly freshness: ReadFreshness;
}

const STATUS_LABELS: Readonly<Record<string, string>> = Object.freeze({
  open: "Open",
  on_scene: "On Scene",
  monitoring: "Monitoring",
  on_hold: "On Hold",
  closed: "Closed",
});

/**
 * The IC context, or null when the client is not working in one.
 *
 * Both halves are required. An event-scoped read is not constructible without an
 * event id, and the department is where the incident capabilities are held, so a
 * client holding neither has no IMS to show rather than an empty one.
 */
export const incidentSessionContext = computed<IncidentSessionContext | null>(
  () => {
    const event = sessionEventContext.value;
    const department = selectedSessionDepartment.value;

    if (event === null || department === null) {
      return null;
    }

    return {
      eventId: event.eventId,
      eventLabel: event.eventLabel ?? "This event",
      organizationLabel: sessionOrganizationLabel.value ?? "This organization",
      icDepartmentLabel: department.departmentLabel,
      roleLabel: incidentRoleLabel(),
    };
  },
);

/**
 * What the IMS surfaces may offer, from the capabilities the session carries
 * for the department the client is working in.
 *
 * Presentation only. Each of these is decided again on the server, and a client
 * that fails to hide an action is still refused (CLIENT-006).
 */
export const incidentAccess = computed<IncidentAccess>(() => {
  const department = selectedSessionDepartment.value;
  const canView = departmentHasCapability(department, CAPABILITY_INCIDENTS_VIEW);

  return {
    canView,
    canCreate:
      canView && departmentHasCapability(department, CAPABILITY_INCIDENTS_CREATE),
    canUpdate:
      canView && departmentHasCapability(department, CAPABILITY_INCIDENTS_UPDATE),
    canAddNote:
      canView &&
      departmentHasCapability(department, CAPABILITY_INCIDENTS_ADD_NOTE),
    canLinkFieldReport:
      canView &&
      departmentHasCapability(
        department,
        CAPABILITY_INCIDENTS_LINK_FIELD_REPORT,
      ),
    canPrint:
      canView && departmentHasCapability(department, CAPABILITY_INCIDENTS_PRINT),
    canViewFieldReports:
      canView &&
      departmentHasCapability(department, CAPABILITY_FIELD_REPORTS_VIEW_EVENT),
  };
});

/**
 * Whether the list opens an incident for viewing or for editing.
 *
 * A per-device preference and nothing more: it decides which route a row links
 * to, and the edit route is only offered to a caller who holds
 * `incidents.update` regardless.
 *
 * Kept in local storage rather than in memory. An operator working a busy event
 * sets this once because they never want the read-only stop on the way to the
 * form, and a preference that resets on every reload is one they have to set
 * again every time the tab is refreshed — which is the same as not having it.
 * It is a device preference and not session state: it says nothing about who is
 * signed in and grants nothing, so it survives a sign-out the way the configured
 * node URL does.
 */
const INCIDENT_LIST_OPEN_MODE_KEY = "meridian.ims.incident-list.open-mode";

const incidentListOpenMode = ref<IncidentListOpenMode>(
  readIncidentListOpenMode(),
);

export function resolveIncidentListOpenMode(): IncidentListOpenMode {
  return incidentListOpenMode.value;
}

export function setIncidentListOpenMode(mode: IncidentListOpenMode): void {
  incidentListOpenMode.value = mode;

  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem(INCIDENT_LIST_OPEN_MODE_KEY, mode);
  } catch {
    // The choice still holds for this session; it just will not survive a
    // restart. Storage being unavailable is not a reason to refuse it.
  }
}

/** The reactive preference, for a surface that has to follow it. */
export const incidentListOpenModePreference = computed(
  () => incidentListOpenMode.value,
);

function readIncidentListOpenMode(): IncidentListOpenMode {
  if (typeof window === "undefined") {
    return "view";
  }

  try {
    return window.localStorage.getItem(INCIDENT_LIST_OPEN_MODE_KEY) === "edit"
      ? "edit"
      : "view";
  } catch {
    return "view";
  }
}

/**
 * The stored IC preload (technical spec 9.3 as amended 2026-08-20): the
 * event's open incidents and its most recent entries, carried in the set for
 * an IC-scoped caller, serialized by the node in the list endpoint's own
 * shape.
 *
 * The whole stored list answers, whatever was asked. Search, filters, and
 * paging are the node's selection machinery over the event's full history, and
 * a device holding a bounded preload cannot honestly claim to have applied
 * them — so the answer names `state: "all"` as its selection, carries no
 * filter vocabulary or presets, and is disclosed as narrowed. "Everything this
 * device holds" is the one selection the copy can truthfully serve.
 */
function storedIncidentList(
  eventId: string,
): OfflineReadProjection<IncidentListPayload> {
  return (source) => {
    if (!source.carries("ims_incidents")) {
      return null;
    }

    const incidents = source
      .section<IncidentPayload>("ims_incidents")
      .filter((incident) => incident.event_id === eventId);

    return {
      data: {
        event_id: eventId,
        filters: { state: "all" },
        pagination: {
          page: 1,
          per_page: Math.max(incidents.length, DEFAULT_INCIDENT_LIST_PAGE_SIZE),
          total: incidents.length,
          total_pages: 1,
          has_more: false,
        },
        incidents: [...incidents].sort(
          (left, right) =>
            (right.created_at ?? "").localeCompare(left.created_at ?? "") ||
            right.incident_number.localeCompare(left.incident_number),
        ),
      },
      narrowed: true,
    };
  };
}

/**
 * Read one page of an event's incidents.
 *
 * The selection goes to the node as query parameters and comes back on
 * `filters`, so the controls render from the node's answer rather than from
 * what was typed: an unparseable value is a refusal with a sentence, not a
 * control quietly showing something the list was not narrowed by.
 */
export async function getEventIncidents(
  eventId: string,
  query: Readonly<Record<string, string | number | null | undefined>> = {},
): Promise<IncidentList> {
  const search = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === null || value === undefined || value === "") {
      continue;
    }

    search.set(key, String(value));
  }

  const suffix = search.toString();
  const { data: payload, freshness } =
    await meridianCachedJson<IncidentListPayload>(
      `/api/events/${encodeURIComponent(eventId)}/incidents${suffix === "" ? "" : `?${suffix}`}`,
      { offline: storedIncidentList(eventId) },
    );

  return {
    freshness,
    eventId: payload.event_id ?? eventId,
    filters: toFilterSelection(payload.filters ?? {}),
    filterOptions: {
      states: payload.filter_options?.states ?? [],
      priorities: payload.filter_options?.priorities ?? [],
      sorts: payload.filter_options?.sorts ?? [],
      types: payload.filter_options?.types ?? [],
      responders: (payload.filter_options?.responders ?? []).map((option) => ({
        staffId: option.staff_id,
        displayName: option.display_name,
      })),
      maxPerPage:
        payload.filter_options?.max_per_page ?? DEFAULT_INCIDENT_LIST_PAGE_SIZE,
    },
    assignable: {
      statuses: payload.assignable?.statuses ?? [],
      priorities: payload.assignable?.priorities ?? [],
      types: payload.assignable?.types ?? [],
      responders: (payload.assignable?.responders ?? []).map((option) => ({
        staffId: option.staff_id,
        displayName: option.display_name,
        detail: option.detail ?? "",
      })),
    },
    pagination: {
      page: payload.pagination?.page ?? 1,
      perPage: payload.pagination?.per_page ?? DEFAULT_INCIDENT_LIST_PAGE_SIZE,
      total: payload.pagination?.total ?? 0,
      totalPages: Math.max(1, payload.pagination?.total_pages ?? 1),
      hasMore: payload.pagination?.has_more ?? false,
    },
    presets: (payload.presets ?? []).map(toPreset),
    incidents: (payload.incidents ?? []).map(toIncident),
  };
}

/**
 * The user whose views populate the device incident cache, or null when there
 * is nobody the cache may serve.
 *
 * Both halves of the gate live here so no call site can forget one: a caller
 * holding no `incidents.view` capability in the department they are working
 * never touches the cache in either direction (technical spec 19.2 — regular
 * staff see no incident UI, and their device holds no incident), and a client
 * holding no session has nobody to attribute a view to.
 */
function incidentCacheUserId(): string | null {
  if (!incidentAccess.value.canView) {
    return null;
  }

  return clientSessionState.document?.user.id ?? null;
}

/**
 * Read one incident.
 *
 * An incident outside the caller's visibility, or one that does not belong to
 * this event, is the node's refusal rather than a row missing from a list, so
 * the screen can say what happened instead of rendering empty.
 *
 * This read is also what populates the device incident cache (INC-017;
 * technical spec 19.2): a view answered by the node is stored, so an IC user
 * can re-read in the field anything they have already read at a desk. When the
 * node cannot be reached at all, the cached copy of a previously viewed
 * incident answers instead. A refusal is not an unreachable node: the node
 * spoke, and serving a stored copy over its answer would be the client
 * re-granting access the node had just withheld (CLIENT-006).
 */
export async function getEventIncident(
  eventId: string,
  incidentId: string,
): Promise<ImsIncident> {
  let payload: { incident: IncidentPayload };

  try {
    payload = (await meridianCachedJson<{ incident: IncidentPayload }>(
      `/api/events/${encodeURIComponent(eventId)}/incidents/${encodeURIComponent(incidentId)}`,
    )).data;
  } catch (error) {
    if (error instanceof MeridianApiError) {
      throw error;
    }

    const userId = incidentCacheUserId();
    const cached =
      userId === null ? null : readViewedIncident(eventId, incidentId, userId);

    /*
     * The stored IC preload can answer too (technical spec 9.3 as amended
     * 2026-08-20), and for an incident nobody on this device has opened it is
     * the only thing that can. Where both copies exist, the newer `updated_at`
     * wins: the viewed cache is refreshed by every online view and the preload
     * by every set refresh, and neither is guaranteed the later of the two.
     */
    const stored =
      userId === null
        ? undefined
        : offlineReadSource()
            ?.section<IncidentPayload>("ims_incidents")
            .find(
              (incident) =>
                incident.id === incidentId && incident.event_id === eventId,
            );

    if (stored !== undefined) {
      const storedIncident = toIncident(stored);

      if (
        cached === null ||
        storedIncident.updatedAt.localeCompare(cached.incident.updatedAt) >= 0
      ) {
        return storedIncident;
      }
    }

    if (cached === null) {
      throw error;
    }

    return cached.incident;
  }

  const incident = toIncident(payload.incident);
  const userId = incidentCacheUserId();

  if (userId !== null) {
    recordIncidentView(incident, userId);
  }

  return incident;
}

/** One Field Report as the offline read set carries it (technical spec 9.3). */
interface StoredFieldReportRow {
  readonly id: string;
  readonly event_id: string;
  readonly staff_id: string | null;
  readonly fra_number: string | null;
  readonly temporary_local_number: string | null;
  readonly title: string;
  readonly body: string;
  readonly created_at: string | null;
  readonly device_submitted_at: string | null;
}

interface StoredStaffRow {
  readonly id: string;
  readonly legal_name: string | null;
  readonly preferred_name: string | null;
}

/**
 * The display name for a staff row the set carries, built the way the node
 * builds it: preferred name where there is one, legal name otherwise.
 */
function storedStaffNames(
  rows: readonly StoredStaffRow[],
): ReadonlyMap<string, string> {
  return new Map(
    rows.map((row) => [
      row.id,
      row.preferred_name !== null && row.preferred_name !== ""
        ? row.preferred_name
        : (row.legal_name ?? ""),
    ]),
  );
}

/**
 * The Field Reports this device can show with no node in reach (M18.50;
 * technical spec 9.3, 17.2).
 *
 * Two lists become one. The set carries the caller's own submitted reports as
 * the node holds them, and the outbox carries the ones this device has filed and
 * not yet sent — the reports a person standing in a field has just written, which
 * are the ones they are most likely to go looking for.
 *
 * They are unioned on the device-generated identifier rather than concatenated.
 * A Field Report has been keyed by a UUID the device minted since M9.1 and the
 * node stores that same key, so a report waiting in the outbox and the accepted
 * copy of it in the set are one record under one key. Concatenating would show
 * it twice, once with its temporary local number and once with its FRA number,
 * which reads as two reports of the same incident.
 *
 * It is narrower than the node's list, and the surface's stale-read disclosure is
 * what says so: this is the copy this device holds, and this device holds the
 * caller's own reports rather than the event's.
 */
function storedFieldReports(
  eventId: string,
): OfflineReadProjection<FieldReportListPayload> {
  return (source) => {
    if (!source.carries("field_reports")) {
      return null;
    }

    const names = storedStaffNames(source.section<StoredStaffRow>("staff"));

    const stored = source
      .section<StoredFieldReportRow>("field_reports")
      .filter((report) => report.event_id === eventId)
      .map((report) => ({
        id: report.id,
        event_id: report.event_id,
        display_number: report.fra_number ?? report.temporary_local_number ?? "",
        title: report.title,
        author_name: names.get(report.staff_id ?? "") ?? "",
        body: report.body,
        created_at: report.created_at ?? report.device_submitted_at ?? "",
      }));

    const pending = source
      .pending("submit-field-report")
      .filter((command) => command.eventId === eventId)
      .map((command) => {
        const payload = command.payload as {
          readonly staff_id?: unknown;
          readonly temporary_local_number?: unknown;
          readonly title?: unknown;
          readonly body?: unknown;
          readonly device_submitted_at?: unknown;
        };
        const staffId =
          typeof payload.staff_id === "string" ? payload.staff_id : "";

        return {
          id: command.key,
          event_id: eventId,
          /*
           * The temporary local number, and it stays temporary-looking on
           * purpose (technical spec 17.5): a report the node has not seen must
           * not be presented under something that reads like an FRA number.
           */
          display_number:
            typeof payload.temporary_local_number === "string"
              ? payload.temporary_local_number
              : "",
          title: typeof payload.title === "string" ? payload.title : "",
          author_name: names.get(staffId) ?? "",
          body: typeof payload.body === "string" ? payload.body : "",
          created_at:
            typeof payload.device_submitted_at === "string"
              ? payload.device_submitted_at
              : command.queuedAt,
        };
      });

    return {
      data: {
        event_id: eventId,
        field_reports: unionPendingByDeviceId(
          stored,
          pending,
          (report) => report.id,
        ) as FieldReportListPayload["field_reports"],
      },
      // Narrower than the request: the set carries this reader's own reports,
      // not the event's, and the surface names the difference rather than
      // letting a short list read as a quiet shift.
      narrowed: true,
    };
  };
}

/**
 * Read the event's Field Reports, as Incident Command sees them.
 *
 * Carries its own freshness because what answers it offline is not the same list
 * (M18.50). A stale copy of an event's Field Reports is worth showing; a stale
 * copy presented as the event's current list is not, and the surface says which
 * one it has.
 */
export async function getEventFieldReports(
  eventId: string,
): Promise<ImsFieldReportList> {
  const { data, freshness } = await meridianCachedJson<FieldReportListPayload>(
    `/api/events/${encodeURIComponent(eventId)}/field-reports`,
    { offline: storedFieldReports(eventId) },
  );

  return {
    freshness,
    reports: (data.field_reports ?? []).map((report) => ({
      id: report.id,
      eventId: report.event_id ?? eventId,
      displayNumber: report.display_number,
      title: report.title,
      authorName: report.author_name,
      body: report.body,
      createdAt: report.created_at ?? "",
      relatedIncidents: (report.related_incidents ?? []).map((incident) => ({
        id: incident.id,
        incidentNumber: incident.incident_number,
        title: incident.title,
        status: incident.status,
        priorityLabel: incident.priority_label ?? "",
      })),
    })),
  };
}

export function blankIncidentAutosaveForm(
  now = new Date(),
  assignable: IncidentAssignableOptions | null = null,
): IncidentAutosaveForm {
  return {
    title: "",
    status: assignable?.statuses[0] ?? "open",
    priorityLabel: assignable?.priorities[0] ?? "Routine",
    incidentTypeNames: [],
    responderStaffIds: [],
    startedAt: toDatetimeLocalValue(now),
    locationName: "",
    locationAddress: "",
    locationDetails: "",
  };
}

export function incidentToAutosaveForm(
  incident: ImsIncident,
): IncidentAutosaveForm {
  return {
    title: incident.title,
    status: incident.status,
    priorityLabel: incident.priorityLabel,
    incidentTypeNames: [...incident.incidentTypeNames],
    responderStaffIds: incident.responders.map((responder) => responder.staffId),
    startedAt: toDatetimeLocalValue(new Date(incident.startedAt)),
    locationName: incident.locationName ?? "",
    locationAddress: incident.locationAddress ?? "",
    locationDetails: incident.locationDetails ?? "",
  };
}

/**
 * Open an incident.
 *
 * `initial_field_update_fields` names the fields the author changed from the
 * blank form before the first save, which is what the node records as the
 * opening field-update entry. It is the only place the client tells the node
 * anything about the timeline, and it says which fields moved, not what the
 * entry should say.
 */
export async function createIncident(
  eventId: string,
  form: IncidentAutosaveForm,
  previousForm: IncidentAutosaveForm | null = null,
): Promise<string> {
  const response = (await sendIncidentCommand("create-incident", {
    event_id: eventId,
    ...incidentAttributes(form),
    initial_field_update_fields: previousForm
      ? changedFormFields(previousForm, form)
      : [],
  })) as { id?: unknown };

  if (typeof response?.id !== "string") {
    throw new Error("The node accepted the incident but named no record.");
  }

  return response.id;
}

export async function updateIncident(
  eventId: string,
  incidentId: string,
  form: IncidentAutosaveForm,
): Promise<void> {
  await sendIncidentCommand("update-incident", {
    event_id: eventId,
    incident_id: incidentId,
    ...incidentAttributes(form),
  });
}

export async function appendIncidentNote(
  eventId: string,
  incidentId: string,
  body: string,
): Promise<void> {
  await sendIncidentCommand("append-incident-note", {
    event_id: eventId,
    incident_id: incidentId,
    body: body.trim(),
  });
}

export async function strikeIncidentNote(
  eventId: string,
  incidentId: string,
  timelineEntryId: string,
  reason: string,
): Promise<void> {
  await sendIncidentCommand("strike-incident-note", {
    event_id: eventId,
    incident_id: incidentId,
    timeline_entry_id: timelineEntryId,
    reason: reason.trim(),
  });
}

export async function linkIncident(
  eventId: string,
  incidentId: string,
  targetIncidentId: string,
): Promise<void> {
  await sendIncidentCommand("link-incident", {
    event_id: eventId,
    incident_id: incidentId,
    target_incident_id: targetIncidentId,
  });
}

export async function unlinkIncident(
  eventId: string,
  incidentId: string,
  targetIncidentId: string,
): Promise<void> {
  await sendIncidentCommand("unlink-incident", {
    event_id: eventId,
    incident_id: incidentId,
    target_incident_id: targetIncidentId,
  });
}

export async function linkFieldReport(
  eventId: string,
  incidentId: string,
  fieldReportId: string,
): Promise<void> {
  await sendIncidentCommand("link-field-report", {
    event_id: eventId,
    incident_id: incidentId,
    field_report_id: fieldReportId,
  });
}

export async function unlinkFieldReport(
  eventId: string,
  incidentId: string,
  fieldReportId: string,
): Promise<void> {
  await sendIncidentCommand("unlink-field-report", {
    event_id: eventId,
    incident_id: incidentId,
    field_report_id: fieldReportId,
  });
}

/**
 * Strike an incident attachment.
 *
 * A strike, not a delete: the file stops being served and the reason is kept
 * (INC-013). The node requires the reason, so it is asked for rather than
 * defaulted.
 */
export async function strikeIncidentAttachment(
  eventId: string,
  incidentId: string,
  attachmentId: string,
  reason: string,
): Promise<void> {
  await sendIncidentCommand("strike-incident-attachment", {
    event_id: eventId,
    incident_id: incidentId,
    attachment_id: attachmentId,
    reason: reason.trim(),
  });
}

/**
 * Save the caller's list preset under this name, and return their presets.
 *
 * Saving an existing name updates it: that is the node's behavior, not a
 * decision made here, and the response carries the whole list back so the
 * control never has to guess where the new one sorts.
 */
export async function saveIncidentListPreset(
  eventId: string,
  name: string,
  filters: IncidentListFilterSelection,
): Promise<readonly IncidentListPreset[]> {
  const response = (await sendIncidentCommand("save-incident-list-preset", {
    event_id: eventId,
    name: name.trim(),
    filters: toFilterPayload(filters),
  })) as { presets?: PresetPayload[] };

  return (response?.presets ?? []).map(toPreset);
}

export async function deleteIncidentListPreset(
  eventId: string,
  presetId: string,
): Promise<readonly IncidentListPreset[]> {
  const response = (await sendIncidentCommand("delete-incident-list-preset", {
    event_id: eventId,
    preset_id: presetId,
  })) as { presets?: PresetPayload[] };

  return (response?.presets ?? []).map(toPreset);
}

export function statusLabel(status: IncidentStatus): string {
  return STATUS_LABELS[status] ?? status;
}

export function formatIncidentDateTime(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const year = String(date.getFullYear());
  const hour = String(date.getHours()).padStart(2, "0");
  const minute = String(date.getMinutes()).padStart(2, "0");

  return `${month}-${day}-${year} ${hour}:${minute}`;
}

export function visibleIncidentTimelineEntries(
  entries: readonly IncidentTimelineEntry[],
  showFullHistory: boolean,
): readonly IncidentTimelineEntry[] {
  if (showFullHistory) {
    return entries;
  }

  const latestOpenCloseEntryId = latestOpenCloseStatusEntry(entries)?.id ?? null;

  return entries.filter((entry) => {
    if (entry.strickenAt) {
      return false;
    }

    if (
      entry.entryType === "incident_opened" ||
      entry.entryType === "operational_note" ||
      entry.entryType === "field_report_linked"
    ) {
      return true;
    }

    return entry.id === latestOpenCloseEntryId;
  });
}

/**
 * Incidents this one may be linked to, ordered as the IMS specification asks.
 *
 * The node found them — same event, matching the picker's search — and the
 * ordering on top of that is the specification's: candidates sharing a `#tag`
 * with this incident first, most recently created first within each group
 * (IMS spec 9). Anything already actively linked is dropped, and an incident is
 * never a candidate for itself.
 */
export function orderedLinkCandidates(
  incident: ImsIncident,
  candidates: readonly ImsIncident[],
): ImsIncident[] {
  const linkedIds = new Set(incident.linkedIncidents.map((linked) => linked.id));
  const tags = incidentTagSet(incident);

  return candidates
    .filter((candidate) => candidate.id !== incident.id)
    .filter((candidate) => !linkedIds.has(candidate.id))
    .sort((left, right) => {
      const leftShares = sharesTag(incidentTagSet(left), tags);
      const rightShares = sharesTag(incidentTagSet(right), tags);

      if (leftShares !== rightShares) {
        return leftShares ? -1 : 1;
      }

      return (
        right.createdAt.localeCompare(left.createdAt) ||
        right.incidentNumber.localeCompare(left.incidentNumber)
      );
    })
    .slice(0, 6);
}

/**
 * Field Reports this incident may be attached to, ordered the same way.
 *
 * Most recent first is the node's acceptance time, which is what the
 * specification names for Field Report candidates.
 */
export function orderedFieldReportCandidates(
  incident: ImsIncident,
  candidates: readonly FieldReportLinkCandidate[],
  search = "",
): FieldReportLinkCandidate[] {
  const attachedIds = new Set(
    incident.attachedFieldReports.map((report) => report.id),
  );
  const tags = incidentTagSet(incident);
  const normalizedSearch = normalizeSearch(search);

  return candidates
    .filter((candidate) => !attachedIds.has(candidate.id))
    .filter((candidate) => fieldReportMatches(candidate, normalizedSearch))
    .sort((left, right) => {
      const leftShares = sharesTag(textTagSet([left.title, left.body]), tags);
      const rightShares = sharesTag(textTagSet([right.title, right.body]), tags);

      if (leftShares !== rightShares) {
        return leftShares ? -1 : 1;
      }

      return (
        right.createdAt.localeCompare(left.createdAt) ||
        right.displayNumber.localeCompare(left.displayNumber)
      );
    })
    .slice(0, 6);
}

/**
 * Every incident command, sent now or refused now.
 *
 * The idempotency key is generated per call rather than per form. These are
 * connected-only commands and nothing holds them, so the key is not what makes
 * a repeat safe here; it is what lets the node recognize a repeat if one of
 * these ever becomes queueable.
 */
async function sendIncidentCommand(
  commandType: Parameters<typeof sendConnectedCommand>[0]["commandType"],
  payload: Readonly<Record<string, unknown>>,
): Promise<unknown> {
  return sendConnectedCommand({
    commandType,
    idempotencyKey: commandIdempotencyKey(),
    payload,
    eventId: typeof payload.event_id === "string" ? payload.event_id : null,
  });
}

function commandIdempotencyKey(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `incident-command-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}

/**
 * The form as the create and update commands take it.
 *
 * Trimmed, and empty text sent as null. The node trims too, so this changes
 * nothing it stores; it changes what a whitespace-only location does, which
 * would otherwise be stored as a location that looks set and reads blank.
 */
function incidentAttributes(
  form: IncidentAutosaveForm,
): Record<string, unknown> {
  return {
    title: form.title.trim(),
    status: form.status,
    priority_label: form.priorityLabel,
    started_at: fromDatetimeLocalValue(form.startedAt),
    location_name: nullableText(form.locationName),
    location_address: nullableText(form.locationAddress),
    location_details: nullableText(form.locationDetails),
    incident_type_names: normalizedStringList(form.incidentTypeNames),
    responder_staff_ids: [...new Set(form.responderStaffIds)],
  };
}

/** The command's own field names for what moved between two form states. */
function changedFormFields(
  previous: IncidentAutosaveForm,
  next: IncidentAutosaveForm,
): string[] {
  const before = incidentAttributes(previous);
  const after = incidentAttributes(next);

  return Object.keys(after).filter(
    (field) => JSON.stringify(before[field]) !== JSON.stringify(after[field]),
  );
}

function toFilterSelection(
  payload: Partial<FilterSelectionPayload>,
): IncidentListFilterSelection {
  return {
    search: payload.search ?? "",
    state: payload.state ?? "active",
    priority: payload.priority ?? "all",
    type: payload.type ?? "all",
    responder: payload.responder ?? "all",
    startedFrom: payload.started_from ?? null,
    startedTo: payload.started_to ?? null,
    sort: payload.sort ?? "updated",
    direction: payload.direction ?? "desc",
  };
}

function toFilterPayload(
  selection: IncidentListFilterSelection,
): Record<string, string> {
  const payload: Record<string, string> = {
    search: selection.search,
    state: selection.state,
    priority: selection.priority,
    type: selection.type,
    responder: selection.responder,
    sort: selection.sort,
    direction: selection.direction,
  };

  if (selection.startedFrom) {
    payload.started_from = selection.startedFrom;
  }

  if (selection.startedTo) {
    payload.started_to = selection.startedTo;
  }

  return payload;
}

function toPreset(payload: PresetPayload): IncidentListPreset {
  return {
    id: payload.id,
    eventId: payload.event_id,
    name: payload.name,
    filters: toFilterSelection(payload.filters ?? {}),
    query: payload.query ?? {},
  };
}

function toIncident(payload: IncidentPayload): ImsIncident {
  const timelineEntries = (payload.timeline_entries ?? []).map(
    toTimelineEntry,
  );
  const attachedFieldReports = (payload.attached_field_reports ?? []).map(
    (report) => ({
      id: report.field_report_id ?? report.id,
      displayNumber: report.display_number,
      title: report.title,
      authorName: report.author_name,
      body: report.body,
      linkedAt: report.linked_at ?? null,
    }),
  );
  const incidentTypeNames = payload.incident_type_names ?? [];
  const responders = (payload.responders ?? []).map((responder) => ({
    staffId: responder.staff_id,
    displayName: responder.display_name,
    relationshipLabel: responder.relationship_label ?? "Responder",
  }));

  return {
    id: payload.id,
    eventId: payload.event_id,
    incidentNumber: payload.incident_number,
    title: payload.title ?? "",
    status: payload.status,
    priorityLabel: payload.priority_label ?? "",
    incidentTypeNames,
    responders,
    linkedIncidents: (payload.linked_incidents ?? []).map((linked) => ({
      id: linked.id,
      incidentNumber: linked.incident_number,
      title: linked.title,
      status: linked.status,
    })),
    attachedFieldReports,
    attachments: (payload.attachments ?? []).map((attachment) => ({
      id: attachment.id,
      filename: attachment.filename,
      mimeType: attachment.mime_type,
      byteSize: attachment.byte_size,
      createdAt: attachment.created_at ?? null,
    })),
    startedAt: payload.started_at ?? "",
    locationName: payload.location_name ?? null,
    locationAddress: payload.location_address ?? null,
    locationDetails: payload.location_details ?? null,
    createdByName: payload.created_by_name ?? null,
    createdAt: payload.created_at ?? "",
    updatedAt: payload.updated_at ?? payload.created_at ?? "",
    closedAt: payload.closed_at ?? null,
    nameReferenceChips: (payload.name_reference_chips ?? []).map((chip) => ({
      token: chip.token,
      normalizedToken: chip.normalized_token,
    })),
    tagChips: textTagChips([
      payload.title ?? "",
      ...incidentTypeNames,
      ...responders.map((responder) => responder.displayName),
      ...attachedFieldReports.map((report) => report.body),
      ...timelineEntries
        .filter((entry) => entry.entryType !== "field_report_linked")
        .filter((entry) => !entry.strickenAt)
        .map((entry) => entry.body ?? ""),
    ]),
    timelineEntries,
  };
}

function toTimelineEntry(payload: TimelineEntryPayload): IncidentTimelineEntry {
  return {
    id: payload.id,
    incidentId: payload.incident_id,
    actorName: payload.actor_name ?? null,
    entryType: payload.entry_type,
    body: payload.body ?? null,
    previousValue: payload.previous_value ?? undefined,
    newValue: payload.new_value ?? undefined,
    reason: payload.reason ?? null,
    createdAt: payload.created_at ?? "",
    strickenAt: payload.stricken_at ?? null,
    strickenReason: payload.stricken_reason ?? null,
  };
}

function latestOpenCloseStatusEntry(
  entries: readonly IncidentTimelineEntry[],
): IncidentTimelineEntry | null {
  const statusEntries = entries.filter((entry) => {
    if (entry.strickenAt || entry.entryType !== "incident_field_updated") {
      return false;
    }

    const status = entry.newValue?.status;

    return status === "open" || status === "closed";
  });

  return statusEntries[statusEntries.length - 1] ?? null;
}

function incidentTagSet(incident: ImsIncident): Set<string> {
  return new Set(incident.tagChips.map((chip) => chip.normalizedTag));
}

function textTagSet(values: readonly string[]): Set<string> {
  return new Set(textTagChips(values).map((chip) => chip.normalizedTag));
}

function sharesTag(
  candidateTags: ReadonlySet<string>,
  incidentTags: ReadonlySet<string>,
): boolean {
  if (incidentTags.size === 0) {
    return false;
  }

  return [...candidateTags].some((tag) => incidentTags.has(tag));
}

function textTagChips(values: readonly string[]): IncidentTagChip[] {
  const byNormalizedTag = new Map<string, IncidentTagChip>();

  for (const value of values) {
    for (const match of value.matchAll(/#([A-Za-z0-9_-]+)/gu)) {
      const tag = match[1] ?? "";

      if (tag === "" || byNormalizedTag.has(tag.toLowerCase())) {
        continue;
      }

      byNormalizedTag.set(tag.toLowerCase(), {
        tag,
        normalizedTag: tag.toLowerCase(),
      });
    }
  }

  return [...byNormalizedTag.values()];
}

function normalizeSearch(search: string): string {
  const trimmed = search.trim();

  return trimmed.startsWith("@") || trimmed.startsWith("#")
    ? trimmed.slice(1).toLowerCase()
    : trimmed.toLowerCase();
}

function fieldReportMatches(
  report: FieldReportLinkCandidate,
  normalizedSearch: string,
): boolean {
  if (normalizedSearch.length === 0) {
    return true;
  }

  return [report.displayNumber, report.title, report.authorName, report.body]
    .join(" ")
    .toLowerCase()
    .includes(normalizedSearch);
}

/**
 * The roles that carry `incidents.view` here, as the node names them.
 *
 * Printed on the IMS heading cards so the person can see which standing they
 * are working under. Names rather than codes, because the node already sent
 * them and a person reading a screen mid-event should not have to translate
 * `ic_lead`.
 */
function incidentRoleLabel(): string {
  const department = selectedSessionDepartment.value;

  if (department === null) {
    return "Incident Command";
  }

  const names = [
    ...new Set(
      department.roles
        .filter((role) => role.capabilities.includes(CAPABILITY_INCIDENTS_VIEW))
        .map((role) => role.role_name)
        .filter((name): name is string => typeof name === "string" && name !== ""),
    ),
  ];

  return names.length > 0 ? names.join(", ") : "Incident Command";
}

function normalizedStringList(values: readonly string[]): string[] {
  const seen = new Set<string>();
  const nextValues: string[] = [];

  for (const value of values) {
    const trimmed = value.trim();

    if (trimmed.length === 0 || seen.has(trimmed.toLowerCase())) {
      continue;
    }

    seen.add(trimmed.toLowerCase());
    nextValues.push(trimmed);
  }

  return nextValues;
}

function nullableText(value: string): string | null {
  const trimmed = value.trim();

  return trimmed.length === 0 ? null : trimmed;
}

function toDatetimeLocalValue(date: Date): string {
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const year = String(date.getFullYear()).padStart(4, "0");
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  const hour = String(date.getHours()).padStart(2, "0");
  const minute = String(date.getMinutes()).padStart(2, "0");

  return `${year}-${month}-${day}T${hour}:${minute}`;
}

function fromDatetimeLocalValue(value: string): string {
  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime())
    ? new Date().toISOString()
    : parsed.toISOString();
}

interface FilterSelectionPayload {
  readonly search: string;
  readonly state: string;
  readonly priority: string;
  readonly type: string;
  readonly responder: string;
  readonly started_from: string | null;
  readonly started_to: string | null;
  readonly sort: string;
  readonly direction: string;
}

interface PresetPayload {
  readonly id: string;
  readonly event_id: string;
  readonly name: string;
  readonly filters?: Partial<FilterSelectionPayload>;
  readonly query?: Record<string, string>;
}

interface TimelineEntryPayload {
  readonly id: string;
  readonly incident_id: string;
  readonly actor_name?: string | null;
  readonly entry_type: string;
  readonly body?: string | null;
  readonly previous_value?: Record<string, string | null> | null;
  readonly new_value?: Record<string, string | null> | null;
  readonly reason?: string | null;
  readonly created_at?: string | null;
  readonly stricken_at?: string | null;
  readonly stricken_reason?: string | null;
}

interface IncidentPayload {
  readonly id: string;
  readonly event_id: string;
  readonly incident_number: string;
  readonly status: string;
  readonly priority_label?: string | null;
  readonly started_at?: string | null;
  readonly title?: string | null;
  readonly location_name?: string | null;
  readonly location_address?: string | null;
  readonly location_details?: string | null;
  readonly incident_type_names?: string[];
  readonly responders?: {
    readonly staff_id: string;
    readonly display_name: string;
    readonly relationship_label?: string | null;
  }[];
  readonly linked_incidents?: {
    readonly id: string;
    readonly incident_number: string;
    readonly title: string;
    readonly status: string;
  }[];
  readonly attached_field_reports?: {
    readonly id: string;
    readonly field_report_id?: string;
    readonly display_number: string;
    readonly title: string;
    readonly author_name: string;
    readonly body: string;
    readonly linked_at?: string | null;
  }[];
  readonly attachments?: {
    readonly id: string;
    readonly filename: string;
    readonly mime_type: string;
    readonly byte_size: number;
    readonly created_at?: string | null;
  }[];
  readonly created_by_name?: string | null;
  readonly created_at?: string | null;
  readonly updated_at?: string | null;
  readonly closed_at?: string | null;
  readonly name_reference_chips?: {
    readonly token: string;
    readonly normalized_token: string;
  }[];
  readonly timeline_entries?: TimelineEntryPayload[];
}

interface IncidentListPayload {
  readonly event_id?: string;
  readonly filters?: Partial<FilterSelectionPayload>;
  readonly filter_options?: {
    readonly states?: string[];
    readonly priorities?: string[];
    readonly sorts?: string[];
    readonly types?: string[];
    readonly responders?: {
      readonly staff_id: string;
      readonly display_name: string;
    }[];
    readonly max_per_page?: number;
  };
  readonly assignable?: {
    readonly statuses?: string[];
    readonly priorities?: string[];
    readonly types?: string[];
    readonly responders?: {
      readonly staff_id: string;
      readonly display_name: string;
      readonly detail?: string;
    }[];
  };
  readonly pagination?: {
    readonly page?: number;
    readonly per_page?: number;
    readonly total?: number;
    readonly total_pages?: number;
    readonly has_more?: boolean;
  };
  readonly presets?: PresetPayload[];
  readonly incidents?: IncidentPayload[];
}

interface FieldReportListPayload {
  readonly event_id?: string;
  readonly field_reports?: {
    readonly id: string;
    readonly event_id?: string;
    readonly display_number: string;
    readonly title: string;
    readonly author_name: string;
    readonly body: string;
    readonly created_at?: string | null;
    readonly related_incidents?: {
      readonly id: string;
      readonly incident_number: string;
      readonly title: string;
      readonly status: string;
      readonly priority_label?: string | null;
    }[];
  }[];
}
