// The waiver administration surface's data layer (M18.18; WAIVER-001 through
// WAIVER-006, WAIVER-010; CLIENT-023).
//
// `WaiverService` has enforced scope, expiry, and completion on the node since
// M7.2, and shift signup has refused an incomplete required waiver since M7.6
// — but nothing in the product could create a waiver or record that somebody
// signed one. This module is a translation of the two reads and five commands
// M18.18 adds.
//
// Two properties are load-bearing:
//
//  1. **Authority arrives with the read, not from the session.** Waiver
//     administration authority follows the scope of the waiver (WAIVER-010) —
//     organizers for organization scope, department leads for department
//     scope, team leads for team scope — which is three different questions no
//     single capability code answers. The node resolves the caller's
//     maintainable scopes and answers with exactly the waivers and scope
//     options they hold; a caller holding none gets the refusal, and the
//     surface renders that answer rather than predicting it (CLIENT-006).
//  2. **Everything here is connected-only.** A completion of a document-backed
//     waiver records the document version the person was shown (WAIVER-008),
//     and one held on a device would record whichever version the device last
//     cached. The administration commands are desk work with no urgent case.

import { meridianCachedJson } from "@/api/meridianApi";
import { sendConnectedCommand } from "@/outbox/submitCommand";
import type { MeridianCommandType } from "@/outbox/commandCatalog";

/** The document a waiver references as the text being agreed to (WAIVER-007). */
export interface WaiverDocumentReference {
  readonly documentType: string;
  readonly documentId: string;
  readonly title: string;
  readonly version: string;
  readonly published: boolean;
}

export interface AdministeredWaiver {
  readonly id: string;
  readonly organizationId: string;
  readonly scopeType: string;
  readonly scopeId: string;
  readonly scopeLabel: string;
  readonly name: string;
  readonly description: string | null;
  /** Days a completion stays current, or null for one that never lapses (WAIVER-002). */
  readonly expiresAfterDays: number | null;
  /** Null for a waiver with no document reference (WAIVER-009). */
  readonly document: WaiverDocumentReference | null;
  readonly archived: boolean;
  readonly currentCompletionCount: number;
  readonly totalCompletionCount: number;
}

/** A scope the caller may administer waivers in (WAIVER-010). */
export interface WaiverScopeOption {
  readonly scopeType: string;
  readonly scopeId: string;
  readonly label: string;
}

/** A published document the create form may reference (WAIVER-007). */
export interface WaiverDocumentOption {
  readonly documentType: string;
  readonly documentId: string;
  readonly title: string;
  readonly version: string;
}

export interface WaiverAdministration {
  readonly organizationId: string;
  readonly organizationName: string | null;
  readonly scopes: readonly WaiverScopeOption[];
  readonly documents: readonly WaiverDocumentOption[];
  readonly waivers: readonly AdministeredWaiver[];
}

/** One person the waiver asks, and where they stand (WAIVER-003). */
export interface WaiverRosterRow {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly complete: boolean;
  /** Every completion has lapsed (WAIVER-002) — the state WAIVER-006 blocks on. */
  readonly lapsed: boolean;
  readonly completedAt: string | null;
  readonly expiresAt: string | null;
  /** The document version the completion acknowledged (WAIVER-008), or null. */
  readonly acknowledgedVersion: string | null;
}

export interface WaiverDetail {
  readonly waiver: AdministeredWaiver;
  /** The referenced text with fragments inline (WAIVER-007; POL-022), or null. */
  readonly renderedDocument: {
    readonly title: string;
    readonly version: string;
    readonly renderedHtml: string;
  } | null;
  readonly documentUnavailableReason: string | null;
  readonly roster: readonly WaiverRosterRow[];
}

interface WaiverPayload {
  readonly id: string;
  readonly organization_id?: string;
  readonly scope_type?: string;
  readonly scope_id?: string;
  readonly scope_label?: string;
  readonly name?: string;
  readonly description?: string | null;
  readonly expires_after_days?: number | null;
  readonly document?: {
    readonly document_type?: string;
    readonly document_id?: string;
    readonly title?: string;
    readonly version?: string;
    readonly published?: boolean;
  } | null;
  readonly archived?: boolean;
  readonly current_completion_count?: number;
  readonly total_completion_count?: number;
}

interface WaiverIndexPayload {
  readonly organization_id?: string;
  readonly organization_name?: string | null;
  readonly scopes?: readonly {
    readonly scope_type?: string;
    readonly scope_id?: string;
    readonly label?: string;
  }[];
  readonly documents?: readonly {
    readonly document_type?: string;
    readonly document_id?: string;
    readonly title?: string;
    readonly version?: string;
  }[];
  readonly waivers?: readonly WaiverPayload[];
}

interface WaiverDetailPayload {
  readonly waiver?: WaiverPayload;
  readonly rendered_document?: {
    readonly title?: string;
    readonly version?: string;
    readonly rendered_html?: string;
  } | null;
  readonly document_unavailable_reason?: string | null;
  readonly roster?: readonly {
    readonly staff_id: string;
    readonly display_name?: string;
    readonly handle?: string | null;
    readonly complete?: boolean;
    readonly lapsed?: boolean;
    readonly completed_at?: string | null;
    readonly expires_at?: string | null;
    readonly acknowledged_version?: string | null;
  }[];
}

