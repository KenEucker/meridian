import { afterEach, beforeEach, describe, expect, it } from "vitest";

import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";
import {
  attachPendingFieldReportPhotos,
  clearPendingFieldReportPhotos,
  configurePendingFieldReportPhotoStore,
  getPendingFieldReportPhotos,
  hydratePendingFieldReportPhotos,
  listPendingFieldReportPhotoRecords,
  markPendingFieldReportPhotoStatus,
  pendingFieldReportPhotoReportIds,
} from "@/field-reports/pendingFieldReportPhotos";
import {
  createFieldReportPhotoStore,
  fieldReportPhotoStore,
} from "@/field-reports/pendingFieldReportPhotoStore";

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

function memoryStorage(): Storage {
  const values = new Map<string, string>();
  return {
    get length() {
      return values.size;
    },
    clear() {
      values.clear();
    },
    getItem(key: string) {
      return values.has(key) ? (values.get(key) ?? null) : null;
    },
    key(index: number) {
      return Array.from(values.keys())[index] ?? null;
    },
    removeItem(key: string) {
      values.delete(key);
    },
    setItem(key: string, value: string) {
      values.set(key, value);
    },
  };
}

beforeEach(() => {
  configurePendingFieldReportPhotoStore(createFieldReportPhotoStore(null));
});

afterEach(async () => {
  await clearPendingFieldReportPhotos();
  configurePendingFieldReportPhotoStore(fieldReportPhotoStore);
});

describe("pendingFieldReportPhotos (M9.8)", () => {
  it("associates processed photos with a submitted report id", async () => {
    await attachPendingFieldReportPhotos("report-1", [
      processed("p1"),
      processed("p2"),
    ]);

    expect(await pendingFieldReportPhotoReportIds()).toEqual(["report-1"]);
    expect(await getPendingFieldReportPhotos("report-1")).toHaveLength(2);
    expect((await getPendingFieldReportPhotos("report-1"))[0]?.bytes).toEqual(
      Uint8Array.from([1, 2, 3]),
    );
  });

  it("persists encrypted pending photos across hydrate cycles", async () => {
    const storage = memoryStorage();
    configurePendingFieldReportPhotoStore(createFieldReportPhotoStore(storage));

    await attachPendingFieldReportPhotos("report-1", [processed("p1")]);
    expect(storage.getItem("meridian.field-reports.pending-photos.v1")).toBeTruthy();

    configurePendingFieldReportPhotoStore(createFieldReportPhotoStore(storage));
    await hydratePendingFieldReportPhotos();

    const records = await listPendingFieldReportPhotoRecords("report-1");
    expect(records).toHaveLength(1);
    expect(records[0]?.syncStatus).toBe("pending_upload");
    expect(records[0]?.photo.bytes).toEqual(Uint8Array.from([1, 2, 3]));
  });

  it("clears pending photos for one report or all reports", async () => {
    await attachPendingFieldReportPhotos("report-1", [processed("p1")]);
    await attachPendingFieldReportPhotos("report-2", [processed("p2")]);

    await clearPendingFieldReportPhotos("report-1");
    expect(await getPendingFieldReportPhotos("report-1")).toEqual([]);
    expect(await getPendingFieldReportPhotos("report-2")).toHaveLength(1);

    await clearPendingFieldReportPhotos();
    expect(await pendingFieldReportPhotoReportIds()).toEqual([]);
  });

  it("retains uploaded photos locally for author preview", async () => {
    await attachPendingFieldReportPhotos("report-1", [processed("p1")]);
    await markPendingFieldReportPhotoStatus("p1", "uploaded");

    // Pending-upload helper excludes uploaded rows.
    expect(await getPendingFieldReportPhotos("report-1")).toEqual([]);

    const records = await listPendingFieldReportPhotoRecords("report-1");
    expect(records).toHaveLength(1);
    expect(records[0]?.syncStatus).toBe("uploaded");
    expect(records[0]?.photo.bytes).toEqual(Uint8Array.from([1, 2, 3]));
  });
});

