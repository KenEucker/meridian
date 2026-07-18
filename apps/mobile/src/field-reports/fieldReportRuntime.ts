// Shared Field Report runtime for author surfaces (M9.4).
//
// Wires the M9.2 pending outbox and the M9.4 author catalog so submit, list,
// and detail share one device state. Catalog contents are hydrated from and
// written to a temporary localStorage seam so refresh keeps author submissions
// visible. Durable encrypted local storage and PowerSync transport remain
// later Alpha 1 tasks.

import { ref } from "vue";

import { AuthorFieldReportCatalog } from "@/field-reports/authorFieldReportCatalog";
import { fieldReportLocalStore } from "@/field-reports/fieldReportLocalStore";
import { FIELD_REPORT_PENDING_SYNC } from "@/field-reports/offlineFieldReport";
import { PendingFieldReportQueue } from "@/field-reports/pendingFieldReportQueue";

export const pendingFieldReportQueue = new PendingFieldReportQueue();
export const authorFieldReportCatalog = new AuthorFieldReportCatalog();

/**
 * Vue dependency for author list/detail surfaces. Incremented whenever the
 * catalog changes so computed views re-read the in-memory Map.
 */
export const fieldReportCatalogRevision = ref(0);

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

/** Reset runtime state between tests. */
export function resetFieldReportRuntime(): void {
  pendingFieldReportQueue.clear();
  authorFieldReportCatalog.clear();
  fieldReportLocalStore.clear();
  fieldReportCatalogRevision.value = 0;
}
