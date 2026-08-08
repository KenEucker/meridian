// The client's single offline read set, and the pull that fills it (M18.48;
// ADR-0003; CLIENT-021, CLIENT-022; technical spec 9.3, 9.5, 11A.7).
//
// One store per device, hydrated from durable storage at import — before any
// screen mounts and before the client knows whether it has a network — so a
// device that was closed at an event comes back holding what its user may read.
// Hydration is asynchronous because IndexedDB is, and the revision ref below is
// how a surface that rendered before it finished re-renders when it does.
//
// **What this module owns and what it does not.** It owns the store and the
// conditional request that fills it: the two cannot sensibly be separated,
// because `If-None-Match` is the version the store is holding and a pull that
// did not know it would transfer the whole set on every refresh. It does not
// own *when* to pull — login, reconnect, and context switch are M18.49's — nor
// the 11A.4 staleness rule that refuses to serve a set past its event window,
// nor the banner that reports refresh state. Nothing calls `pullOfflineReadSet`
// yet; M18.49 is where it acquires its triggers and M18.50 is where the read
// models start reading what it stored.
//
// Dropping is registered by `main.ts` alongside the other context-scoped caches,
// rather than here, so the list of what does not survive a switch stays readable
// in one place.

import { ref } from "vue";

import { meridianFetch } from "@/api/meridianApi";
import { createOfflineReadSetStore } from "@/offline/offlineReadSetStore";
import {
  createOfflineReadSetStorage,
  type OfflineReadSetContext,
} from "@/offline/offlineReadSetStorage";

export const offlineReadSetStore = createOfflineReadSetStore(
  createOfflineReadSetStorage(),
);

/**
 * Vue dependency for surfaces that read the stored set.
 *
 * The store is a plain object so it can be reasoned about and tested without a
 * reactive system, exactly as the command outbox is. Every change that alters
 * what a surface would render bumps this, and a surface that reads it
 * recomputes.
 */
export const offlineReadSetRevision = ref(0);

export type OfflineReadSetPullOutcome =
  /** The node sent a new set and it replaced whatever was held. */
  | "refreshed"
  /** The node reports the held set is still current (304). */
  | "unchanged"
  /** The node could not be reached. Whatever is held stands. */
  | "unreachable"
  /** The node refused the credential. Nothing is held afterwards. */
  | "unauthenticated"
  /** The node refused for a reason of its own — an event this caller cannot resolve. */
  | "refused"
  /** The node answered with something that is not a read set. */
  | "unusable";

export interface OfflineReadSetPullResult {
  readonly outcome: OfflineReadSetPullOutcome;
  /** The node's own words, when it refused with any. */
  readonly detail: string | null;
}

/** The node's own words for a refusal, when it sent any. */
function refusalDetail(body: unknown): string | null {
  const message = (body as { message?: unknown } | null)?.message;

  return typeof message === "string" && message !== "" ? message : null;
}

function pullPath(eventId: string | null): string {
  return eventId === null
    ? "/api/offline-read-set"
    : `/api/offline-read-set?event_id=${encodeURIComponent(eventId)}`;
}

/**
 * Fetch the set for a context and store it.
 *
 * Conditional by the version the store already holds, so the refresh that finds
 * nothing new costs a header rather than a payload — the device asking is the one
 * on the weak connection at the event.
 *
 * A refusal is not an unreachable node and is not treated as one. A 401 or 403
 * means the node was reached and said this credential is no good, so the set goes
 * with it: continuing to hold records composed from a withdrawn grant is the one
 * thing a device-local copy must never do (CLIENT-006, CLIENT-022). Any other
 * refusal — an event this caller cannot resolve on this node — leaves what is
 * held alone, because it says nothing about the caller's standing in the context
 * they are actually in.
 */
export async function pullOfflineReadSet(
  context: OfflineReadSetContext,
  options: { readonly now?: Date } = {},
): Promise<OfflineReadSetPullResult> {
  const held = offlineReadSetStore.version();
  const headers: Record<string, string> = {};

  if (held !== null) {
    headers["If-None-Match"] = `"${held}"`;
  }

  let response: Response;

  try {
    response = await meridianFetch(pullPath(context.eventId), { headers });
  } catch {
    return { outcome: "unreachable", detail: null };
  }

  if (response.status === 304) {
    return { outcome: "unchanged", detail: null };
  }

  const text = await response.text();
  let body: unknown = null;

  if (text !== "") {
    try {
      body = JSON.parse(text) as unknown;
    } catch {
      body = null;
    }
  }

  if (!response.ok) {
    const detail = refusalDetail(body);

    if (response.status === 401 || response.status === 403) {
      clearOfflineReadSet();

      return { outcome: "unauthenticated", detail };
    }

    return { outcome: "refused", detail };
  }

  try {
    await offlineReadSetStore.replace(
      body,
      context,
      (options.now ?? new Date()).toISOString(),
    );
  } catch {
    // Not a read set. What was held stands, because a device holding last
    // night's authorized records is better off than one holding nothing
    // (technical spec 9.3).
    return { outcome: "unusable", detail: null };
  }

  offlineReadSetRevision.value += 1;

  return { outcome: "refreshed", detail: null };
}

/**
 * Read the durable copy into memory (import-time, and after a simulated restart).
 */
export async function hydrateOfflineReadSet(): Promise<void> {
  const record = await offlineReadSetStore.hydrate();

  if (record !== null) {
    offlineReadSetRevision.value += 1;
  }
}

void hydrateOfflineReadSet();

/**
 * Forget everything this device holds offline.
 *
 * Sign-out, context switch, and shared-workstation session end all reach this
 * through the reset registry (technical spec 13.3). Synchronous in memory, so
 * there is no tick in which the previous session's rows are still readable.
 */
export function clearOfflineReadSet(): void {
  offlineReadSetStore.clear();
  offlineReadSetRevision.value += 1;
}

/** Reset store state between tests. */
export function resetOfflineReadSet(): void {
  offlineReadSetStore.clear();
  offlineReadSetRevision.value = 0;
}
