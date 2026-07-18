// Pending processed Field Report photos awaiting upload sync (M9.7 seam).
//
// Technical spec 18.2: photo sync is separate from the text record; a report
// may be locally submitted while photos are still pending upload. M9.8 owns
// encrypted durable storage and the upload transport. This in-memory map keeps
// processed blobs associated with a report id for the current session so the
// create flow can hand off without embedding binaries in the text outbox.

import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";

const pendingByReportId = new Map<string, ProcessedFieldReportPhoto[]>();

export function attachPendingFieldReportPhotos(
  fieldReportId: string,
  photos: readonly ProcessedFieldReportPhoto[],
): void {
  if (photos.length === 0) {
    pendingByReportId.delete(fieldReportId);
    return;
  }

  pendingByReportId.set(
    fieldReportId,
    photos.map((photo) => Object.freeze({ ...photo, bytes: photo.bytes.slice() })),
  );
}

export function getPendingFieldReportPhotos(
  fieldReportId: string,
): readonly ProcessedFieldReportPhoto[] {
  return pendingByReportId.get(fieldReportId)?.slice() ?? [];
}

export function clearPendingFieldReportPhotos(fieldReportId?: string): void {
  if (typeof fieldReportId === "string") {
    pendingByReportId.delete(fieldReportId);
    return;
  }

  pendingByReportId.clear();
}

export function pendingFieldReportPhotoReportIds(): string[] {
  return Array.from(pendingByReportId.keys());
}
