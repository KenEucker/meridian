import { describe, expect, it } from "vitest";

import {
  isGifBytes,
  isGifMime,
  jpegContainsExifSegment,
} from "@/field-reports/fieldReportPhotoDetect";

function bytes(...values: number[]): Uint8Array {
  return Uint8Array.from(values);
}

describe("fieldReportPhotoDetect (M9.7)", () => {
  it("detects GIF87a and GIF89a magic bytes", () => {
    expect(isGifBytes(bytes(0x47, 0x49, 0x46, 0x38, 0x37, 0x61, 0x00))).toBe(
      true,
    );
    expect(isGifBytes(bytes(0x47, 0x49, 0x46, 0x38, 0x39, 0x61, 0x00))).toBe(
      true,
    );
    expect(isGifBytes(bytes(0xff, 0xd8, 0xff, 0xe0))).toBe(false);
  });

  it("detects image/gif MIME case-insensitively", () => {
    expect(isGifMime("image/gif")).toBe(true);
    expect(isGifMime("IMAGE/GIF")).toBe(true);
    expect(isGifMime("image/jpeg")).toBe(false);
    expect(isGifMime(null)).toBe(false);
  });

  it("detects a JPEG APP1 Exif segment", () => {
    const withExif = bytes(
      0xff,
      0xd8,
      0xff,
      0xe1,
      0x00,
      0x10,
      0x45,
      0x78,
      0x69,
      0x66,
      0x00,
      0x00,
      0x00,
      0x00,
      0x00,
      0x00,
      0x00,
      0x00,
      0x00,
      0x00,
      0xff,
      0xd9,
    );
    const withoutExif = bytes(0xff, 0xd8, 0xff, 0xd9);

    expect(jpegContainsExifSegment(withExif)).toBe(true);
    expect(jpegContainsExifSegment(withoutExif)).toBe(false);
  });
});
