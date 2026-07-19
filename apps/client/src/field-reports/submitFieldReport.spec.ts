import { describe, expect, it } from "vitest";

import { AuthorFieldReportCatalog } from "@/field-reports/authorFieldReportCatalog";
import {
  applyLocalFieldReportAcceptance,
  submitFieldReport,
} from "@/field-reports/submitFieldReport";
import {
  FIELD_REPORT_ACCEPTED,
  FIELD_REPORT_PENDING_SYNC,
  type CreateOfflineFieldReportInput,
} from "@/field-reports/offlineFieldReport";
import { PendingFieldReportQueue } from "@/field-reports/pendingFieldReportQueue";

const BASE_INPUT: CreateOfflineFieldReportInput = {
  eventId: "event-1",
  submittedByUserId: "user-1",
  staffId: "staff-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
  title: "Medical assist near Gate A",
  body: "Observed a medical assist near Gate A.",
};

describe("submitFieldReport", () => {
  it("finalizes a report into the outbox and author catalog", () => {
    const queue = new PendingFieldReportQueue();
    const catalog = new AuthorFieldReportCatalog();

    const report = submitFieldReport(BASE_INPUT, {
      queue,
      catalog,
      notifyCatalogChanged: () => undefined,
      generateId: () => "3f2a1b9c-1111-2222-3333-444455556666",
      now: () => new Date("2027-06-01T12:00:00.000Z"),
    });

    expect(report.syncStatus).toBe(FIELD_REPORT_PENDING_SYNC);
    expect(queue.has(report.id)).toBe(true);
    expect(catalog.getForAuthor(report.id, "user-1")?.id).toBe(report.id);
  });

  it("applies local acceptance by updating the catalog and draining the outbox", () => {
    const queue = new PendingFieldReportQueue();
    const catalog = new AuthorFieldReportCatalog();

    const report = submitFieldReport(BASE_INPUT, {
      queue,
      catalog,
      notifyCatalogChanged: () => undefined,
      generateId: () => "3f2a1b9c-1111-2222-3333-444455556666",
    });

    const accepted = applyLocalFieldReportAcceptance(
      report.id,
      {
        fraNumber: "FRA-2027-000123",
        serverReceivedAt: "2027-06-01T12:05:00.000Z",
      },
      { queue, catalog, notifyCatalogChanged: () => undefined },
    );

    expect(accepted.syncStatus).toBe(FIELD_REPORT_ACCEPTED);
    expect(accepted.fraNumber).toBe("FRA-2027-000123");
    expect(queue.has(report.id)).toBe(false);
    expect(catalog.get(report.id)?.fraNumber).toBe("FRA-2027-000123");
  });
});
