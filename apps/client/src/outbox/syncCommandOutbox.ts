// Drain the command outbox to the node (M16.10; CLIENT-015 through CLIENT-017;
// technical spec 11A.5; data/API 5.3, 5.6).
//
// One drain for every command the device holds, replacing the separate Field
// Report and attendance sync passes. It sends each queued command in the order
// it was created, under the idempotency key the device generated, and records
// what the node said.
//
// The distinction this module exists to make is between a command the node
// refused and a command that never got there:
//
//   rejected   The node reached a decision and said no. It will not retry on its
//              own, it keeps the node's reason, and it stays visible until
//              somebody deals with it (CLIENT-017).
//   queued     Nothing was decided — no network, the node down, the answer
//              unreadable. The command goes back in the queue with the failure
//              recorded and is sent again next time.
//
// An expired token, a revoked device, and a rate limit are all deliberately on
// the retry side of that line. They are refusals of the *request*, not of the
// command: the work is still valid and a person who checked somebody in should
// not lose it because their token lapsed while they were out of coverage.
//
// One command failing never stops the ones behind it. Each carries its own
// device timestamp and its own key, so a stuck command is a stuck command rather
// than a stopped queue.

import {
  holdsMeridianCredential,
  MeridianApiError,
  meridianJson,
} from "@/api/meridianApi";
import { applyCommandAcceptance } from "@/outbox/commandAcceptance";
import {
  describeCommand,
  type MeridianCommandType,
} from "@/outbox/commandCatalog";
import {
  commandOutbox,
  notifyCommandOutbox,
} from "@/outbox/commandOutboxRuntime";

export type CommandSyncOutcome = "accepted" | "rejected" | "retryable";

export interface CommandSyncResult {
  readonly idempotencyKey: string;
  readonly commandType: MeridianCommandType;
  readonly outcome: CommandSyncOutcome;
  readonly error: string | null;
}

export interface SyncCommandOutboxResult {
  readonly attempted: number;
  readonly accepted: number;
  readonly rejected: number;
  readonly retryable: number;
  /** Why nothing was attempted, when the client could not try at all. */
  readonly blockedReason: string | null;
  readonly lastError: string | null;
  readonly results: readonly CommandSyncResult[];
}

/**
 * HTTP answers that refuse the request without deciding the command.
 *
 * 401/403 is a credential problem, 408/425 a timing one, 429 a throttle, and
 * every 5xx a node problem. None of them mean the command is wrong.
 */
const RETRYABLE_STATUSES: readonly number[] = [401, 403, 408, 425, 429];

let syncInFlight: Promise<SyncCommandOutboxResult> | null = null;

/**
 * Send every queued command.
 *
 * Concurrent callers share one pass — the shell's connectivity watch and a
 * surface's retry button can fire together, and sending the same command twice
 * in parallel would be pointless work even though the key makes it harmless.
 */
export async function syncCommandOutbox(): Promise<SyncCommandOutboxResult> {
  if (syncInFlight) {
    return syncInFlight;
  }

  syncInFlight = runSync().finally(() => {
    syncInFlight = null;
  });

  return syncInFlight;
}

function summarize(
  results: readonly CommandSyncResult[],
  blockedReason: string | null,
): SyncCommandOutboxResult {
  const lastError =
    results.filter((result) => result.error !== null).at(-1)?.error ?? null;

  return {
    attempted: results.length,
    accepted: results.filter((result) => result.outcome === "accepted").length,
    rejected: results.filter((result) => result.outcome === "rejected").length,
    retryable: results.filter((result) => result.outcome === "retryable").length,
    blockedReason,
    lastError,
    results,
  };
}

async function runSync(): Promise<SyncCommandOutboxResult> {
  /*
   * Nothing goes on the wire without a credential (M16.11).
   *
   * Either is enough, and which one it is depends on what this client is: a
   * signed-in device holds a bearer token, and a Kiosk holds a shared-workstation
   * session key instead (AUTH-030). A client holding neither keeps its commands
   * rather than sending requests that can only be refused — the work is not lost,
   * it is waiting for somebody to sign in.
   */
  if (!holdsMeridianCredential()) {
    return summarize([], "This device is not signed in, so commands are waiting.");
  }

  const results: CommandSyncResult[] = [];

  for (const queued of commandOutbox.pending()) {
    const descriptor = describeCommand(queued.commandType);
    const sending = commandOutbox.markSending(
      queued.idempotencyKey,
      new Date().toISOString(),
    );
    notifyCommandOutbox();

    try {
      const response = await meridianJson<unknown>(descriptor.path, {
        method: "POST",
        body: JSON.stringify(sending.payload),
      });

      applyCommandAcceptance(sending, response);
      commandOutbox.markAccepted(
        sending.idempotencyKey,
        new Date().toISOString(),
      );
      settleOverriddenCommand(sending.overridesIdempotencyKey);
      results.push({
        idempotencyKey: sending.idempotencyKey,
        commandType: sending.commandType,
        outcome: "accepted",
        error: null,
      });
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : `${descriptor.label} could not be sent.`;

      if (isRejection(error)) {
        commandOutbox.markRejected(
          sending.idempotencyKey,
          new Date().toISOString(),
          message,
          rejectionReasonCode(error),
        );
        results.push({
          idempotencyKey: sending.idempotencyKey,
          commandType: sending.commandType,
          outcome: "rejected",
          error: message,
        });
      } else {
        commandOutbox.markRetryable(sending.idempotencyKey, message);
        results.push({
          idempotencyKey: sending.idempotencyKey,
          commandType: sending.commandType,
          outcome: "retryable",
          error: message,
        });
      }
    } finally {
      notifyCommandOutbox();
    }
  }

  return summarize(results, null);
}

/**
 * The refused command an accepted override resolves (M18.55; CLIENT-017A).
 *
 * Dropped from the queue at the moment the override lands, and this is the one
 * place that has the fact needed to do it: the override succeeded, so the work
 * the refusal was about is on the node's roster. Leaving the refusal on screen
 * afterwards would be a warning about work that has now happened — which trains
 * a person to ignore the indicator, exactly what UI implementation contract 16.2
 * is written against.
 *
 * This is not the silent discard CLIENT-017 forbids. The refusal was shown, a
 * person read it, and that person's own act is what removed it; nothing here
 * decides anything on their behalf. Note it survives an override that is
 * *refused* — that override is a new rejection with its own reason, and the
 * original stays put underneath it, marked as already acted on.
 */
function settleOverriddenCommand(overriddenKey: string | null): void {
  if (overriddenKey === null) {
    return;
  }

  commandOutbox.dismiss(overriddenKey);
}

/**
 * The node's refusal reason as a code, where it gave one.
 *
 * A refusal body carries the sentence in `message` and, since M18.55, a
 * `reason_code` beside it. Read defensively: a node that predates the field, an
 * error page that is not JSON, and a framework validation failure are all
 * refusals with no code, and the answer for all three is null — which reads
 * downstream as "not overridable", the safe direction.
 */
function rejectionReasonCode(error: unknown): string | null {
  if (!(error instanceof MeridianApiError)) {
    return null;
  }

  const body = error.body;

  if (typeof body !== "object" || body === null) {
    return null;
  }

  const code = (body as { reason_code?: unknown }).reason_code;

  return typeof code === "string" && code !== "" ? code : null;
}

/** Whether the node decided against the command, as opposed to not answering. */
function isRejection(error: unknown): boolean {
  if (!(error instanceof MeridianApiError)) {
    return false;
  }

  return (
    error.status >= 400 &&
    error.status < 500 &&
    !RETRYABLE_STATUSES.includes(error.status)
  );
}
