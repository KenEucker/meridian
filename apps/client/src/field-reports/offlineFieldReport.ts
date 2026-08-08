// Offline Field Report create operation (M9.2 / M9.7A).
//
// Technical spec section 17.2 (Offline behavior) requires that Field Reports
// can be created offline, that there are no drafts, that a report is finalized
// once submitted, and that offline-created reports look submitted immediately
// even while still pending sync. Technical spec section 17.5 requires an
// offline report to show a clearly temporary local number until the server
// assigns the FRA number. Data/API section 7.2 lists Field Report creation as
// an Alpha 1 offline write, and section 10.15 defines the `field_reports`
// record shape this local record mirrors, including required immutable title.
//
// This module owns the pure, device-side create step only: it generates the
// device UUID, records the device submission timestamp, assigns a temporary
// local number, normalizes the required title, and produces a finalized/
// immutable local record in the `pending_sync` state. Server acceptance and
// FRA numbering are M9.3; author list/create/detail surfaces are M9.4; photo
// capture limits are M9.7; photo sync/storage is M9.8; server-side Name
// Reference parsing is M9.6A. Persisting the record to the encrypted local
// store, and signing the sync operation, are owned by later Alpha 1 tasks; the
// report reaches the node through the command outbox (technical spec 11A.5).
// Injecting the id generator and clock keeps this deterministic in tests.

/**
 * Record-level sync status, mirroring `field_reports.sync_status` in data/API
 * section 10.15. A device only ever *creates* a report in `pending_sync`;
 * `accepted` is written by the server on acceptance (M9.3) and is included here
 * so the local record type matches the values the server can return.
 */
export const FIELD_REPORT_PENDING_SYNC = "pending_sync" as const;
export const FIELD_REPORT_ACCEPTED = "accepted" as const;

/** Max title length after trimming (FR-003; technical spec 17.3; data/API 10.15). */
export const FIELD_REPORT_TITLE_MAX_LENGTH = 200;

export type FieldReportSyncStatus =
  | typeof FIELD_REPORT_PENDING_SYNC
  | typeof FIELD_REPORT_ACCEPTED;

/**
 * Local append-only correction on a submitted Field Report (FR-007-FR-009;
 * data/API 10.15 `field_report_appends`). Appends have no title and never
 * rewrite the original body. Server command transport remains a later task;
 * local author detail persists appends on-device with the author catalog.
 */
export interface OfflineFieldReportAppend {
  readonly id: string;
  readonly body: string;
  readonly deviceSubmittedAt: string;
  readonly syncStatus: FieldReportSyncStatus;
}

/**
 * Who typed a Field Report someone else reported.
 *
 * An operator taking a report by dictation is one person recording another
 * person's account, so the two identifiers separate rather than one standing in
 * for the other: `staffId` is the staff member whose report it is, and
 * `submittedByUserId` stays the operator who actually submitted it. Neither is
 * rewritten to look like the other, because both facts are true and an export or
 * an audit needs to be able to tell them apart (data/API 10.15).
 */
export interface FieldReportDictation {
  /** Staff id of the operator who typed the report. */
  readonly recordedByStaffId: string;
  /** Operator's display name, as shown in the prepended attribution line. */
  readonly recordedByDisplayName: string;
  /** Reporting staff member's display name, for the same line. */
  readonly reportedByDisplayName: string;
}

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
  readonly title: string;
  readonly body: string;
  readonly deviceSubmittedAt: string;
  readonly serverReceivedAt: string | null;
  readonly originDeviceId: string;
  /**
   * The node this report originated at, when the device knows one. Null for a
   * browser, which cannot learn a node id; the node that accepts the command
   * records itself as the origin instead (M16.21, M16.22).
   */
  readonly originNodeId: string | null;
  readonly syncStatus: FieldReportSyncStatus;
  readonly createdAt: string;
  readonly appends: readonly OfflineFieldReportAppend[];
  /** Set when an operator typed this report for the reporting staff member. */
  readonly dictation: FieldReportDictation | null;
}

/**
 * Caller-supplied fields for an offline Field Report submission. Department and
 * team context are optional ("if available", technical spec 17.3). The device,
 * node, author, staff, and event identifiers come from the authenticated field
 * session. Title is required plain text (trimmed, 1-200 characters).
 */
