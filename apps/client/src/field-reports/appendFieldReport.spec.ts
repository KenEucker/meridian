import { describe, expect, it } from "vitest";

import { appendFieldReport } from "@/field-reports/appendFieldReport";
import { AuthorFieldReportCatalog } from "@/field-reports/authorFieldReportCatalog";
import {
  createOfflineFieldReport,
  type CreateOfflineFieldReportInput,
} from "@/field-reports/offlineFieldReport";

const BASE_INPUT: CreateOfflineFieldReportInput = {
  eventId: "event-1",
  submittedByUserId: "user-1",
  staffId: "staff-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
  title: "Medical assist near Gate A",
  body: "Observed a medical assist near Gate A.",
};

describe("appendFieldReport", () => {
  it("records an author append and notifies the catalog revision hook", () => {
    const catalog = new AuthorFieldReportCatalog();
    const report = createOfflineFieldReport(BASE_INPUT, {
      generateId: () => "3f2a1b9c-1111-2222-3333-444455556666",
      now: () => new Date("2027-06-01T12:00:00.000Z"),
    });
    catalog.recordSubmitted(report);

    let notified = 0;
    const updated = appendFieldReport(
      {
        fieldReportId: report.id,
        authorUserId: "user-1",
        body: "  Follow-up detail.  ",
      },
      {
        catalog,
        generateId: () => "aaaaaaaa-1111-2222-3333-444455556666",
        now: () => new Date("2027-06-01T13:00:00.000Z"),
        notifyCatalogChanged: () => {
          notified += 1;
        },
      },
    );

    expect(notified).toBe(1);
    expect(updated.body).toBe(report.body);
    expect(updated.appends).toEqual([
      {
        id: "aaaaaaaa-1111-2222-3333-444455556666",
        body: "Follow-up detail.",
        deviceSubmittedAt: "2027-06-01T13:00:00.000Z",
        syncStatus: "pending_sync",
      },
    ]);
  });
});
