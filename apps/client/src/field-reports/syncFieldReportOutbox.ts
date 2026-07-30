// Bring this device's Field Report work up to date (M9.8; M16.10).
//
// Two different things have to happen in order, and only one of them is a
// command. The text is a `submit-field-report` command and drains with every
// other command through the shared outbox (technical spec 11A.5). The photos are
// binary uploads against a report the server already has, so they can only go
// once the text has been accepted (technical spec 18.2).
//
// This module is what the Field Report surfaces call when they want both halves
// done and want to be told about their own work. The drain it starts is the
// whole queue's, not the Field Reports' — there is one queue — and the counts it
// reports are filtered back down to Field Report commands, because a "Retry
// sync" button on a Field Report should not report on somebody's check-ins.

import {
  authorFieldReportCatalog,
  bumpFieldReportCatalogRevision,
  bumpFieldReportPhotoRevision,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import { FIELD_REPORT_ACCEPTED } from "@/field-reports/offlineFieldReport";
import { pendingFieldReportPhotoReportIds } from "@/field-reports/pendingFieldReportPhotos";
import { syncPendingFieldReportPhotos } from "@/field-reports/syncFieldReportPhotos";
import { uploadFieldReportPhoto } from "@/field-reports/uploadFieldReportPhoto";
import { syncCommandOutbox } from "@/outbox/syncCommandOutbox";

export interface SyncFieldReportOutboxResult {
  readonly textAttempted: number;
  readonly textAccepted: number;
  readonly textFailed: number;
  readonly photosAttempted: number;
  readonly photosUploaded: number;
  readonly photosFailed: number;
  readonly blockedReason: string | null;
  readonly lastError: string | null;
}

export async function syncFieldReportOutbox(): Promise<SyncFieldReportOutboxResult> {
  const empty: SyncFieldReportOutboxResult = {
    textAttempted: 0,
    textAccepted: 0,
    textFailed: 0,
    photosAttempted: 0,
    photosUploaded: 0,
    photosFailed: 0,
    blockedReason: null,
    lastError: null,
  };

  const commands = await syncCommandOutbox();

  if (commands.blockedReason !== null) {
    return { ...empty, blockedReason: commands.blockedReason };
  }

  const text = commands.results.filter(
    (result) => result.commandType === "submit-field-report",
  );
  const textAttempted = text.length;
  const textAccepted = text.filter(
    (result) => result.outcome === "accepted",
  ).length;
  let lastError = text.filter((result) => result.error !== null).at(-1)?.error ?? null;

  const session = resolveFieldSession();

  if (!session) {
    return {
      ...empty,
      textAttempted,
      textAccepted,
      textFailed: textAttempted - textAccepted,
      blockedReason: "Field session is unavailable.",
      lastError,
    };
  }

  let photosAttempted = 0;
  let photosUploaded = 0;
  let photosFailed = 0;

  for (const reportId of await pendingFieldReportPhotoReportIds()) {
    if (
      authorFieldReportCatalog.get(reportId)?.syncStatus !== FIELD_REPORT_ACCEPTED
    ) {
      continue;
    }

    const photoResult = await syncPendingFieldReportPhotos(
      (request) => uploadFieldReportPhoto(request, session),
      reportId,
    );
    photosAttempted += photoResult.attempted;
    photosUploaded += photoResult.uploaded;
    photosFailed += photoResult.failed;
  }

  if (photosFailed > 0 && lastError === null) {
    lastError = "One or more Field Report photos failed to upload.";
  }

  if (textAccepted > 0) {
    bumpFieldReportCatalogRevision();
  }
  if (photosUploaded > 0 || photosFailed > 0) {
    bumpFieldReportPhotoRevision();
  }

  return {
    textAttempted,
    textAccepted,
    textFailed: textAttempted - textAccepted,
    photosAttempted,
    photosUploaded,
    photosFailed,
    blockedReason: null,
    lastError,
  };
}
