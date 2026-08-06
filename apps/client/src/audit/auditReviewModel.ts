// Audit review, as an organizer's client reads it (M18.29; requirements 2.4;
// UI contract 12.6 `organizer.audit`; ORG-015).
//
// One organization-scoped read, filtered by the node. Two properties are worth
// being explicit about, because both are decisions the node makes and this
// client only renders:
//
//  - **Incident and Field Report history is not in the answer.** ORG-015 says
//    organizing grants no access to either, and an audit row naming one
//    discloses that it exists, when it happened, and who worked it. An
//    organizer who also holds IC standing reads that history through the
//    incident's own timeline as an IC user. There is nothing to filter here,
//    and nothing this client could ask for that would widen it.
//  - **Rows carry the fields that changed, not their values.** An audit payload
//    is whatever the writing path snapshotted, so serving it would make this
//    surface an unscoped read of every field every audited path happens to
//    record. Attribution — who, what, when, why — is what requirements 2.4
//    asks of an audit record, and it is what a row carries.
//
// Nothing is cached. A history somebody is reading to answer "who changed this"
// is a claim about the record as it stands, and a stale copy answers a question
// nobody asked.

import { meridianJson } from "@/api/meridianApi";

export interface AuditEntry {
  readonly id: string;
  readonly action: string;
  readonly entityType: string;
  /** The entity type in words, since the stored value is a class name. */
  readonly entityLabel: string;
  readonly entityId: string;
  /** Null for a scheduled job, which acts with no person behind it. */
  readonly actorName: string | null;
  readonly actorUserId: string | null;
  readonly eventId: string | null;
  readonly eventName: string | null;
  readonly departmentId: string | null;
  readonly departmentName: string | null;
  readonly reason: string | null;
  readonly sourceContext: string;
  readonly recordedAt: string | null;
  readonly changedFields: readonly string[];
}

export interface AuditPage {
  readonly page: number;
  readonly perPage: number;
  readonly total: number;
  readonly lastPage: number;
}

export interface AuditReview {
  readonly organizationId: string;
  readonly entries: readonly AuditEntry[];
  readonly pagination: AuditPage;
  /** Values read off the rows this caller may see, never a hardcoded list. */
  readonly actions: readonly string[];
  readonly entityTypes: readonly string[];
}

/** What a reader may narrow the record by. */
export interface AuditFilters {
  readonly action?: string;
  readonly entityType?: string;
  readonly from?: string;
  readonly to?: string;
  readonly page?: number;
}

interface EntryPayload {
  readonly id?: string;
  readonly action?: string;
  readonly entity_type?: string;
  readonly entity_label?: string;
  readonly entity_id?: string;
  readonly actor_name?: string | null;
  readonly actor_user_id?: string | null;
  readonly event_id?: string | null;
  readonly event_name?: string | null;
  readonly department_id?: string | null;
  readonly department_name?: string | null;
  readonly reason?: string | null;
  readonly source_context?: string;
  readonly recorded_at?: string | null;
  readonly changed_fields?: readonly string[];
}

interface ReviewPayload {
  readonly organization_id?: string;
  readonly entries?: readonly EntryPayload[];
  readonly pagination?: {
    page?: number;
    per_page?: number;
    total?: number;
    last_page?: number;
  };
  readonly options?: {
    actions?: readonly string[];
    entity_types?: readonly string[];
  };
}

function toEntry(payload: EntryPayload): AuditEntry {
  return {
    id: payload.id ?? "",
    action: payload.action ?? "",
    entityType: payload.entity_type ?? "",
    entityLabel: payload.entity_label ?? payload.entity_type ?? "",
    entityId: payload.entity_id ?? "",
    actorName: payload.actor_name ?? null,
    actorUserId: payload.actor_user_id ?? null,
    eventId: payload.event_id ?? null,
    eventName: payload.event_name ?? null,
    departmentId: payload.department_id ?? null,
    departmentName: payload.department_name ?? null,
    reason: payload.reason ?? null,
    sourceContext: payload.source_context ?? "system",
    recordedAt: payload.recorded_at ?? null,
    changedFields: payload.changed_fields ?? [],
  };
}

export async function getAuditReview(
  organizationId: string,
  filters: AuditFilters = {},
): Promise<AuditReview> {
  const query = new URLSearchParams();

  if (filters.action) {
    query.set("action", filters.action);
  }

  if (filters.entityType) {
    query.set("entity_type", filters.entityType);
  }

  if (filters.from) {
    query.set("from", filters.from);
  }

  if (filters.to) {
    query.set("to", filters.to);
  }

  if (filters.page !== undefined && filters.page > 1) {
    query.set("page", String(filters.page));
  }

  const suffix = query.toString() === "" ? "" : `?${query.toString()}`;

  const payload = await meridianJson<ReviewPayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/audit${suffix}`,
  );

  return {
    organizationId: payload?.organization_id ?? organizationId,
    entries: (payload?.entries ?? []).map(toEntry),
    pagination: {
      page: payload?.pagination?.page ?? 1,
      perPage: payload?.pagination?.per_page ?? 50,
      total: payload?.pagination?.total ?? 0,
      lastPage: payload?.pagination?.last_page ?? 1,
    },
    actions: payload?.options?.actions ?? [],
    entityTypes: payload?.options?.entity_types ?? [],
  };
}
