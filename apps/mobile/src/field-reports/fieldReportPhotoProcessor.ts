// Field Report photo capture processing (M9.7).
//
// Technical spec 18.3 / data/API 10.17 / UI contract 14.3:
// - reject GIFs
// - accept common phone still formats where the runtime can decode them
// - fit within 2560 × 1900
// - compress to ≤ 5 MB
// - strip EXIF (including GPS) by re-encoding pixels
// - convert multi-image phone photos to a single bitmap
// - do not keep the original full-resolution bytes after processing
//
// Preferred stored format is WebP (technical spec 18.5 filename example), with
// JPEG fallback when WebP encoding is unavailable. Sync/storage is M9.8.

import {
  isGifBytes,
  isGifMime,
} from "@/field-reports/fieldReportPhotoDetect";
import { fitFieldReportPhotoDimensions } from "@/field-reports/fieldReportPhotoFit";
import {
  FIELD_REPORT_PHOTO_FALLBACK_MIME,
  FIELD_REPORT_PHOTO_MAX_BYTES,
  FIELD_REPORT_PHOTO_PREFERRED_MIME,
  type FieldReportPhotoStoredMime,
} from "@/field-reports/fieldReportPhotoLimits";

export class FieldReportPhotoProcessingError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "FieldReportPhotoProcessingError";
  }
}

export interface ProcessedFieldReportPhoto {
  readonly id: string;
  readonly mimeType: FieldReportPhotoStoredMime;
  readonly byteSize: number;
  readonly width: number;
  readonly height: number;
  readonly bytes: Uint8Array;
  readonly checksumSha256: string;
  readonly processedAt: string;
}

export interface DecodedFieldReportPhoto {
  readonly width: number;
  readonly height: number;
  readonly draw: (
    context: CanvasRenderingContext2D,
    width: number,
    height: number,
  ) => void;
  readonly close?: () => void;
}

export interface FieldReportPhotoProcessorDependencies {
  readonly generateId?: () => string;
  readonly now?: () => Date;
  readonly decodeImage?: (bytes: Uint8Array, mimeType: string) => Promise<DecodedFieldReportPhoto>;
  readonly encodeImage?: (
    source: DecodedFieldReportPhoto,
    width: number,
    height: number,
    mimeType: FieldReportPhotoStoredMime,
    quality: number,
  ) => Promise<Uint8Array>;
  readonly digestSha256?: (bytes: Uint8Array) => Promise<string>;
  readonly preferWebp?: boolean;
}

const QUALITY_STEPS = [0.92, 0.84, 0.76, 0.68, 0.6, 0.52, 0.44, 0.36, 0.28, 0.2];

function defaultGenerateId(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  if (typeof cryptoScope?.randomUUID !== "function") {
    throw new FieldReportPhotoProcessingError(
      "A UUID generator is unavailable; inject `generateId` to process a Field Report photo.",
    );
  }

  return cryptoScope.randomUUID();
}

async function defaultDigestSha256(bytes: Uint8Array): Promise<string> {
  const cryptoScope = (
    globalThis as {
      crypto?: {
        subtle?: { digest: (algorithm: string, data: BufferSource) => Promise<ArrayBuffer> };
      };
    }
  ).crypto;

  if (typeof cryptoScope?.subtle?.digest !== "function") {
    throw new FieldReportPhotoProcessingError(
      "SubtleCrypto digest is unavailable; inject `digestSha256` to checksum a Field Report photo.",
    );
  }

  const digest = await cryptoScope.subtle.digest("SHA-256", toArrayBuffer(bytes));
  return Array.from(new Uint8Array(digest))
    .map((value) => value.toString(16).padStart(2, "0"))
    .join("");
}

function toArrayBuffer(bytes: Uint8Array): ArrayBuffer {
  return bytes.buffer.slice(
    bytes.byteOffset,
    bytes.byteOffset + bytes.byteLength,
  ) as ArrayBuffer;
}

function toBlobPart(bytes: Uint8Array): BlobPart {
  return toArrayBuffer(bytes);
}

