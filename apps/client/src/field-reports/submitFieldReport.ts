// Finalize a Field Report submission for the author surfaces (M9.4; M16.10).
//
// UI contract 14.1: on Submit the Field Report is finalized (no drafts).
// Technical spec 17.2: offline-created reports look submitted immediately even
// while pending sync. This orchestrates the M9.2 create step, records the report
// in the author catalog so list/detail can show it, and queues the
// `submit-field-report` command.
//
// The command goes into the shared outbox rather than a Field Report queue of
// its own (M16.10; technical spec 11A.5). Field Reports are a caller of that
// queue now, not the owner of one: the report's device UUID is the command's
// idempotency key, so a report submitted twice, replayed after an interrupted
// sync, or re-sent after a restart is the same command every time.

import {
  AuthorFieldReportCatalog,
  type FieldReportAcceptanceUpdate,
} from "@/field-reports/authorFieldReportCatalog";
import { fieldReportCommandPayload } from "@/field-reports/submitFieldReportCommand";
import {
  authorFieldReportCatalog,
  bumpFieldReportCatalogRevision,
} from "@/field-reports/fieldReportRuntime";
import {
  createOfflineFieldReport,
  type CreateOfflineFieldReportInput,
  type OfflineFieldReport,
  type OfflineFieldReportDependencies,
} from "@/field-reports/offlineFieldReport";
import { queueCommand } from "@/outbox/submitCommand";

export interface SubmitFieldReportDependencies extends OfflineFieldReportDependencies {
  readonly catalog?: AuthorFieldReportCatalog;
  readonly notifyCatalogChanged?: () => void;
}

/**
 * Create a finalized offline Field Report, queue its command, and record it in
 * the author catalog. Idempotent on UUID: a retried submit with the same
 * generated id does not duplicate the catalog entry or the queued command.
 */
export function submitFieldReport(
  input: CreateOfflineFieldReportInput,
  dependencies: SubmitFieldReportDependencies = {},
): OfflineFieldReport {
  const catalog = dependencies.catalog ?? authorFieldReportCatalog;
  const notify =
    dependencies.notifyCatalogChanged ?? bumpFieldReportCatalogRevision;

  const report = createOfflineFieldReport(input, {
    generateId: dependencies.generateId,
    now: dependencies.now,
  });

  queueCommand({
    commandType: "submit-field-report",
    idempotencyKey: report.id,
    payload: fieldReportCommandPayload(report),
    eventId: report.eventId,
    detail: report.temporaryLocalNumber,
  });
  catalog.recordSubmitted(report);
  notify();

  return report;
}

/**
 * Apply server acceptance to local author state: update the catalog display
 * identity (technical spec 17.5).
 *
 * The queue entry is not drained here. The outbox settles its own commands —
 * an accepted command stays visible as accepted (CLIENT-017) rather than
 * disappearing the moment it lands — so this applies the part of the acceptance
 * that belongs to the Field Report and nothing else.
 */
export function applyLocalFieldReportAcceptance(
  id: string,
  acceptance: FieldReportAcceptanceUpdate,
  dependencies: SubmitFieldReportDependencies = {},
): OfflineFieldReport {
  const catalog = dependencies.catalog ?? authorFieldReportCatalog;
  const notify =
    dependencies.notifyCatalogChanged ?? bumpFieldReportCatalogRevision;

  const accepted = catalog.recordAcceptance(id, acceptance);
  notify();

  return accepted;
}
