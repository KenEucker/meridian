// How long a cached session stays usable (M16.5; CLIENT-008; technical spec
// 11A.4; UI implementation contract 19A.2).
//
// Staleness is bounded by the event window rather than by a fixed number of
// hours, and that is the whole point of the rule. A multi-day event with no
// connectivity is the normal case Meridian is built for, and a device that lost
// its permissions eight hours into a five-day festival would be a device that
// stopped working in exactly the conditions it was bought for. Tying the bound
// to the event means the cache lasts precisely as long as the operation it was
// cached for.
//
// The window that bounds it is the *active event window* — the same window that
// decides event authority (data/API "Enforcing event authority"), so the cache
// expires when the event's operational phase closes and not at some separate
// client-side deadline. The event's own `ends_at` is the fallback for an event
// with no active window recorded, because "the event window" for an event that
// never declared an operational phase is the event's own dates.
//
// A window with no recorded end has not ended. That follows the authority rule
// verbatim ("a window with no end stays active until an end is recorded") and it
// is the honest reading: the client is being asked whether the window has
// passed, and there is nothing for it to have passed.

import {
  sessionContextEvent,
  type SessionDocument,
  type SessionEvent,
} from "@/session/sessionDocument";

/**
 * Whether a session document may establish navigation and permissions.
 *
 * `refresh_required` is not an error state. It is the client saying it will not
 * grant access from what it holds until it has heard from the node again.
 */
export type SessionAccess = "granted" | "refresh_required";

/**
 * Why a refresh is required. Reported so a surface can say which of the two
 * CLIENT-008 conditions applies rather than "something is stale".
 */
export type SessionRefreshReason =
  /** The client holds no event context to bound staleness by. */
  | "no_event_context"
  /**
   * The context names an event the document does not carry, so there is no
   * window to check. A locked node answers for its own event even to a caller
   * who holds no association with it, which is how this arises.
   */
  | "event_window_unknown"
  /** The window the cache was bounded by has ended. */
  | "event_window_ended";

export interface SessionVerdict {
  readonly access: SessionAccess;
  /** Set only when access is refused. */
  readonly reason: SessionRefreshReason | null;
  /** The window end the verdict was decided against, when there is one. */
  readonly windowEndsAt: string | null;
}

const GRANTED: SessionVerdict = Object.freeze({
  access: "granted",
  reason: null,
  windowEndsAt: null,
});

/**
 * The instant the cached session stops being usable, or null when the event
 * declares no end and the cache is therefore unbounded.
 *
 * The active event window wins over the event's own dates. Where an organizer
 * recorded both, the active window is the one authority is handed over on, and
 * it is normally the wider of the two — setup and teardown are inside the
 * operational phase and outside the published event dates, and a device on site
 * during teardown still needs to check people out.
 */
export function sessionWindowEnd(event: SessionEvent): string | null {
  return event.active_event_window_ends_at ?? event.ends_at ?? null;
}

/**
 * Whether a session document may still be worked from.
 *
 * A document that came straight from the node is always usable — it *is* the
 * node's current answer — so this is asked of cached documents. It is written
 * against the document rather than against the cache entry because the answer
 * depends on the event, not on when the copy was written to disk: a session
 * cached one minute before an event closed is stale, and one cached four days
 * before it closes is not.
 */
export function evaluateSessionDocument(
  document: SessionDocument,
  now: Date = new Date(),
): SessionVerdict {
  if (document.context.event_id === null) {
    return Object.freeze({
      access: "refresh_required",
      reason: "no_event_context",
      windowEndsAt: null,
    });
  }

  const event = sessionContextEvent(document);

  if (event === null) {
    return Object.freeze({
      access: "refresh_required",
      reason: "event_window_unknown",
      windowEndsAt: null,
    });
  }

  const endsAt = sessionWindowEnd(event);

  if (endsAt === null) {
    return GRANTED;
  }

  const end = Date.parse(endsAt);

  // An unparseable end is treated as ended. The alternative is granting access
  // from a timestamp this client could not read, which is the one way a
  // malformed value could extend a session rather than shorten it.
  if (Number.isNaN(end)) {
    return Object.freeze({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: endsAt,
    });
  }

  if (now.getTime() > end) {
    return Object.freeze({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: endsAt,
    });
  }

  return Object.freeze({
    access: "granted",
    reason: null,
    windowEndsAt: endsAt,
  });
}
