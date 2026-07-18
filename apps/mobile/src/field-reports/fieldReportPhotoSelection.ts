// In-progress Field Report photo selection for the create surface (M9.7).
//
// Holds processed photos selected before Submit. Photos remain separate from
// the text report record (technical spec 18.2); durable encrypted local storage
// and upload sync are M9.8. This bag only keeps processed blobs for the compose
// step and associates them with a report id after successful submit.

import { FIELD_REPORT_PHOTO_MAX_COUNT } from "@/field-reports/fieldReportPhotoLimits";
import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";
import {
  assertCanAddFieldReportPhotos,
  FieldReportPhotoLimitError,
  remainingFieldReportPhotoSlots,
} from "@/field-reports/fieldReportPhotoSlots";

export interface SelectedFieldReportPhoto extends ProcessedFieldReportPhoto {
  /** Object URL for local preview; revoke on remove/clear. */
  readonly previewUrl: string;
}

export class FieldReportPhotoSelection {
  private readonly photos: SelectedFieldReportPhoto[] = [];
  private readonly existingCount: number;

  constructor(existingCount: number = 0) {
    this.existingCount = existingCount;
  }

  list(): readonly SelectedFieldReportPhoto[] {
    return this.photos.slice();
  }

  count(): number {
    return this.photos.length;
  }

  remainingSlots(): number {
    return remainingFieldReportPhotoSlots(
      this.existingCount,
      this.photos.length,
      FIELD_REPORT_PHOTO_MAX_COUNT,
    );
  }

  add(photo: ProcessedFieldReportPhoto, previewUrl: string): SelectedFieldReportPhoto {
    assertCanAddFieldReportPhotos(this.existingCount, this.photos.length, 1);

    const selected = Object.freeze({
      ...photo,
      previewUrl,
    });
    this.photos.push(selected);
    return selected;
  }

  remove(id: string): void {
    const index = this.photos.findIndex((photo) => photo.id === id);
    if (index < 0) {
      return;
    }

    const [removed] = this.photos.splice(index, 1);
    if (removed?.previewUrl && typeof URL?.revokeObjectURL === "function") {
      URL.revokeObjectURL(removed.previewUrl);
    }
  }

  clear(): void {
    for (const photo of this.photos) {
      if (photo.previewUrl && typeof URL?.revokeObjectURL === "function") {
        URL.revokeObjectURL(photo.previewUrl);
      }
    }
    this.photos.length = 0;
  }

  /**
   * Snapshot processed photos for association with a submitted report id.
   * Preview URLs are omitted; callers should clear the selection afterward.
   */
  snapshotForSubmit(): ProcessedFieldReportPhoto[] {
    return this.photos.map((photo) =>
      Object.freeze({
        id: photo.id,
        mimeType: photo.mimeType,
        byteSize: photo.byteSize,
        width: photo.width,
        height: photo.height,
        bytes: photo.bytes,
        checksumSha256: photo.checksumSha256,
        processedAt: photo.processedAt,
      }),
    );
  }
}

export { FieldReportPhotoLimitError };
