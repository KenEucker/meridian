// The one durable local queue every Meridian command goes through (M16.10;
// CLIENT-015 through CLIENT-017; technical spec 11A.5; data/API 5.3, 5.6;
// UI implementation contract 16.3).
//
// Alpha 1 carried two queues, one for Field Reports and one for attendance, each
// with its own store, its own drain, and its own idea of what "pending" meant. A
// device could therefore be half-drained in ways nothing could report on, and
// every new offline write would have added a third. This is the replacement:
// Field Reports and attendance are callers of it, not owners of their own
// queues.
//
// Three properties are the whole point of the thing:
//
//  1. **The key is the command.** Every entry carries a client-generated
//     idempotency key — the operation UUID the device already mints — and the
//     queue is keyed by it. Enqueuing a key that is already held is not an
//     error and not a second command; it is the same command (data/API 5.3,
//     5.6). That is what makes replay after an interrupted sync safe.
//  2. **Nothing settles silently.** A command the node refused stays in the
//     queue as `rejected` with the node's reason on it until a person dismisses
//     it. A rejected check-in that vanished would leave someone believing they
//     recorded attendance they did not (CLIENT-017).
//  3. **Failure is not rejection.** An unreachable node returns a command to
//     `queued`. Only the node saying no makes it `rejected`. The distinction is
//     made by the drain; this module just holds whichever verdict it is given.
//
// The status names are the four in UI implementation contract 16.3 and are used
// verbatim by the surfaces that report them.

import type { MeridianCommandType } from "@/outbox/commandCatalog";

/** The command states in UI implementation contract 16.3. */
export type CommandStatus = "queued" | "sending" | "accepted" | "rejected";

export interface OutboxCommand {
  /** Client-generated idempotency key; the device's operation UUID. */
  readonly idempotencyKey: string;
  readonly commandType: MeridianCommandType;
  /** The request body, ready for the wire, so a restart can send it unaided. */
  readonly payload: Readonly<Record<string, unknown>>;
  /** Which event the command belongs to, where the command has one. */
  readonly eventId: string | null;
  /**
   * What this particular command was, in the issuer's terms — a Field Report's
   * temporary local number, a staff member's name. The command label says what
   * kind of work it is; this says which one.
   */
  readonly detail: string | null;
  readonly queuedAt: string;
  readonly status: CommandStatus;
  readonly attempts: number;
  readonly lastAttemptAt: string | null;
  /** When the node accepted or refused it. */
  readonly settledAt: string | null;
  /**
   * Why the command is in the state it is in: the node's refusal for a rejected
   * command, the transport failure for one that went back to the queue.
   */
  readonly statusReason: string | null;
}

export class CommandOutboxError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "CommandOutboxError";
  }
}

export interface EnqueueCommandInput {
  readonly idempotencyKey: string;
  readonly commandType: MeridianCommandType;
  readonly payload: Readonly<Record<string, unknown>>;
  readonly queuedAt: string;
  readonly eventId?: string | null;
  readonly detail?: string | null;
}

const SETTLED: readonly CommandStatus[] = ["accepted", "rejected"];

/**
 * Ordered, idempotency-key-addressed command queue.
 *
 * Insertion order is preserved and drained in order, so commands reach the node
 * in the order the device created them. Ordering is a convenience, not a
 * guarantee the domain relies on: each command carries its own device timestamp,
 * and one command failing does not hold up the ones behind it.
 */
export class CommandOutbox {
  private readonly commands = new Map<string, OutboxCommand>();

  /**
   * Hold a command for submission.
   *
   * Returns the entry that is now held. A repeated key returns the existing
   * entry untouched rather than replacing it: the second call is the same
   * command, and overwriting would discard the attempt history and the verdict
   * of the first (data/API 5.3).
   */
  enqueue(input: EnqueueCommandInput): OutboxCommand {
    const held = this.commands.get(input.idempotencyKey);

    if (held !== undefined) {
      return held;
    }

    const command: OutboxCommand = Object.freeze({
      idempotencyKey: input.idempotencyKey,
      commandType: input.commandType,
      payload: Object.freeze({ ...input.payload }),
      eventId: input.eventId ?? null,
      detail: input.detail ?? null,
      queuedAt: input.queuedAt,
      status: "queued",
      attempts: 0,
      lastAttemptAt: null,
      settledAt: null,
      statusReason: null,
    });

    this.commands.set(command.idempotencyKey, command);

    return command;
  }

  has(idempotencyKey: string): boolean {
    return this.commands.has(idempotencyKey);
  }

  get(idempotencyKey: string): OutboxCommand | undefined {
    return this.commands.get(idempotencyKey);
  }

  /** Every held command, in the order it was queued. */
  all(): readonly OutboxCommand[] {
    return Array.from(this.commands.values());
  }

  byStatus(status: CommandStatus): readonly OutboxCommand[] {
    return this.all().filter((command) => command.status === status);
  }

  byType(commandType: MeridianCommandType): readonly OutboxCommand[] {
    return this.all().filter((command) => command.commandType === commandType);
  }

  /** Commands waiting to reach the node, in submission order. */
  pending(): readonly OutboxCommand[] {
    return this.byStatus("queued");
  }

  /**
   * Work this device is still the only copy of: queued, plus whatever was on the
   * wire when the count was taken. A rejected command is excluded — it is not
   * going to send, and counting it among the work that will would misreport the
   * one state a person most needs told apart.
   */
  unsent(commandType?: MeridianCommandType): readonly OutboxCommand[] {
    return this.all().filter(
      (command) =>
        (command.status === "queued" || command.status === "sending") &&
        (commandType === undefined || command.commandType === commandType),
    );
  }

