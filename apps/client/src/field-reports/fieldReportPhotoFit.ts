// Fit geometry for Field Report photo processing (M9.7).
//
// Technical spec 18.3 / data/API 10.17: compressed image dimensions max
// 2560 × 1900 while preserving aspect ratio. Original full-resolution photos
// are not kept after processing.

import {
  FIELD_REPORT_PHOTO_MAX_HEIGHT,
  FIELD_REPORT_PHOTO_MAX_WIDTH,
} from "@/field-reports/fieldReportPhotoLimits";

export interface ImageDimensions {
  readonly width: number;
  readonly height: number;
}

/**
 * Scale `source` to fit inside the Alpha 1 max box without upscaling.
 * Landscape and portrait sources both clamp to the same absolute width/height
 * caps (2560 × 1900), preserving aspect ratio.
 */
export function fitFieldReportPhotoDimensions(
  source: ImageDimensions,
  maxWidth: number = FIELD_REPORT_PHOTO_MAX_WIDTH,
  maxHeight: number = FIELD_REPORT_PHOTO_MAX_HEIGHT,
): ImageDimensions {
  if (
    !Number.isFinite(source.width) ||
    !Number.isFinite(source.height) ||
    source.width <= 0 ||
    source.height <= 0
  ) {
    throw new Error("Field Report photo dimensions must be positive.");
  }

  const widthScale = maxWidth / source.width;
  const heightScale = maxHeight / source.height;
  const scale = Math.min(1, widthScale, heightScale);

  return {
    width: Math.max(1, Math.round(source.width * scale)),
    height: Math.max(1, Math.round(source.height * scale)),
  };
}
