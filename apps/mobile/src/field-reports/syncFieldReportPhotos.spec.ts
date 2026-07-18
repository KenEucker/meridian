import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";
import {
  attachPendingFieldReportPhotos,
  clearPendingFieldReportPhotos,
  configurePendingFieldReportPhotoStore,
  listPendingFieldReportPhotoRecords,
} from "@/field-reports/pendingFieldReportPhotos";
import {
  createFieldReportPhotoStore,
  fieldReportPhotoStore,
} from "@/field-reports/pendingFieldReportPhotoStore";
import { syncPendingFieldReportPhotos } from "@/field-reports/syncFieldReportPhotos";

function processed(id: string): ProcessedFieldReportPhoto {
  return Object.freeze({
    id,
    mimeType: "image/webp",
    byteSize: 3,
    width: 1,
    height: 1,
    bytes: Uint8Array.from([1, 2, 3]),
    checksumSha256: "abc123",
    processedAt: "2027-07-04T13:22:10.000Z",
  });
}

beforeEach(() => {
  configurePendingFieldReportPhotoStore(createFieldReportPhotoStore(null));
});

afterEach(async () => {
  await clearPendingFieldReportPhotos();
  configurePendingFieldReportPhotoStore(fieldReportPhotoStore);
});

describe("syncPendingFieldReportPhotos (M9.8)", () => {
  it("uploads pending photos and clears them on success", async () => {
    await attachPendingFieldReportPhotos("report-1", [
      processed("p1"),
      processed("p2"),
    ]);

    const uploader = vi.fn(async (request) => ({
      id: request.id,
      checksum: request.checksumSha256,
    }));

    const result = await syncPendingFieldReportPhotos(uploader, "report-1");

    expect(result).toEqual({ attempted: 2, uploaded: 2, failed: 0 });
    expect(uploader).toHaveBeenCalledTimes(2);
    const uploaded = await listPendingFieldReportPhotoRecords("report-1");
    expect(uploaded).toHaveLength(2);
    expect(uploaded.every((record) => record.syncStatus === "uploaded")).toBe(
      true,
    );
  });

  it("marks failed uploads as recoverable and retries them", async () => {
    await attachPendingFieldReportPhotos("report-1", [processed("p1")]);

    const failing = vi.fn(async () => {
      throw new Error("network down");
    });
    const failed = await syncPendingFieldReportPhotos(failing, "report-1");
    expect(failed).toEqual({ attempted: 1, uploaded: 0, failed: 1 });

    const records = await listPendingFieldReportPhotoRecords("report-1");
    expect(records[0]?.syncStatus).toBe("failed");
    expect(records[0]?.lastError).toBe("network down");

    const succeeding = vi.fn(async (request) => ({
      id: request.id,
      checksum: request.checksumSha256,
    }));
    const retried = await syncPendingFieldReportPhotos(succeeding, "report-1");
    expect(retried).toEqual({ attempted: 1, uploaded: 1, failed: 0 });
    const afterRetry = await listPendingFieldReportPhotoRecords("report-1");
    expect(afterRetry).toHaveLength(1);
    expect(afterRetry[0]?.syncStatus).toBe("uploaded");
  });
});