  get size(): number {
    return this.commands.size;
  }

  /** Mark a command as on the wire (UI implementation contract 16.3). */
  markSending(idempotencyKey: string, at: string): OutboxCommand {
    const command = this.require(idempotencyKey);

    return this.replace({
      ...command,
      status: "sending",
      attempts: command.attempts + 1,
      lastAttemptAt: at,
      statusReason: null,
    });
  }

  /** The node applied it. */
  markAccepted(idempotencyKey: string, at: string): OutboxCommand {
    const command = this.require(idempotencyKey);

    return this.replace({
      ...command,
      status: "accepted",
      settledAt: at,
      statusReason: null,
    });
  }

  /**
   * The node refused it and it will not retry on its own.
   *
   * Kept, with the reason, until somebody dismisses it. A refusal the user never
   * sees is the failure mode CLIENT-017 names.
   */
  markRejected(
    idempotencyKey: string,
    at: string,
    reason: string,
  ): OutboxCommand {
    const command = this.require(idempotencyKey);

    return this.replace({
      ...command,
      status: "rejected",
      settledAt: at,
      statusReason: reason,
    });
  }

  /**
   * The attempt failed without the node deciding anything, so the command goes
   * back in the queue with what went wrong recorded against it.
   */
  markRetryable(idempotencyKey: string, reason: string): OutboxCommand {
    const command = this.require(idempotencyKey);

    return this.replace({
      ...command,
      status: "queued",
      statusReason: reason,
    });
  }

  /**
   * Put a refused command back in the queue, by a deliberate act.
   *
   * The drain will never do this on its own — a rejection is the node's decision
   * and retrying it automatically would loop forever and bury the refusal the
   * user was meant to see (CLIENT-017). But a refusal is not always the last
   * word: a node misconfigured, a shift not yet started, a department not yet
   * assigned are all reasons a command that failed at noon succeeds at one. The
   * person who was shown the reason is the one who can judge that, so they get a
   * way to act on it that is not "retype the whole thing".
   */
  retry(idempotencyKey: string): OutboxCommand {
    const command = this.require(idempotencyKey);

    if (command.status !== "rejected") {
      throw new CommandOutboxError(
        "Only a command the node refused can be retried.",
      );
    }

    return this.replace({
      ...command,
      status: "queued",
      settledAt: null,
      statusReason: null,
    });
  }

  /**
   * Drop a settled command, by a deliberate act.
   *
   * Refuses anything still in flight: a queued or sending command is unsent
   * work, and the queue is the only copy of it.
   */
  dismiss(idempotencyKey: string): boolean {
    const command = this.commands.get(idempotencyKey);

    if (command === undefined) {
      return false;
    }

    if (!SETTLED.includes(command.status)) {
      throw new CommandOutboxError(
        "A command that has not reached the node cannot be dismissed.",
      );
    }

    return this.commands.delete(idempotencyKey);
  }

  /**
   * Keep only the most recent accepted commands.
   *
   * Accepted commands are kept so a surface can report them (CLIENT-017), but
   * they are history rather than work: the domain record the command produced is
   * the lasting one. Without a bound the store would grow for the life of the
   * install. Queued, sending, and rejected commands are never pruned.
   */
  pruneAccepted(limit: number): number {
    const accepted = this.byStatus("accepted");
    const excess = accepted.length - Math.max(limit, 0);

    if (excess <= 0) {
      return 0;
    }

    for (const command of accepted.slice(0, excess)) {
      this.commands.delete(command.idempotencyKey);
    }

    return excess;
  }

  /**
   * Drop every command the node has already decided on, keeping unsent work.
   *
   * What a session ending runs (M16.22). A settled command is a verdict about
   * somebody's work — a rejection carries the node's sentence about a Field
   * Report that person filed — and the next person to sign in on this device has
   * no business reading it, cannot act on it, and would reasonably read a
   * refusal on their screen as a refusal of something they did.
   *
   * Queued and sending commands stay, and that difference is the whole rule.
   * They are unsent work this device holds the only copy of, and technical spec
   * 13.3 requires them to survive a session ending; they carry no verdict for
   * anyone to misread, and they drain to the node the same way whoever is signed
   * in next.
   */
  dropSettled(): number {
    let dropped = 0;

    for (const command of this.all()) {
      if (SETTLED.includes(command.status)) {
        this.commands.delete(command.idempotencyKey);
        dropped += 1;
      }
    }

    return dropped;
  }

  /** Install a set of commands, replacing whatever is held. */
  replaceAll(commands: readonly OutboxCommand[]): void {
    this.commands.clear();

    for (const command of commands) {
      this.commands.set(command.idempotencyKey, Object.freeze({ ...command }));
    }
  }

  clear(): void {
    this.commands.clear();
  }

  private require(idempotencyKey: string): OutboxCommand {
    const command = this.commands.get(idempotencyKey);

    if (command === undefined) {
      throw new CommandOutboxError(
        `No queued command is held for \`${idempotencyKey}\`.`,
      );
    }

    return command;
  }

  private replace(command: OutboxCommand): OutboxCommand {
    const frozen = Object.freeze(command);
    this.commands.set(frozen.idempotencyKey, frozen);

    return frozen;
  }
}
