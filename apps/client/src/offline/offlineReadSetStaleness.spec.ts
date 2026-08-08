// The 11A.4 staleness rule applied to the offline read set (M18.49;
// CLIENT-001; technical spec 9.3, 11A.4).

import { describe, expect, it } from "vitest";

import { offlineReadSetReadiness } from "@/offline/offlineReadSetFixture";
import { evaluateOfflineReadSet } from "@/offline/offlineReadSetStaleness";

const INSIDE_WINDOW = new Date("2027-06-04T12:00:00Z");
const AFTER_WINDOW = new Date("2027-06-09T12:00:00Z");

describe("evaluating a held set", () => {
  it("grants a set inside the window it was composed for", () => {
    expect(
      evaluateOfflineReadSet(
        offlineReadSetReadiness(),
        "storage",
        INSIDE_WINDOW,
      ),
    ).toEqual({
      access: "granted",
      reason: null,
      windowEndsAt: "2027-06-08T12:00:00+00:00",
    });
  });

  it("refuses a set past its event window rather than serving it stale", () => {
    /*
     * The rule the permission cache already applies, applied to the other thing
     * a device holds between answers: a cached answer is usable for the duration
     * of the event the node is locked to and no longer (technical spec 11A.4).
     */
    expect(
      evaluateOfflineReadSet(offlineReadSetReadiness(), "storage", AFTER_WINDOW),
    ).toEqual({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: "2027-06-08T12:00:00+00:00",
    });
  });

  it("refuses a set the node composed before the window closed, too", () => {
    // The set is data *for an event*, so a window that closes while the
    // application is open closes underneath rows composed before it. The
    // permission cache lets a live document stand; a read set does not get to.
    expect(
      evaluateOfflineReadSet(
        offlineReadSetReadiness(),
        "network",
        AFTER_WINDOW,
      ).access,
    ).toBe("refresh_required");
  });

  it("grants a set whose event declares no window end", () => {
    // A window with no recorded end has not ended. That is the event-authority
    // rule verbatim, and it is the honest reading of the question being asked.
    expect(
      evaluateOfflineReadSet(
        offlineReadSetReadiness({ usable_until: null }),
        "storage",
        AFTER_WINDOW,
      ),
    ).toEqual({ access: "granted", reason: null, windowEndsAt: null });
  });

  it("refuses a window end it cannot read", () => {
    // Treated as ended, because granting from a timestamp nobody could parse is
    // the one way a malformed value could extend a set's life.
    expect(
      evaluateOfflineReadSet(
        offlineReadSetReadiness({ usable_until: "whenever" }),
        "storage",
        INSIDE_WINDOW,
      ),
    ).toEqual({
      access: "refresh_required",
      reason: "event_window_ended",
      windowEndsAt: "whenever",
    });
  });

  it("refuses a stored set that names no event", () => {
    // Nothing bounds its age, which is the second condition 11A.4 names
    // alongside the ended window.
    expect(
      evaluateOfflineReadSet(
        offlineReadSetReadiness({ context_event_id: null, usable_until: null }),
        "storage",
        INSIDE_WINDOW,
      ),
    ).toEqual({
      access: "refresh_required",
      reason: "no_event_context",
      windowEndsAt: null,
    });
  });

  it("grants the same set while it is the node's own answer", () => {
    /*
     * A staff member with no event resolved still holds their own record and
     * their own documents. Refusing the node's answer the moment it arrives
     * would leave them with nothing while online, which is not what a staleness
     * rule is for — the copy read off disk tomorrow is the one with no answer to
     * "how old is this".
     */
    expect(
      evaluateOfflineReadSet(
        offlineReadSetReadiness({ context_event_id: null, usable_until: null }),
        "network",
        INSIDE_WINDOW,
      ).access,
    ).toBe("granted");
  });

  it("refuses when the device holds nothing at all", () => {
    expect(evaluateOfflineReadSet(null, "storage", INSIDE_WINDOW)).toEqual({
      access: "refresh_required",
      reason: "nothing_held",
      windowEndsAt: null,
    });
  });
});
