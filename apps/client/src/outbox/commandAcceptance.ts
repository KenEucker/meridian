// What happens locally when the node accepts a command (M16.10; CLIENT-015;
// technical spec 11A.5, 17.5).
//
// The queue can send any command it holds without help — the catalog gives the
// endpoint and the entry carries the body — but a few commands leave local state
// that has to be brought into line with the node's answer. A Field Report that
// showed a temporary local number takes its FRA number here (technical spec
// 17.5); an attendance operation checks that the answer is about the operation
// it sent.
//
// This table lives with the outbox rather than in a registry the features
// populate at import, and that is a deliberate trade. A registry would put
// ownership with the feature, at the cost of the drain being able to complete a
// command only if the right module happened to be imported — which after a
// restart depends on which screen the user opened. The queue has to be able to
// finish work it was holding before anybody navigated anywhere, so the set of
// handlers is named in one place that the drain always has.
//
// A handler that cannot make sense of the response throws. The drain reads that
// as a failed attempt rather than a rejection, so the command stays queued and
// is sent again under the same idempotency key — which the node treats as the
// same command (data/API 5.3), so nothing is applied twice.

import type { MeridianCommandType } from "@/outbox/commandCatalog";
import type { OutboxCommand } from "@/outbox/commandOutbox";
import { applyLocalFieldReportAcceptance } from "@/field-reports/submitFieldReport";

export class CommandAcceptanceError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "CommandAcceptanceError";
  }
}

type CommandAcceptanceHandler = (
  command: OutboxCommand,
  response: unknown,
) => void;

function asRecord(value: unknown): Record<string, unknown> {
  if (typeof value !== "object" || value === null || Array.isArray(value)) {
    throw new CommandAcceptanceError(
      "The node's answer was not a command acceptance.",
    );
  }

  return value as Record<string, unknown>;
}

function requireString(
  record: Record<string, unknown>,
  key: string,
): string {
  const value = record[key];

  if (typeof value !== "string" || value === "") {
    throw new CommandAcceptanceError(
      `The node's acceptance carried no \`${key}\`.`,
    );
  }

  return value;
}

/**
 * A Field Report becomes the server's record: its FRA number replaces the
 * temporary local number and the author catalog stops showing it as pending
 * (technical spec 17.5).
 */
function acceptFieldReport(command: OutboxCommand, response: unknown): void {
  const record = asRecord(response);

  applyLocalFieldReportAcceptance(command.idempotencyKey, {
    fraNumber: requireString(record, "fra_number"),
    serverReceivedAt: requireString(record, "server_received_at"),
  });
}

/**
 * An attendance operation has no local record beyond the queue entry, so all
 * there is to do is confirm the node answered about the operation that was sent.
 * A mismatched operation UUID would mean the acceptance belongs to a different
 * command, and marking this one accepted on the strength of it would drop unsent
 * work.
 */
function acceptAttendanceOperation(
  command: OutboxCommand,
  response: unknown,
): void {
  const record = asRecord(response);

  if (requireString(record, "operation_uuid") !== command.idempotencyKey) {
    throw new CommandAcceptanceError(
      "The node answered about a different attendance operation.",
    );
  }

  requireString(record, "server_received_at");
}

const HANDLERS: Partial<
  Record<MeridianCommandType, CommandAcceptanceHandler>
> = {
  "submit-field-report": acceptFieldReport,
  "check-in-staff": acceptAttendanceOperation,
  "check-out-staff": acceptAttendanceOperation,
  "mark-no-show": acceptAttendanceOperation,
};

/**
 * Apply whatever the accepted command leaves behind locally. A command with no
 * local state to settle needs no handler and is accepted as it stands.
 */
export function applyCommandAcceptance(
  command: OutboxCommand,
  response: unknown,
): void {
  HANDLERS[command.commandType]?.(command, response);
}
