// Field Report photo slot enforcement (M9.7).
//
// Technical spec 18.3/18.4 and data/API 10.17: max 2 photos total per Field
// Report, including photos added later via appends. Capture UI and any future
// append path share this budget helper so the aggregate cannot exceed 2.

import { FIELD_REPORT_PHOTO_MAX_COUNT } from "@/field-reports/fieldReportPhotoLimits";

export class FieldReportPhotoLimitError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "FieldReportPhotoLimitError";
  }
}

/**
 * How many additional photos may still be attached given photos already on the
 * report (original + prior appends) and photos already selected in the current
 * compose step.
 */
export function remainingFieldReportPhotoSlots(
  existingCount: number,
  selectedCount: number = 0,
  maxCount: number = FIELD_REPORT_PHOTO_MAX_COUNT,
): number {
  if (
    !Number.isInteger(existingCount) ||
    existingCount < 0 ||
    !Number.isInteger(selectedCount) ||
    selectedCount < 0
  ) {
    throw new FieldReportPhotoLimitError(
      "Field Report photo counts must be non-negative integers.",
    );
  }

  return Math.max(0, maxCount - existingCount - selectedCount);
}

/**
 * Assert that adding `incomingCount` photos would not exceed the Alpha 1 max.
 * Throws `FieldReportPhotoLimitError` when the budget is exhausted.
 */
export function assertCanAddFieldReportPhotos(
  existingCount: number,
  selectedCount: number,
  incomingCount: number,
  maxCount: number = FIELD_REPORT_PHOTO_MAX_COUNT,
): void {
  if (!Number.isInteger(incomingCount) || incomingCount < 0) {
    throw new FieldReportPhotoLimitError(
      "Incoming Field Report photo count must be a non-negative integer.",
    );
  }

  const remaining = remainingFieldReportPhotoSlots(
    existingCount,
    selectedCount,
    maxCount,
  );

  if (incomingCount > remaining) {
    throw new FieldReportPhotoLimitError(
      `Field Reports allow at most ${maxCount} photos total. ${remaining} slot${remaining === 1 ? "" : "s"} remaining.`,
    );
  }
}