function toWaiver(payload: WaiverPayload): AdministeredWaiver {
  const document = payload.document;

  return {
    id: payload.id,
    organizationId: payload.organization_id ?? "",
    scopeType: payload.scope_type ?? "organization",
    scopeId: payload.scope_id ?? "",
    scopeLabel: payload.scope_label ?? "",
    name: payload.name ?? "Untitled waiver",
    description: payload.description ?? null,
    expiresAfterDays: payload.expires_after_days ?? null,
    document:
      document === undefined || document === null
        ? null
        : {
            documentType: document.document_type ?? "policy",
            documentId: document.document_id ?? "",
            title: document.title ?? "Untitled document",
            version: document.version ?? "",
            published: document.published ?? false,
          },
    archived: payload.archived ?? false,
    currentCompletionCount: payload.current_completion_count ?? 0,
    totalCompletionCount: payload.total_completion_count ?? 0,
  };
}

export async function getWaiverAdministration(
  organizationId: string,
): Promise<WaiverAdministration> {
  const payload = (
    await meridianCachedJson<WaiverIndexPayload>(
      `/api/organizations/${encodeURIComponent(organizationId)}/waivers`,
    )
  ).data;

  return {
    organizationId: payload.organization_id ?? organizationId,
    organizationName: payload.organization_name ?? null,
    scopes: (payload.scopes ?? []).map((option) => ({
      scopeType: option.scope_type ?? "organization",
      scopeId: option.scope_id ?? "",
      label: option.label ?? "",
    })),
    documents: (payload.documents ?? []).map((option) => ({
      documentType: option.document_type ?? "policy",
      documentId: option.document_id ?? "",
      title: option.title ?? "Untitled document",
      version: option.version ?? "",
    })),
    waivers: (payload.waivers ?? []).map(toWaiver),
  };
}

export async function getWaiverDetail(
  organizationId: string,
  waiverId: string,
): Promise<WaiverDetail | null> {
  const payload = (
    await meridianCachedJson<WaiverDetailPayload>(
      `/api/organizations/${encodeURIComponent(organizationId)}/waivers/${encodeURIComponent(waiverId)}`,
    )
  ).data;

  if (payload.waiver === undefined) {
    return null;
  }

  const rendered = payload.rendered_document;

  return {
    waiver: toWaiver(payload.waiver),
    renderedDocument:
      rendered === undefined || rendered === null
        ? null
        : {
            title: rendered.title ?? "Untitled document",
            version: rendered.version ?? "",
            renderedHtml: rendered.rendered_html ?? "",
          },
    documentUnavailableReason: payload.document_unavailable_reason ?? null,
    roster: (payload.roster ?? []).map((row) => ({
      staffId: row.staff_id,
      displayName: row.display_name ?? "Unknown staff member",
      handle: row.handle ?? null,
      complete: row.complete ?? false,
      lapsed: row.lapsed ?? false,
      completedAt: row.completed_at ?? null,
      expiresAt: row.expires_at ?? null,
      acknowledgedVersion: row.acknowledged_version ?? null,
    })),
  };
}

export interface NewWaiverInput {
  readonly organizationId: string;
  readonly scopeType: string;
  readonly scopeId: string;
  readonly name: string;
  readonly description: string | null;
  readonly expiresAfterDays: number | null;
  readonly documentType: string | null;
  readonly documentId: string | null;
}

export async function createWaiver(
  input: NewWaiverInput,
): Promise<AdministeredWaiver | null> {
  const result = (await sendCommand("create-waiver", {
    organization_id: input.organizationId,
    scope_type: input.scopeType,
    scope_id: input.scopeId,
    name: input.name,
    description: input.description,
    expires_after_days: input.expiresAfterDays,
    document_type: input.documentType,
    document_id: input.documentId,
  })) as { readonly waiver?: WaiverPayload | null } | null;

  return result?.waiver == null ? null : toWaiver(result.waiver);
}

export interface WaiverUpdateInput {
  readonly waiverId: string;
  readonly name: string;
  readonly description: string | null;
  readonly expiresAfterDays: number | null;
  readonly documentType: string | null;
  readonly documentId: string | null;
}

export async function updateWaiver(
  input: WaiverUpdateInput,
): Promise<AdministeredWaiver | null> {
  const result = (await sendCommand("update-waiver", {
    waiver_id: input.waiverId,
    name: input.name,
    description: input.description,
    expires_after_days: input.expiresAfterDays,
    document_type: input.documentType,
    document_id: input.documentId,
  })) as { readonly waiver?: WaiverPayload | null } | null;

  return result?.waiver == null ? null : toWaiver(result.waiver);
}

export async function setWaiverArchived(
  waiverId: string,
  archived: boolean,
): Promise<AdministeredWaiver | null> {
  const result = (await sendCommand(
    archived ? "archive-waiver" : "restore-waiver",
    { waiver_id: waiverId },
  )) as { readonly waiver?: WaiverPayload | null } | null;

  return result?.waiver == null ? null : toWaiver(result.waiver);
}

export async function recordWaiverCompletion(
  waiverId: string,
  staffId: string,
): Promise<void> {
  await sendCommand("record-waiver-completion", {
    waiver_id: waiverId,
    staff_id: staffId,
  });
}

/** A completion moment or expiry, in the reader's own time zone. */
export function formatWaiverMoment(moment: string | null): string {
  if (moment === null) {
    return "—";
  }

  const parsed = new Date(moment);

  return Number.isNaN(parsed.getTime())
    ? moment
    : parsed.toLocaleString([], { dateStyle: "medium", timeStyle: "short" });
}

async function sendCommand(
  commandType: MeridianCommandType,
  payload: Readonly<Record<string, unknown>>,
): Promise<unknown> {
  return sendConnectedCommand({
    commandType,
    idempotencyKey: commandIdempotencyKey(commandType),
    payload,
  });
}

function commandIdempotencyKey(commandType: string): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${commandType}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
