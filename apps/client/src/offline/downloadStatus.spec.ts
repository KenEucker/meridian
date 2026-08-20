// Per-artifact offline download status (CLIENT-025, CLIENT-027; technical spec
// 9.7; UI contract 16.4, 11.14).
//
// Two things are under test and they are the two the spec section leads with.
// The denominator: it comes only from node responses, so a device with no
// session is at 0 of 0 and a caller whose context names no organization is at
// 1 of 1 — never stuck at 1 of 2 against a client-side list. The completion
// notice: it fires exactly on the incomplete-to-complete transition, never on
// a refresh check that downloads nothing, and again only after the device has
// been incomplete in between.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { nextTick } from "vue";

import {
  installBrandingProfileForTests,
  MERIDIAN_PROFILE,
  resetToMeridian,
} from "@/branding/brandingProfile";
import {
  BRANDING_ARTIFACT_KEY,
  DOWNLOAD_COMPLETE_NOTICE_MS,
  downloadCompleteNoticeVisible,
  installDownloadStatusObserver,
  READ_SET_ARTIFACT_KEY,
  recordDownloadStatusObservation,
  resetDownloadStatusForTests,
  resolveDownloadStatus,
  type DownloadStatus,
} from "@/offline/downloadStatus";
import {
  installOfflineReadSet,
  offlineReadSetPayload,
} from "@/offline/offlineReadSetFixture";
import {
  refreshOfflineReadSet,
  resetOfflineReadSetRefresh,
} from "@/offline/offlineReadSetRefresh";
import { resetOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { configureMeridianApi } from "@/api/meridianApi";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import {
  FIXTURE_ORGANIZATION,
  fixtureSessionDocument,
} from "@/session/sessionDocumentFixture";

const insideWindow = new Date("2026-09-11T18:35:00+00:00");

function installFixtureSession(
  overrides: Parameters<typeof fixtureSessionDocument>[0] = {},
): void {
  installClientSession(fixtureSessionDocument(overrides), "network", insideWindow);
}

/** A branding profile for the fixture organization, applied as if fetched. */
function installFixtureBranding(): void {
  installBrandingProfileForTests({
    ...MERIDIAN_PROFILE,
    organization_id: FIXTURE_ORGANIZATION,
    display_name: "Northwood Collective",
    is_branded: true,
  });
}

/** A read set whose window is open around the moments these specs ask at. */
function usableReadSetPayload() {
  return offlineReadSetPayload({
    readiness: { usable_until: "2099-01-01T00:00:00+00:00" },
  });
}

function status(overrides: Partial<DownloadStatus> = {}): DownloadStatus {
  return {
    named: 2,
    downloaded: 2,
    complete: true,
    artifacts: [],
    ...overrides,
  };
}

beforeEach(() => {
  window.localStorage.clear();
  resetDownloadStatusForTests();
  resetOfflineReadSet();
  resetOfflineReadSetRefresh();
  resetToMeridian();
  clearClientSession();
});

afterEach(() => {
  resetDownloadStatusForTests();
  resetOfflineReadSet();
  resetOfflineReadSetRefresh();
  resetToMeridian();
  clearClientSession();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
  vi.useRealTimers();
  window.localStorage.clear();
});

describe("the denominator", () => {
  it("names nothing for a device with no session", () => {
    const resolved = resolveDownloadStatus();

    expect(resolved.named).toBe(0);
    expect(resolved.artifacts).toEqual([]);
    // 0 of 0 is not complete: nothing has been named, so nothing has landed.
    expect(resolved.complete).toBe(false);
  });

  it("composes from what the node's responses name for this caller", () => {
    installFixtureSession();

    const resolved = resolveDownloadStatus();

    expect(resolved.named).toBe(2);
    expect(resolved.artifacts.map((artifact) => artifact.key)).toEqual([
      READ_SET_ARTIFACT_KEY,
      BRANDING_ARTIFACT_KEY,
    ]);
    expect(resolved.downloaded).toBe(0);
    expect(resolved.complete).toBe(false);
  });

  it("does not count an artifact the node never named for this caller", () => {
    // A context resolving no organization names no branding assets: the
    // denominator is 1, not 2-with-one-forever-pending. The same rule is what
    // keeps a caller entitled to no map package at 2 of 2 rather than 2 of 3.
    installFixtureSession({
      context: {
        organization_id: null,
        event_id: "event-decompression-2026",
        department_id: null,
        node_locked: true,
        node_locked_event_id: "event-decompression-2026",
        switching_available: false,
      },
    });

    const resolved = resolveDownloadStatus();

    expect(resolved.named).toBe(1);
    expect(resolved.artifacts[0]?.key).toBe(READ_SET_ARTIFACT_KEY);
  });

  it("reaches complete when every named artifact is held", async () => {
    installFixtureSession();
    await installOfflineReadSet(usableReadSetPayload());
    installFixtureBranding();

    const resolved = resolveDownloadStatus();

    expect(resolved.downloaded).toBe(2);
    expect(resolved.complete).toBe(true);

    const readSet = resolved.artifacts.find(
      (artifact) => artifact.key === READ_SET_ARTIFACT_KEY,
    );

    expect(readSet?.state).toBe("downloaded");
    // Delivery moment, which is what the row's "last downloaded" claims.
    expect(readSet?.lastDownloadedAt).not.toBeNull();
  });

  it("reports a refused refresh as failed, with the node's words", async () => {
    installFixtureSession();
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({ message: "This caller cannot resolve that event." }),
            { status: 422, headers: { "content-type": "application/json" } },
          ),
      ),
    );

    await refreshOfflineReadSet("requested");

    const readSet = resolveDownloadStatus().artifacts.find(
      (artifact) => artifact.key === READ_SET_ARTIFACT_KEY,
    );

    expect(readSet?.state).toBe("failed");
    expect(readSet?.detail).toBe("This caller cannot resolve that event.");
  });

  it("reports an unreachable node as pending, not as failure", async () => {
    installFixtureSession();
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    await refreshOfflineReadSet("requested");

    const readSet = resolveDownloadStatus().artifacts.find(
      (artifact) => artifact.key === READ_SET_ARTIFACT_KEY,
    );

    // An unreachable node is the situation the offline machinery exists for,
    // never by itself a defect to dress in red.
    expect(readSet?.state).toBe("pending");
  });
});

