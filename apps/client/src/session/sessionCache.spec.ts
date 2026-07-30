import { beforeEach, describe, expect, it } from "vitest";

import {
  SESSION_CACHE_KEY,
  clearCachedSession,
  createSessionCache,
  readCachedSession,
  writeCachedSession,
} from "@/session/sessionCache";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

describe("session cache", () => {
  beforeEach(() => {
    window.localStorage.clear();
  });

  it("persists the session document across a restart", () => {
    writeCachedSession(fixtureSessionDocument(), "2026-09-11T18:30:05+00:00");

    // A fresh cache instance stands in for a reload: nothing is carried in
    // memory, so anything read back came off the device.
    const restarted = createSessionCache(window.localStorage).read();

    expect(restarted?.cachedAt).toBe("2026-09-11T18:30:05+00:00");
    expect(restarted?.document.user.email).toBe("dana@example.com");
    expect(restarted?.document.capabilities).toEqual([
      "department.manage",
      "shift.assign",
    ]);
  });

  it("replaces the stored document rather than merging with it", () => {
    writeCachedSession(fixtureSessionDocument());
    writeCachedSession(
      fixtureSessionDocument({ capabilities: ["shift.assign"], roles: [] }),
    );

    expect(readCachedSession()?.document.capabilities).toEqual([
      "shift.assign",
    ]);
    expect(readCachedSession()?.document.roles).toEqual([]);
  });

  it("forgets the session on request", () => {
    writeCachedSession(fixtureSessionDocument());
    clearCachedSession();

    expect(readCachedSession()).toBeNull();
    expect(window.localStorage.getItem(SESSION_CACHE_KEY)).toBeNull();
  });

  it("reads nothing when the device has never stored a session", () => {
    expect(readCachedSession()).toBeNull();
  });

  it("discards a corrupt entry instead of booting from it", () => {
    window.localStorage.setItem(SESSION_CACHE_KEY, "{not json");

    expect(readCachedSession()).toBeNull();
  });

  it("discards an entry written under a different envelope version", () => {
    window.localStorage.setItem(
      SESSION_CACHE_KEY,
      JSON.stringify({
        version: 2,
        document: fixtureSessionDocument(),
        cachedAt: "2026-09-11T18:30:05+00:00",
      }),
    );

    expect(readCachedSession()).toBeNull();
  });

  it("discards a half-document rather than establishing permissions from it", () => {
    // A document that lost its capability list would otherwise boot the client
    // as a user with no authority, which looks like a permission problem rather
    // than a storage problem.
    const { capabilities: _capabilities, ...withoutCapabilities } =
      fixtureSessionDocument();

    window.localStorage.setItem(
      SESSION_CACHE_KEY,
      JSON.stringify({
        version: 1,
        document: withoutCapabilities,
        cachedAt: "2026-09-11T18:30:05+00:00",
      }),
    );

    expect(readCachedSession()).toBeNull();
  });

  it("discards a document whose context is missing rather than leaving staleness unbounded", () => {
    const { context: _context, ...withoutContext } = fixtureSessionDocument();

    window.localStorage.setItem(
      SESSION_CACHE_KEY,
      JSON.stringify({
        version: 1,
        document: withoutContext,
        cachedAt: "2026-09-11T18:30:05+00:00",
      }),
    );

    expect(readCachedSession()).toBeNull();
  });

  it("keeps the session for the life of the page when storage is unavailable", () => {
    const cache = createSessionCache(null);

    cache.write(fixtureSessionDocument(), "2026-09-11T18:30:05+00:00");

    expect(cache.read()?.document.user.id).toBe("user-dana");

    cache.clear();

    expect(cache.read()).toBeNull();
  });
});
