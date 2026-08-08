// How a read model answers when the node did not (M18.50; CLIENT-001,
// CLIENT-015, CLIENT-021; technical spec 9.3, 17.2).
//
// A read model that wants to work offline hands `meridianCachedJson` a
// projection: a function from what this device holds to the payload that read
// model already parses. The seam decides *when* to call it — only a node that
// did not answer, never a node that refused — and what to disclose about the
// result; the projection decides what the answer is.
//
// It is a function rather than a table of section-to-endpoint mappings because
// the shape a surface needs is the surface's own business. The node composes
// `logistics_staff_index` rows; whether the dictation picker wants a display
// name built handle-first is a fact about the dictation picker, and a central
// mapping would be a second place that has to know it.
//
// **What a projection is handed.** An {@link OfflineReadSource}: the sections
// this device holds, a search over one of them, the moment the device took
// delivery, and the commands sitting in the outbox unsent. Not the store itself
// — a read model that could reach the store could also write to it, hydrate it,
// or clear it, and none of those are a read model's to do.
//
// **What it is not handed is a node.** A projection that could make a request
// would be a second read path with its own error handling, which is the thing
// the single seam exists to prevent.

import type { OfflineReadSetRow } from "@/offline/offlineReadSet";
import {
  offlineReadSetStore,
  offlineReadSetStoredAt,
  offlineReadSetUsable,
} from "@/offline/offlineReadSetRuntime";
import type { MeridianCommandType } from "@/outbox/commandCatalog";
import { commandOutbox } from "@/outbox/commandOutboxRuntime";

/**
 * A command this device is holding that the node has not accepted yet.
 *
 * Queued and sending both count. What does not is a command the node refused:
 * a rejected submission is not a record that exists anywhere, and unioning one
 * into a list would show somebody a report the node has already said it does not
 * have (CLIENT-017). Refusals are reported by the outbox notice, which is where
 * a person can act on them.
 */
export interface PendingLocalCommand {
  /** The client-generated idempotency key: the device-generated identifier. */
  readonly key: string;
  readonly commandType: MeridianCommandType;
  readonly payload: Readonly<Record<string, unknown>>;
  readonly queuedAt: string;
  readonly eventId: string | null;
}

export interface OfflineReadSource {
  /** Whether the set carries this section at all, as opposed to carrying none. */
  readonly carries: (section: string) => boolean;
  /** One section's rows. Empty for a section this caller's set does not carry. */
  readonly section: <R = OfflineReadSetRow>(section: string) => readonly R[];
  /** Rows of one section matching a typed query, filtered in memory. */
  readonly search: <R = OfflineReadSetRow>(
    section: string,
    query: string,
    fields: readonly string[],
  ) => readonly R[];
  /** Unsent commands of one type, oldest first. */
  readonly pending: (
    commandType: MeridianCommandType,
  ) => readonly PendingLocalCommand[];
  /** When this device took delivery of the set it is holding. */
  readonly storedAt: string | null;
}

/** What a projection answers with. */
export interface OfflineAnswer<T> {
  readonly data: T;
  /**
   * Whether this answer covers less than the request would have.
   *
   * Set by a projection that knows its own copy is not the whole question — a
   * library search answered from the published documents this device holds
   * rather than from everything the node would have searched. The surface says
   * so, because "no matches" and "no matches in what this device holds" are
   * different sentences (UI implementation contract 16.2).
   */
  readonly narrowed?: boolean;
}

/**
 * A read model's offline answer, or null when it has none.
 *
 * Null is the honest answer for a device holding no set, holding one composed
 * without the section this read needs, or holding one whose module was switched
 * off (MOD-016). The seam turns it into the transport failure the caller would
 * have got anyway, so a surface with nothing to show reports the node it could
 * not reach rather than rendering an empty list as though that were the answer.
 */
export type OfflineReadProjection<T> = (
  source: OfflineReadSource,
) => OfflineAnswer<T> | null;

/**
 * What this device can answer from right now, or null when it may answer
 * nothing.
 *
 * Null covers both "holds no set" and "holds one it may no longer serve": the
 * 11A.4 event-window rule is applied here, once, rather than left to each
 * projection to remember. A set past the window of the event it was composed for
 * is not a stale copy to be disclosed — it is a copy that may not be shown at
 * all (M18.49).
 */
export function offlineReadSource(
  now: Date = new Date(),
): OfflineReadSource | null {
  if (!offlineReadSetUsable(now)) {
    return null;
  }

  return {
    carries: (section) => offlineReadSetStore.carries(section),
    section: <R,>(section: string) => offlineReadSetStore.section<R>(section),
    search: <R,>(section: string, query: string, fields: readonly string[]) =>
      offlineReadSetStore.search<R>(section, query, fields),
    pending: (commandType) =>
      commandOutbox
        .all()
        .filter(
          (command) =>
            command.commandType === commandType &&
            (command.status === "queued" || command.status === "sending"),
        )
        .map((command) => ({
          key: command.idempotencyKey,
          commandType: command.commandType,
          payload: command.payload,
          queuedAt: command.queuedAt,
          eventId: command.eventId,
        })),
    storedAt: offlineReadSetStoredAt(),
  };
}

/**
 * Stored rows plus the locally pending ones, deduplicated on the device-generated
 * identifier (technical spec 17.2; data/API 5.3, 5.6).
 *
 * Every Alpha 1 offline write is keyed by a UUID the device minted before it had
 * a node to ask, and the node stores that same identifier as the record's own
 * key. So a report waiting in the outbox and the accepted copy of it in the read
 * set are not two rows that look alike — they are one row under one key, and
 * telling them apart is an identifier match rather than a comparison of titles
 * and timestamps that would collapse two genuine reports filed a minute apart.
 *
 * The stored row wins. It is the node's copy of the same record: it carries the
 * FRA number the device could not assign and whatever the node did to the
 * submission on the way in, and preferring the local one would show somebody a
 * temporary local number for a report that has had a real number for hours.
 */
export function unionPendingByDeviceId<T>(
  stored: readonly T[],
  pending: readonly T[],
  identify: (row: T) => string,
): readonly T[] {
  const held = new Set(stored.map(identify));

  return [...stored, ...pending.filter((row) => !held.has(identify(row)))];
}