describe("the completion notice", () => {
  it("fires exactly once on the transition from incomplete to complete", () => {
    expect(recordDownloadStatusObservation(status({ complete: false }))).toBe(
      false,
    );
    expect(recordDownloadStatusObservation(status({ complete: true }))).toBe(
      true,
    );
    expect(downloadCompleteNoticeVisible.value).toBe(true);
  });

  it("does not fire for a refresh check that downloads nothing", () => {
    recordDownloadStatusObservation(status({ complete: false }));
    recordDownloadStatusObservation(status({ complete: true }));

    // The refresh that found nothing new: still complete, no transition.
    expect(recordDownloadStatusObservation(status({ complete: true }))).toBe(
      false,
    );
  });

  it("does not fire for a device that was never observed incomplete", () => {
    // Booting already holding everything is not a transition; the first
    // observation has nothing to have moved from.
    expect(recordDownloadStatusObservation(status({ complete: true }))).toBe(
      false,
    );
    expect(downloadCompleteNoticeVisible.value).toBe(false);
  });

  it("fires again only after the device has been incomplete again", () => {
    recordDownloadStatusObservation(status({ complete: false }));
    recordDownloadStatusObservation(status({ complete: true }));
    recordDownloadStatusObservation(status({ complete: true }));

    // A context switch or a newly published artifact makes it incomplete...
    recordDownloadStatusObservation(status({ complete: false }));

    // ...and the download that closes the gap announces itself once more.
    expect(recordDownloadStatusObservation(status({ complete: true }))).toBe(
      true,
    );
  });

  it("dismisses itself", () => {
    vi.useFakeTimers();

    recordDownloadStatusObservation(status({ complete: false }));
    recordDownloadStatusObservation(status({ complete: true }));
    expect(downloadCompleteNoticeVisible.value).toBe(true);

    vi.advanceTimersByTime(DOWNLOAD_COMPLETE_NOTICE_MS + 1);

    expect(downloadCompleteNoticeVisible.value).toBe(false);
  });

  it("is driven by the observed status for the life of the application", async () => {
    const stop = installDownloadStatusObserver();

    try {
      installFixtureSession();
      await nextTick();
      expect(downloadCompleteNoticeVisible.value).toBe(false);

      await installOfflineReadSet(usableReadSetPayload());
      installFixtureBranding();
      await nextTick();

      expect(downloadCompleteNoticeVisible.value).toBe(true);
    } finally {
      stop();
    }
  });
});
