// Author-visible submitted Field Report catalog (M9.4).
//
// FR-004 and technical spec 17.6 require that users see their own submitted
// Field Reports. UI implementation contract section 12.3 defines the author
// list/detail surfaces (`staff.field-reports.index` / `.show`), and section
// 14.1 requires that a submitted Field Report can be viewed by its author.
//
// The pending outbox (M9.2) only holds reports awaiting sync and drops them on
// acceptance. This catalog keeps every finalized submission the author may
// view on-device — pending or accepted — filtered by author. The offline read
// set carries the author's own reports (M18.46) and the HTTP
// `GET /api/events/{event}/field-reports` read path remains deferred; this is
// the local author-visibility seam those feed.

import {
  FIELD_REPORT_ACCEPTED,
  type OfflineFieldReport,
  type OfflineFieldReportAppend,
} from "@/field-reports/offlineFieldReport";

export class AuthorFieldReportCatalogError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "AuthorFieldReportCatalogError";
  }
}

export interface FieldReportAcceptanceUpdate {
  readonly fraNumber: string;
  readonly serverReceivedAt: string;
}

/**
 * Author-scoped, UUID-keyed library of finalized Field Reports. Insertion order
 * is preserved so the list surface shows newest-last / submission order.
 */
export class AuthorFieldReportCatalog {
  private readonly reports = new Map<string, OfflineFieldReport>();

  /**
   * Record a finalized submitted report for author viewing. Idempotent on
   * UUID: a duplicate record with the same id is ignored and returns `false`.
   */
  recordSubmitted(report: OfflineFieldReport): boolean {
    if (this.reports.has(report.id)) {
      return false;
    }

    this.reports.set(report.id, report);

    return true;
  }

  /** Resolve a report by UUID regardless of author (internal/runtime use). */
  get(id: string): OfflineFieldReport | undefined {
    return this.reports.get(id);
  }

  /**
   * Whether a report belongs in one person's own list (FR-004).
   *
   * Two people can own a dictated report and both are correct: the staff member
   * whose account it is, and the operator who submitted it for them. Matching
   * either identifier means a dictated report appears in the reporting staff
   * member's list, which is where they will look for it, without disappearing
   * from the list of the operator who took it down.
   *
   * `staffId` is only consulted when the caller supplies one. A caller that
   * knows only a user id keeps the original author-only behavior.
   */
  private belongsTo(
    report: OfflineFieldReport,
    authorUserId: string,
    staffId?: string,
  ): boolean {
    if (report.submittedByUserId === authorUserId) {
      return true;
    }

    return staffId !== undefined && report.staffId === staffId;
  }

  /**
   * Resolve a report only when it belongs to the given person (FR-004).
   * Non-owners receive `undefined` — the same fail-closed outcome as a miss.
   */
  getForAuthor(
    id: string,
    authorUserId: string,
    staffId?: string,
  ): OfflineFieldReport | undefined {
    const report = this.reports.get(id);

    if (!report || !this.belongsTo(report, authorUserId, staffId)) {
      return undefined;
    }

    return report;
  }

  /**
   * Own-reports list, optionally scoped to one event. Newest submissions
   * appear last (submission order).
   */
  listForAuthor(
    authorUserId: string,
    eventId?: string,
    staffId?: string,
  ): readonly OfflineFieldReport[] {
    return Array.from(this.reports.values()).filter((report) => {
      if (!this.belongsTo(report, authorUserId, staffId)) {
        return false;
      }

      if (eventId !== undefined && report.eventId !== eventId) {
        return false;
      }

      return true;
    });
  }

  /**
   * Apply server acceptance to a locally known report: replace the temporary
   * display identity with the FRA number and mark `accepted` (technical spec
   * 17.5). Idempotent when the report is already accepted with the same FRA.
   */
  recordAcceptance(
    id: string,
    acceptance: FieldReportAcceptanceUpdate,
  ): OfflineFieldReport {
    const existing = this.reports.get(id);

    if (!existing) {
      throw new AuthorFieldReportCatalogError(
        `Field Report ${id} is not in the author catalog.`,
      );
    }

    if (
      typeof acceptance.fraNumber !== "string" ||
      acceptance.fraNumber.trim().length === 0
    ) {
      throw new AuthorFieldReportCatalogError(
        "Field Report acceptance requires a FRA number.",
      );
    }

    if (
      typeof acceptance.serverReceivedAt !== "string" ||
      acceptance.serverReceivedAt.trim().length === 0
    ) {
      throw new AuthorFieldReportCatalogError(
        "Field Report acceptance requires a server received timestamp.",
      );
    }

    const accepted: OfflineFieldReport = Object.freeze({
      ...existing,
      fraNumber: acceptance.fraNumber,
      serverReceivedAt: acceptance.serverReceivedAt,
      syncStatus: FIELD_REPORT_ACCEPTED,
      appends: existing.appends,
    });

    this.reports.set(id, accepted);

    return accepted;
  }

  /**
   * Append a correction for the author without rewriting title/body (FR-007,
   * FR-009). Non-authors and unknown reports fail closed.
   */
  recordAppend(
    fieldReportId: string,
    authorUserId: string,
    append: OfflineFieldReportAppend,
  ): OfflineFieldReport {
    const existing = this.getForAuthor(fieldReportId, authorUserId);

    if (!existing) {
      throw new AuthorFieldReportCatalogError(
        "Only the original Field Report author may append to the report.",
      );
    }

    if (existing.appends.some((entry) => entry.id === append.id)) {
      return existing;
    }

    const updated: OfflineFieldReport = Object.freeze({
      ...existing,
      appends: Object.freeze([...existing.appends, Object.freeze({ ...append })]),
    });

    this.reports.set(fieldReportId, updated);

    return updated;
  }

  /** Number of author-visible reports currently held. */
  get size(): number {
    return this.reports.size;
  }

  /** Full catalog snapshot in submission order (for local persistence). */
  snapshot(): readonly OfflineFieldReport[] {
    return Array.from(this.reports.values());
  }

  /**
   * Replace catalog contents from a persisted snapshot. Used on device boot
   * before authenticated durable storage lands.
   */
  replaceAll(reports: readonly OfflineFieldReport[]): void {
    this.reports.clear();

    for (const report of reports) {
      this.reports.set(
        report.id,
        Object.freeze({
          ...report,
          appends: Object.freeze(
            (report.appends ?? []).map((append) => Object.freeze({ ...append })),
          ),
        }),
      );
    }
  }

  /** Test/reset helper: clear all recorded reports. */
  clear(): void {
    this.reports.clear();
  }
}
