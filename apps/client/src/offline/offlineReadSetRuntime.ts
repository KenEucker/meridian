// The client's single offline read set, and the pull that fills it (M18.48;
// ADR-0003; CLIENT-021, CLIENT-022; technical spec 9.3, 9.5, 11A.7).
//
// One store per device, hydrated from durable storage at import — before any
// screen mounts and before the client knows whether it has a network — so a
// device that was closed at an event comes back holding what its user may read.
// Hydration is asynchronous because IndexedDB is, and the revision ref below is
// how a surface that rendered before it finished re-renders when it does.
//
// **What this module owns and what it does not.** It owns the store, the
// conditional request that fills it, and the gate every read of it passes
// through. The first two cannot sensibly be separated, because `If-None-Match`
// is the version the store is holding and a pull that did not know it would
// transfer the whole set on every refresh. The gate is here because it needs the
// one thing only this module knows — whether what is held came off the wire or
// off the disk (M18.49; technical spec 11A.4). It does not own *when* to pull:
// login, reconnect, and context switch are `offlineReadSetRefresh.ts`, which
// also reports refresh state to the banner.
//
// **Read through the gate, not through the store.** `offlineReadSetStore` is
// exported for the specs and for the refresh module; a surface reads through
// {@link readOfflineReadSetSection} and {@link searchOfflineReadSet}, which
// refuse a set past its event window rather than serving rows composed for an
// event that has closed. M18.50 moves the read models onto these.
//
// Dropping is registered by `main.ts` alongside the other context-scoped caches,
// rather than here, so the list of what does not survive a switch stays readable
// in one place.

import { ref } from "vue";

import { meridianFetch } from "@/api/meridianApi";
import type { OfflineReadSetRow } from "@/offline/offlineReadSet";
import {
  evaluateOfflineReadSet,
  type OfflineReadSetSource,
  type OfflineReadSetVerdict,
} from "@/offline/offlineReadSetStaleness";
import { createOfflineReadSetStore } from "@/offline/offlineReadSetStore";
import {
  createOfflineReadSetStorage,
  type OfflineReadSetContext,
} from "@/offline/offlineReadSetStorage";
import { clientSessionState } from "@/session/clientSession";
import { withinRefreshFallback } from "@/session/sessionStaleness";

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

/**
 * Where the set the device is holding came from.
 *
 * Only 11A.4 cares, and it cares about one case: a set with no event context is
 * the node's current answer when it has just arrived and is an unbounded stored
 * copy the next morning. Tracked here rather than written into the stored record
 * because it is a fact about this session, not about the record — a record that
 * came off the wire is a record off the disk as soon as the process restarts.
 */
let heldSource: OfflineReadSetSource | null = null;

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
    /*
     * The node answered: the copy this device holds is its current answer, as
     * surely as a fresh transfer would have been. Both facts that hang off that
     * are recorded — the set is re-stamped with this moment, because a 304 is a
     * successful refresh and the six-week fallback (CLIENT-008A) counts from
     * the last one; and the source becomes the wire, because a stored set with
     * no event context is refused as unbounded (11A.4) when the one thing that
     * would bound it is exactly the confirmation that just arrived.
     */
    await offlineReadSetStore.confirm(
      (options.now ?? new Date()).toISOString(),
    );
    heldSource = "network";
    offlineReadSetRevision.value += 1;

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

  heldSource = "network";
  offlineReadSetRevision.value += 1;

  return { outcome: "refreshed", detail: null };
}

/**
 * The read of the durable copy that is in progress, or the last one.
 *
 * Held rather than fired and forgotten, because the refresh issued at boot
 * (M18.49) has to wait for it. `If-None-Match` is the version the store is
 * holding, and a refresh that overtook hydration would hold no version, send no
 * conditional header, and transfer the whole set — on every start, on precisely
 * the connection this endpoint was made conditional for.
 */
let hydration: Promise<void> = Promise.resolve();

/**
 * Read the durable copy into memory (import-time, and after a simulated restart).
 */
