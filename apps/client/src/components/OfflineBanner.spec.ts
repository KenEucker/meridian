import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import OfflineBanner from "@/components/OfflineBanner.vue";

describe("OfflineBanner", () => {
  it("stays silent when online so it does not interrupt routine work", () => {
    const wrapper = mount(OfflineBanner, { props: { state: "online" } });

    expect(wrapper.find(".offline-banner").exists()).toBe(false);
    expect(wrapper.text()).toBe("");
  });

  it("renders the canonical label and meaning for an affected state", () => {
    const wrapper = mount(OfflineBanner, {
      props: { state: "offline_usable" },
    });

    const banner = wrapper.get(".offline-banner");
    expect(banner.attributes("data-state")).toBe("offline_usable");
    expect(wrapper.get(".offline-banner__label").text()).toBe(
      "Offline but usable",
    );
    expect(wrapper.get(".offline-banner__meaning").text()).toBe(
      "Local work can continue.",
    );
  });

  it("distinguishes local node from central connectivity", () => {
    const local = mount(OfflineBanner, {
      props: { state: "local_node_reachable" },
    });
    const central = mount(OfflineBanner, {
      props: { state: "central_unreachable" },
    });

    expect(local.get(".offline-banner__label").text()).toBe(
      "Local node reachable",
    );
    expect(central.get(".offline-banner__label").text()).toBe(
      "Central unreachable",
    );
  });

  it("is a non-interruptive polite status region, not a dialog", () => {
    const wrapper = mount(OfflineBanner, { props: { state: "sync_failed" } });

    const banner = wrapper.get(".offline-banner");
    expect(banner.attributes("role")).toBe("status");
    expect(banner.attributes("aria-live")).toBe("polite");
  });

  it("conveys tone with a text label and a decorative glyph, not color alone", () => {
    const wrapper = mount(OfflineBanner, { props: { state: "sync_conflict" } });

    const banner = wrapper.get(".offline-banner");
    expect(banner.classes()).toContain("offline-banner--critical");
    expect(wrapper.get(".offline-banner__label").text()).toBe("Sync conflict");
    expect(wrapper.get(".offline-banner__glyph").attributes("aria-hidden")).toBe(
      "true",
    );
  });

  it("shows the queued count only for the queued state", () => {
    const queued = mount(OfflineBanner, {
      props: { state: "sync_queued", queuedCount: 3 },
    });
    expect(queued.get(".offline-banner__queued").text()).toBe(
      "3 actions queued",
    );

    const singular = mount(OfflineBanner, {
      props: { state: "sync_queued", queuedCount: 1 },
    });
    expect(singular.get(".offline-banner__queued").text()).toBe(
      "1 action queued",
    );

    const other = mount(OfflineBanner, {
      props: { state: "central_unreachable", queuedCount: 3 },
    });
    expect(other.find(".offline-banner__queued").exists()).toBe(false);
  });

  it("shows an optional scope label and repair path when provided", () => {
    const wrapper = mount(OfflineBanner, {
      props: {
        state: "sync_failed",
        scope: "Field reports",
        repairHref: "/sync/repair",
      },
    });

    expect(wrapper.get(".offline-banner__scope").text()).toBe("Field reports");
    const repair = wrapper.get(".offline-banner__repair");
    expect(repair.attributes("href")).toBe("/sync/repair");
  });

  it("omits the repair path by default", () => {
    const wrapper = mount(OfflineBanner, {
      props: { state: "sync_failed" },
    });

    expect(wrapper.find(".offline-banner__repair").exists()).toBe(false);
  });
});
