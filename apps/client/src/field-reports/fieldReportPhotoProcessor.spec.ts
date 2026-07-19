import { describe, expect, it } from "vitest";

import {
  FIELD_REPORT_PHOTO_FALLBACK_MIME,
  FIELD_REPORT_PHOTO_MAX_BYTES,
  FIELD_REPORT_PHOTO_MAX_HEIGHT,
  FIELD_REPORT_PHOTO_MAX_WIDTH,
  FIELD_REPORT_PHOTO_PREFERRED_MIME,
} from "@/field-reports/fieldReportPhotoLimits";
import {
  FieldReportPhotoProcessingError,
  processFieldReportPhoto,
  type DecodedFieldReportPhoto,
} from "@/field-reports/fieldReportPhotoProcessor";

const GIF_BYTES = Uint8Array.from([
  0x47, 0x49, 0x46, 0x38, 0x39, 0x61, 0x01, 0x00, 0x01, 0x00,
]);

function decoded(width: number, height: number): DecodedFieldReportPhoto {
  return {
    width,
    height,
    draw() {
      // no-op for injected encode path
    },
  };
}

describe("processFieldReportPhoto (M9.7)", () => {
  it("rejects GIF magic bytes even when MIME claims jpeg", async () => {
    await expect(
      processFieldReportPhoto(GIF_BYTES, "image/jpeg"),
    ).rejects.toBeInstanceOf(FieldReportPhotoProcessingError);

    await expect(
      processFieldReportPhoto(GIF_BYTES, "image/jpeg"),
    ).rejects.toThrow(/GIF/);
  });

  it("rejects image/gif MIME before decode", async () => {
    await expect(
      processFieldReportPhoto(Uint8Array.from([0xff, 0xd8]), "image/gif"),
    ).rejects.toThrow(/GIF/);
  });

  it("fits oversized sources and returns processed metadata under limits", async () => {
    const processed = await processFieldReportPhoto(
      Uint8Array.from([0x01, 0x02, 0x03, 0x04]),
      "image/png",
      {
        generateId: () => "photo-1",
        now: () => new Date("2027-07-04T13:22:10.000Z"),
        digestSha256: async () => "checksum-1",
        decodeImage: async () => decoded(5000, 4000),
        encodeImage: async (_source, width, height, mimeType) => {
          expect(width).toBeLessThanOrEqual(FIELD_REPORT_PHOTO_MAX_WIDTH);
          expect(height).toBeLessThanOrEqual(FIELD_REPORT_PHOTO_MAX_HEIGHT);
          expect(mimeType).toBe(FIELD_REPORT_PHOTO_PREFERRED_MIME);
          return Uint8Array.from([0x52, 0x49, 0x46, 0x46, 0x00, 0x00]);
        },
      },
    );

    expect(processed.id).toBe("photo-1");
    expect(processed.width).toBeLessThanOrEqual(FIELD_REPORT_PHOTO_MAX_WIDTH);
    expect(processed.height).toBeLessThanOrEqual(FIELD_REPORT_PHOTO_MAX_HEIGHT);
    expect(processed.byteSize).toBeLessThanOrEqual(FIELD_REPORT_PHOTO_MAX_BYTES);
    expect(processed.mimeType).toBe(FIELD_REPORT_PHOTO_PREFERRED_MIME);
    expect(processed.checksumSha256).toBe("checksum-1");
    expect(processed.processedAt).toBe("2027-07-04T13:22:10.000Z");
  });

  it("falls back to JPEG when WebP encoding fails", async () => {
    const processed = await processFieldReportPhoto(
      Uint8Array.from([0x01, 0x02]),
      "image/jpeg",
      {
        generateId: () => "photo-2",
        now: () => new Date("2027-07-04T13:22:10.000Z"),
        digestSha256: async () => "checksum-2",
        decodeImage: async () => decoded(100, 100),
        encodeImage: async (_source, _w, _h, mimeType) => {
          if (mimeType === FIELD_REPORT_PHOTO_PREFERRED_MIME) {
            throw new FieldReportPhotoProcessingError("webp unavailable");
          }

          return Uint8Array.from([0xff, 0xd8, 0xff, 0xd9]);
        },
      },
    );

    expect(processed.mimeType).toBe(FIELD_REPORT_PHOTO_FALLBACK_MIME);
  });

  it("reduces quality until the output is at most 5 MB", async () => {
    let calls = 0;
    const processed = await processFieldReportPhoto(
      Uint8Array.from([0x01]),
      "image/jpeg",
      {
        generateId: () => "photo-3",
        now: () => new Date("2027-07-04T13:22:10.000Z"),
        digestSha256: async () => "checksum-3",
        decodeImage: async () => decoded(100, 100),
        encodeImage: async (_source, _w, _h, _mime, quality) => {
          calls += 1;
          if (quality > 0.5) {
            return new Uint8Array(FIELD_REPORT_PHOTO_MAX_BYTES + 10);
          }

          return new Uint8Array(1024);
        },
      },
    );

    expect(calls).toBeGreaterThan(1);
    expect(processed.byteSize).toBeLessThanOrEqual(FIELD_REPORT_PHOTO_MAX_BYTES);
  });

  it("fails when compression cannot meet the 5 MB cap", async () => {
    await expect(
      processFieldReportPhoto(Uint8Array.from([0x01]), "image/jpeg", {
        decodeImage: async () => decoded(100, 100),
        encodeImage: async () => new Uint8Array(FIELD_REPORT_PHOTO_MAX_BYTES + 1),
      }),
    ).rejects.toThrow(/5/);
  });
});
