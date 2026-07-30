import { afterEach, describe, expect, it } from "vitest";

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
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";

const BASE_INPUT: CreateOfflineFieldReportInput = {
  eventId: "event-1",
  submittedByUserId: "user-1",
  staffId: "staff-1",
  originDeviceId: "device-1",
  originNodeId: "node-1",
  title: "Medical assist near Gate A",
  body: "Observed a medical assist near Gate A.",
};

afterEach(() => {
  resetCommandOutbox();
});

describe("submitFieldReport", () => {
  it("finalizes a report into the command outbox and author catalog", () => {
    const catalog = new AuthorFieldReportCatalog();

    const report = submitFieldReport(BASE_INPUT, {
      catalog,
      notifyCatalogChanged: () => undefined,
      generateId: () => "3f2a1b9c-1111-2222-3333-444455556666",
      now: () => new Date("2027-06-01T12:00:00.000Z"),
    });

    expect(report.syncStatus).toBe(FIELD_REPORT_PENDING_SYNC);
    expect(catalog.getForAuthor(report.id, "user-1")?.id).toBe(report.id);

    const command = commandOutbox.get(report.id);
    expect(command?.commandType).toBe("submit-field-report");
    expect(command?.status).toBe("queued");
    // The report's device UUID is the idempotency key, so the command the node
    // eventually receives is the one this device generated (CLIENT-016).
    expect(command?.idempotencyKey).toBe(report.id);
    expect(command?.payload).toMatchObject({
      id: report.id,
      event_id: "event-1",
      title: "Medical assist near Gate A",
      body: "Observed a medical assist near Gate A.",
    });
    expect(command?.detail).toBe(report.temporaryLocalNumber);
  });

  it("queues one command for a report submitted twice", () => {
    // The same report submitted again is the same command, not a second one.
    const catalog = new AuthorFieldReportCatalog();
    const dependencies = {
      catalog,
      notifyCatalogChanged: () => undefined,
      generateId: () => "3f2a1b9c-1111-2222-3333-444455556666",
      now: () => new Date("2027-06-01T12:00:00.000Z"),
    };

    submitFieldReport(BASE_INPUT, dependencies);
    submitFieldReport(BASE_INPUT, dependencies);

    expect(commandOutbox.size).toBe(1);
    expect(catalog.size).toBe(1);
  });

  it("applies local acceptance by updating the catalog", () => {
    const catalog = new AuthorFieldReportCatalog();

    const report = submitFieldReport(BASE_INPUT, {
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
      { catalog, notifyCatalogChanged: () => undefined },
    );

    expect(accepted.syncStatus).toBe(FIELD_REPORT_ACCEPTED);
    expect(accepted.fraNumber).toBe("FRA-2027-000123");
    expect(catalog.get(report.id)?.fraNumber).toBe("FRA-2027-000123");
  });
});
