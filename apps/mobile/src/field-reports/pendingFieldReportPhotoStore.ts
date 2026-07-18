// Durable encrypted local storage for pending Field Report photos (M9.8).
//
// Technical spec 18.2: photos are stored locally encrypted until synced.
// This store keeps processed blobs out of the text outbox and survives refresh
// via localStorage (AES-GCM when Web Crypto is available; opaque base64
// ciphertext envelope otherwise falls back to a clearly marked plaintext
// envelope for test environments without SubtleCrypto).

import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";

export const FIELD_REPORT_PHOTO_STORE_KEY =
  "meridian.field-reports.pending-photos.v1";

export const FIELD_REPORT_PHOTO_KEY_STORE_KEY =
  "meridian.field-reports.photo-encryption-key.v1";

export type PendingFieldReportPhotoSyncStatus =
  | "pending_upload"
  | "uploaded"
  | "failed";

export interface PendingFieldReportPhotoRecord {
  readonly photo: ProcessedFieldReportPhoto;
  readonly fieldReportId: string;
  readonly syncStatus: PendingFieldReportPhotoSyncStatus;
  readonly lastError: string | null;
}

interface StoredEnvelopeV1 {
  readonly version: 1;
  readonly algorithm: "aes-gcm" | "plaintext-test";
  readonly iv?: string;
  readonly ciphertext: string;
}

function bytesToBase64(bytes: Uint8Array): string {
  let binary = "";
  for (const byte of bytes) {
    binary += String.fromCharCode(byte);
  }
  return btoa(binary);
}

