// Pending processed Field Report photos awaiting upload sync (M9.7 / M9.8).
//
// Technical spec 18.2: photo sync is separate from the text record; a report
// may be locally submitted while photos are still pending upload. M9.8 owns
// durable encrypted storage and the upload transport seam. This module keeps
// processed blobs associated with a report id, persists them encrypted, and
// exposes sync-status updates as uploads succeed or fail.

import type { ProcessedFieldReportPhoto } from "@/field-reports/fieldReportPhotoProcessor";
import {
  fieldReportPhotoStore,
  type FieldReportPhotoStore,
  type PendingFieldReportPhotoRecord,
  type PendingFieldReportPhotoSyncStatus,
} from "@/field-reports/pendingFieldReportPhotoStore";

const pendingByPhotoId = new Map<string, PendingFieldReportPhotoRecord>();
let hydrated = false;
let store: FieldReportPhotoStore = fieldReportPhotoStore;

function cloneRecord(
  record: PendingFieldReportPhotoRecord,
): PendingFieldReportPhotoRecord {
  return {
    fieldReportId: record.fieldReportId,
    syncStatus: record.syncStatus,
    lastError: record.lastError,
    photo: Object.freeze({
      ...record.photo,
      bytes: record.photo.bytes.slice(),
    }),
  };
}

async function ensureHydrated(): Promise<void> {
  if (hydrated) {
    return;
  }

  const loaded = await store.load();
  pendingByPhotoId.clear();
  for (const record of loaded) {
    pendingByPhotoId.set(record.photo.id, cloneRecord(record));
  }
  hydrated = true;
}

async function persist(): Promise<void> {
  await store.save(Array.from(pendingByPhotoId.values()).map(cloneRecord));
}

/** Test helper: swap the durable store backend and reset memory. */
export function configurePendingFieldReportPhotoStore(
  nextStore: FieldReportPhotoStore,
): void {
  store = nextStore;
  pendingByPhotoId.clear();
  hydrated = false;
}

export async function hydratePendingFieldReportPhotos(): Promise<void> {
  hydrated = false;
  await ensureHydrated();
}

export async function attachPendingFieldReportPhotos(
  fieldReportId: string,
  photos: readonly ProcessedFieldReportPhoto[],
): Promise<void> {
  await ensureHydrated();

  for (const [photoId, record] of [...pendingByPhotoId.entries()]) {
    if (record.fieldReportId === fieldReportId) {
      pendingByPhotoId.delete(photoId);
    }
  }

  for (const photo of photos) {
    pendingByPhotoId.set(photo.id, {
      fieldReportId,
      syncStatus: "pending_upload",
      lastError: null,
      photo: Object.freeze({ ...photo, bytes: photo.bytes.slice() }),
    });
  }

  await persist();
}

export async function getPendingFieldReportPhotos(
  fieldReportId: string,
): Promise<readonly ProcessedFieldReportPhoto[]> {
  await ensureHydrated();

  return Array.from(pendingByPhotoId.values())
    .filter(
      (record) =>
        record.fieldReportId === fieldReportId &&
        record.syncStatus !== "uploaded",
    )
    .map((record) => cloneRecord(record).photo);
}

export async function listPendingFieldReportPhotoRecords(
  fieldReportId?: string,
): Promise<readonly PendingFieldReportPhotoRecord[]> {
  await ensureHydrated();

  return Array.from(pendingByPhotoId.values())
    .filter((record) =>
      fieldReportId === undefined
        ? true
        : record.fieldReportId === fieldReportId,
    )
    .map(cloneRecord);
}

export async function markPendingFieldReportPhotoStatus(
  photoId: string,
  syncStatus: PendingFieldReportPhotoSyncStatus,
  lastError: string | null = null,
): Promise<void> {
  await ensureHydrated();
  const existing = pendingByPhotoId.get(photoId);
  if (!existing) {
    return;
  }

  // Keep uploaded blobs locally for author detail previews. Photos do not sync
  // back down from the server (technical spec 18.5 / 18.6).
  pendingByPhotoId.set(photoId, {
    ...existing,
    syncStatus,
    lastError: syncStatus === "uploaded" ? null : lastError,
  });

  await persist();
}

export async function clearPendingFieldReportPhotos(
  fieldReportId?: string,
): Promise<void> {
  await ensureHydrated();

  if (typeof fieldReportId === "string") {
    for (const [photoId, record] of [...pendingByPhotoId.entries()]) {
      if (record.fieldReportId === fieldReportId) {
        pendingByPhotoId.delete(photoId);
      }
    }
    await persist();
    return;
  }

  pendingByPhotoId.clear();
  await store.clear();
  hydrated = true;
}

export async function pendingFieldReportPhotoReportIds(): Promise<string[]> {
  await ensureHydrated();
  return Array.from(
    new Set(
      Array.from(pendingByPhotoId.values()).map(
        (record) => record.fieldReportId,
      ),
    ),
  );
}
