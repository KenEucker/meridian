import { describe, expect, it } from "vitest";

import {
  createOfflineFieldReport,
  type CreateOfflineFieldReportInput,
  type OfflineFieldReport,
} from "@/field-reports/offlineFieldReport";
import {
  PendingFieldReportQueue,
  PendingFieldReportQueueError,
} from "@/field-reports/pendingFieldReportQueue";

const BASE_INPUT: CreateOfflineFieldReportInput = {
  eventId: "event-1",
  submittedByUserId: "user-1",
  staffId: "staff-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
  title: "Medical assist near Gate A",
  body: "Observed a medical assist near Gate A.",
};

function reportWithId(id: string, body = BASE_INPUT.body): OfflineFieldReport {
  return createOfflineFieldReport(
    { ...BASE_INPUT, body },
    { generateId: () => id, now: () => new Date("2027-06-01T12:00:00.000Z") },
  );
}

describe("PendingFieldReportQueue", () => {
  it("holds a submitted report in local pending state", () => {
    const queue = new PendingFieldReportQueue();
    const report = reportWithId("11111111-1111-1111-1111-111111111111");

    expect(queue.enqueue(report)).toBe(true);
    expect(queue.size).toBe(1);
    expect(queue.has(report.id)).toBe(true);
    expect(queue.get(report.id)).toBe(report);
    expect(queue.pending()).toEqual([report]);
  });

  it("is idempotent: re-enqueuing the same UUID does not duplicate the report", () => {
    const queue = new PendingFieldReportQueue();
    const report = reportWithId("11111111-1111-1111-1111-111111111111");

    expect(queue.enqueue(report)).toBe(true);
    expect(queue.enqueue(report)).toBe(false);
    expect(queue.enqueue({ ...report, body: "resubmitted copy" })).toBe(false);

    expect(queue.size).toBe(1);
    expect(queue.get(report.id)?.body).toBe(BASE_INPUT.body);
  });

  it("preserves submission order across multiple reports", () => {
    const queue = new PendingFieldReportQueue();
    const first = reportWithId("11111111-1111-1111-1111-111111111111", "first");
    const second = reportWithId(
      "22222222-2222-2222-2222-222222222222",
      "second",
    );

    queue.enqueue(first);
    queue.enqueue(second);

    expect(queue.pending().map((report) => report.id)).toEqual([
      first.id,
      second.id,
    ]);
  });

  it("rejects reports that are not pending sync", () => {
    const queue = new PendingFieldReportQueue();
    const accepted: OfflineFieldReport = {
      ...reportWithId("11111111-1111-1111-1111-111111111111"),
      syncStatus: "accepted",
    };

    expect(() => queue.enqueue(accepted)).toThrow(PendingFieldReportQueueError);
    expect(queue.size).toBe(0);
  });

  it("drains a report once synced and is idempotent on repeated callbacks", () => {
    const queue = new PendingFieldReportQueue();
    const report = reportWithId("11111111-1111-1111-1111-111111111111");
    queue.enqueue(report);

    expect(queue.markSynced(report.id)).toBe(true);
    expect(queue.markSynced(report.id)).toBe(false);
    expect(queue.has(report.id)).toBe(false);
    expect(queue.size).toBe(0);
    expect(queue.pending()).toEqual([]);
  });

  it("does not block unrelated pending reports when one syncs", () => {
    const queue = new PendingFieldReportQueue();
    const first = reportWithId("11111111-1111-1111-1111-111111111111");
    const second = reportWithId("22222222-2222-2222-2222-222222222222");
    queue.enqueue(first);
    queue.enqueue(second);

    queue.markSynced(first.id);

    expect(queue.pending().map((report) => report.id)).toEqual([second.id]);
  });
});
