// Shared Field Report runtime for author surfaces (M9.4 / M9.8).
//
// Wires the M9.2 pending outbox and the M9.4 author catalog so submit, list,
// and detail share one device state. Catalog contents are hydrated from and
// written to a temporary localStorage seam so refresh keeps author submissions
// visible. Pending Field Report photos use durable encrypted storage (M9.8).
// PowerSync transport remains a later Alpha 1 sync task.

import { ref } from "vue";

import { AuthorFieldReportCatalog } from "@/field-reports/authorFieldReportCatalog";
import { fieldReportLocalStore } from "@/field-reports/fieldReportLocalStore";
import { FIELD_REPORT_PENDING_SYNC } from "@/field-reports/offlineFieldReport";
import {
  clearPendingFieldReportPhotos,
  hydratePendingFieldReportPhotos,
} from "@/field-reports/pendingFieldReportPhotos";
import { PendingFieldReportQueue } from "@/field-reports/pendingFieldReportQueue";

export const pendingFieldReportQueue = new PendingFieldReportQueue();
export const authorFieldReportCatalog = new AuthorFieldReportCatalog();

/**
 * Vue dependency for author list/detail surfaces. Incremented whenever the
 * catalog changes so computed views re-read the in-memory Map.
 */
export const fieldReportCatalogRevision = ref(0);

/** Vue dependency for pending photo upload state on detail surfaces. */
export const fieldReportPhotoRevision = ref(0);

function hydrateFromLocalStore(): void {
  const reports = fieldReportLocalStore.load();
  authorFieldReportCatalog.replaceAll(reports);

  pendingFieldReportQueue.clear();
  for (const report of reports) {
    if (report.syncStatus === FIELD_REPORT_PENDING_SYNC) {
      pendingFieldReportQueue.enqueue(report);
    }
  }
}

hydrateFromLocalStore();
void hydratePendingFieldReportPhotos();

export function persistFieldReportRuntime(): void {
  fieldReportLocalStore.save(authorFieldReportCatalog.snapshot());
}

/** Re-read catalog/outbox from local storage (simulates a page refresh). */
export function reloadFieldReportRuntimeFromLocalStore(): void {
  hydrateFromLocalStore();
  fieldReportCatalogRevision.value += 1;
}

export function bumpFieldReportCatalogRevision(): void {
  persistFieldReportRuntime();
  fieldReportCatalogRevision.value += 1;
}

export function bumpFieldReportPhotoRevision(): void {
  fieldReportPhotoRevision.value += 1;
}

/** Reset runtime state between tests. */
export async function resetFieldReportRuntime(): Promise<void> {
  pendingFieldReportQueue.clear();
  authorFieldReportCatalog.clear();
  fieldReportLocalStore.clear();
  await clearPendingFieldReportPhotos();
  fieldReportCatalogRevision.value = 0;
  fieldReportPhotoRevision.value = 0;
}
