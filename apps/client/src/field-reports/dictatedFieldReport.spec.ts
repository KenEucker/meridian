import { describe, expect, it } from "vitest";

import {
  findDictationStaff,
  searchDictationStaff,
  type DictationStaffOption,
} from "@/field-reports/dictationStaffDirectory";
import {
  createOfflineFieldReport,
  fieldReportDictationLine,
  OfflineFieldReportError,
} from "@/field-reports/offlineFieldReport";
import { AuthorFieldReportCatalog } from "@/field-reports/authorFieldReportCatalog";

const BASE_INPUT = {
  eventId: "event-1",
  submittedByUserId: "operator-user",
  staffId: "operator-staff",
  originDeviceId: "device-1",
  originNodeId: "node-1",
  title: "Radio call",
  body: "Reported a light out on Esplanade.",
};

const DICTATION = {
  recordedByStaffId: "operator-staff",
  recordedByDisplayName: "Omar ICOperator",
  reportedByDisplayName: "Vera Staff",
};

const DIRECTORY: readonly DictationStaffOption[] = [
  { staffId: "staff-1", displayName: "Vera Staff", detail: "Dirt" },
  { staffId: "staff-2", displayName: "Sam Shiftlead", detail: "Dirt" },
  { staffId: "staff-3", displayName: "Dana Departmentlead", detail: "Command" },
];

describe("dictated Field Report creation", () => {
  it("prepends the attribution line to the immutable body", () => {
    const report = createOfflineFieldReport(
      { ...BASE_INPUT, staffId: "staff-1", dictation: DICTATION },
      { generateId: () => "report-1", now: () => new Date("2027-06-01T12:00:00Z") },
    );

    expect(report.body).toBe(
      "Field Report filled out by Omar ICOperator on behalf of Vera Staff\n\n" +
        "Reported a light out on Esplanade.",
    );
  });

  // Both facts are true and an export has to be able to tell them apart.
  it("keeps the operator as submitter and the reporting staff member as staff", () => {
    const report = createOfflineFieldReport(
      { ...BASE_INPUT, staffId: "staff-1", dictation: DICTATION },
      { generateId: () => "report-1" },
    );

    expect(report.submittedByUserId).toBe("operator-user");
    expect(report.staffId).toBe("staff-1");
    expect(report.dictation).toEqual(DICTATION);
  });

  it("leaves an ordinary Field Report body and dictation untouched", () => {
    const report = createOfflineFieldReport(BASE_INPUT, {
      generateId: () => "report-1",
    });

    expect(report.body).toBe("Reported a light out on Esplanade.");
    expect(report.dictation).toBeNull();
  });

  it("refuses a dictation missing the names the attribution line needs", () => {
    expect(() =>
      createOfflineFieldReport(
        {
          ...BASE_INPUT,
          dictation: { ...DICTATION, reportedByDisplayName: "  " },
        },
        { generateId: () => "report-1" },
      ),
    ).toThrow(OfflineFieldReportError);
  });

  it("builds the attribution line from the dictation", () => {
    expect(fieldReportDictationLine(DICTATION)).toBe(
      "Field Report filled out by Omar ICOperator on behalf of Vera Staff",
    );
  });
});

describe("dictated Field Report visibility", () => {
  const dictated = createOfflineFieldReport(
    { ...BASE_INPUT, staffId: "staff-1", dictation: DICTATION },
    { generateId: () => "report-1" },
  );

  function catalogWithDictatedReport(): AuthorFieldReportCatalog {
    const catalog = new AuthorFieldReportCatalog();
    catalog.recordSubmitted(dictated);

    return catalog;
  }

  it("shows the report to the staff member it is about", () => {
    const catalog = catalogWithDictatedReport();

    expect(catalog.listForAuthor("someone-else", undefined, "staff-1")).toHaveLength(1);
    expect(
      catalog.getForAuthor("report-1", "someone-else", "staff-1")?.id,
    ).toBe("report-1");
  });

  it("still shows the report to the operator who submitted it", () => {
    const catalog = catalogWithDictatedReport();

    expect(catalog.listForAuthor("operator-user")).toHaveLength(1);
    expect(catalog.getForAuthor("report-1", "operator-user")?.id).toBe("report-1");
  });

  it("hides the report from an unrelated staff member", () => {
    const catalog = catalogWithDictatedReport();

    expect(catalog.listForAuthor("other-user", undefined, "staff-9")).toEqual([]);
    expect(catalog.getForAuthor("report-1", "other-user", "staff-9")).toBeUndefined();
  });

  // A caller that knows only a user id keeps the original author-only rule.
  it("does not widen visibility when no staff id is supplied", () => {
    const catalog = catalogWithDictatedReport();

    expect(catalog.listForAuthor("other-user")).toEqual([]);
    expect(catalog.getForAuthor("report-1", "other-user")).toBeUndefined();
  });
});

describe("dictation staff directory", () => {
  it("returns the head of the directory before anything is typed", () => {
    expect(searchDictationStaff("", DIRECTORY, 2).map((o) => o.displayName)).toEqual([
      "Vera Staff",
      "Sam Shiftlead",
    ]);
  });

  it("matches on name and on the disambiguating detail, case-insensitively", () => {
    expect(searchDictationStaff("vera", DIRECTORY).map((o) => o.staffId)).toEqual([
      "staff-1",
    ]);
    expect(searchDictationStaff("command", DIRECTORY).map((o) => o.staffId)).toEqual([
      "staff-3",
    ]);
  });

  it("caps the result list", () => {
    expect(searchDictationStaff("", DIRECTORY, 1)).toHaveLength(1);
  });

  it("returns no matches rather than the whole roster for an unknown name", () => {
    expect(searchDictationStaff("nobody", DIRECTORY)).toEqual([]);
  });

  it("resolves and misses staff by id", () => {
    expect(findDictationStaff("staff-2", DIRECTORY)?.displayName).toBe("Sam Shiftlead");
    expect(findDictationStaff("staff-404", DIRECTORY)).toBeNull();
  });
});
