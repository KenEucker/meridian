// Shared Field Report runtime for author surfaces (M9.4 / M9.8 / M16.10).
//
// Holds the M9.4 author catalog so submit, list, and detail share one device
// state. Catalog contents are hydrated from and written to a temporary
// localStorage seam so refresh keeps author submissions visible. Pending Field
// Report photos use durable encrypted storage (M9.8).
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
import { fieldReportCommandPayload } from "@/field-reports/submitFieldReportCommand";
import { commandOutbox } from "@/outbox/commandOutboxRuntime";
import { queueCommand } from "@/outbox/submitCommand";

export const authorFieldReportCatalog = new AuthorFieldReportCatalog();

/**
 * Vue dependency for author list/detail surfaces. Incremented whenever the
 * catalog changes so computed views re-read the in-memory Map.
 */
export const fieldReportCatalogRevision = ref(0);

/** Vue dependency for pending photo upload state on detail surfaces. */
export const fieldReportPhotoRevision = ref(0);

/**
 * Make sure every report the catalog shows as pending has a command to send it
 * (M16.10).
 *
 * The catalog and the outbox are two durable stores, and a report that says
 * "Queued locally" while nothing is queued is the worst way for them to
 * disagree: the author is told their work is waiting to sync, and it will wait
 * forever. It is silent, and the only copy of what they wrote is on this device.
 *
 * Two ways to arrive there, one of them certain:
 *
 *  - Reports written before the outbox existed. The queue this replaced was
 *    rebuilt from the catalog on every hydrate, so it never needed its own
 *    durable copy and never had one. Anything already pending when a device
 *    updates has a catalog entry and no command.
 *  - The two stores drifting later — one write landing and the other not,
 *    storage evicted, a partial clear.
 *
 * So reconciliation runs on every install rather than as a one-off migration.
 * It only ever adds: a report whose command is already held is skipped by key,
 * which means an accepted command is not re-sent and — the case that matters —
 * a rejected one is not quietly resurrected behind the user's back.
 */
function reconcilePendingCommands(reports: readonly OfflineFieldReport[]): void {
  for (const report of reports) {
    if (
      report.syncStatus !== FIELD_REPORT_PENDING_SYNC ||
      commandOutbox.has(report.id)
    ) {
      continue;
    }

    queueCommand({
      commandType: "submit-field-report",
      idempotencyKey: report.id,
      payload: fieldReportCommandPayload(report),
      eventId: report.eventId,
      detail: report.temporaryLocalNumber,
    });
  }
}

function installReports(reports: readonly OfflineFieldReport[]): void {
  authorFieldReportCatalog.replaceAll(reports);
  reconcilePendingCommands(reports);
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
