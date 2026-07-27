import { afterEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";

import { appConfigForUiMode, type UiMode } from "@/app/appConfig";
import AppShell from "@/components/AppShell.vue";
import { resetSelectedFixtureDepartment } from "@/department-teams/fixtureDepartmentAccess";
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
  resetSelectedFixtureDepartment();
  setDeviceOnLine(true);
  window.localStorage.removeItem("meridian.ui.theme");
  delete document.documentElement.dataset.theme;
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
    expect(
      wrapper
        .get(".app-shell__user-button")
        .attributes("data-connection-status"),
    ).toBe("degraded");
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

  it("shows the node connection scale at full strength", async () => {
    // Contract 16.1A: four steps, worst to best, colour never alone.
    setDeviceOnLine(true);
    const online = mount(AppShell, { global: { stubs: routerLinkStub } });
    expect(
      online.get(".app-shell__user-button").attributes("data-connection-status"),
    ).toBe("connected");
    expect(
      online.get(".app-shell__user-button").attributes("aria-label"),
    ).toContain("Connected and fully capable");

    setDeviceOnLine(false);
    const degraded = mount(AppShell, { global: { stubs: routerLinkStub } });
    expect(
      degraded
        .get(".app-shell__user-button")
        .attributes("data-connection-status"),
    ).toBe("degraded");
    // The step is a scale of notice; the canonical 16.1 label still carries the
    // state as text.
    await degraded.get(".app-shell__user-button").trigger("click");
    expect(degraded.get(".app-shell__connection-note").text()).toContain(
      "Node connection degraded",
    );
    expect(
      degraded
        .get(".app-shell__connection-note")
        .attributes("data-connection-status"),
    ).toBe("degraded");
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
      expect(wrapper.get(".app-shell__home").attributes("aria-label")).toBe(
        productName,
      );
      expect(wrapper.get(".app-shell__mark").attributes("src")).toBe(
        "/assets/brand/meridian-mark.png",
      );
      expect(wrapper.get(".app-shell__product-name").text()).toBe("Meridian");
      expect(wrapper.get(".app-shell__mode-name").text()).toBe(
        productName.replace("Meridian ", ""),
      );
      expect(wrapper.get(".app-shell__context").text()).toContain("Rangers");
      expect(wrapper.get(".app-shell__context").text()).toContain(
        "Local Field Event",
      );
      expect(wrapper.find(".app-shell__menu-theme").exists()).toBe(false);
      expect(wrapper.get(".app-shell__user-button").text()).toContain(
        "Fixture user",
      );
      expect(
        wrapper.get(".app-shell__user-button").attributes(
          "data-connection-status",
        ),
      ).toBe("connected");
      const workflowLabels = wrapper
        .findAll(".app-shell__tab")
        .map((tab) => tab.text());

      expect(workflowLabels).toContain("Me");
      expect(workflowLabels).toContain("Logistics");
      expect(workflowLabels).toContain("Operations");
      expect(workflowLabels).toContain("Admin");
      // IMS Field Reports is reached from inside Incidents and from the home
      // directory, not from the tab bar.
      expect(workflowLabels).not.toContain("Reports");
      expect(workflowLabels).not.toContain("Field Reports");
      expect(workflowLabels).not.toContain("My Field Reports");
      expect(workflowLabels).not.toContain("Notes");
      expect(workflowLabels).not.toContain("Operations Center");
      expect(workflowLabels).not.toContain("Team");
      expect(workflowLabels).not.toContain("Teams");
      expect(workflowLabels).not.toContain("Log");
      expect(workflowLabels).not.toContain("Radio log");
      expect(wrapper.findAll(".app-shell__dropdown-icon").length).toBeGreaterThan(
        1,
      );
      expect(wrapper.find(".app-shell__user-icon").exists()).toBe(true);
    },
  );

  it("opens a fixture user dropdown from the shell", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    expect(wrapper.find(".app-shell__user-menu").exists()).toBe(false);
    await wrapper.get(".app-shell__user-button").trigger("click");

    expect(wrapper.get(".app-shell__user-menu").text()).toContain(
      "Fixture user",
    );
    expect(wrapper.get(".app-shell__connection-note").text()).toContain(
      "Connected and fully capable",
    );
    expect(wrapper.get(".app-shell__menu-theme").text()).toContain("Light");
    expect(wrapper.get(".app-shell__menu-theme").text()).toContain("Dark");
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("Settings");
    expect(wrapper.get(".app-shell__user-menu").text()).toContain(
      "Switch user",
    );
    expect(wrapper.get(".app-shell__user-menu").text()).toContain(
      "Switch organization",
    );
    expect(wrapper.get(".app-shell__user-menu").text()).toContain(
      "Switch department",
    );
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("Organizer");
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("Gate");
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("DPW");
    expect(wrapper.get(".app-shell__user-menu").text()).toContain(
      "Switch event",
    );
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("Sign out");
  });

  it("keeps admin-only switching placeholders out of non-admin shells", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("field"),
      },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");

    expect(wrapper.get(".app-shell__user-menu").text()).toContain(
      "Switch department",
    );
    expect(wrapper.get(".app-shell__user-menu").text()).not.toContain(
      "Switch organization",
    );
    expect(wrapper.get(".app-shell__user-menu").text()).not.toContain(
      "Switch event",
    );
  });

  it("updates workflow access when the fixture user switches departments", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");
    await wrapper
      .findAll(".app-shell__department-switch button")
      .find((button) => button.text().includes("Gate"))!
      .trigger("click");

    expect(wrapper.get(".app-shell__context").text()).toContain("Gate");
    let workflowLabels = wrapper
      .findAll(".app-shell__tab")
      .map((tab) => tab.text());
    expect(workflowLabels).toContain("Me");
    expect(workflowLabels).not.toContain("Admin");
    expect(workflowLabels).not.toContain("Overview");
    expect(workflowLabels).not.toContain("Operations");

    await wrapper.get(".app-shell__user-button").trigger("click");
    await wrapper
      .findAll(".app-shell__department-switch button")
      .find(
        (button) =>
          button.text().includes("DPW") &&
          button.attributes("aria-checked") === "false",
      )!
      .trigger("click");

    expect(wrapper.get(".app-shell__context").text()).toContain("DPW");
    workflowLabels = wrapper.findAll(".app-shell__tab").map((tab) => tab.text());
    expect(workflowLabels).toContain("Admin");
    expect(workflowLabels).not.toContain("Overview");
    expect(workflowLabels).not.toContain("Logistics");
    expect(workflowLabels).not.toContain("Incidents");
  });

  it("still combines the menus for the fullest fixture role", () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    // The Rangers department lead reaches every workflow the fixtures grant,
    // and still lands under the combine threshold at nine items.
    expect(wrapper.get(".app-shell__workflow-button").text()).toContain("Menu");
    expect(wrapper.find(".app-shell__staff-menu").exists()).toBe(false);

    expect(
      wrapper.findAll(".app-shell__tab").map((tab) => tab.text()),
    ).toEqual([
      "Me",
      "Event Info",
      "Overview",
      "Planning",
      "Logistics",
      "Operations",
      "Incidents",
      "Admin",
    ]);
  });

  it("combines Staff and Workflows into one menu when the list is short", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");
    await wrapper
      .findAll(".app-shell__department-switch button")
      .find((button) => button.text().includes("Gate"))!
      .trigger("click");

    // The Gate fixture user is a plain member: six items total, so splitting
    // them across two dropdowns would only make the reader guess.
    expect(wrapper.find(".app-shell__staff-menu").exists()).toBe(false);
    expect(wrapper.get(".app-shell__workflow-button").text()).toContain("Menu");

    expect(
      wrapper.findAll(".app-shell__tab").map((tab) => tab.text()),
    ).toEqual([
      "Me",
      "Event Info",
      "Documents",
      "Shifts",
      "Trainings",
      "My Field Reports",
    ]);
  });

  it("keeps Event Info beside Me for a team lead's combined menu", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");
    await wrapper
      .findAll(".app-shell__department-switch button")
      .find(
        (button) =>
          button.text().includes("DPW") &&
          button.attributes("aria-checked") === "false",
      )!
      .trigger("click");

    expect(wrapper.find(".app-shell__staff-menu").exists()).toBe(false);
    expect(
      wrapper.findAll(".app-shell__tab").map((tab) => tab.text()),
    ).toEqual(["Me", "Event Info", "Team", "Admin"]);
  });

  it("closes the fixture user dropdown after choosing an item", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");
    expect(wrapper.find(".app-shell__user-menu").exists()).toBe(true);

    await wrapper.get(".app-shell__user-menu a").trigger("click");

    expect(wrapper.find(".app-shell__user-menu").exists()).toBe(false);
  });

  it("closes the fixture user dropdown after clicking outside it", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
      attachTo: document.body,
    });

    await wrapper.get(".app-shell__user-button").trigger("click");
    expect(wrapper.find(".app-shell__user-menu").exists()).toBe(true);

    document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
    await wrapper.vm.$nextTick();

    expect(wrapper.find(".app-shell__user-menu").exists()).toBe(false);
    wrapper.unmount();
  });

  it("keeps workflows behind a mobile menu control", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    const menu = wrapper.get(".app-shell__workflow-menu");
    const button = wrapper.get(".app-shell__workflow-button");

    expect(menu.attributes("data-open")).toBe("false");
    expect(button.attributes("aria-expanded")).toBe("false");
    expect(button.attributes("aria-controls")).toBe("app-shell-workflow-tabs");

    await button.trigger("click");

    expect(menu.attributes("data-open")).toBe("true");
    expect(button.attributes("aria-expanded")).toBe("true");
    expect(wrapper.get("#app-shell-workflow-tabs").text()).toContain(
      "Logistics",
    );

    await button.trigger("click");
  });

  it("closes the workflow menu after choosing an item", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    const menu = wrapper.get(".app-shell__workflow-menu");

    await wrapper.get(".app-shell__workflow-button").trigger("click");
    expect(menu.attributes("data-open")).toBe("true");

    await wrapper.get(".app-shell__tab").trigger("click");

    expect(menu.attributes("data-open")).toBe("false");
  });

  it("closes the workflow menu after clicking outside it", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
      attachTo: document.body,
    });

    const menu = wrapper.get(".app-shell__workflow-menu");

    await wrapper.get(".app-shell__workflow-button").trigger("click");
    expect(menu.attributes("data-open")).toBe("true");

    document.body.dispatchEvent(new Event("pointerdown", { bubbles: true }));
    await wrapper.vm.$nextTick();

    expect(menu.attributes("data-open")).toBe("false");
    wrapper.unmount();
  });

  it("renders compact theme labels for small screens", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");
    const buttons = wrapper.findAll(".app-shell__menu-theme button");

    expect(buttons[0]?.attributes("aria-label")).toBe("Light theme");
    expect(buttons[0]?.find(".app-shell__theme-label--long").text()).toBe(
      "Light",
    );
    expect(buttons[0]?.find(".app-shell__theme-label--short").text()).toBe(
      "\u2600",
    );
    expect(buttons[1]?.attributes("aria-label")).toBe("Dark theme");
    expect(buttons[1]?.find(".app-shell__theme-label--long").text()).toBe(
      "Dark",
    );
    expect(buttons[1]?.find(".app-shell__theme-label--short").text()).toBe(
      "\u263e",
    );
  });

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
    expect(wrapper.get(".app-shell__mark").attributes("src")).toBe(
      "/assets/brand/meridian-mark.png",
    );
    expect(wrapper.get(".app-shell__mode-name").text()).toBe("Kiosk");
  });
});
