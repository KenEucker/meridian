// Field Report photo capture limits (M9.7).
//
// Technical spec section 18.3 and data/API section 10.17 fix Alpha 1 limits:
// max 2 photos total (including appends), max compressed dimensions
// 2560 × 1900, max compressed size 5 MB, no GIFs, EXIF stripped. UI contract
// 14.3 mirrors the same rules for the Submit Field Report surface.
//
// Sync/upload/storage of processed blobs is M9.8. This module owns the shared
// numeric and MIME constants used by capture processing and slot enforcement.

/** Maximum photos attached to one Field Report across the original and appends. */
export const FIELD_REPORT_PHOTO_MAX_COUNT = 2;

/** Maximum width of a processed Field Report photo. */
export const FIELD_REPORT_PHOTO_MAX_WIDTH = 2560;

/** Maximum height of a processed Field Report photo. */
export const FIELD_REPORT_PHOTO_MAX_HEIGHT = 1900;

/** Maximum byte size of a processed Field Report photo (5 MB). */
export const FIELD_REPORT_PHOTO_MAX_BYTES = 5 * 1024 * 1024;

/** Preferred stored MIME after processing (matches technical spec 18.5 example). */
export const FIELD_REPORT_PHOTO_PREFERRED_MIME = "image/webp" as const;

/** Fallback stored MIME when WebP encoding is unavailable. */
export const FIELD_REPORT_PHOTO_FALLBACK_MIME = "image/jpeg" as const;

export type FieldReportPhotoStoredMime =
  | typeof FIELD_REPORT_PHOTO_PREFERRED_MIME
  | typeof FIELD_REPORT_PHOTO_FALLBACK_MIME;

/** MIME types rejected before decode. */
export const FIELD_REPORT_PHOTO_REJECTED_MIMES = Object.freeze([
  "image/gif",
] as const);

/**
 * Common phone still-image MIME types accepted for decode where practical.
 * Undecodable formats (for example some HEIC variants) fail at decode time
 * rather than silently accepting the original bytes.
 */
export const FIELD_REPORT_PHOTO_ACCEPTED_SOURCE_MIMES = Object.freeze([
  "image/jpeg",
  "image/jpg",
  "image/png",
  "image/webp",
  "image/heic",
  "image/heif",
  "image/avif",
  "image/bmp",
  "image/tiff",
] as const);
