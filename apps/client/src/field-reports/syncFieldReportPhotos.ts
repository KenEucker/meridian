// Upload sync seam for pending Field Report photos (M9.8).
//
// Technical spec 18.2 / 18.5: photo sync is separate from the text record and
// verifies checksums. This module drains the durable pending photo store through
// an injected uploader so unit tests can exercise success/failure without a
// live HTTP transport. Signed node envelopes remain a later sync task.

import {
  listPendingFieldReportPhotoRecords,
  markPendingFieldReportPhotoStatus,
} from "@/field-reports/pendingFieldReportPhotos";
import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";

export interface FieldReportPhotoUploadRequest {
  readonly id: string;
  readonly fieldReportId: string;
  readonly bytes: Uint8Array;
  readonly checksumSha256: string;
  readonly mimeType: string;
}

export interface FieldReportPhotoUploadResult {
  readonly id: string;
  readonly checksum: string;
}

export type FieldReportPhotoUploader = (
  request: FieldReportPhotoUploadRequest,
) => Promise<FieldReportPhotoUploadResult>;

export interface SyncFieldReportPhotosResult {
  readonly attempted: number;
  readonly uploaded: number;
  readonly failed: number;
}

function toRequest(
  fieldReportId: string,
  photo: ProcessedFieldReportPhoto,
): FieldReportPhotoUploadRequest {
  return {
    id: photo.id,
    fieldReportId,
    bytes: photo.bytes,
    checksumSha256: photo.checksumSha256,
    mimeType: photo.mimeType,
  };
}

/**
 * Attempt to upload all pending (and previously failed) photos for one report,
 * or every report when `fieldReportId` is omitted.
 */
export async function syncPendingFieldReportPhotos(
  uploader: FieldReportPhotoUploader,
  fieldReportId?: string,
): Promise<SyncFieldReportPhotosResult> {
  const records = (
    await listPendingFieldReportPhotoRecords(fieldReportId)
  ).filter(
    (record) =>
      record.syncStatus === "pending_upload" || record.syncStatus === "failed",
  );

  let uploaded = 0;
  let failed = 0;

  for (const record of records) {
    try {
      const result = await uploader(
        toRequest(record.fieldReportId, record.photo),
      );
      if (result.id !== record.photo.id) {
        throw new Error("Uploader returned a different photo id.");
      }
      await markPendingFieldReportPhotoStatus(record.photo.id, "uploaded");
      uploaded += 1;
    } catch (error) {
      const message =
        error instanceof Error ? error.message : "Photo upload failed.";
      await markPendingFieldReportPhotoStatus(
        record.photo.id,
        "failed",
        message,
      );
      failed += 1;
    }
  }

  return {
    attempted: records.length,
    uploaded,
    failed,
  };
}
