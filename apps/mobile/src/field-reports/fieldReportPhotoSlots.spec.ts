import { describe, expect, it } from "vitest";

import {
  assertCanAddFieldReportPhotos,
  FieldReportPhotoLimitError,
  remainingFieldReportPhotoSlots,
} from "@/field-reports/fieldReportPhotoSlots";
import { FieldReportPhotoSelection } from "@/field-reports/fieldReportPhotoSelection";
import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";

function processed(id: string): ProcessedFieldReportPhoto {
  return Object.freeze({
    id,
    mimeType: "image/webp",
    byteSize: 12,
    width: 10,
    height: 10,
    bytes: Uint8Array.from([1, 2, 3]),
    checksumSha256: "abc",
    processedAt: "2027-07-04T13:22:10.000Z",
  });
}

describe("fieldReportPhotoSlots (M9.7)", () => {
  it("allows up to two photos across existing and selected counts", () => {
    expect(remainingFieldReportPhotoSlots(0, 0)).toBe(2);
    expect(remainingFieldReportPhotoSlots(1, 0)).toBe(1);
    expect(remainingFieldReportPhotoSlots(1, 1)).toBe(0);
    expect(remainingFieldReportPhotoSlots(2, 0)).toBe(0);
  });

  it("rejects a third photo when the budget is exhausted", () => {
    expect(() => assertCanAddFieldReportPhotos(0, 2, 1)).toThrow(
      FieldReportPhotoLimitError,
    );
    expect(() => assertCanAddFieldReportPhotos(1, 1, 1)).toThrow(
      /at most 2 photos/,
    );
    expect(() => assertCanAddFieldReportPhotos(0, 0, 2)).not.toThrow();
  });

  it("counts append-existing photos against the same max-2 budget", () => {
    const selection = new FieldReportPhotoSelection(1);
    expect(selection.remainingSlots()).toBe(1);
    selection.add(processed("a"), "blob:a");
    expect(selection.remainingSlots()).toBe(0);
    expect(() => selection.add(processed("b"), "blob:b")).toThrow(
      FieldReportPhotoLimitError,
    );
  });

  it("snapshots processed photos without preview URLs", () => {
    const selection = new FieldReportPhotoSelection();
    selection.add(processed("a"), "blob:a");
    selection.add(processed("b"), "blob:b");

    const snapshot = selection.snapshotForSubmit();
    expect(snapshot).toHaveLength(2);
    expect(snapshot[0]).not.toHaveProperty("previewUrl");
    expect(snapshot[0]?.id).toBe("a");
  });
});
