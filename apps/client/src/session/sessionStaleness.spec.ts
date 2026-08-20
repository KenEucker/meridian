import { describe, expect, it } from "vitest";

import type { SessionDocument } from "@/session/sessionDocument";
import {
  fixtureSessionDocument,
  fixtureSessionEvent,
} from "@/session/sessionDocumentFixture";
import {
  DEVICE_TRUST_WINDOW_MS,
  evaluateSessionDocument,
  sessionWindowEnd,
} from "@/session/sessionStaleness";

const insideWindow = new Date("2026-09-11T18:30:00+00:00");
const afterWindow = new Date("2026-09-16T18:30:00+00:00");

/** A document whose context names no event, the between-events device case. */
function documentWithoutEventContext(): SessionDocument {
  return fixtureSessionDocument({
    context: {
      organization_id: null,
      event_id: null,
      department_id: null,
      node_locked: false,
      node_locked_event_id: null,
      switching_available: true,
    },
  });
}

/** An ISO moment a whole number of weeks before `now`. */
function weeksBefore(now: Date, weeks: number): string {
  return new Date(now.getTime() - weeks * 7 * 24 * 60 * 60 * 1000).toISOString();
}

describe("session staleness", () => {
  it("bounds a cached session by the active event window", () => {
    const document = fixtureSessionDocument();

    expect(evaluateSessionDocument(document, insideWindow)).toEqual({
      access: "granted",
      reason: null,
      windowEndsAt: "2026-09-15T16:00:00+00:00",
    });
  });

  it("requires a refresh once the event window has ended", () => {
    const document = fixtureSessionDocument();

    expect(evaluateSessionDocument(document, afterWindow)).toEqual({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: "2026-09-15T16:00:00+00:00",
    });
  });

  it("stays usable through the whole window, including its final instant", () => {
    const document = fixtureSessionDocument();

    // The day the event's own dates end but its operational phase has not:
    // teardown is inside the active window, and someone is still being checked
    // out during it.
    expect(
      evaluateSessionDocument(document, new Date("2026-09-14T09:00:00+00:00"))
        .access,
    ).toBe("granted");
    expect(
      evaluateSessionDocument(document, new Date("2026-09-15T16:00:00+00:00"))
        .access,
    ).toBe("granted");
    expect(
      evaluateSessionDocument(document, new Date("2026-09-15T16:00:01+00:00"))
        .access,
    ).toBe("refresh_required");
  });

  it("requires a refresh when the client holds no event context and no refresh moment", () => {
    // No `lastRefreshedAt` is the fail-closed reading: the six-week fallback
    // (CLIENT-008A) cannot be counted from a record that does not say when the
    // node last answered, so only the CLIENT-008 rule stands.
    const document = documentWithoutEventContext();

    expect(evaluateSessionDocument(document, insideWindow)).toEqual({
      access: "refresh_required",
      reason: "no_event_context",
      windowEndsAt: null,
    });
  });

  it("requires a refresh when the context names an event the document does not carry", () => {
    // A node locked to an event answers for it even to a caller who holds no
    // association with it, so the event can be named in the context and absent
    // from the association list. There is then no window to check, and the
    // client refuses rather than assuming one.
    const document = fixtureSessionDocument({ events: [] });

    expect(evaluateSessionDocument(document, insideWindow)).toEqual({
      access: "refresh_required",
      reason: "event_window_unknown",
      windowEndsAt: null,
    });
  });

  it("falls back to the event's own end when no active window is recorded", () => {
    const event = fixtureSessionEvent({
      active_event_window_starts_at: null,
      active_event_window_ends_at: null,
    });
    const document = fixtureSessionDocument({ events: [event] });

    expect(sessionWindowEnd(event)).toBe("2026-09-13T16:00:00+00:00");
    expect(
      evaluateSessionDocument(document, new Date("2026-09-12T09:00:00+00:00"))
        .access,
    ).toBe("granted");
    expect(
      evaluateSessionDocument(document, new Date("2026-09-14T09:00:00+00:00"))
        .access,
    ).toBe("refresh_required");
  });

  it("treats a window with no recorded end as not yet ended", () => {
    // The event-authority rule this bound follows says a window with no end
    // stays active until an end is recorded. There is nothing for the client to
    // have passed.
    const event = fixtureSessionEvent({
      ends_at: null,
      active_event_window_ends_at: null,
    });
    const document = fixtureSessionDocument({ events: [event] });

    expect(sessionWindowEnd(event)).toBeNull();
    expect(evaluateSessionDocument(document, afterWindow)).toEqual({
      access: "granted",
      reason: null,
      windowEndsAt: null,
    });
  });

  it("treats an unreadable window end as ended", () => {
    const document = fixtureSessionDocument({
      events: [fixtureSessionEvent({ active_event_window_ends_at: "soon" })],
    });

    expect(evaluateSessionDocument(document, insideWindow)).toEqual({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: "soon",
    });
  });
});

