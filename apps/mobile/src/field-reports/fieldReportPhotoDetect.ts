// Field Report photo type detection helpers (M9.7).
//
// GIFs are unsupported (technical spec 18.3 / data/API 10.17 / UI contract 14.3).
// Detection uses magic bytes first so a spoofed MIME cannot bypass the rule.

const GIF87A = [0x47, 0x49, 0x46, 0x38, 0x37, 0x61] as const;
const GIF89A = [0x47, 0x49, 0x46, 0x38, 0x39, 0x61] as const;

function matchesMagic(
  bytes: Uint8Array,
  signature: readonly number[],
): boolean {
  if (bytes.length < signature.length) {
    return false;
  }

  return signature.every((value, index) => bytes[index] === value);
}

/** True when the byte prefix is a GIF87a or GIF89a signature. */
export function isGifBytes(bytes: Uint8Array): boolean {
  return matchesMagic(bytes, GIF87A) || matchesMagic(bytes, GIF89A);
}

/** True when a declared MIME is image/gif (case-insensitive). */
export function isGifMime(mimeType: string | null | undefined): boolean {
  return typeof mimeType === "string" && mimeType.trim().toLowerCase() === "image/gif";
}

/**
 * JPEG APP1 / EXIF marker scan. Used to verify that processed output no longer
 * carries EXIF (including GPS) after canvas / GD re-encode.
 */
export function jpegContainsExifSegment(bytes: Uint8Array): boolean {
  if (bytes.length < 4 || bytes[0] !== 0xff || bytes[1] !== 0xd8) {
    return false;
  }

  let offset = 2;
  while (offset + 4 < bytes.length) {
    if (bytes[offset] !== 0xff) {
      offset += 1;
      continue;
    }

    const marker = bytes[offset + 1];
    if (marker === 0xd9 || marker === 0xda) {
      break;
    }

    const size = (bytes[offset + 2] << 8) | bytes[offset + 3];
    if (size < 2 || offset + 2 + size > bytes.length) {
      break;
    }

    if (marker === 0xe1) {
      const payloadStart = offset + 4;
      const payload = bytes.subarray(payloadStart, offset + 2 + size);
      const header = String.fromCharCode(...payload.subarray(0, 4));
      if (header === "Exif") {
        return true;
      }
    }

    offset += 2 + size;
  }

  return false;
}
