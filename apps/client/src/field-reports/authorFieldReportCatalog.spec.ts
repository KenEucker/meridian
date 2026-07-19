import { describe, expect, it } from "vitest";

import {
  AuthorFieldReportCatalog,
  AuthorFieldReportCatalogError,
} from "@/field-reports/authorFieldReportCatalog";
import {
  createOfflineFieldReport,
  FIELD_REPORT_ACCEPTED,
  FIELD_REPORT_PENDING_SYNC,
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

function report(
  overrides: Partial<CreateOfflineFieldReportInput> = {},
  id = "3f2a1b9c-1111-2222-3333-444455556666",
) {
  return createOfflineFieldReport(
    { ...BASE_INPUT, ...overrides },
    { generateId: () => id, now: () => new Date("2027-06-01T12:00:00.000Z") },
  );
}

describe("AuthorFieldReportCatalog", () => {
  it("records submitted reports for the author and lists them in submission order", () => {
    const catalog = new AuthorFieldReportCatalog();
    const first = report({}, "aaaaaaaa-1111-2222-3333-444455556666");
    const second = report({}, "bbbbbbbb-1111-2222-3333-444455556666");

    expect(catalog.recordSubmitted(first)).toBe(true);
    expect(catalog.recordSubmitted(second)).toBe(true);
    expect(catalog.recordSubmitted(first)).toBe(false);

    const listed = catalog.listForAuthor("user-1");
    expect(listed.map((item) => item.id)).toEqual([first.id, second.id]);
  });

  it("hides reports from non-authors (FR-004)", () => {
    const catalog = new AuthorFieldReportCatalog();
    const own = report();
    const other = report(
      { submittedByUserId: "user-2" },
      "cccccccc-1111-2222-3333-444455556666",
    );

    catalog.recordSubmitted(own);
    catalog.recordSubmitted(other);

    expect(catalog.listForAuthor("user-1")).toHaveLength(1);
    expect(catalog.listForAuthor("user-1")[0]?.id).toBe(own.id);
    expect(catalog.getForAuthor(own.id, "user-1")?.id).toBe(own.id);
    expect(catalog.getForAuthor(own.id, "user-2")).toBeUndefined();
    expect(catalog.getForAuthor(other.id, "user-1")).toBeUndefined();
  });

  it("can scope the author list to one event", () => {
    const catalog = new AuthorFieldReportCatalog();
    catalog.recordSubmitted(report({ eventId: "event-1" }));
    catalog.recordSubmitted(
      report(
        { eventId: "event-2" },
        "dddddddd-1111-2222-3333-444455556666",
      ),
    );

    expect(catalog.listForAuthor("user-1", "event-1")).toHaveLength(1);
    expect(catalog.listForAuthor("user-1", "event-1")[0]?.eventId).toBe(
      "event-1",
    );
  });

  it("replaces the temporary number with the FRA number on acceptance", () => {
    const catalog = new AuthorFieldReportCatalog();
    const pending = report();
    catalog.recordSubmitted(pending);

    const accepted = catalog.recordAcceptance(pending.id, {
      fraNumber: "FRA-2027-000001",
      serverReceivedAt: "2027-06-01T12:05:00.000Z",
    });

    expect(accepted.syncStatus).toBe(FIELD_REPORT_ACCEPTED);
    expect(accepted.fraNumber).toBe("FRA-2027-000001");
    expect(accepted.serverReceivedAt).toBe("2027-06-01T12:05:00.000Z");
    expect(accepted.body).toBe(pending.body);
    expect(catalog.get(pending.id)?.syncStatus).toBe(FIELD_REPORT_ACCEPTED);
    expect(pending.syncStatus).toBe(FIELD_REPORT_PENDING_SYNC);
  });

  it("rejects acceptance updates for unknown reports", () => {
    const catalog = new AuthorFieldReportCatalog();

    expect(() =>
      catalog.recordAcceptance("missing", {
        fraNumber: "FRA-2027-000001",
        serverReceivedAt: "2027-06-01T12:05:00.000Z",
      }),
    ).toThrow(AuthorFieldReportCatalogError);
  });
});