function base64ToBytes(value: string): Uint8Array {
  const binary = atob(value);
  const bytes = new Uint8Array(binary.length);
  for (let index = 0; index < binary.length; index += 1) {
    bytes[index] = binary.charCodeAt(index);
  }
  return bytes;
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

function serializeRecords(
  records: readonly PendingFieldReportPhotoRecord[],
): string {
  return JSON.stringify(
    records.map((record) => ({
      fieldReportId: record.fieldReportId,
      syncStatus: record.syncStatus,
      lastError: record.lastError,
      photo: {
        id: record.photo.id,
        mimeType: record.photo.mimeType,
        byteSize: record.photo.byteSize,
        width: record.photo.width,
        height: record.photo.height,
        checksumSha256: record.photo.checksumSha256,
        processedAt: record.photo.processedAt,
        bytesBase64: bytesToBase64(record.photo.bytes),
      },
    })),
  );
}

function parseRecords(raw: string): PendingFieldReportPhotoRecord[] {
  const parsed = JSON.parse(raw) as unknown;
  if (!Array.isArray(parsed)) {
    return [];
  }

  const records: PendingFieldReportPhotoRecord[] = [];
  for (const entry of parsed) {
    if (typeof entry !== "object" || entry === null) {
      continue;
    }
    const row = entry as Record<string, unknown>;
    const photo = row.photo as Record<string, unknown> | undefined;
    if (
      typeof row.fieldReportId !== "string" ||
      (row.syncStatus !== "pending_upload" &&
        row.syncStatus !== "uploaded" &&
        row.syncStatus !== "failed") ||
      !photo ||
      typeof photo.id !== "string" ||
      typeof photo.mimeType !== "string" ||
      typeof photo.byteSize !== "number" ||
      typeof photo.width !== "number" ||
      typeof photo.height !== "number" ||
      typeof photo.checksumSha256 !== "string" ||
      typeof photo.processedAt !== "string" ||
      typeof photo.bytesBase64 !== "string"
    ) {
      continue;
    }

    records.push({
      fieldReportId: row.fieldReportId,
      syncStatus: row.syncStatus,
      lastError: typeof row.lastError === "string" ? row.lastError : null,
      photo: Object.freeze({
        id: photo.id,
        mimeType: photo.mimeType as ProcessedFieldReportPhoto["mimeType"],
        byteSize: photo.byteSize,
        width: photo.width,
        height: photo.height,
        checksumSha256: photo.checksumSha256,
        processedAt: photo.processedAt,
        bytes: base64ToBytes(photo.bytesBase64),
      }),
    });
  }

  return records;
}

async function getOrCreateAesKey(storage: Storage): Promise<CryptoKey | null> {
  const subtle = globalThis.crypto?.subtle;
  if (!subtle) {
    return null;
  }

  const existing = storage.getItem(FIELD_REPORT_PHOTO_KEY_STORE_KEY);
  if (existing) {
    try {
      const raw = base64ToBytes(existing);
      return await subtle.importKey("raw", raw, "AES-GCM", false, [
        "encrypt",
        "decrypt",
      ]);
    } catch {
      storage.removeItem(FIELD_REPORT_PHOTO_KEY_STORE_KEY);
    }
  }

  const key = await subtle.generateKey({ name: "AES-GCM", length: 256 }, true, [
    "encrypt",
    "decrypt",
  ]);
  const exported = new Uint8Array(await subtle.exportKey("raw", key));
  storage.setItem(FIELD_REPORT_PHOTO_KEY_STORE_KEY, bytesToBase64(exported));
  return key;
}

async function encryptPayload(
  storage: Storage,
  plaintext: string,
): Promise<StoredEnvelopeV1> {
  const subtle = globalThis.crypto?.subtle;
  const key = subtle ? await getOrCreateAesKey(storage) : null;
  if (!subtle || !key) {
    return {
      version: 1,
      algorithm: "plaintext-test",
      ciphertext: bytesToBase64(new TextEncoder().encode(plaintext)),
    };
  }

  const iv = globalThis.crypto.getRandomValues(new Uint8Array(12));
  const encrypted = await subtle.encrypt(
    { name: "AES-GCM", iv },
    key,
    new TextEncoder().encode(plaintext),
  );

  return {
    version: 1,
    algorithm: "aes-gcm",
    iv: bytesToBase64(iv),
    ciphertext: bytesToBase64(new Uint8Array(encrypted)),
  };
}

async function decryptPayload(
  storage: Storage,
  envelope: StoredEnvelopeV1,
): Promise<string> {
  if (envelope.algorithm === "plaintext-test") {
    return new TextDecoder().decode(base64ToBytes(envelope.ciphertext));
  }

  const subtle = globalThis.crypto?.subtle;
  const key = subtle ? await getOrCreateAesKey(storage) : null;
  if (!subtle || !key || !envelope.iv) {
    throw new Error("Unable to decrypt Field Report photo store.");
  }

  const decrypted = await subtle.decrypt(
    { name: "AES-GCM", iv: base64ToBytes(envelope.iv) },
    key,
    base64ToBytes(envelope.ciphertext),
  );

  return new TextDecoder().decode(decrypted);
}

export interface FieldReportPhotoStore {
  readonly load: () => Promise<PendingFieldReportPhotoRecord[]>;
  readonly save: (
    records: readonly PendingFieldReportPhotoRecord[],
  ) => Promise<void>;
  readonly clear: () => Promise<void>;
}

/** Create a durable encrypted photo store, or an in-memory fallback. */
export function createFieldReportPhotoStore(
  storage: Storage | null = readStorage(),
): FieldReportPhotoStore {
  let memoryFallback: PendingFieldReportPhotoRecord[] = [];

  return {
    async load(): Promise<PendingFieldReportPhotoRecord[]> {
      if (!storage) {
        return memoryFallback.map((record) => ({
          ...record,
          photo: Object.freeze({
            ...record.photo,
            bytes: record.photo.bytes.slice(),
          }),
        }));
      }

      const raw = storage.getItem(FIELD_REPORT_PHOTO_STORE_KEY);
      if (!raw) {
        return [];
      }

      try {
        const envelope = JSON.parse(raw) as StoredEnvelopeV1;
        if (envelope.version !== 1 || typeof envelope.ciphertext !== "string") {
          return [];
        }
        return parseRecords(await decryptPayload(storage, envelope));
      } catch {
        return [];
      }
    },

    async save(records: readonly PendingFieldReportPhotoRecord[]): Promise<void> {
      const snapshot = records.map((record) => ({
        ...record,
        photo: Object.freeze({
          ...record.photo,
          bytes: record.photo.bytes.slice(),
        }),
      }));

      if (!storage) {
        memoryFallback = snapshot;
        return;
      }

      const envelope = await encryptPayload(storage, serializeRecords(snapshot));
      storage.setItem(FIELD_REPORT_PHOTO_STORE_KEY, JSON.stringify(envelope));
    },

    async clear(): Promise<void> {
      memoryFallback = [];
      storage?.removeItem(FIELD_REPORT_PHOTO_STORE_KEY);
    },
  };
}

export const fieldReportPhotoStore = createFieldReportPhotoStore();