export function hydrateOfflineReadSet(): Promise<void> {
  hydration = (async () => {
    const record = await offlineReadSetStore.hydrate();

    if (record !== null) {
      heldSource = "storage";
      offlineReadSetRevision.value += 1;
    }
  })();

  return hydration;
}

/** Resolves once this device knows what it was already holding. */
export function offlineReadSetHydrated(): Promise<void> {
  return hydration;
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
  heldSource = null;
  offlineReadSetRevision.value += 1;
}

/** Reset store state between tests. */
export function resetOfflineReadSet(): void {
  offlineReadSetStore.clear();
  heldSource = null;
  hydration = Promise.resolve();
  offlineReadSetRevision.value = 0;
}

/**
 * Whether the set this device holds may be served, and why not when it may not.
 *
 * Evaluated against the moment it is asked rather than stamped at refresh time,
 * because the thing that changes is the clock: an application left open through
 * the end of an event window has to stop serving where it stands, not at its
 * next restart.
 *
 * The six-week fallback (CLIENT-008A) is decided here because it takes both
 * halves of what the two stores hold: the session document, whose device trust
 * bounds the fallback, and this set's own `storedAt`, which is the last
 * successful refresh the six weeks count from. The rule itself is the
 * session's, imported rather than restated, so the permission cache and the
 * read set cannot drift apart about what the fallback is.
 */
export function offlineReadSetVerdict(
  now: Date = new Date(),
): OfflineReadSetVerdict {
  // Read reactively, so a computed built on top of this recomputes when a
  // refresh replaces the set or a drop takes it away.
  void offlineReadSetRevision.value;

  const held = offlineReadSetStore.held();

  if (held === null || heldSource === null) {
    return evaluateOfflineReadSet(null, "storage", now);
  }

  const document = clientSessionState.document;

  return evaluateOfflineReadSet(
    held.set.readiness,
    heldSource,
    now,
    document !== null && withinRefreshFallback(document, held.storedAt, now),
  );
}

/** Whether a surface may render from what this device holds. */
export function offlineReadSetUsable(now: Date = new Date()): boolean {
  return offlineReadSetVerdict(now).access === "granted";
}

/**
 * When this device took delivery of the set it holds, or null when it holds
 * none.
 *
 * Delivery rather than composition, because that is what a stale-read notice is
 * claiming: "the copy this device stored" is dated by the moment the device
 * stored it (M18.50; UI implementation contract 16.2). A 304 re-stamps it — a
 * confirmation that the copy is current is the node re-answering with it, and
 * the moment the six-week fallback counts from (CLIENT-008A).
 */
export function offlineReadSetStoredAt(): string | null {
  void offlineReadSetRevision.value;

  return offlineReadSetStore.held()?.storedAt ?? null;
}

/**
 * One section's rows, or none where the set may not be served.
 *
 * Refusal is silence rather than an error, and that is the same shape a caller
 * already handles: a section the node did not compose for this user is absent
 * too. What tells the two apart is {@link offlineReadSetVerdict} — a surface
 * that needs to say *why* it is showing nothing asks that, and the banner says
 * it once for the whole application (M18.49; UI contract 11.13).
 */
export function readOfflineReadSetSection<T = OfflineReadSetRow>(
  name: string,
  now: Date = new Date(),
): readonly T[] {
  void offlineReadSetRevision.value;

  return offlineReadSetUsable(now) ? offlineReadSetStore.section<T>(name) : [];
}

/** Whether the set carries this section at all, and may be served. */
export function offlineReadSetCarries(
  name: string,
  now: Date = new Date(),
): boolean {
  void offlineReadSetRevision.value;

  return offlineReadSetUsable(now) && offlineReadSetStore.carries(name);
}

/** Rows of one section matching a typed query, or none where the set may not be served. */
export function searchOfflineReadSet<T = OfflineReadSetRow>(
  name: string,
  query: string,
  fields: readonly string[],
  now: Date = new Date(),
): readonly T[] {
  void offlineReadSetRevision.value;

  return offlineReadSetUsable(now)
    ? offlineReadSetStore.search<T>(name, query, fields)
    : [];
}
