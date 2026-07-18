import { afterEach, describe, expect, it } from "vitest";

import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";
import {
  attachPendingFieldReportPhotos,
  clearPendingFieldReportPhotos,
  getPendingFieldReportPhotos,
  pendingFieldReportPhotoReportIds,
} from "@/field-reports/pendingFieldReportPhotos";

function processed(id: string): ProcessedFieldReportPhoto {
  return Object.freeze({
    id,
    mimeType: "image/webp",
    byteSize: 3,
    width: 1,
    height: 1,
    bytes: Uint8Array.from([1, 2, 3]),
    checksumSha256: "abc",
    processedAt: "2027-07-04T13:22:10.000Z",
  });
}

afterEach(() => {
  clearPendingFieldReportPhotos();
});

describe("pendingFieldReportPhotos (M9.7)", () => {
  it("associates processed photos with a submitted report id", () => {
    attachPendingFieldReportPhotos("report-1", [processed("p1"), processed("p2")]);

    expect(pendingFieldReportPhotoReportIds()).toEqual(["report-1"]);
    expect(getPendingFieldReportPhotos("report-1")).toHaveLength(2);
    expect(getPendingFieldReportPhotos("report-1")[0]?.bytes).toEqual(
      Uint8Array.from([1, 2, 3]),
    );
  });

  it("clears pending photos for one report or all reports", () => {
    attachPendingFieldReportPhotos("report-1", [processed("p1")]);
    attachPendingFieldReportPhotos("report-2", [processed("p2")]);

    clearPendingFieldReportPhotos("report-1");
    expect(getPendingFieldReportPhotos("report-1")).toEqual([]);
    expect(getPendingFieldReportPhotos("report-2")).toHaveLength(1);

    clearPendingFieldReportPhotos();
    expect(pendingFieldReportPhotoReportIds()).toEqual([]);
  });
});
