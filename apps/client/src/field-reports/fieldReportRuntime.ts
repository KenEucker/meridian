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
import {
  FIELD_REPORT_PENDING_SYNC,
  type OfflineFieldReport,
} from "@/field-reports/offlineFieldReport";
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

function installReports(reports: readonly OfflineFieldReport[]): void {
  authorFieldReportCatalog.replaceAll(reports);

  pendingFieldReportQueue.clear();
  for (const report of reports) {
    if (report.syncStatus === FIELD_REPORT_PENDING_SYNC) {
      pendingFieldReportQueue.enqueue(report);
    }
  }
}

function hydrateFromLocalStore(): void {
  installReports(fieldReportLocalStore.load());
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

/**
 * Drop the author catalog records that belong to an event this client has left
 * (M16.7; CLIENT-014).
 *
 * Registered as a context reset, so switching event does not leave the previous
 * event's Field Reports on the author's list. They are not lost: they are the
 * server's records, and they come back with the catalog when the device works
 * that event again.
 *
 * A report still pending sync is kept whatever event it belongs to, because it
 * is not a record from the previous context — it is unsent work this device is
 * the only copy of. Discarding it to satisfy a display rule would destroy
 * something a user typed, which is the one outcome the outbox exists to prevent
 * (technical spec 11A.5). It stays queued and stays visible as the author's own
 * pending report.
 */
export function discardFieldReportsOutsideEvent(eventId: string | null): void {
  const kept = authorFieldReportCatalog
    .snapshot()
    .filter(
      (report) =>
        report.eventId === eventId ||
        report.syncStatus === FIELD_REPORT_PENDING_SYNC,
    );

  installReports(kept);
  persistFieldReportRuntime();
  fieldReportCatalogRevision.value += 1;
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