function createCanvas(
  width: number,
  height: number,
): {
  canvas: HTMLCanvasElement | OffscreenCanvas;
  context: CanvasRenderingContext2D | OffscreenCanvasRenderingContext2D;
} {
  if (typeof OffscreenCanvas === "function") {
    const canvas = new OffscreenCanvas(width, height);
    const context = canvas.getContext("2d");
    if (!context) {
      throw new FieldReportPhotoProcessingError(
        "Unable to allocate an OffscreenCanvas context for Field Report photo processing.",
      );
    }

    return { canvas, context };
  }

  if (typeof document?.createElement === "function") {
    const canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    const context = canvas.getContext("2d");
    if (!context) {
      throw new FieldReportPhotoProcessingError(
        "Unable to allocate a canvas context for Field Report photo processing.",
      );
    }

    return { canvas, context };
  }

  throw new FieldReportPhotoProcessingError(
    "Canvas encoding is unavailable in this runtime; inject `encodeImage`.",
  );
}

async function canvasToBytes(
  canvas: HTMLCanvasElement | OffscreenCanvas,
  mimeType: FieldReportPhotoStoredMime,
  quality: number,
): Promise<Uint8Array> {
  if ("convertToBlob" in canvas && typeof canvas.convertToBlob === "function") {
    const blob = await canvas.convertToBlob({ type: mimeType, quality });
    return new Uint8Array(await blob.arrayBuffer());
  }

  const htmlCanvas = canvas as HTMLCanvasElement;
  if (typeof htmlCanvas.toBlob !== "function") {
    throw new FieldReportPhotoProcessingError(
      "Canvas toBlob is unavailable; inject `encodeImage`.",
    );
  }

  const blob = await new Promise<Blob>((resolve, reject) => {
    htmlCanvas.toBlob(
      (result) => {
        if (!result) {
          reject(
            new FieldReportPhotoProcessingError(
              `Unable to encode Field Report photo as ${mimeType}.`,
            ),
          );
          return;
        }

        resolve(result);
      },
      mimeType,
      quality,
    );
  });

  return new Uint8Array(await blob.arrayBuffer());
}

async function defaultEncodeImage(
  source: DecodedFieldReportPhoto,
  width: number,
  height: number,
  mimeType: FieldReportPhotoStoredMime,
  quality: number,
): Promise<Uint8Array> {
  const { canvas, context } = createCanvas(width, height);
  source.draw(context as CanvasRenderingContext2D, width, height);
  return canvasToBytes(canvas, mimeType, quality);
}

async function defaultDecodeImage(
  bytes: Uint8Array,
  mimeType: string,
): Promise<DecodedFieldReportPhoto> {
  if (typeof createImageBitmap === "function") {
    const blob = new Blob([toBlobPart(bytes)], {
      type: mimeType || "application/octet-stream",
    });
    let bitmap: ImageBitmap;
    try {
      bitmap = await createImageBitmap(blob);
    } catch {
      throw new FieldReportPhotoProcessingError(
        "Unable to decode this image. Use a JPEG, PNG, or WebP photo.",
      );
    }

    return {
      width: bitmap.width,
      height: bitmap.height,
      draw(context, width, height) {
        context.drawImage(bitmap, 0, 0, width, height);
      },
      close() {
        bitmap.close();
      },
    };
  }

  if (typeof Image === "function" && typeof URL?.createObjectURL === "function") {
    const blob = new Blob([toBlobPart(bytes)], {
      type: mimeType || "application/octet-stream",
    });
    const objectUrl = URL.createObjectURL(blob);

    try {
      const image = await new Promise<HTMLImageElement>((resolve, reject) => {
        const element = new Image();
        element.onload = () => resolve(element);
        element.onerror = () =>
          reject(
            new FieldReportPhotoProcessingError(
              "Unable to decode this image. Use a JPEG, PNG, or WebP photo.",
            ),
          );
        element.src = objectUrl;
      });

      return {
        width: image.naturalWidth || image.width,
        height: image.naturalHeight || image.height,
        draw(context, width, height) {
          context.drawImage(image, 0, 0, width, height);
        },
      };
    } finally {
      URL.revokeObjectURL(objectUrl);
    }
  }

  throw new FieldReportPhotoProcessingError(
    "Image decoding is unavailable in this runtime; inject `decodeImage`.",
  );
}

