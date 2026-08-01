// The client's single command outbox instance (M16.10; CLIENT-015, CLIENT-017).
//
// One queue per device, hydrated from durable storage at import — before any
// screen mounts and before the client knows whether it has a network — so a
// device that was closed with unsent work comes back holding it.
//
// The revision ref is the Vue dependency for surfaces that read the queue: the
// outbox itself is a plain Map so it can be reasoned about and tested without a
// reactive system, and every mutation routed through `notifyCommandOutbox`
// persists the queue and bumps the revision in one step. A surface that reads
// the revision re-computes; a surface that forgets to is the reason mutations do
// not go anywhere else.
//
// The outbox is deliberately absent from the context reset registry that an
// event switch and a shared-workstation session end run. Queued commands are not
// context data — they are unsent work this device is the only copy of — and
// technical spec 13.3 requires them to survive a session ending.
//
// What does not survive a session ending is a verdict. `discardSettledCommands`
// is the seam sign-out calls: accepted and rejected commands are dropped and
// unsent work is kept, so the next person to sign in on this device is not shown
// the node's refusal of somebody else's Field Report.

import { ref } from "vue";

import { CommandOutbox } from "@/outbox/commandOutbox";
import { commandOutboxLocalStore } from "@/outbox/commandOutboxLocalStore";

/**
 * How many accepted commands are kept for display.
 *
 * Enough that a shift lead who worked a rush of check-ins can still see them
 * reported as accepted, bounded so the store does not grow for the life of the
 * install. Queued and rejected commands are never pruned.
 */
export const ACCEPTED_COMMAND_RETENTION = 50;

export const commandOutbox = new CommandOutbox();

/** Vue dependency for surfaces that render outbox state. */
export const commandOutboxRevision = ref(0);

function hydrateFromLocalStore(): void {
  commandOutbox.replaceAll(commandOutboxLocalStore.load());
}

hydrateFromLocalStore();

/** Persist the queue and tell every surface reading it that it changed. */
export function notifyCommandOutbox(): void {
  commandOutbox.pruneAccepted(ACCEPTED_COMMAND_RETENTION);
  commandOutboxLocalStore.save(commandOutbox.all());
  commandOutboxRevision.value += 1;
}

/**
 * Drop every command the node has already decided on, keeping unsent work.
 *
 * Run when a session ends, whichever way it ends: signing out of a personal
 * device and a shared workstation locking are the same event as far as "the
 * verdicts on this screen belong to the person who just left" is concerned.
 */
export function discardSettledCommands(): number {
  const dropped = commandOutbox.dropSettled();

  if (dropped > 0) {
    notifyCommandOutbox();
  }

  return dropped;
}

/** Re-read the queue from durable storage (simulates a restart). */
export function reloadCommandOutboxFromLocalStore(): void {
  hydrateFromLocalStore();
  commandOutboxRevision.value += 1;
}

/** Reset queue state between tests. */
export function resetCommandOutbox(): void {
  commandOutbox.clear();
  commandOutboxLocalStore.clear();
  commandOutboxRevision.value = 0;
}
