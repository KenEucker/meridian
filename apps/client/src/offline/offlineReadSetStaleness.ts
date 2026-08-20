// How long an offline read set stays usable (M18.49; CLIENT-001; technical spec
// 9.3, 11A.4).
//
// This is the same rule the permission cache already applies in
// `session/sessionStaleness.ts`, applied to the other thing a device holds
// between answers. Section 11A.4 states it once and it governs both: a cached
// answer "remains usable for the duration of the event the node is locked to",
// and once that window has ended — or where there is no event context to bound
// it by — the client requires a successful refresh before working from what it
// holds. Tying staleness to the event window rather than to a number of hours is
// the whole point: a five-day festival with no connectivity is the case Meridian
// is built for, and a device that dropped its data eight hours in would fail in
// exactly the conditions it was bought for.
//
// Two things differ from the permission cache, and both follow from what a read
// set is rather than from a preference.
//
// **The window is the node's word, not the client's arithmetic.** The set
// carries `readiness.usable_until`, which the composer fills from the context
// event's active window. The client does not re-derive it from event dates it
// happens to hold: the node composed the set, the node knows which event it was
// composed against, and a second derivation on the device is a second answer to
// a question that already has one.
//
// **The event window binds a live answer too.** `evaluateSessionDocument` bounds
// the cached copy and lets a document straight from the node stand, which is
// right for permissions: the node has just spoken. A read set is different in
// one respect — it is *data for an event*, and an event window that closes while
// the application is open closes underneath rows composed before it. So the
// window is checked whatever the set's source. What is checked only against the
// stored copy is the *absence* of an event context: a set the node composed a
// moment ago with no event to bound it is the node's current answer, and there
// is nothing stale about it. The same set read off disk tomorrow has nothing to
// say how old it is, which is precisely the condition 11A.4 refuses.
//
// **The six-week fallback widens both refusals** (CLIENT-008A). The requirement
// widens "the cached response" as a whole — navigation, permissions, *and
// cached data* — so once the event window has ended, or where a stored set
// names no event to bound it by, the set stays servable for up to six weeks
// from the last successful refresh, bounded by the device session's trust.
// Whether that fallback covers this device right now is the session's question
// (`withinRefreshFallback` in `session/sessionStaleness.ts`); the caller
// answers it and passes the verdict in, so the two caches cannot disagree
// about the rule. What the fallback never covers is a device holding nothing:
// six weeks of grace on an absent set would be a grant composed from nothing.

import type { OfflineReadSetReadiness } from "@/offline/offlineReadSet";

/** Whether the set a device holds may be served to a surface. */
export type OfflineReadSetAccess = "granted" | "refresh_required";

/**
 * Why the set may not be served. Reported rather than collapsed into a boolean
 * so a surface can say which condition applies instead of "something is stale".
 */
export type OfflineReadSetRefreshReason =
  /** The device holds no set at all. */
  | "nothing_held"
  /** The stored set names no event, so there is no window to bound it by. */
  | "no_event_context"
  /** The window the set was composed for has ended. */
  | "event_window_ended";

/** Where the set the device is holding came from. */
export type OfflineReadSetSource =
  /** The node composed it for this session. */
  | "network"
  /** It was read off this device's durable storage at boot. */
  | "storage";

export interface OfflineReadSetVerdict {
  readonly access: OfflineReadSetAccess;
  /** Set only when access is refused. */
  readonly reason: OfflineReadSetRefreshReason | null;
  /** The window end the verdict was decided against, when there is one. */
  readonly windowEndsAt: string | null;
}

const GRANTED: OfflineReadSetVerdict = Object.freeze({
  access: "granted",
  reason: null,
  windowEndsAt: null,
});

const NOTHING_HELD: OfflineReadSetVerdict = Object.freeze({
  access: "refresh_required",
  reason: "nothing_held",
  windowEndsAt: null,
});

function refuse(
  reason: OfflineReadSetRefreshReason,
  windowEndsAt: string | null,
): OfflineReadSetVerdict {
  return Object.freeze({
    access: "refresh_required",
    reason,
    windowEndsAt,
  });
}

function grant(windowEndsAt: string | null): OfflineReadSetVerdict {
  return Object.freeze({
    access: "granted",
    reason: null,
    windowEndsAt,
  });
}

/**
 * Whether the set a device holds may still be served.
 *
 * A window with no recorded end has not ended, which follows the event-authority
 * rule verbatim and is the honest reading: the question is whether the window
 * has passed, and there is nothing for it to have passed. An end this client
 * cannot parse is treated as ended, because the alternative is serving rows on
 * the strength of a timestamp nobody could read — the one way a malformed value
 * could extend a set's life rather than shorten it. The six-week fallback still
 * applies to it, as it does for the session: the fallback is decided from
 * timestamps this client *can* read.
 *
 * `withinRefreshFallback` is whether CLIENT-008A's six-week bound covers this
 * device right now, decided by the caller from the session's own rule and the
 * moment this set was last successfully refreshed.
 */
export function evaluateOfflineReadSet(
  readiness: OfflineReadSetReadiness | null,
  source: OfflineReadSetSource,
  now: Date = new Date(),
  withinRefreshFallback = false,
): OfflineReadSetVerdict {
  if (readiness === null) {
    return NOTHING_HELD;
  }

  const endsAt = readiness.usable_until ?? null;

  if (readiness.context_event_id === null) {
    /*
     * Nothing bounds this set's age. The node's own answer is allowed to say so
     * — a staff member with no event resolved still holds their own record and
     * their own documents, and refusing that the moment it arrives would leave
     * them with nothing while online. The copy read off disk has no window to
     * answer "how old is this", so it is served only while the six-week
     * fallback covers it (CLIENT-008A) and refused beyond that (11A.4).
     */
    if (source === "network" || withinRefreshFallback) {
      return GRANTED;
    }

    return refuse("no_event_context", null);
  }

  if (endsAt === null) {
    return GRANTED;
  }

  const end = Date.parse(endsAt);

  if (Number.isNaN(end) || now.getTime() > end) {
    return withinRefreshFallback
      ? grant(endsAt)
      : refuse("event_window_ended", endsAt);
  }

  return grant(endsAt);
}