async function encodeWithinBudget(
  source: DecodedFieldReportPhoto,
  width: number,
  height: number,
  mimeType: FieldReportPhotoStoredMime,
  encodeImage: NonNullable<FieldReportPhotoProcessorDependencies["encodeImage"]>,
): Promise<Uint8Array> {
  let last: Uint8Array | null = null;

  for (const quality of QUALITY_STEPS) {
    const encoded = await encodeImage(source, width, height, mimeType, quality);
    last = encoded;
    if (encoded.byteLength <= FIELD_REPORT_PHOTO_MAX_BYTES) {
      return encoded;
    }
  }

  if (last && last.byteLength <= FIELD_REPORT_PHOTO_MAX_BYTES) {
    return last;
  }

  throw new FieldReportPhotoProcessingError(
    `Unable to compress the photo under ${FIELD_REPORT_PHOTO_MAX_BYTES} bytes.`,
  );
}

/**
 * Process a captured Field Report photo into Meridian's Alpha 1 stored limits.
 * Returns only the processed bytes; callers must not retain the source File /
 * Blob after a successful process when building the pending upload set.
 */
export async function processFieldReportPhoto(
  source: Blob | ArrayBuffer | Uint8Array,
  declaredMimeType: string | null | undefined = null,
  dependencies: FieldReportPhotoProcessorDependencies = {},
): Promise<ProcessedFieldReportPhoto> {
  const bytes =
    source instanceof Uint8Array
      ? source
      : source instanceof ArrayBuffer
        ? new Uint8Array(source)
        : new Uint8Array(await source.arrayBuffer());

  const mimeFromBlob =
    typeof Blob !== "undefined" && source instanceof Blob ? source.type : "";
  const mimeType = (declaredMimeType || mimeFromBlob || "").trim().toLowerCase();

  if (isGifMime(mimeType) || isGifBytes(bytes)) {
    throw new FieldReportPhotoProcessingError(
      "GIF images are not supported for Field Report photos.",
    );
  }

  if (bytes.byteLength === 0) {
    throw new FieldReportPhotoProcessingError("Field Report photo bytes are empty.");
  }

  const decodeImage = dependencies.decodeImage ?? defaultDecodeImage;
  const encodeImage = dependencies.encodeImage ?? defaultEncodeImage;
  const digestSha256 = dependencies.digestSha256 ?? defaultDigestSha256;
  const generateId = dependencies.generateId ?? defaultGenerateId;
  const now = dependencies.now ?? (() => new Date());
  const preferWebp = dependencies.preferWebp ?? true;

  const decoded = await decodeImage(bytes, mimeType || "application/octet-stream");

  try {
    const fitted = fitFieldReportPhotoDimensions({
      width: decoded.width,
      height: decoded.height,
    });

    const mimeCandidates: FieldReportPhotoStoredMime[] = preferWebp
      ? [FIELD_REPORT_PHOTO_PREFERRED_MIME, FIELD_REPORT_PHOTO_FALLBACK_MIME]
      : [FIELD_REPORT_PHOTO_FALLBACK_MIME, FIELD_REPORT_PHOTO_PREFERRED_MIME];

    let processedBytes: Uint8Array | null = null;
    let storedMime: FieldReportPhotoStoredMime | null = null;
    let lastError: unknown = null;

    for (const candidate of mimeCandidates) {
      try {
        processedBytes = await encodeWithinBudget(
          decoded,
          fitted.width,
          fitted.height,
          candidate,
          encodeImage,
        );
        storedMime = candidate;
        break;
      } catch (error) {
        lastError = error;
      }
    }

    if (!processedBytes || !storedMime) {
      if (lastError instanceof FieldReportPhotoProcessingError) {
        throw lastError;
      }

      throw new FieldReportPhotoProcessingError(
        "Unable to encode the Field Report photo into a supported stored format.",
      );
    }

    return Object.freeze({
      id: generateId(),
      mimeType: storedMime,
      byteSize: processedBytes.byteLength,
      width: fitted.width,
      height: fitted.height,
      bytes: processedBytes,
      checksumSha256: await digestSha256(processedBytes),
      processedAt: now().toISOString(),
    });
  } finally {
    decoded.close?.();
  }
}

/**
 * Convenience wrapper for `<input type="file">` / gallery picks.
 * Processes each file independently; callers enforce the max-2 slot budget.
 */
export async function processFieldReportPhotoFile(
  file: File,
  dependencies: FieldReportPhotoProcessorDependencies = {},
): Promise<ProcessedFieldReportPhoto> {
  return processFieldReportPhoto(file, file.type, dependencies);
}
