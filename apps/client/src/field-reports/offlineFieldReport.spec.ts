import { describe, expect, it } from "vitest";

import {
  buildTemporaryLocalNumber,
  createOfflineFieldReport,
  createOfflineFieldReportAppend,
  fieldReportSubmissionView,
  FIELD_REPORT_PENDING_SYNC,
  isPendingSync,
  OfflineFieldReportError,
  type CreateOfflineFieldReportInput,
  type OfflineFieldReport,
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

function createWith(
  overrides: Partial<CreateOfflineFieldReportInput> = {},
  id = "3f2a1b9c-1111-2222-3333-444455556666",
  at = new Date("2027-06-01T12:00:00.000Z"),
): OfflineFieldReport {
  return createOfflineFieldReport(
    { ...BASE_INPUT, ...overrides },
    { generateId: () => id, now: () => at },
  );
}

describe("createOfflineFieldReport", () => {
  it("generates a device UUID and submitted local state offline", () => {
    const report = createWith();

    expect(report.id).toBe("3f2a1b9c-1111-2222-3333-444455556666");
    expect(report.syncStatus).toBe(FIELD_REPORT_PENDING_SYNC);
    expect(report.deviceSubmittedAt).toBe("2027-06-01T12:00:00.000Z");
    expect(report.createdAt).toBe("2027-06-01T12:00:00.000Z");
    expect(isPendingSync(report)).toBe(true);
  });

  it("uses the device UUID as the temporary local number and leaves FRA numbering to the server", () => {
    const report = createWith();

    expect(report.temporaryLocalNumber).toBe("LOCAL-3F2A1B9C");
    expect(report.fraNumber).toBeNull();
    expect(report.serverReceivedAt).toBeNull();
  });

  it("carries the caller-supplied event, author, staff, device, node, title, and body", () => {
    const report = createWith({
      departmentId: "dept-1",
      teamId: "team-1",
      title: "  Radio check failed  ",
      body: "Radio check failed on channel 3.",
    });

    expect(report.eventId).toBe("event-1");
    expect(report.submittedByUserId).toBe("user-1");
    expect(report.staffId).toBe("staff-1");
    expect(report.originDeviceId).toBe("device-1");
    expect(report.originNodeId).toBe("node-1");
    expect(report.departmentId).toBe("dept-1");
    expect(report.teamId).toBe("team-1");
    expect(report.title).toBe("Radio check failed");
    expect(report.body).toBe("Radio check failed on channel 3.");
  });

  it("rejects empty or oversized titles", () => {
    expect(() => createWith({ title: "   " })).toThrow(
      "Field Report title is required.",
    );
    expect(() => createWith({ title: "a".repeat(201) })).toThrow(
      "Field Report title must be at most 200 characters.",
    );
  });

  it("allows duplicate titles within an event", () => {
    const first = createWith({ title: "Same title" });
    const second = createWith(
      { title: "Same title" },
      "aaaaaaaa-1111-2222-3333-444455556666",
    );

    expect(first.title).toBe("Same title");
    expect(second.title).toBe("Same title");
  });

  it("defaults optional department/team context to null when unavailable", () => {
    const report = createWith();

    expect(report.departmentId).toBeNull();
    expect(report.teamId).toBeNull();
  });

  it("preserves the original body text verbatim as the source of truth", () => {
    const body = "  @bucket seen near @blue-hat at Gate A  ";
    const report = createWith({ body });

    expect(report.body).toBe(body);
  });

  it("returns a frozen, finalized record (no drafts, immutable after submit)", () => {
    const report = createWith();

    expect(Object.isFrozen(report)).toBe(true);
    expect(() => {
      (report as { body: string }).body = "tampered";
    }).toThrow(TypeError);
    expect(() => {
      (report as { title: string }).title = "tampered";
    }).toThrow(TypeError);
  });

  it("generates a fresh UUID per submission by default", () => {
    const ids = new Set<string>();

    for (let i = 0; i < 25; i += 1) {
      ids.add(createOfflineFieldReport(BASE_INPUT).id);
    }

    expect(ids.size).toBe(25);
  });

  it.each([
    ["eventId"],
    ["submittedByUserId"],
    ["staffId"],
    ["originDeviceId"],
    ["originNodeId"],
  ] as const)("rejects a missing %s", (field) => {
    expect(() =>
      createWith({ [field]: "   " } as Partial<CreateOfflineFieldReportInput>),
    ).toThrow(OfflineFieldReportError);
  });

  it("rejects empty body text", () => {
    expect(() => createWith({ body: "   " })).toThrow(
      "Field Report body text is required.",
    );
  });

  it("starts with an empty append timeline", () => {
    expect(createWith().appends).toEqual([]);
  });
});

describe("createOfflineFieldReportAppend", () => {
  it("creates a pending append without a title", () => {
    const append = createOfflineFieldReportAppend("Later detail.", {
      generateId: () => "aaaaaaaa-1111-2222-3333-444455556666",
      now: () => new Date("2027-06-01T13:00:00.000Z"),
    });

    expect(append).toEqual({
      id: "aaaaaaaa-1111-2222-3333-444455556666",
      body: "Later detail.",
      deviceSubmittedAt: "2027-06-01T13:00:00.000Z",
      syncStatus: FIELD_REPORT_PENDING_SYNC,
    });
    expect(Object.isFrozen(append)).toBe(true);
  });

  it("rejects blank append body text", () => {
    expect(() => createOfflineFieldReportAppend("   ")).toThrow(
      "Field Report append body text is required.",
    );
  });
});

describe("buildTemporaryLocalNumber", () => {
  it("prefixes LOCAL- and is visually distinct from an FRA number", () => {
    const number = buildTemporaryLocalNumber(
      "3f2a1b9c-1111-2222-3333-444455556666",
    );

    expect(number).toBe("LOCAL-3F2A1B9C");
    expect(number.startsWith("FRA-")).toBe(false);
  });
});

describe("fieldReportSubmissionView", () => {
  it("shows the report as submitted and pending with the temporary number", () => {
    const view = fieldReportSubmissionView(createWith());

    expect(view.submitted).toBe(true);
    expect(view.pendingSync).toBe(true);
    expect(view.displayNumber).toBe("LOCAL-3F2A1B9C");
    expect(view.displayNumberIsTemporary).toBe(true);
  });

  it("shows the FRA number and drops pending once the server has accepted it", () => {
    const accepted: OfflineFieldReport = {
      ...createWith(),
      fraNumber: "FRA-2027-000123",
      serverReceivedAt: "2027-06-01T12:05:00.000Z",
      syncStatus: "accepted",
    };

    const view = fieldReportSubmissionView(accepted);

    expect(view.submitted).toBe(true);
    expect(view.pendingSync).toBe(false);
    expect(view.displayNumber).toBe("FRA-2027-000123");
    expect(view.displayNumberIsTemporary).toBe(false);
  });
});
