// How long a cached session stays usable (M16.5; CLIENT-008, CLIENT-008A;
// technical spec 11A.4, 12.2; UI implementation contract 19A.2).
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
//
// CLIENT-008A widens the rule with a second bound: the cached response stays
// usable until the *later* of the event window end and six weeks from the last
// successful refresh — the same window device trust itself is valid for
// (technical spec 12.2). The fallback is what keeps a device holding no event
// context — a phone pointed at central between events, a kiosk waiting for its
// node — from losing its navigation the moment the node stops answering. An
// unreachable node is the situation this cache exists for, never by itself a
// reason to withdraw what the device holds. The fallback is bounded by the
// device session's trust: a document naming a device whose trust has expired or
// been revoked gets no widening, only the original event-window rule.

import {
  sessionContextEvent,
  type SessionDocument,
  type SessionEvent,
} from "@/session/sessionDocument";

/**
 * The six-week device trust window (technical spec 12.2), which is also the
 * cached-session fallback bound (CLIENT-008A) and the viewed-incident cache
 * expiry (INC-018). One constant, because the specs tie all three to the same
 * duration on purpose.
 */
export const DEVICE_TRUST_WINDOW_MS = 6 * 7 * 24 * 60 * 60 * 1000;

/**
 * Whether a session document may establish navigation and permissions.
 *
 * `refresh_required` is not an error state. It is the client saying it will not
 * grant access from what it holds until it has heard from the node again.
 */
export type SessionAccess = "granted" | "refresh_required";

/**
 * Why a refresh is required. Reported so a surface can say which of the
 * CLIENT-008 conditions applies rather than "something is stale". Under
 * CLIENT-008A each of these is reported only once the six-week fallback has
 * also lapsed (or was never available because trust expired).
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

function grant(windowEndsAt: string | null = null): SessionVerdict {
  return Object.freeze({ access: "granted", reason: null, windowEndsAt });
}

function refuse(
  reason: SessionRefreshReason,
  windowEndsAt: string | null = null,
): SessionVerdict {
  return Object.freeze({ access: "refresh_required", reason, windowEndsAt });
}

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
 * Whether the six-week fallback (CLIENT-008A) still covers this device.
 *
 * Three things have to hold, and each failure closes the fallback rather than
 * the whole cache — the original event-window rule still applies without it:
 *
 *  - a last successful refresh is known and readable. A device that cannot say
 *    when the node last answered has nothing to count six weeks from;
 *  - the device session's trust has not expired or been revoked. The fallback
 *    is "the same window device trust itself is valid for" (technical spec
 *    12.2), so a document naming an untrusted device gets no widening. A
 *    document naming no device at all is a shared-workstation session or an
 *    older build's copy — nothing was asked, which is not a refusal;
 *  - `now` is inside six weeks of that refresh.
 *
 * Exported because CLIENT-008A widens "the cached response" as a whole —
 * navigation, permissions, *and cached data* — so the offline read set's
 * staleness gate applies exactly this rule, counted from its own last
 * successful refresh, rather than a second derivation of it. The parameter is
 * the device block alone because that is all the rule reads of the document.
 */
export function withinRefreshFallback(
  document: Pick<SessionDocument, "device">,
  lastRefreshedAt: string | null,
  now: Date,
): boolean {
  if (lastRefreshedAt === null) {
    return false;
  }

  const refreshed = Date.parse(lastRefreshedAt);

  if (Number.isNaN(refreshed)) {
    return false;
  }

  const device = document.device ?? null;

  if (device !== null) {
    if (!device.trusted) {
      return false;
    }

    if (device.trusted_until !== null) {
      const trustedUntil = Date.parse(device.trusted_until);

      // An unreadable trust end closes the fallback rather than extending it,
      // for the same reason an unreadable window end reads as ended below.
      if (Number.isNaN(trustedUntil) || now.getTime() > trustedUntil) {
        return false;
      }
    }
  }

  return now.getTime() <= refreshed + DEVICE_TRUST_WINDOW_MS;
}

/**
 * Whether a session document may still be worked from.
 *
 * A document that came straight from the node is always usable — it *is* the
 * node's current answer — so this is asked of cached documents. The verdict is
 * the later of two bounds (technical spec 11A.4): the event window the document
 * resolved at, and six weeks from `lastRefreshedAt` — the device-clock moment
 * of the last refresh that reached the node, threaded from the cached-session
 * record rather than re-derived here. A caller passing no `lastRefreshedAt`
 * gets the original CLIENT-008 rule alone, which is the fail-closed reading of
 * a record that cannot say when the node last answered.
 */
export function evaluateSessionDocument(
  document: SessionDocument,
  now: Date = new Date(),
  lastRefreshedAt: string | null = null,
): SessionVerdict {
  const fallback = withinRefreshFallback(document, lastRefreshedAt, now);

  if (document.context.event_id === null) {
    // No event context bounds this copy, which under CLIENT-008 alone required
    // a refresh outright. The six-week fallback is precisely for this device —
    // the field-observed failure was an Android Field app losing its whole menu
    // because its node stopped answering between events.
    return fallback ? grant() : refuse("no_event_context");
  }

  const event = sessionContextEvent(document);

  if (event === null) {
    return fallback ? grant() : refuse("event_window_unknown");
  }

  const endsAt = sessionWindowEnd(event);

  if (endsAt === null) {
    return grant();
  }

  const end = Date.parse(endsAt);

  // An unparseable end is treated as ended. The alternative is granting access
  // from a timestamp this client could not read, which is the one way a
  // malformed value could extend a session rather than shorten it. The
  // six-week fallback still applies: it is decided from timestamps this client
  // *can* read.
  if (Number.isNaN(end) || now.getTime() > end) {
    return fallback ? grant(endsAt) : refuse("event_window_ended", endsAt);
  }

  return grant(endsAt);
}
