// Durable persistence for the command outbox (M16.10; CLIENT-015).
//
// "Durable" is the requirement: a command issued without connectivity has to
// survive the application being closed, the tab being reloaded, the Electron
// process restarting, and — per technical spec 13.3 — a shared-workstation
// session ending. `localStorage` is the same device-local seam the Field Report
// catalog and the attendance queue already used and moves onto encrypted local
// storage with them when that lands; this task changes where the queue is, not
// what backs it.
//
// Two rules make the stored copy safe to boot from:
//
//  - A stored entry that does not structurally match is dropped rather than
//    repaired. A half-written command has no payload to send and no key to
//    dedupe on, and guessing at either would put an unknown request on the wire.
//  - `sending` is not a state anything can be restored into. It means a request
//    was in flight when the process ended, and nothing on this device knows
//    whether the node saw it. It comes back as `queued`, and the idempotency key
//    is what makes sending it again the same command rather than a second one
//    (data/API 5.3).
//
// A field the entry does not carry is filled in rather than treated as a
// structural mismatch. The two rules above are about entries that are *wrong*;
// an entry written by an earlier build is merely older, and dropping a queued
// check-in because this version of the client added a column to the shape would
// be losing work over a schema. M18.55's three override fields are the first to
// arrive that way, and they read back as null — which is what they were.

import { isCommandType } from "@/outbox/commandCatalog";
import type { CommandStatus, OutboxCommand } from "@/outbox/commandOutbox";

export const COMMAND_OUTBOX_LOCAL_STORE_KEY = "meridian.outbox.commands.v1";

const RESTORABLE_STATUSES: readonly CommandStatus[] = [
  "queued",
  "accepted",
  "rejected",
];

interface StoredCommandOutboxState {
  readonly version: 1;
  readonly commands: OutboxCommand[];
}

export interface CommandOutboxLocalStore {
  readonly load: () => OutboxCommand[];
  readonly save: (commands: readonly OutboxCommand[]) => void;
  readonly clear: () => void;
}

function isNullableString(value: unknown): value is string | null {
  return value === null || typeof value === "string";
}

function isStoredCommand(value: unknown): value is OutboxCommand {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const command = value as Record<string, unknown>;

  return (
    typeof command.idempotencyKey === "string" &&
    command.idempotencyKey !== "" &&
    isCommandType(command.commandType) &&
    typeof command.payload === "object" &&
    command.payload !== null &&
    !Array.isArray(command.payload) &&
    typeof command.queuedAt === "string" &&
    typeof command.status === "string" &&
    (RESTORABLE_STATUSES as readonly string[]).includes(command.status) &&
    typeof command.attempts === "number" &&
    Number.isFinite(command.attempts) &&
    isNullableString(command.eventId) &&
    isNullableString(command.detail) &&
    isNullableString(command.lastAttemptAt) &&
    isNullableString(command.settledAt) &&
    isNullableString(command.statusReason) &&
    isNullableString(command.statusReasonCode) &&
    isNullableString(command.overridesIdempotencyKey) &&
    isNullableString(command.overriddenByIdempotencyKey)
  );
}

/**
 * Read one stored entry.
 *
 * `sending` is rewritten to `queued` before validation, because a process that
 * died mid-request is the ordinary way that state is reached and dropping the
 * command would lose work the device is the only copy of.
 */
function readStoredCommand(value: unknown): OutboxCommand | null {
  if (typeof value !== "object" || value === null) {
    return null;
  }

  const candidate: Record<string, unknown> = {
    /*
     * Fields an entry written before M18.55 has never heard of. Defaulted ahead
     * of validation so an older queue survives the upgrade; an entry that does
     * carry them keeps whatever it carries, including a value of the wrong type,
     * which the check below still rejects.
     */
    statusReasonCode: null,
    overridesIdempotencyKey: null,
    overriddenByIdempotencyKey: null,
    ...(value as Record<string, unknown>),
  };

  if (candidate.status === "sending") {
    candidate.status = "queued";
    candidate.statusReason =
      "This device restarted while the command was being sent.";
  }

  return isStoredCommand(candidate) ? Object.freeze(candidate) : null;
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

export function createCommandOutboxLocalStore(
  storage: Storage | null = readStorage(),
): CommandOutboxLocalStore {
  let memoryFallback: OutboxCommand[] = [];

  return {
    load(): OutboxCommand[] {
      if (!storage) {
        return memoryFallback.map((command) => Object.freeze({ ...command }));
      }

      try {
        const raw = storage.getItem(COMMAND_OUTBOX_LOCAL_STORE_KEY);

        if (!raw) {
          return [];
        }

        const parsed = JSON.parse(raw) as Partial<StoredCommandOutboxState>;

        if (parsed.version !== 1 || !Array.isArray(parsed.commands)) {
          return [];
        }

        return parsed.commands
          .map(readStoredCommand)
          .filter((command): command is OutboxCommand => command !== null);
      } catch {
        return [];
      }
    },

    save(commands: readonly OutboxCommand[]): void {
      const payload: StoredCommandOutboxState = {
        version: 1,
        commands: commands.map((command) => ({ ...command })),
      };

      if (!storage) {
        memoryFallback = payload.commands.map((command) =>
          Object.freeze({ ...command }),
        );

        return;
      }

      try {
        storage.setItem(
          COMMAND_OUTBOX_LOCAL_STORE_KEY,
          JSON.stringify(payload),
        );
      } catch {
        memoryFallback = payload.commands.map((command) =>
          Object.freeze({ ...command }),
        );
      }
    },

    clear(): void {
      memoryFallback = [];

      if (!storage) {
        return;
      }

      try {
        storage.removeItem(COMMAND_OUTBOX_LOCAL_STORE_KEY);
      } catch {
        // Best effort: in-memory state is already empty.
      }
    },
  };
}

export const commandOutboxLocalStore = createCommandOutboxLocalStore();
