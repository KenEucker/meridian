import { meridianApiConfig } from "@/api/meridianApi";
import { pendingAttendanceQueue } from "@/shift-board/attendanceRuntime";
import { submitAttendanceOperationCommand } from "@/shift-board/submitAttendanceOperationCommand";
import { markAttendanceOperationSynced } from "@/shift-board/submitAttendanceOperation";

export interface SyncAttendanceOutboxResult {
  readonly attempted: number;
  readonly accepted: number;
  readonly failed: number;
}

let syncInFlight: Promise<SyncAttendanceOutboxResult> | null = null;

export async function syncAttendanceOutbox(): Promise<SyncAttendanceOutboxResult> {
  if (syncInFlight) {
    return syncInFlight;
  }

  syncInFlight = runSync().finally(() => {
    syncInFlight = null;
  });

  return syncInFlight;
}

async function runSync(): Promise<SyncAttendanceOutboxResult> {
  const empty: SyncAttendanceOutboxResult = {
    attempted: 0,
    accepted: 0,
    failed: 0,
  };

  if (!meridianApiConfig().bearerToken) {
    return empty;
  }

  let attempted = 0;
  let accepted = 0;
  let failed = 0;

  for (const operation of pendingAttendanceQueue.pending()) {
    attempted += 1;

    try {
      const acceptance = await submitAttendanceOperationCommand(operation);
      if (
        acceptance.operationUuid !== operation.operationUuid ||
        !acceptance.serverReceivedAt
      ) {
        throw new Error("Server acceptance did not return matching sync data.");
      }

      markAttendanceOperationSynced(operation.operationUuid);
      accepted += 1;
    } catch {
      failed += 1;
    }
  }

  return {
    attempted,
    accepted,
    failed,
  };
}
