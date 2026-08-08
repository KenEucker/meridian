// How a surface issues a command (M16.10; CLIENT-015, CLIENT-016, CLIENT-018;
// technical spec 11A.5; UI operating guide 17.7).
//
// Every command goes through here, and the catalog decides which of the two
// paths it takes:
//
//   `queueCommand`         an Alpha 1 offline write. Written to the durable
//                          queue and sent whenever the node is next reachable.
//   `sendConnectedCommand` a command the specification restricts to connected
//                          operation. Sent now or refused now — never queued.
//
// The split is enforced in both directions. Handing a connected-only command to
// `queueCommand` is refused, and handing an offline write to
// `sendConnectedCommand` is refused too, so a caller that picks the wrong one
// finds out at the call rather than by having its work quietly mishandled.
//
// Refusing at issue time is the requirement, not a convenience: "Telling a user
// their work is queued when it can never send is worse than refusing it" (UI
// implementation contract 16.3). A person who acknowledges a policy at a dead
// camp is owed the news that it did not happen while they are still standing
// there, not four hours later when a queue drains and reports a rejection.
//
// Neither path drains the queue. Draining is `syncCommandOutbox`, driven by the
// shell's connectivity watch and by the retry controls on the surfaces that own
// the work, so issuing a command never blocks on the network.

import { meridianJson } from "@/api/meridianApi";
import { deviceLocalNodeReachable } from "@/offline/useConnectivity";
import {
  describeCommand,
  type CommandDescriptor,
  type MeridianCommandType,
} from "@/outbox/commandCatalog";
import type { OutboxCommand } from "@/outbox/commandOutbox";
import {
  commandOutbox,
  notifyCommandOutbox,
} from "@/outbox/commandOutboxRuntime";

/**
 * A command that cannot be held on this device was issued where it cannot be
 * sent. Carries the reason the catalog states, which is the text the surface
 * shows the person who issued it.
 */
export class ConnectedOnlyCommandError extends Error {
  readonly commandType: MeridianCommandType;

  constructor(descriptor: CommandDescriptor, message: string) {
    super(message);
    this.name = "ConnectedOnlyCommandError";
    this.commandType = descriptor.type;
  }
}

export interface SubmitCommandInput {
  readonly commandType: MeridianCommandType;
  /** Client-generated idempotency key; the device's operation UUID. */
  readonly idempotencyKey: string;
  readonly payload: Readonly<Record<string, unknown>>;
  readonly eventId?: string | null;
  readonly detail?: string | null;
}

export interface QueueCommandDependencies {
  readonly now?: () => Date;
  readonly notifyQueueChanged?: () => void;
}

/**
 * Hold an offline-writable command for submission.
 *
 * Synchronous, and deliberately so: writing to the queue is a local act, and a
 * surface that has captured somebody's work should be able to say it captured it
 * without waiting on a network it may not have. Idempotent on the key — issuing
 * the same command twice returns the entry already held rather than queueing a
 * second one (CLIENT-016).
 */
export function queueCommand(
  input: SubmitCommandInput,
  dependencies: QueueCommandDependencies = {},
): OutboxCommand {
  const descriptor = describeCommand(input.commandType);

  if (!descriptor.offlineWritable) {
    throw new ConnectedOnlyCommandError(
      descriptor,
      descriptor.connectedOnlyReason ??
        "This command needs a connection to the node.",
    );
  }

  const now = dependencies.now ?? (() => new Date());
  const notify = dependencies.notifyQueueChanged ?? notifyCommandOutbox;

  const command = commandOutbox.enqueue({
    idempotencyKey: input.idempotencyKey,
    commandType: input.commandType,
    payload: input.payload,
    eventId: input.eventId ?? null,
    detail: input.detail ?? null,
    queuedAt: now().toISOString(),
  });

  notify();

  return command;
}

/**
 * Send a connected-only command, or refuse it where it stands.
 *
 * The gate is the local tier and nothing else (M18.52). "Connected" here means a
 * Meridian node is reachable, not that the whole deployment is healthy: an
 * incident is created against the on-site node that will hold it, and refusing
 * that because central's internet is down would take away the exact capability
 * an on-site node exists to provide. What is refused is a command issued with no
 * node to send it to — which is the refusal the catalog's reasons are written
 * for, and the one the person is shown.
 *
 * A request that goes out and fails is reported to the caller as a failure and is
 * still not queued, because the command is one the specification says cannot be
 * held.
 */
export async function sendConnectedCommand(
  input: SubmitCommandInput,
): Promise<unknown> {
  const descriptor = describeCommand(input.commandType);

  if (descriptor.offlineWritable) {
    throw new ConnectedOnlyCommandError(
      descriptor,
      `\`${descriptor.type}\` is an offline write and belongs in the command outbox.`,
    );
  }

  if (!deviceLocalNodeReachable.value) {
    throw new ConnectedOnlyCommandError(
      descriptor,
      descriptor.connectedOnlyReason ??
        "This command needs a connection to the node.",
    );
  }

  return meridianJson<unknown>(descriptor.path, {
    method: "POST",
    body: JSON.stringify(input.payload),
  });
}

/**
 * Put a refused command back in the queue, because the person who was shown the
 * refusal decided it is worth another go.
 *
 * User-initiated only. Nothing retries a rejection automatically (CLIENT-017),
 * and the command keeps its original idempotency key, so if the node did apply
 * some part of it before refusing, sending it again is still the same command.
 */
export function retryCommand(
  idempotencyKey: string,
  dependencies: QueueCommandDependencies = {},
): OutboxCommand {
  const notify = dependencies.notifyQueueChanged ?? notifyCommandOutbox;
  const command = commandOutbox.retry(idempotencyKey);

  notify();

  return command;
}

/**
 * Drop a command the node settled.
 *
 * The user acting on a rejection is what closes it out; nothing dismisses one on
 * their behalf (CLIENT-017).
 */
export function dismissCommand(
  idempotencyKey: string,
  dependencies: QueueCommandDependencies = {},
): boolean {
  const notify = dependencies.notifyQueueChanged ?? notifyCommandOutbox;
  const dismissed = commandOutbox.dismiss(idempotencyKey);

  if (dismissed) {
    notify();
  }

  return dismissed;
}
