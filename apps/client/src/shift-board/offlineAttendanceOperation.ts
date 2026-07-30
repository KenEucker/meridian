// Offline attendance operations for the Shift Lead Board (M10.5).
//
// Technical spec 20.1 and data/API 7.2 allow check-in, check-out, and no-show
// to be created offline. This module owns the device-side operation envelope:
// a UUID idempotency key, device timestamp, actor/staff/device/node identity,
// shift context, and optional check-out actual times. Server acceptance,
// derived attendance state, hours creation, audit history, signatures, and
// conflict handling remain server/sync responsibilities.

export const ATTENDANCE_PENDING_SYNC = "pending_sync" as const;
export const ATTENDANCE_ACCEPTED = "accepted" as const;

export const ATTENDANCE_OPERATION_TYPES = [
  "check_in",
  "check_out",
  "mark_no_show",
] as const;

export type AttendanceOperationType =
  (typeof ATTENDANCE_OPERATION_TYPES)[number];

export type AttendanceOperationSyncStatus =
  | typeof ATTENDANCE_PENDING_SYNC
  | typeof ATTENDANCE_ACCEPTED;

export interface OfflineAttendanceOperation {
  readonly operationUuid: string;
  readonly operationType: AttendanceOperationType;
  readonly eventId: string;
  readonly departmentId: string;
  readonly teamId: string | null;
  readonly shiftId: string;
  readonly shiftAssignmentId: string | null;
  readonly staffId: string;
  readonly createdByUserId: string;
  readonly originDeviceId: string;
  readonly originNodeId: string;
  readonly deviceCreatedAt: string;
  readonly actualStartedAt: string | null;
  readonly actualEndedAt: string | null;
  readonly serverReceivedAt: string | null;
  readonly syncStatus: AttendanceOperationSyncStatus;
  readonly createdAt: string;
}

export interface CreateOfflineAttendanceOperationInput {
  readonly operationType: AttendanceOperationType;
  readonly eventId: string;
  readonly departmentId: string;
  readonly shiftId: string;
  readonly staffId: string;
  readonly createdByUserId: string;
  readonly originDeviceId: string;
  readonly originNodeId: string;
  readonly teamId?: string | null;
  readonly shiftAssignmentId?: string | null;
  readonly actualStartedAt?: string | null;
  readonly actualEndedAt?: string | null;
}

export interface OfflineAttendanceOperationDependencies {
  readonly generateId?: () => string;
  readonly now?: () => Date;
}

export class OfflineAttendanceOperationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "OfflineAttendanceOperationError";
  }
}

const REQUIRED_ID_FIELDS: readonly (keyof CreateOfflineAttendanceOperationInput)[] =
  [
    "eventId",
    "departmentId",
    "shiftId",
    "staffId",
    "createdByUserId",
    "originDeviceId",
    "originNodeId",
  ];

function defaultGenerateId(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  if (typeof cryptoScope?.randomUUID !== "function") {
    throw new OfflineAttendanceOperationError(
      "A UUID generator is unavailable; inject `generateId` to create attendance offline.",
    );
  }

  return cryptoScope.randomUUID();
}

function requireNonEmpty(
  value: string | null | undefined,
  field: string,
): string {
  if (typeof value !== "string" || value.trim().length === 0) {
    throw new OfflineAttendanceOperationError(`Attendance ${field} is required.`);
  }

  return value;
}

function normalizeOptionalId(value: string | null | undefined): string | null {
  if (value === null || value === undefined) {
    return null;
  }

  if (value.trim().length === 0) {
    return null;
  }

  return value;
}

function requireIsoTimestamp(value: string, field: string): string {
  const parsed = Date.parse(value);

  if (Number.isNaN(parsed)) {
    throw new OfflineAttendanceOperationError(
      `Attendance ${field} must be a valid timestamp.`,
    );
  }

  return value;
}

function isAttendanceOperationType(
  value: string,
): value is AttendanceOperationType {
  return ATTENDANCE_OPERATION_TYPES.includes(value as AttendanceOperationType);
}

export function createOfflineAttendanceOperation(
  input: CreateOfflineAttendanceOperationInput,
  dependencies: OfflineAttendanceOperationDependencies = {},
): OfflineAttendanceOperation {
  if (!isAttendanceOperationType(input.operationType)) {
    throw new OfflineAttendanceOperationError(
      "Attendance operation type must be check_in, check_out, or mark_no_show.",
    );
  }

  for (const field of REQUIRED_ID_FIELDS) {
    requireNonEmpty(input[field] as string | null | undefined, field);
  }

  const generateId = dependencies.generateId ?? defaultGenerateId;
  const now = dependencies.now ?? (() => new Date());
  const operationUuid = requireNonEmpty(generateId(), "operationUuid");
  const deviceCreatedAt = now().toISOString();

  let actualStartedAt: string | null = null;
  let actualEndedAt: string | null = null;

  if (input.operationType === "check_out") {
    actualStartedAt = input.actualStartedAt
      ? requireIsoTimestamp(input.actualStartedAt, "actualStartedAt")
      : null;
    actualEndedAt = input.actualEndedAt
      ? requireIsoTimestamp(input.actualEndedAt, "actualEndedAt")
      : deviceCreatedAt;

    if (
      actualStartedAt !== null &&
      Date.parse(actualEndedAt) <= Date.parse(actualStartedAt)
    ) {
      throw new OfflineAttendanceOperationError(
        "Attendance actual end time must be after actual start time.",
      );
    }
  } else if (input.actualStartedAt || input.actualEndedAt) {
    throw new OfflineAttendanceOperationError(
      "Attendance actual times are only supported for check-out.",
    );
  }

  return Object.freeze({
    operationUuid,
    operationType: input.operationType,
    eventId: input.eventId,
    departmentId: input.departmentId,
    teamId: normalizeOptionalId(input.teamId),
    shiftId: input.shiftId,
    shiftAssignmentId: normalizeOptionalId(input.shiftAssignmentId),
    staffId: input.staffId,
    createdByUserId: input.createdByUserId,
    originDeviceId: input.originDeviceId,
    originNodeId: input.originNodeId,
    deviceCreatedAt,
    actualStartedAt,
    actualEndedAt,
    serverReceivedAt: null,
    syncStatus: ATTENDANCE_PENDING_SYNC,
    createdAt: deviceCreatedAt,
  });
}
