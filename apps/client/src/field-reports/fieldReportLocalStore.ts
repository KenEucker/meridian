// Browser-local persistence for author Field Report surfaces (M9.4).
//
// Durable encrypted local storage is still a later Alpha 1 task. Until that
// lands, author list/detail must survive a page refresh for human QA and field
// shell exercise. This module stores the author catalog (and pending outbox
// membership) in `localStorage` as a temporary device-local seam — not the
// long-term encrypted store and not a sync transport.

import {
  FIELD_REPORT_ACCEPTED,
  FIELD_REPORT_PENDING_SYNC,
  type OfflineFieldReport,
  type OfflineFieldReportAppend,
} from "@/field-reports/offlineFieldReport";

export const FIELD_REPORT_LOCAL_STORE_KEY =
  "meridian.field-reports.author-catalog.v1";

interface StoredFieldReportState {
  readonly version: 1;
  readonly reports: OfflineFieldReport[];
}

export interface FieldReportLocalStore {
  readonly load: () => OfflineFieldReport[];
  readonly save: (reports: readonly OfflineFieldReport[]) => void;
  readonly clear: () => void;
}

function isSyncStatus(value: unknown): value is OfflineFieldReport["syncStatus"] {
  return value === FIELD_REPORT_PENDING_SYNC || value === FIELD_REPORT_ACCEPTED;
}

function isOfflineFieldReportAppend(
  value: unknown,
): value is OfflineFieldReportAppend {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const append = value as Record<string, unknown>;

  return (
    typeof append.id === "string" &&
    typeof append.body === "string" &&
    typeof append.deviceSubmittedAt === "string" &&
    isSyncStatus(append.syncStatus)
  );
}

function normalizeAppends(
  value: unknown,
): readonly OfflineFieldReportAppend[] | null {
  if (value === undefined) {
    return [];
  }

  if (!Array.isArray(value)) {
    return null;
  }

  if (!value.every(isOfflineFieldReportAppend)) {
    return null;
  }

  return value.map((append) =>
    Object.freeze({
      id: append.id,
      body: append.body,
      deviceSubmittedAt: append.deviceSubmittedAt,
      syncStatus: append.syncStatus,
    }),
  );
}

function isOfflineFieldReport(value: unknown): value is OfflineFieldReport {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const report = value as Record<string, unknown>;
  const appends = normalizeAppends(report.appends);

  if (appends === null) {
    return false;
  }

  return (
    typeof report.id === "string" &&
    typeof report.eventId === "string" &&
    typeof report.submittedByUserId === "string" &&
    typeof report.staffId === "string" &&
    typeof report.temporaryLocalNumber === "string" &&
    typeof report.title === "string" &&
    typeof report.body === "string" &&
    typeof report.deviceSubmittedAt === "string" &&
    typeof report.originDeviceId === "string" &&
    (report.originNodeId === null || typeof report.originNodeId === "string") &&
    typeof report.createdAt === "string" &&
    isSyncStatus(report.syncStatus) &&
    (report.fraNumber === null || typeof report.fraNumber === "string") &&
    (report.serverReceivedAt === null ||
      typeof report.serverReceivedAt === "string") &&
    (report.departmentId === null || typeof report.departmentId === "string") &&
    (report.teamId === null || typeof report.teamId === "string")
  );
}

function coerceOfflineFieldReport(value: unknown): OfflineFieldReport | null {
  if (!isOfflineFieldReport(value)) {
    return null;
  }

  const report = value as OfflineFieldReport & {
    readonly appends?: readonly OfflineFieldReportAppend[];
  };
  const appends = normalizeAppends(report.appends) ?? [];

  return Object.freeze({
    ...report,
    appends: Object.freeze([...appends]),
  });
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

/** Create a localStorage-backed store, or an in-memory fallback when unavailable. */
export function createFieldReportLocalStore(
  storage: Storage | null = readStorage(),
): FieldReportLocalStore {
  let memoryFallback: OfflineFieldReport[] = [];

  return {
    load(): OfflineFieldReport[] {
      if (!storage) {
        return memoryFallback
          .map(coerceOfflineFieldReport)
          .filter((report): report is OfflineFieldReport => report !== null);
      }

      try {
        const raw = storage.getItem(FIELD_REPORT_LOCAL_STORE_KEY);
        if (!raw) {
          return [];
        }

        const parsed = JSON.parse(raw) as Partial<StoredFieldReportState>;
        if (parsed.version !== 1 || !Array.isArray(parsed.reports)) {
          return [];
        }

        return parsed.reports
          .map(coerceOfflineFieldReport)
          .filter((report): report is OfflineFieldReport => report !== null);
      } catch {
        return [];
      }
    },

    save(reports: readonly OfflineFieldReport[]): void {
      const payload: StoredFieldReportState = {
        version: 1,
        reports: reports.map((report) => ({ ...report })),
      };

      if (!storage) {
        memoryFallback = payload.reports.map((report) =>
          Object.freeze({ ...report }),
        );
        return;
      }

      try {
        storage.setItem(FIELD_REPORT_LOCAL_STORE_KEY, JSON.stringify(payload));
      } catch {
        // Quota or privacy mode: keep working in memory for this session.
        memoryFallback = payload.reports.map((report) =>
          Object.freeze({ ...report }),
        );
      }
    },

    clear(): void {
      memoryFallback = [];

      if (!storage) {
        return;
      }

      try {
        storage.removeItem(FIELD_REPORT_LOCAL_STORE_KEY);
      } catch {
        // Ignore storage clear failures; in-memory state is already empty.
      }
    },
  };
}

export const fieldReportLocalStore = createFieldReportLocalStore();
