// Local submitted-state outbox for offline Field Reports (M9.2).
//
// Technical spec section 17.2 requires offline-created Field Reports to look
// submitted immediately even while pending sync, and section 17.2 also requires
// failed sync to remain recoverable. Data/API section 7.2 lists Field Report
// creation as an Alpha 1 offline write, and section 5.3 requires commands to be
// idempotent on repeated operation UUID (section 7.4 restates that for node
// operations). This queue is the in-memory model of the device's
// submitted-but-not-yet-synced Field Reports: it holds each finalized local
// record, dedupes by the device-generated UUID so a re-submitted create is
// idempotent, and exposes the pending set for the sync step (M9.3) to drain.
//
// This module intentionally does not persist to disk, encrypt, sign, or perform
// the sync itself. Durable encrypted local storage, PowerSync integration, and
// signed sync operations are owned by later Alpha 1 tasks; this queue is the
// device-side domain seam those tasks build on.

import {
  FIELD_REPORT_PENDING_SYNC,
  type OfflineFieldReport,
} from "@/field-reports/offlineFieldReport";

export class PendingFieldReportQueueError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "PendingFieldReportQueueError";
  }
}

/**
 * Ordered, UUID-keyed outbox of offline Field Reports awaiting sync. Insertion
 * order is preserved so reports sync in submission order.
 */
export class PendingFieldReportQueue {
  private readonly reports = new Map<string, OfflineFieldReport>();

  /**
   * Add a finalized, pending-sync report to the outbox. Idempotent: enqueuing a
   * report whose UUID is already queued is a no-op and returns `false`, so a
   * retried offline submit never duplicates the record (data/API 5.3). Returns
   * `true` when the report was newly added.
   */
  enqueue(report: OfflineFieldReport): boolean {
    if (report.syncStatus !== FIELD_REPORT_PENDING_SYNC) {
      throw new PendingFieldReportQueueError(
        "Only pending-sync Field Reports can be queued for sync.",
      );
    }

    if (this.reports.has(report.id)) {
      return false;
    }

    this.reports.set(report.id, report);

    return true;
  }

  /** Whether a report with this UUID is currently queued. */
  has(id: string): boolean {
    return this.reports.has(id);
  }

  /** Resolve a queued report by UUID, or `undefined` when not queued. */
  get(id: string): OfflineFieldReport | undefined {
    return this.reports.get(id);
  }

  /** Snapshot of queued reports in submission order. */
  pending(): readonly OfflineFieldReport[] {
    return Array.from(this.reports.values());
  }

  /** Number of reports still waiting to sync. */
  get size(): number {
    return this.reports.size;
  }

  /**
   * Remove a report from the outbox once the server has accepted it (M9.3).
   * Idempotent: returns `true` when a queued report was removed, `false` when
   * the UUID was not queued (already drained), so repeated sync callbacks are
   * safe.
   */
  markSynced(id: string): boolean {
    return this.reports.delete(id);
  }

  /** Test/reset helper: drain the entire outbox. */
  clear(): void {
    this.reports.clear();
  }
}
