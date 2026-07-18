// Offline Field Report create operation (M9.2).
//
// Technical spec section 17.2 (Offline behavior) requires that Field Reports
// can be created offline, that there are no drafts, that a report is finalized
// once submitted, and that offline-created reports look submitted immediately
// even while still pending sync. Technical spec section 17.5 requires an
// offline report to show a clearly temporary local number until the server
// assigns the FRA number. Data/API section 7.2 lists Field Report creation as
// an Alpha 1 offline write, and section 10.15 defines the `field_reports`
// record shape this local record mirrors.
//
// This module owns the pure, device-side create step only: it generates the
// device UUID, records the device submission timestamp, assigns a temporary
// local number, and produces a finalized/immutable local record in the
// `pending_sync` state. Server acceptance and FRA numbering are M9.3; author
// list/create/detail surfaces are M9.4; photos are M9.7/M9.8; server-side Name
// Reference parsing is M9.6A. Persisting the record to the encrypted local store and
// PowerSync, and signing the sync operation, are owned by later Alpha 1 tasks.
// Injecting the id generator and clock keeps this deterministic in tests.

/**
 * Record-level sync status, mirroring `field_reports.sync_status` in data/API
 * section 10.15. A device only ever *creates* a report in `pending_sync`;
 * `accepted` is written by the server on acceptance (M9.3) and is included here
 * so the local record type matches the values the server can return.
 */
export const FIELD_REPORT_PENDING_SYNC = "pending_sync" as const;
export const FIELD_REPORT_ACCEPTED = "accepted" as const;

export type FieldReportSyncStatus =
  | typeof FIELD_REPORT_PENDING_SYNC
  | typeof FIELD_REPORT_ACCEPTED;

/**
 * Local Field Report record, mirroring the `field_reports` columns from
 * data/API section 10.15 that exist at device-create time. `fraNumber` and
 * `serverReceivedAt` stay `null` until the server accepts the report (M9.3).
 */
export interface OfflineFieldReport {
  readonly id: string;
  readonly eventId: string;
  readonly departmentId: string | null;
  readonly teamId: string | null;
  readonly submittedByUserId: string;
  readonly staffId: string;
  readonly fraNumber: string | null;
  readonly temporaryLocalNumber: string;
  readonly body: string;
  readonly deviceSubmittedAt: string;
  readonly serverReceivedAt: string | null;
  readonly originDeviceId: string;
  readonly originNodeId: string;
  readonly syncStatus: FieldReportSyncStatus;
  readonly createdAt: string;
}

/**
 * Caller-supplied fields for an offline Field Report submission. Department and
 * team context are optional ("if available", technical spec 17.3). The device,
 * node, author, staff, and event identifiers come from the authenticated field
 * session.
 */
export interface CreateOfflineFieldReportInput {
  readonly eventId: string;
  readonly submittedByUserId: string;
  readonly staffId: string;
  readonly originDeviceId: string;
  readonly originNodeId: string;
  readonly body: string;
  readonly departmentId?: string | null;
  readonly teamId?: string | null;
}

/**
 * Injectable device primitives. Defaults use the real platform: `crypto`
 * generates the UUID (the offline idempotency key, technical spec 17.2/17.5)
 * and the system clock records the device submission time.
 */
export interface OfflineFieldReportDependencies {
  readonly generateId?: () => string;
  readonly now?: () => Date;
}

/** Error thrown when required create input is missing or invalid. */
export class OfflineFieldReportError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "OfflineFieldReportError";
  }
}

const REQUIRED_ID_FIELDS: readonly (keyof CreateOfflineFieldReportInput)[] = [
  "eventId",
  "submittedByUserId",
  "staffId",
  "originDeviceId",
  "originNodeId",
];

function defaultGenerateId(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  if (typeof cryptoScope?.randomUUID !== "function") {
    throw new OfflineFieldReportError(
      "A UUID generator is unavailable; inject `generateId` to create a Field Report offline.",
    );
  }

  return cryptoScope.randomUUID();
}

function requireNonEmpty(
  value: string | null | undefined,
  field: string,
): string {
  if (typeof value !== "string" || value.trim().length === 0) {
    throw new OfflineFieldReportError(`Field Report ${field} is required.`);
  }

  return value;
}

/**
 * Build the clearly-temporary local number shown until the server assigns the
 * FRA number (technical spec 17.5). The `LOCAL-` prefix keeps it visually
 * distinct from a real `FRA-YYYY-NNNNNN` number so the swap after sync is
 * obvious, and deriving it from the report UUID keeps it stable and unique.
 */
export function buildTemporaryLocalNumber(id: string): string {
  const compact = id.replace(/-/g, "").toUpperCase();
  const suffix = compact.slice(0, 8) || compact;

  return `LOCAL-${suffix}`;
}

/**
 * Create a finalized, immutable offline Field Report. The returned record has a
 * device-generated UUID, a device submission timestamp, a temporary local
 * number, and the `pending_sync` status. It is frozen because a submitted Field
 * Report is finalized and never edited (technical spec 17.2/17.4, FR-007).
 */
export function createOfflineFieldReport(
  input: CreateOfflineFieldReportInput,
  dependencies: OfflineFieldReportDependencies = {},
): OfflineFieldReport {
  for (const field of REQUIRED_ID_FIELDS) {
    requireNonEmpty(input[field] as string | null | undefined, field);
  }

  if (input.body.trim().length === 0) {
    throw new OfflineFieldReportError("Field Report body text is required.");
  }

  const generateId = dependencies.generateId ?? defaultGenerateId;
  const now = dependencies.now ?? (() => new Date());

  const id = requireNonEmpty(generateId(), "id");
  const submittedAt = now().toISOString();

  return Object.freeze({
    id,
    eventId: input.eventId,
    departmentId: input.departmentId ?? null,
    teamId: input.teamId ?? null,
    submittedByUserId: input.submittedByUserId,
    staffId: input.staffId,
    fraNumber: null,
    temporaryLocalNumber: buildTemporaryLocalNumber(id),
    body: input.body,
    deviceSubmittedAt: submittedAt,
    serverReceivedAt: null,
    originDeviceId: input.originDeviceId,
    originNodeId: input.originNodeId,
    syncStatus: FIELD_REPORT_PENDING_SYNC,
    createdAt: submittedAt,
  });
}

/** Whether a local record is still waiting to sync. */
export function isPendingSync(report: OfflineFieldReport): boolean {
  return report.syncStatus === FIELD_REPORT_PENDING_SYNC;
}

/**
 * View-model for the "looks submitted immediately" presentation (technical spec
 * 17.2) and the "temporary local number until synced" rule (technical spec
 * 17.5). A Field Report is always presented as submitted because there are no
 * drafts; the FRA number replaces the temporary local number once assigned.
 */
export interface FieldReportSubmissionView {
  readonly submitted: true;
  readonly pendingSync: boolean;
  readonly displayNumber: string;
  readonly displayNumberIsTemporary: boolean;
}

export function fieldReportSubmissionView(
  report: OfflineFieldReport,
): FieldReportSubmissionView {
  const hasFraNumber =
    typeof report.fraNumber === "string" && report.fraNumber.length > 0;

  return {
    submitted: true,
    pendingSync: isPendingSync(report),
    displayNumber: hasFraNumber
      ? (report.fraNumber as string)
      : report.temporaryLocalNumber,
    displayNumberIsTemporary: !hasFraNumber,
  };
}
