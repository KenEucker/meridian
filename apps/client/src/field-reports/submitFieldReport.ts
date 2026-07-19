// Finalize a Field Report submission for the author surfaces (M9.4).
//
// UI contract 14.1: on Submit the Field Report is finalized (no drafts).
// Technical spec 17.2: offline-created reports look submitted immediately even
// while pending sync. This orchestrates the M9.2 create + outbox enqueue and
// records the report in the author catalog so list/detail can show it.

import {
  AuthorFieldReportCatalog,
  type FieldReportAcceptanceUpdate,
} from "@/field-reports/authorFieldReportCatalog";
import {
  authorFieldReportCatalog,
  bumpFieldReportCatalogRevision,
  pendingFieldReportQueue,
} from "@/field-reports/fieldReportRuntime";
import {
  createOfflineFieldReport,
  type CreateOfflineFieldReportInput,
  type OfflineFieldReport,
  type OfflineFieldReportDependencies,
} from "@/field-reports/offlineFieldReport";
import { PendingFieldReportQueue } from "@/field-reports/pendingFieldReportQueue";

export interface SubmitFieldReportDependencies extends OfflineFieldReportDependencies {
  readonly queue?: PendingFieldReportQueue;
  readonly catalog?: AuthorFieldReportCatalog;
  readonly notifyCatalogChanged?: () => void;
}

/**
 * Create a finalized offline Field Report, enqueue it for sync, and record it
 * in the author catalog. Idempotent on UUID: a retried submit with the same
 * generated id does not duplicate catalog or queue entries.
 */
export function submitFieldReport(
  input: CreateOfflineFieldReportInput,
  dependencies: SubmitFieldReportDependencies = {},
): OfflineFieldReport {
  const queue = dependencies.queue ?? pendingFieldReportQueue;
  const catalog = dependencies.catalog ?? authorFieldReportCatalog;
  const notify =
    dependencies.notifyCatalogChanged ?? bumpFieldReportCatalogRevision;

  const report = createOfflineFieldReport(input, {
    generateId: dependencies.generateId,
    now: dependencies.now,
  });

  queue.enqueue(report);
  catalog.recordSubmitted(report);
  notify();

  return report;
}

/**
 * Apply server acceptance to local author state: update the catalog display
 * identity and drain the pending outbox (technical spec 17.5).
 */
export function applyLocalFieldReportAcceptance(
  id: string,
  acceptance: FieldReportAcceptanceUpdate,
  dependencies: SubmitFieldReportDependencies = {},
): OfflineFieldReport {
  const queue = dependencies.queue ?? pendingFieldReportQueue;
  const catalog = dependencies.catalog ?? authorFieldReportCatalog;
  const notify =
    dependencies.notifyCatalogChanged ?? bumpFieldReportCatalogRevision;

  const accepted = catalog.recordAcceptance(id, acceptance);
  queue.markSynced(id);
  notify();

  return accepted;
}
