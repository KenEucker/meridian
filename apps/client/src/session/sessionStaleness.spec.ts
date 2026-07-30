import { describe, expect, it } from "vitest";

import {
  fixtureSessionDocument,
  fixtureSessionEvent,
} from "@/session/sessionDocumentFixture";
import {
  evaluateSessionDocument,
  sessionWindowEnd,
} from "@/session/sessionStaleness";

const insideWindow = new Date("2026-09-11T18:30:00+00:00");
const afterWindow = new Date("2026-09-16T18:30:00+00:00");

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

  it("requires a refresh when the client holds no event context", () => {
    const document = fixtureSessionDocument({
      context: {
        organization_id: null,
        event_id: null,
        department_id: null,
        node_locked: false,
        node_locked_event_id: null,
        switching_available: true,
      },
    });

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
