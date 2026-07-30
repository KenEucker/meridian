// Shared Field Report runtime for author surfaces (M9.4 / M9.8 / M16.10).
//
// Holds the M9.4 author catalog so submit, list, and detail share one device
// state. Catalog contents are hydrated from and written to a temporary
// localStorage seam so refresh keeps author submissions visible. Pending Field
// Report photos use durable encrypted storage (M9.8). PowerSync transport
// remains a later Alpha 1 sync task.
//
// Since M16.10 the catalog is display state and nothing more: what is still owed
// to the node lives in the shared command outbox, which each report's device
// UUID keys (technical spec 11A.5). The catalog's `sync_status` says how a report
// should read on screen; the outbox says what is still to be sent.

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
  authorFieldReportCatalog.clear();
  fieldReportLocalStore.clear();
  await clearPendingFieldReportPhotos();
  fieldReportCatalogRevision.value = 0;
  fieldReportPhotoRevision.value = 0;
}
