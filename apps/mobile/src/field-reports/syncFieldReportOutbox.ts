// Drain local Field Report text + photo outboxes to the server (M9.8).
//
// Text acceptance must succeed before photo upload (technical spec 18.2).

import { meridianApiConfig } from "@/api/meridianApi";
import {
  bumpFieldReportCatalogRevision,
  bumpFieldReportPhotoRevision,
  pendingFieldReportQueue,
} from "@/field-reports/fieldReportRuntime";
import { resolveFieldSession } from "@/field-reports/fieldSession";
import { submitFieldReportCommand } from "@/field-reports/submitFieldReportCommand";
import { applyLocalFieldReportAcceptance } from "@/field-reports/submitFieldReport";
import { syncPendingFieldReportPhotos } from "@/field-reports/syncFieldReportPhotos";
import { uploadFieldReportPhoto } from "@/field-reports/uploadFieldReportPhoto";

export interface SyncFieldReportOutboxResult {
  readonly textAttempted: number;
  readonly textAccepted: number;
  readonly textFailed: number;
  readonly photosAttempted: number;
  readonly photosUploaded: number;
  readonly photosFailed: number;
}

let syncInFlight: Promise<SyncFieldReportOutboxResult> | null = null;

export async function syncFieldReportOutbox(): Promise<SyncFieldReportOutboxResult> {
  if (syncInFlight) {
    return syncInFlight;
  }

  syncInFlight = runSync().finally(() => {
    syncInFlight = null;
  });

  return syncInFlight;
}

async function runSync(): Promise<SyncFieldReportOutboxResult> {
  const empty: SyncFieldReportOutboxResult = {
    textAttempted: 0,
    textAccepted: 0,
    textFailed: 0,
    photosAttempted: 0,
    photosUploaded: 0,
    photosFailed: 0,
  };

  // Without a configured local API token, keep queues local-only (tests and
  // browsers that have not enabled MERIDIAN local Field command auth).
  if (!meridianApiConfig().bearerToken) {
    return empty;
  }

  const session = resolveFieldSession();
  let textAttempted = 0;
  let textAccepted = 0;
  let textFailed = 0;

  if (session) {
    for (const report of pendingFieldReportQueue.pending()) {
      textAttempted += 1;
      try {
        const acceptance = await submitFieldReportCommand(report);
        if (!acceptance.fraNumber || !acceptance.serverReceivedAt) {
          throw new Error('Server acceptance did not return FRA/server timestamps.');
        }
        applyLocalFieldReportAcceptance(report.id, {
          fraNumber: acceptance.fraNumber,
          serverReceivedAt: acceptance.serverReceivedAt,
        });
        textAccepted += 1;
      } catch {
        textFailed += 1;
      }
    }
  }

  let photosAttempted = 0;
  let photosUploaded = 0;
  let photosFailed = 0;

  if (session) {
    const photoResult = await syncPendingFieldReportPhotos((request) =>
      uploadFieldReportPhoto(request, session),
    );
    photosAttempted = photoResult.attempted;
    photosUploaded = photoResult.uploaded;
    photosFailed = photoResult.failed;
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
    textFailed,
    photosAttempted,
    photosUploaded,
    photosFailed,
  };
}
