// The offline download readout on Settings, and the completion toast
// (CLIENT-026, CLIENT-027; technical spec 9.7; UI contract 16.4, 11.14).
//
// The readout lives beside the cached-permission state (contract 19A.2), so
// these mount the same Settings surface the permission specs do and read the
// section as a person would: the progress summary, each artifact named with
// its state, the repair path on a failure, and the viewed-incident count as a
// count — present for the IC user whose views filled it, absent entirely for
// everybody else.

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount, type VueWrapper } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  installBrandingProfileForTests,
  MERIDIAN_PROFILE,
  resetToMeridian,
} from "@/branding/brandingProfile";
import DownloadCompleteToast from "@/components/DownloadCompleteToast.vue";
import type { ImsIncident } from "@/ims/incidentReadModel";
import {
  recordIncidentView,
  resetViewedIncidentCacheForTests,
} from "@/ims/viewedIncidentCache";
import {
  DOWNLOAD_COMPLETE_NOTICE,
  recordDownloadStatusObservation,
  resetDownloadStatusForTests,
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
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import { resetHiddenPageAnswers } from "@/session/hiddenPages";
import { resetMenuPageAnswers } from "@/session/menuPages";
import { resetSelectedSessionDepartment } from "@/session/sessionAccess";
import {
  FIXTURE_ORGANIZATION,
  fixtureSessionDocument,
} from "@/session/sessionDocumentFixture";
import AboutView from "@/views/AboutView.vue";

const insideWindow = new Date("2026-09-11T18:35:00+00:00");

const mounted: VueWrapper[] = [];

async function mountSettings(): Promise<VueWrapper> {
  const router = createRouter({ history: createWebHistory(), routes });

  await router.push("/settings/about");
  await router.isReady();

  const wrapper = mount(AboutView, { global: { plugins: [router] } });

  mounted.push(wrapper);
  await flushPromises();

  return wrapper;
}

function installFixtureSession(): void {
  installClientSession(fixtureSessionDocument(), "network", insideWindow);
}

function installFixtureBranding(): void {
  installBrandingProfileForTests({
    ...MERIDIAN_PROFILE,
    organization_id: FIXTURE_ORGANIZATION,
    display_name: "Northwood Collective",
    is_branded: true,
  });
}

function usableReadSetPayload() {
  return offlineReadSetPayload({
    readiness: { usable_until: "2099-01-01T00:00:00+00:00" },
  });
}

function stubHealthyNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(
      async () =>
        new Response(JSON.stringify({ status: "ok" }), {
          status: 200,
          headers: { "Content-Type": "application/json" },
        }),
    ),
  );
}

beforeEach(() => {
  window.localStorage.clear();
  resetDownloadStatusForTests();
  resetOfflineReadSet();
  resetOfflineReadSetRefresh();
  resetViewedIncidentCacheForTests();
  resetToMeridian();
  stubHealthyNode();
});

afterEach(() => {
  mounted.splice(0).forEach((wrapper) => wrapper.unmount());
  resetDownloadStatusForTests();
  resetOfflineReadSet();
  resetOfflineReadSetRefresh();
  resetViewedIncidentCacheForTests();
  resetToMeridian();
  clearClientSession();
  resetSelectedSessionDepartment();
  resetHiddenPageAnswers();
  resetMenuPageAnswers();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
  window.localStorage.clear();
});

describe("the download readout on Settings", () => {
  it("says nothing has been named while the device holds no session", async () => {
    const wrapper = await mountSettings();

    expect(wrapper.get(".about__downloads-empty").text()).toContain(
      "no session",
    );
    expect(wrapper.find(".about__downloads-count").exists()).toBe(false);
  });

  it("counts what the node named and names each artifact with its state", async () => {
    installFixtureSession();

    const wrapper = await mountSettings();
    const count = wrapper.get(".about__downloads-count");

    expect(count.text()).toBe("0 of 2 downloaded");
    expect(count.attributes("data-complete")).toBe("false");

    const rows = wrapper.findAll(".about__downloads-list li");

    expect(rows.map((row) => row.text())).toEqual([
      expect.stringContaining("Offline read set"),
      expect.stringContaining("Branding assets"),
    ]);
    expect(rows[0]?.attributes("data-artifact-state")).toBe("pending");
    expect(rows[0]?.text()).toContain("Pending.");
  });

  it("reads complete once every named artifact is held", async () => {
    installFixtureSession();
    await installOfflineReadSet(usableReadSetPayload());
    installFixtureBranding();

    const wrapper = await mountSettings();
    const count = wrapper.get(".about__downloads-count");

    expect(count.text()).toBe("2 of 2 downloaded");
    expect(count.attributes("data-complete")).toBe("true");

    const readSetRow = wrapper.findAll(".about__downloads-list li")[0];

    expect(readSetRow?.attributes("data-artifact-state")).toBe("downloaded");
    expect(readSetRow?.text()).toContain("Last downloaded");
  });

  it("offers a retry on a failed artifact rather than silent incompleteness", async () => {
    installFixtureSession();
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response(JSON.stringify({ message: "Refused." }), {
            status: 422,
            headers: { "content-type": "application/json" },
          }),
      ),
    );
    await refreshOfflineReadSet("requested");

    stubHealthyNode();

    const wrapper = await mountSettings();
    const failedRow = wrapper
      .findAll(".about__downloads-list li")
      .find((row) => row.attributes("data-artifact-state") === "failed");

    expect(failedRow).toBeDefined();
    expect(failedRow?.text()).toContain("Refused.");
    expect(failedRow?.get("button").text()).toBe("Retry download");
  });

  it("reports the viewed-incident cache as a count, never a fraction", async () => {
    installFixtureSession();
    recordIncidentView(
      { id: "incident-1", eventId: "event-decompression-2026" } as ImsIncident,
      "user-dana",
      insideWindow,
    );

    const wrapper = await mountSettings();

    expect(wrapper.get(".about__downloads-incidents").text()).toBe(
      "1 viewed incident cached",
    );
    // The count enters no fraction: the summary still counts only what the
    // node named.
    expect(wrapper.get(".about__downloads-count").text()).toBe(
      "0 of 2 downloaded",
    );
  });

  it("shows no incident line at all for a user whose views cached nothing", async () => {
    installFixtureSession();

    const wrapper = await mountSettings();

    expect(wrapper.find(".about__downloads-incidents").exists()).toBe(false);
  });
});

describe("the completion toast", () => {
  function completionStatus(complete: boolean): DownloadStatus {
    return { named: 2, downloaded: complete ? 2 : 1, complete, artifacts: [] };
  }

  it("renders the one sentence while the notice stands, and only then", async () => {
    const wrapper = mount(DownloadCompleteToast);

    mounted.push(wrapper);
    expect(wrapper.find(".download-toast").exists()).toBe(false);

    recordDownloadStatusObservation(completionStatus(false));
    recordDownloadStatusObservation(completionStatus(true));
    await flushPromises();

    const toast = wrapper.get(".download-toast");

    expect(toast.text()).toBe(DOWNLOAD_COMPLETE_NOTICE);
    expect(toast.attributes("role")).toBe("status");
    // Self-dismissing, so there is no dismiss control to require (11.14).
    expect(toast.find("button").exists()).toBe(false);
  });
});
