import { afterEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";

import { appConfigForUiMode, type UiMode } from "@/app/appConfig";
import AppShell from "@/components/AppShell.vue";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";
import { syncAttendanceOutbox } from "@/shift-board/syncAttendanceOutbox";

vi.mock("@/field-reports/syncFieldReportOutbox", () => ({
  syncFieldReportOutbox: vi.fn(async () => undefined),
}));

vi.mock("@/shift-board/syncAttendanceOutbox", () => ({
  syncAttendanceOutbox: vi.fn(async () => undefined),
}));

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
  vi.clearAllMocks();
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

  it("drains Field Report and attendance outboxes when online", () => {
    setDeviceOnLine(true);

    mount(AppShell, {
      global: { stubs: routerLinkStub },
    });

    expect(syncFieldReportOutbox).toHaveBeenCalledOnce();
    expect(syncAttendanceOutbox).toHaveBeenCalledOnce();
  });
});

describe("AppShell fixed UI mode display", () => {
  it.each([
    ["admin", "Meridian Admin", "server"],
    ["field", "Meridian Field", "mobile"],
    ["kiosk", "Meridian Kiosk", "desktop"],
  ] satisfies Array<[UiMode, string, string]>)(
    "renders the %s shell without mode inference",
    (uiMode, productName, deploymentTarget) => {
      const wrapper = mount(AppShell, {
        props: {
          config: appConfigForUiMode(uiMode),
        },
        global: { stubs: routerLinkStub },
      });

      const shell = wrapper.get(".app-shell");
      expect(shell.classes()).toContain(`app-shell--${uiMode}`);
      expect(shell.attributes("data-ui-mode")).toBe(uiMode);
      expect(shell.attributes("data-deployment-target")).toBe(deploymentTarget);
      expect(shell.attributes("aria-label")).toBe(
        `${productName} application shell`,
      );
      expect(wrapper.get(".app-shell__home").text()).toBe(productName);
    },
  );

  it("keeps the configured product identity when connectivity changes", async () => {
    setDeviceOnLine(true);

    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("kiosk"),
      },
      global: { stubs: routerLinkStub },
    });

    setDeviceOnLine(false);
    window.dispatchEvent(new Event("offline"));
    await wrapper.vm.$nextTick();

    expect(wrapper.get(".app-shell").attributes("data-ui-mode")).toBe("kiosk");
    expect(wrapper.get(".app-shell__home").text()).toBe("Meridian Kiosk");
  });
});