export interface CreateOfflineFieldReportInput {
  readonly eventId: string;
  readonly submittedByUserId: string;
  readonly staffId: string;
  readonly originDeviceId: string;
  /**
   * The node this report originated at, when the device knows one. Null for a
   * browser, which cannot learn a node id; the node that accepts the command
   * records itself as the origin instead (M16.21, M16.22).
   */
  readonly originNodeId?: string | null;
  readonly title: string;
  readonly body: string;
  readonly departmentId?: string | null;
  readonly teamId?: string | null;
  /**
   * Present when an operator is typing this report for someone else. The author
   * identifiers above are then the reporting staff member's, not the operator's.
   */
  readonly dictation?: FieldReportDictation | null;
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
 * Normalize a Field Report title: trim outer whitespace, require 1-200 chars
 * (FR-003; technical spec 17.3; data/API 10.15; UI contract 14.2).
 */
export function normalizeFieldReportTitle(value: string): string {
  if (typeof value !== "string") {
    throw new OfflineFieldReportError("Field Report title is required.");
  }

  const title = value.trim();

  if (title.length === 0) {
    throw new OfflineFieldReportError("Field Report title is required.");
  }

  if (title.length > FIELD_REPORT_TITLE_MAX_LENGTH) {
    throw new OfflineFieldReportError(
      `Field Report title must be at most ${FIELD_REPORT_TITLE_MAX_LENGTH} characters.`,
    );
  }

  return title;
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
 * The attribution line prepended to a dictated Field Report body.
 *
 * It goes into the body rather than beside it because the body is the immutable
 * record (technical spec 17.4, FR-007). Anyone who later reads the report —
 * on another surface, in an export, or attached to an incident — reads that an
 * operator wrote down someone else's account, without that surface having to
 * know about dictation at all.
 */
export function fieldReportDictationLine(dictation: FieldReportDictation): string {
  return `Field Report filled out by ${dictation.recordedByDisplayName} on behalf of ${dictation.reportedByDisplayName}`;
}

function normalizeDictation(
  dictation: FieldReportDictation | null | undefined,
): FieldReportDictation | null {
  if (!dictation) {
    return null;
  }

  const recordedByStaffId = requireNonEmpty(
    dictation.recordedByStaffId,
    "recordedByStaffId",
  );
  const recordedByDisplayName = requireNonEmpty(
    dictation.recordedByDisplayName,
    "recordedByDisplayName",
  ).trim();
  const reportedByDisplayName = requireNonEmpty(
    dictation.reportedByDisplayName,
    "reportedByDisplayName",
  ).trim();

  return Object.freeze({
    recordedByStaffId,
    recordedByDisplayName,
    reportedByDisplayName,
  });
}

/**
 * Create a finalized, immutable offline Field Report. The returned record has a
 * device-generated UUID, a device submission timestamp, a temporary local
 * number, a normalized title, and the `pending_sync` status. It is frozen
 * because a submitted Field Report is finalized and never edited (technical
 * spec 17.2/17.4, FR-007).
 */
export function createOfflineFieldReport(
  input: CreateOfflineFieldReportInput,
  dependencies: OfflineFieldReportDependencies = {},
): OfflineFieldReport {
  for (const field of REQUIRED_ID_FIELDS) {
    requireNonEmpty(input[field] as string | null | undefined, field);
  }

  const title = normalizeFieldReportTitle(input.title);

  if (input.body.trim().length === 0) {
    throw new OfflineFieldReportError("Field Report body text is required.");
  }

  const dictation = normalizeDictation(input.dictation);
  const generateId = dependencies.generateId ?? defaultGenerateId;
  const now = dependencies.now ?? (() => new Date());

  const id = requireNonEmpty(generateId(), "id");
  const submittedAt = now().toISOString();

  // Prepended before the record is frozen, so the attribution is part of the
  // immutable body rather than something a later surface has to remember to add.
  const body = dictation
    ? `${fieldReportDictationLine(dictation)}\n\n${input.body}`
    : input.body;

  return Object.freeze({
    id,
    eventId: input.eventId,
    departmentId: input.departmentId ?? null,
    teamId: input.teamId ?? null,
    submittedByUserId: input.submittedByUserId,
    staffId: input.staffId,
    fraNumber: null,
    temporaryLocalNumber: buildTemporaryLocalNumber(id),
    title,
    body,
    deviceSubmittedAt: submittedAt,
    serverReceivedAt: null,
    originDeviceId: input.originDeviceId,
    originNodeId: input.originNodeId ?? null,
    syncStatus: FIELD_REPORT_PENDING_SYNC,
    createdAt: submittedAt,
    appends: Object.freeze([] as OfflineFieldReportAppend[]),
    dictation,
  });
}

/**
 * Build an immutable local append entry. Body is required after trim; title is
 * never accepted (FR-007 / data/API 10.15).
 */
export function createOfflineFieldReportAppend(
  body: string,
  dependencies: OfflineFieldReportDependencies = {},
): OfflineFieldReportAppend {
  if (typeof body !== "string" || body.trim().length === 0) {
    throw new OfflineFieldReportError(
      "Field Report append body text is required.",
    );
  }

  const generateId = dependencies.generateId ?? defaultGenerateId;
  const now = dependencies.now ?? (() => new Date());
  const id = requireNonEmpty(generateId(), "id");

  return Object.freeze({
    id,
    body: body.trim(),
    deviceSubmittedAt: now().toISOString(),
    syncStatus: FIELD_REPORT_PENDING_SYNC,
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
