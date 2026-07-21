// Author append orchestration for submitted Field Reports (FR-007–FR-009).
//
// Corrections are append-only: the original title and body stay immutable.
// Only the signed-in author may append. Local catalog persistence is wired
// here; HTTP `POST /api/commands/append-field-report` transport remains later.

import { AuthorFieldReportCatalog } from "@/field-reports/authorFieldReportCatalog";
import {
  authorFieldReportCatalog,
  bumpFieldReportCatalogRevision,
} from "@/field-reports/fieldReportRuntime";
import {
  createOfflineFieldReportAppend,
  type OfflineFieldReport,
  type OfflineFieldReportAppend,
  type OfflineFieldReportDependencies,
} from "@/field-reports/offlineFieldReport";

export interface AppendFieldReportInput {
  readonly fieldReportId: string;
  readonly authorUserId: string;
  readonly body: string;
}

export interface AppendFieldReportDependencies
  extends OfflineFieldReportDependencies {
  readonly catalog?: AuthorFieldReportCatalog;
  readonly notifyCatalogChanged?: () => void;
}

/**
 * Append a correction to an author-owned Field Report and persist the catalog.
 */
export function appendFieldReport(
  input: AppendFieldReportInput,
  dependencies: AppendFieldReportDependencies = {},
): OfflineFieldReport {
  const catalog = dependencies.catalog ?? authorFieldReportCatalog;
  const notify =
    dependencies.notifyCatalogChanged ?? bumpFieldReportCatalogRevision;

  const append: OfflineFieldReportAppend = createOfflineFieldReportAppend(
    input.body,
    {
      generateId: dependencies.generateId,
      now: dependencies.now,
    },
  );

  const updated = catalog.recordAppend(
    input.fieldReportId,
    input.authorUserId,
    append,
  );
  notify();

  return updated;
}
