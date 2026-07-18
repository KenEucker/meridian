import { afterEach, describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import AppShell from "@/components/AppShell.vue";

const routerLinkStub = {
  RouterLink: { template: "<a><slot /></a>" },
};

function setDeviceOnLine(value: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
}

afterEach(() => {
  setDeviceOnLine(true);
});

describe("AppShell offline/sync display", () => {
  it("stays silent while the device reports it is online", () => {
    setDeviceOnLine(true);

    const wrapper = mount(AppShell, {
      global: { stubs: routerLinkStub },
    });

    expect(wrapper.find(".offline-banner").exists()).toBe(false);
  });

  it("shows the shared offline banner when the device is offline", () => {
    setDeviceOnLine(false);

    const wrapper = mount(AppShell, {
      global: { stubs: routerLinkStub },
    });

    const banner = wrapper.get(".offline-banner");
    expect(banner.attributes("data-state")).toBe("offline_usable");
    expect(wrapper.get(".offline-banner__label").text()).toBe(
      "Offline but usable",
    );
  });

  it("reveals the banner when the device goes offline after mount", async () => {
    setDeviceOnLine(true);

    const wrapper = mount(AppShell, {
      global: { stubs: routerLinkStub },
    });
    expect(wrapper.find(".offline-banner").exists()).toBe(false);

    setDeviceOnLine(false);
    window.dispatchEvent(new Event("offline"));
    await wrapper.vm.$nextTick();

    expect(wrapper.get(".offline-banner").attributes("data-state")).toBe(
      "offline_usable",
    );
  });
});