/*
 * The six-week fallback (CLIENT-008A; technical spec 11A.4, 12.2).
 *
 * The cached response stays usable until the later of the event window end and
 * six weeks from the last successful refresh, bounded by device trust. The
 * existing reasons are still the vocabulary; they are just reported only once
 * the fallback has also lapsed, so an unreachable node alone never empties the
 * navigation.
 */
describe("the six-week cached-session fallback", () => {
  it("grants a session with no event context refreshed five weeks ago", () => {
    const document = documentWithoutEventContext();

    expect(
      evaluateSessionDocument(document, insideWindow, weeksBefore(insideWindow, 5)),
    ).toEqual({ access: "granted", reason: null, windowEndsAt: null });
  });

  it("requires a refresh with no event context once seven weeks have passed", () => {
    const document = documentWithoutEventContext();

    expect(
      evaluateSessionDocument(document, insideWindow, weeksBefore(insideWindow, 7)),
    ).toEqual({
      access: "refresh_required",
      reason: "no_event_context",
      windowEndsAt: null,
    });
  });

  it("grants past an ended event window while the refresh is two weeks old", () => {
    // The fixture window ended 2026-09-15T16:00; `afterWindow` is the next day.
    const document = fixtureSessionDocument();

    expect(
      evaluateSessionDocument(document, afterWindow, weeksBefore(afterWindow, 2)),
    ).toEqual({
      access: "granted",
      reason: null,
      windowEndsAt: "2026-09-15T16:00:00+00:00",
    });
  });

  it("requires a refresh once both the window and the fallback have lapsed", () => {
    const document = fixtureSessionDocument();
    const longAfterWindow = new Date("2026-11-01T18:30:00+00:00");

    expect(
      evaluateSessionDocument(
        document,
        longAfterWindow,
        weeksBefore(longAfterWindow, 7),
      ),
    ).toEqual({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: "2026-09-15T16:00:00+00:00",
    });
  });

  it("stays usable through the fallback's final instant and not past it", () => {
    const document = documentWithoutEventContext();
    const refreshedAt = "2026-08-01T00:00:00+00:00";
    const lastInstant = new Date(Date.parse(refreshedAt) + DEVICE_TRUST_WINDOW_MS);
    const justPast = new Date(lastInstant.getTime() + 1000);

    expect(evaluateSessionDocument(document, lastInstant, refreshedAt).access).toBe(
      "granted",
    );
    expect(evaluateSessionDocument(document, justPast, refreshedAt).access).toBe(
      "refresh_required",
    );
  });

  it("covers a context naming an event the document does not carry", () => {
    // A locked node answers for its own event even to a caller holding no
    // association with it. With the fallback open the device keeps working;
    // past it the existing reason is reported.
    const document = fixtureSessionDocument({ events: [] });

    expect(
      evaluateSessionDocument(document, insideWindow, weeksBefore(insideWindow, 2))
        .access,
    ).toBe("granted");
    expect(
      evaluateSessionDocument(document, insideWindow, weeksBefore(insideWindow, 7)),
    ).toEqual({
      access: "refresh_required",
      reason: "event_window_unknown",
      windowEndsAt: null,
    });
  });

  it("closes the fallback when the device session's trust has expired", () => {
    // Bounded by device trust (technical spec 12.2): a recently refreshed copy
    // on a device whose trust lapsed gets no widening, only the window rule.
    const document = fixtureSessionDocument({
      device: {
        id: "device-1",
        label: "Dana's phone",
        trusted: false,
        trust_state: "expired",
        trusted_until: "2026-08-01T00:00:00+00:00",
      },
    });

    expect(
      evaluateSessionDocument(document, afterWindow, weeksBefore(afterWindow, 2)),
    ).toEqual({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: "2026-09-15T16:00:00+00:00",
    });
  });

  it("closes the fallback when the trust window itself has passed", () => {
    // Trusted when the document was cached, but `trusted_until` has since
    // passed on the device's clock. Same reading as an expired trust state.
    const document = fixtureSessionDocument({
      device: {
        id: "device-1",
        label: "Dana's phone",
        trusted: true,
        trust_state: "trusted",
        trusted_until: "2026-09-15T00:00:00+00:00",
      },
    });

    expect(
      evaluateSessionDocument(document, afterWindow, weeksBefore(afterWindow, 2))
        .access,
    ).toBe("refresh_required");
  });

  it("keeps the fallback open for a trusted device inside its trust window", () => {
    const document = fixtureSessionDocument({
      device: {
        id: "device-1",
        label: "Dana's phone",
        trusted: true,
        trust_state: "trusted",
        trusted_until: "2026-10-20T00:00:00+00:00",
      },
    });

    expect(
      evaluateSessionDocument(document, afterWindow, weeksBefore(afterWindow, 2))
        .access,
    ).toBe("granted");
  });

  it("does not shorten an open event window, whatever the refresh age", () => {
    // The bound is the *later* of the two. A refresh seven weeks old inside a
    // still-open window changes nothing: the window rule alone grants.
    const document = fixtureSessionDocument();

    expect(
      evaluateSessionDocument(document, insideWindow, weeksBefore(insideWindow, 7))
        .access,
    ).toBe("granted");
  });
});
