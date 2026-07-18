import { describe, expect, it } from "vitest";

import { fitFieldReportPhotoDimensions } from "@/field-reports/fieldReportPhotoFit";
import {
  FIELD_REPORT_PHOTO_MAX_HEIGHT,
  FIELD_REPORT_PHOTO_MAX_WIDTH,
} from "@/field-reports/fieldReportPhotoLimits";

describe("fitFieldReportPhotoDimensions (M9.7)", () => {
  it("leaves images already inside the max box unchanged", () => {
    expect(fitFieldReportPhotoDimensions({ width: 800, height: 600 })).toEqual({
      width: 800,
      height: 600,
    });
  });

  it("fits a wide image within 2560 × 1900 without upscaling", () => {
    expect(
      fitFieldReportPhotoDimensions({ width: 5120, height: 2880 }),
    ).toEqual({
      width: FIELD_REPORT_PHOTO_MAX_WIDTH,
      height: 1440,
    });
  });

  it("fits a tall image within 2560 × 1900 without upscaling", () => {
    expect(
      fitFieldReportPhotoDimensions({ width: 2000, height: 4000 }),
    ).toEqual({
      width: 950,
      height: FIELD_REPORT_PHOTO_MAX_HEIGHT,
    });
  });

  it("rejects non-positive dimensions", () => {
    expect(() => fitFieldReportPhotoDimensions({ width: 0, height: 10 })).toThrow(
      /positive/,
    );
  });
});
