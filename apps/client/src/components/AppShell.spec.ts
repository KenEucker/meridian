import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { mount } from "@vue/test-utils";

import { appConfigForUiMode, type UiMode } from "@/app/appConfig";
import {
  installBrandingProfileForTests,
  MERIDIAN_PROFILE,
  resetToMeridian,
} from "@/branding/brandingProfile";
import AppShell from "@/components/AppShell.vue";
import {
  FIXTURE_GATE_DEPARTMENT_ID,
  FIXTURE_RANGERS_DEPARTMENT_ID,
} from "@/department-teams/fixtureDepartmentAccess";
import { syncFieldReportOutbox } from "@/field-reports/syncFieldReportOutbox";
import { adoptHeldApiToken } from "@/session/apiLogin";
import { clearApiToken, storeApiToken } from "@/session/apiToken";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  installLocalFieldSession,
  localFieldSessionDocument,
  switchableLocalFieldContext,
} from "@/session/localFieldSession";
import {
  resetSelectedSessionDepartment,
  selectSessionDepartment,
  selectedSessionDepartment,
} from "@/session/sessionAccess";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

vi.mock("@/field-reports/syncFieldReportOutbox", () => ({
  syncFieldReportOutbox: vi.fn(async () => undefined),
}));

const routerLinkStub = {
  RouterLink: { template: "<a><slot /></a>" },
};

/**
 * Both the flag and the event, because two things read connectivity: the
 * composable a mounted shell creates, and the process-wide signal the session
 * modules read. Only the event reaches the second one, and leaving it un-fired
 * would leak an offline device into the next test.
 */
function setDeviceOnLine(value: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value,
  });
  window.dispatchEvent(new Event(value ? "online" : "offline"));
}

/*
 * The shell reads its user, its departments, and its navigation from the session
 * response and from nothing else (M16.6; CLIENT-001, CLIENT-004), so every test
 * that expects to see any of them establishes a session first. No server is
 * involved: the document is the same shape `GET /api/me` returns (CLIENT-024).
 */
beforeEach(() => {
  installLocalFieldSession();
  selectSessionDepartment(FIXTURE_RANGERS_DEPARTMENT_ID);
});

afterEach(() => {
  resetToMeridian();
  resetSelectedSessionDepartment();
  setDeviceOnLine(true);
  clearClientSession();
  clearApiToken();
  adoptHeldApiToken();
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

  it("keeps cached-permission state separate from connectivity state", () => {
    // Contract 19A.2: a device can be online with stale permissions or offline
    // with fresh ones, so the two indicators are two elements.
    setDeviceOnLine(true);
    installClientSession(
      fixtureSessionDocument(),
      "cache",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = mount(AppShell, {
      global: { stubs: routerLinkStub },
    });

    expect(wrapper.find(".offline-banner").exists()).toBe(false);
    expect(
      wrapper.get(".session-permissions").attributes("data-session-status"),
    ).toBe("cached");
    expect(wrapper.get(".session-permissions").text()).toContain(
      "Permissions are cached",
    );
    expect(wrapper.get(".session-permissions").text()).toContain(
      "Last refreshed",
    );
  });

  it("says nothing about permissions when the session is current", () => {
    installClientSession(
      fixtureSessionDocument(),
      "network",
      new Date("2026-09-11T18:35:00+00:00"),
    );

    const wrapper = mount(AppShell, {
      global: { stubs: routerLinkStub },
    });

    expect(wrapper.find(".session-permissions").exists()).toBe(false);
  });

  it("drains the command outbox when online", () => {
    // One drain for every command the device holds (M16.10; technical spec
    // 11A.5). Field Report photo uploads ride along behind it, which is why the
    // shell asks for that pass rather than for the queue directly.
    setDeviceOnLine(true);

    mount(AppShell, {
      global: { stubs: routerLinkStub },
    });

    expect(syncFieldReportOutbox).toHaveBeenCalledOnce();
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
      // CLIENT-001: the name on the button is the one the session response
      // carries, not one the client brought with it.
      expect(wrapper.get(".app-shell__user-button").text()).toContain(
        "Local Field Author",
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
      // The author's own Field Report workspace is a personal page and rides
      // with Me, in every role.
      expect(workflowLabels).toContain("My Field Reports");
      // IMS Field Reports is reached from inside Incidents and from the home
      // directory, not from the tab bar.
      expect(workflowLabels).not.toContain("Reports");
      expect(workflowLabels).not.toContain("Field Reports");
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
      // Filled rather than outlined. The connection scale is carried by this
      // icon's color, and a 2px outline gave that color too little area to be
      // read at 16px — applied, but not legible.
      expect(
        wrapper
          .findAll(".app-shell__user-icon > *")
          .map((shape) => shape.attributes("fill")),
      ).toEqual(["currentColor", "currentColor"]);
    },
  );

  it("renders no navigation and no identity before a session resolves", () => {
    // CLIENT-005 through the shell: an empty menu rather than a menu of pages
    // the server would refuse, and no borrowed name in the place a user checks
    // who they are signed in as (CLIENT-001).
    clearClientSession();

    const wrapper = mount(AppShell, {
      props: { config: appConfigForUiMode("admin") },
      global: { stubs: routerLinkStub },
    });

    expect(wrapper.findAll(".app-shell__tab")).toHaveLength(0);
    expect(wrapper.find(".app-shell__context").exists()).toBe(false);
    expect(wrapper.findAll(".app-shell__department-mark")).toHaveLength(0);
    expect(wrapper.get(".app-shell__user-button").text()).toContain(
      "Not signed in",
    );
  });

  it("offers no department switcher to a user with one department", async () => {
    installClientSession(
      localFieldSessionDocument({
        departments: [
          {
            id: FIXTURE_RANGERS_DEPARTMENT_ID,
            organization_id: "88888888-8888-4888-8888-888888888888",
            name: "Rangers",
            code: "RANGERS",
            membership_status: "active",
            archived_at: null,
          },
        ],
      }),
      "network",
    );

    const wrapper = mount(AppShell, {
      props: { config: appConfigForUiMode("admin") },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");

    expect(wrapper.findAll(".app-shell__department-mark")).toHaveLength(0);
    expect(wrapper.find(".app-shell__department-switch").exists()).toBe(false);
    expect(wrapper.get(".app-shell__context-text p").text()).toBe("Rangers");
  });

  it("opens the user dropdown from the shell", async () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    expect(wrapper.find(".app-shell__user-menu").exists()).toBe(false);
    await wrapper.get(".app-shell__user-button").trigger("click");

    expect(wrapper.get(".app-shell__user-menu").text()).toContain(
      "Local Field Author",
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
      "Switch department",
    );
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("Organizer");
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("Gate");
    expect(wrapper.get(".app-shell__user-menu").text()).toContain("DPW");
  });

  /*
   * Signing in and out of a personal device (M16.11; AUTH-018).
   *
   * The menu offers exactly one of the two, and which one follows from whether
   * this device holds a token — not from whether a session document is installed.
   * A development session and a cached session both put a name in the menu while
   * the device holds no credential at all, and offering "sign out" there would be
   * a control that disposes of nothing.
   */
  it("offers sign-in until this device holds a token, then sign-out", async () => {
    const signedOut = mount(AppShell, {
      props: { config: appConfigForUiMode("admin") },
      global: { stubs: routerLinkStub },
    });

    await signedOut.get(".app-shell__user-button").trigger("click");

    expect(signedOut.get(".app-shell__user-menu").text()).toContain("Sign in");
    expect(signedOut.get(".app-shell__user-menu").text()).not.toContain("Sign out");

    storeApiToken({
      token: "device-token",
      user: { id: "user-1", name: "Local Field Author", email: "author@example.test" },
    });
    adoptHeldApiToken();

    const signedIn = mount(AppShell, {
      props: { config: appConfigForUiMode("admin") },
      global: { stubs: routerLinkStub },
    });

    await signedIn.get(".app-shell__user-button").trigger("click");

    expect(signedIn.get(".app-shell__user-menu").text()).toContain("Sign out");
  });

  /*
   * A Kiosk offers neither: it holds a shared-workstation session rather than a
   * token, and both entering and ending one belong to its own surfaces
   * (AUTH-030; technical spec 13.3).
   */
  it("offers no personal sign-in on a Kiosk", async () => {
    storeApiToken({
      token: "device-token",
      user: { id: "user-1", name: "Local Field Author", email: "author@example.test" },
    });
    adoptHeldApiToken();

    const wrapper = mount(AppShell, {
      props: { config: appConfigForUiMode("kiosk") },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");

    expect(wrapper.get(".app-shell__user-menu").text()).not.toContain("Sign in");
    expect(wrapper.get(".app-shell__user-menu").text()).not.toContain("Sign out");
  });
});

/*
 * The organization and event switcher in the shell (M16.7; CLIENT-011 through
 * CLIENT-013).
 *
 * The shell links to the two context screens and never becomes the switcher
 * itself, which is UI contract 4.3 and 6.1. What is asserted here is when those
 * links exist at all.
 */
describe("AppShell context switching", () => {
  async function openUserMenu(uiMode: UiMode = "admin") {
    const wrapper = mount(AppShell, {
      props: { config: appConfigForUiMode(uiMode) },
      global: { stubs: routerLinkStub },
    });

    await wrapper.get(".app-shell__user-button").trigger("click");

    return wrapper;
  }

  it("offers both context screens to a connected user with more than one context", async () => {
    installClientSession(
      localFieldSessionDocument(switchableLocalFieldContext()),
      "network",
    );

    const menu = (await openUserMenu()).get(".app-shell__user-menu");

    expect(menu.text()).toContain("Switch organization");
    expect(menu.text()).toContain("Switch event");
    // The organization the session resolved to, named where the way out of it
    // is (contract rule 4.5: show operating context).
    expect(menu.get(".app-shell__context-switch strong").text()).toBe(
      "Idaho Burners",
    );
  });

  it("says the node owns the context instead of offering a switcher", async () => {
    // The seeded session is a locked on-site node: it holds one event's records
    // and no others, so it reports the lock rather than a choice it could not
    // serve (technical spec 11A.3).
    const menu = (await openUserMenu()).get(".app-shell__user-menu");

    expect(menu.text()).not.toContain("Switch organization");
    expect(menu.text()).not.toContain("Switch event");
    expect(menu.get(".app-shell__context-notice").attributes("data-reason")).toBe(
      "node_locked",
    );
    expect(menu.get(".app-shell__context-notice").text()).toContain(
      "Local Field Event",
    );
  });

  it("offers no switcher to a client that cannot reach its node", async () => {
    // CLIENT-013. Absent rather than disabled, because a disabled control
    // invites the user to keep trying something that cannot work without
    // connectivity they do not have (operating guide 8.3A).
    installClientSession(
      localFieldSessionDocument(switchableLocalFieldContext()),
      "network",
    );
    setDeviceOnLine(false);

    const menu = (await openUserMenu()).get(".app-shell__user-menu");

    expect(menu.text()).not.toContain("Switch organization");
    expect(menu.text()).not.toContain("Switch event");
    expect(menu.find(".app-shell__context-notice").attributes("data-reason")).toBe(
      "disconnected",
    );
  });

  it("offers no switcher to a client running on cached permissions", async () => {
    // A device can hold a network and still not reach its node, which on an
    // event site is the more common of the two.
    installClientSession(
      localFieldSessionDocument(switchableLocalFieldContext()),
      "cache",
    );

    const menu = (await openUserMenu()).get(".app-shell__user-menu");

    expect(menu.text()).not.toContain("Switch organization");
    expect(menu.find(".app-shell__context-notice").attributes("data-reason")).toBe(
      "disconnected",
    );
  });

  it("says nothing at all to a user with one organization and one event", async () => {
    // Nobody misses a switcher they have nothing to switch to, so there is no
    // notice either.
    installClientSession(
      localFieldSessionDocument({
        context: {
          ...localFieldSessionDocument().context,
          node_locked: false,
          node_locked_event_id: null,
          switching_available: false,
        },
      }),
      "network",
    );

    const menu = (await openUserMenu()).get(".app-shell__user-menu");

    expect(menu.text()).not.toContain("Switch organization");
    expect(menu.find(".app-shell__context-notice").exists()).toBe(false);
  });

  it("keeps context selection out of a Kiosk, which is pinned rather than choosing", async () => {
    // Contract mode matrix, "Home / context selection": Field and Admin select
    // context; Kiosk requires pinned context setup.
    installClientSession(
      localFieldSessionDocument(switchableLocalFieldContext()),
      "network",
    );

    const kiosk = (await openUserMenu("kiosk")).get(".app-shell__user-menu");
    expect(kiosk.text()).not.toContain("Switch organization");
    expect(kiosk.find(".app-shell__context-notice").exists()).toBe(false);

    const field = (await openUserMenu("field")).get(".app-shell__user-menu");
    expect(field.text()).toContain("Switch organization");
    expect(field.text()).toContain("Switch event");
  });
});

describe("AppShell menu behavior", () => {

  it("updates workflow access when the user switches departments", async () => {
    // Every entry below follows a capability the session response scopes to the
    // department being switched into (CLIENT-004, CLIENT-005).
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
    // A designated team lead reaches their own team and nothing else here.
    // `department.administer` is what opens Admin and the shift lead role does
    // not carry it, so the entry is gone rather than shown and refused.
    expect(workflowLabels).toContain("Team");
    expect(workflowLabels).not.toContain("Admin");
    expect(workflowLabels).not.toContain("Overview");
    expect(workflowLabels).not.toContain("Logistics");
    expect(workflowLabels).not.toContain("Incidents");
  });

  it("still combines the menus for the fullest role in the session", () => {
    const wrapper = mount(AppShell, {
      props: {
        config: appConfigForUiMode("admin"),
      },
      global: { stubs: routerLinkStub },
    });

    // The Rangers department lead holds every department capability the seeded
    // session carries, and still lands under the combine threshold at ten
    // items.
    expect(wrapper.get(".app-shell__workflow-button").text()).toContain("Menu");
    expect(wrapper.find(".app-shell__staff-menu").exists()).toBe(false);

    expect(
      wrapper.findAll(".app-shell__tab").map((tab) => tab.text()),
    ).toEqual([
      "Me",
      "Event Info",
      "Shifts",
      "My Field Reports",
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

    // In Gate the user is a plain member holding no capability at all: six
    // personal and member items total, so splitting them across two dropdowns
    // would only make the reader guess.
    expect(wrapper.find(".app-shell__staff-menu").exists()).toBe(false);
    expect(wrapper.get(".app-shell__workflow-button").text()).toContain("Menu");

    expect(
      wrapper.findAll(".app-shell__tab").map((tab) => tab.text()),
    ).toEqual([
      "Me",
      "Event Info",
      "Shifts",
      "My Field Reports",
      "Documents",
      "Trainings",
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
    // A team lead has no Admin workflow to reach the department's member pages
    // from, so those pages sit with their personal ones — which is the rule the
    // Staff menu has always applied, now answered from `department.administer`
    // rather than from a fixture flag.
    expect(
      wrapper.findAll(".app-shell__tab").map((tab) => tab.text()),
    ).toEqual([
      "Me",
      "Event Info",
      "Shifts",
      "My Field Reports",
      "Documents",
      "Trainings",
      "Team",
    ]);
  });

  it("closes the user dropdown after choosing an item", async () => {
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

  it("closes the user dropdown after clicking outside it", async () => {
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

describe("AppShell event mark", () => {
  const eventProfile = {
    ...MERIDIAN_PROFILE,
    organization_id: "org-1",
    is_branded: true,
    display_name: "Deep Harbor Collective",
    compact_mark_url: "/branding/assets/org",
    event: {
      event_id: "event-1",
      name: "Desert Bloom",
      lettermark: "DB",
      logo_url: "/branding/assets/event",
    },
  };

  it("shows the locked event's logo instead of the organization mark", () => {
    // BRAND-029: staff working the event know the event, not necessarily the
    // company producing it.
    installBrandingProfileForTests(eventProfile);

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    expect(wrapper.get(".app-shell__mark").attributes("src")).toBe(
      "/branding/assets/event",
    );
    expect(
      document.querySelector('link[rel~="icon"]')?.getAttribute("href"),
    ).toBe("/branding/assets/event");
  });

  it("shows the event's name beside the event's mark", () => {
    // BRAND-030: the mark and the name identify the same party. An event logo
    // beside the producing company's name asks a staff member to recognise
    // something they have no reason to know.
    installBrandingProfileForTests(eventProfile);

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    expect(wrapper.get(".app-shell__product-name").text()).toBe("Desert Bloom");
    expect(document.title).toContain("Desert Bloom");
  });

  it("drops the event name from the context block when the header carries it", () => {
    // The same string twice in one bar, a few centimetres apart, is noise. The
    // department is the part the header never carries.
    installBrandingProfileForTests(eventProfile);

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });
    const context = wrapper.get(".app-shell__context-text");

    expect(context.get("p").text()).toBe("Rangers");
    expect(context.find("span").exists()).toBe(false);
  });

  it("returns the event name to the context block when the header stops carrying it", () => {
    installBrandingProfileForTests({
      ...eventProfile,
      event: { ...eventProfile.event, logo_url: null },
    });

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    expect(wrapper.get(".app-shell__product-name").text()).toBe(
      "Deep Harbor Collective",
    );
    expect(wrapper.get(".app-shell__context-text span").text()).toBe(
      "Local Field Event",
    );
  });

  it("falls back to the organization mark when the event has no logo", () => {
    installBrandingProfileForTests({
      ...eventProfile,
      event: { ...eventProfile.event, logo_url: null },
    });

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    expect(wrapper.get(".app-shell__mark").attributes("src")).toBe(
      "/branding/assets/org",
    );
  });

  it("keeps Meridian's mark and icon on an install with no branding at all", () => {
    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    expect(wrapper.get(".app-shell__mark").attributes("src")).toBe(
      "/assets/brand/meridian-mark.png",
    );
    expect(
      document.querySelector('link[rel~="icon"]')?.getAttribute("href"),
    ).toBe("/favicon.ico");
  });
});

describe("AppShell department marks", () => {
  /** A profile giving Rangers a logo and leaving the others without one. */
  function installDepartmentLogos(): void {
    installBrandingProfileForTests({
      ...MERIDIAN_PROFILE,
      organization_id: "org-1",
      departments: [
        {
          department_id: FIXTURE_RANGERS_DEPARTMENT_ID,
          name: "Rangers",
          accent: "#1f5f4b",
          surface: null,
          lettermark: "RA",
          logo_url: "/branding/assets/rangers",
        },
      ],
    });
  }

  it("shows the current department's logo beside the department and event names", () => {
    installDepartmentLogos();

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });
    const context = wrapper.get(".app-shell__context");

    expect(context.get(".app-shell__context-mark img").attributes("src")).toBe(
      "/branding/assets/rangers",
    );
    expect(context.get(".app-shell__context-text p").text()).toBe("Rangers");
  });

  it("falls back to a lettermark for a department with no logo", async () => {
    installDepartmentLogos();

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    await wrapper.get(".app-shell__user-button").trigger("click");
    await wrapper
      .findAll(".app-shell__department-switch button")[3]!
      .trigger("click");

    const mark = wrapper.get(".app-shell__context-mark");
    expect(mark.find("img").exists()).toBe(false);
    // One word, so the lettermark is its first two letters — the same rule
    // `App\Services\Branding\Lettermark` applies.
    expect(mark.get(".brand-mark__lettermark").text()).toBe("DP");
  });

  it("offers the user's other departments as marks and never the current one", () => {
    installDepartmentLogos();

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });
    const marks = wrapper.findAll(".app-shell__department-mark");

    // Four fixture departments, minus the one currently selected.
    expect(marks).toHaveLength(3);
    expect(marks.map((mark) => mark.attributes("title"))).toEqual([
      "Switch to Organizer",
      "Switch to Gate",
      "Switch to DPW",
    ]);

    // The mark is the whole control, so it carries the accessible name rather
    // than being decorative the way the context mark is.
    expect(marks[0]!.get(".brand-mark").attributes("aria-label")).toBe(
      "Switch to Organizer",
    );
  });

  it("marks a logo switcher and a lettermark switcher apart for styling", () => {
    // A real logo is shown as itself: no button outline and no chip behind it.
    // Two generated letters need an edge to read as something clickable, so
    // the outline is drawn there and only there.
    installDepartmentLogos();
    selectSessionDepartment(FIXTURE_GATE_DEPARTMENT_ID);

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });
    const byTitle = (title: string) =>
      wrapper
        .findAll(".app-shell__department-mark")
        .find((mark) => mark.attributes("title") === title)!;

    const rangers = byTitle("Switch to Rangers");
    expect(rangers.attributes("data-mark")).toBe("logo");
    expect(rangers.get(".brand-mark").attributes("data-mark")).toBe("logo");

    const dpw = byTitle("Switch to DPW");
    expect(dpw.attributes("data-mark")).toBe("lettermark");
    expect(dpw.get(".brand-mark").attributes("data-mark")).toBe("lettermark");
  });

  it("switches department context when a header mark is clicked", async () => {
    installDepartmentLogos();

    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    expect(selectedSessionDepartment.value?.departmentId).toBe(
      FIXTURE_RANGERS_DEPARTMENT_ID,
    );

    await wrapper.findAll(".app-shell__department-mark")[1]!.trigger("click");

    expect(selectedSessionDepartment.value?.departmentId).toBe(
      FIXTURE_GATE_DEPARTMENT_ID,
    );
    expect(wrapper.get(".app-shell__context-text p").text()).toBe("Gate");

    // The department just left rejoins the row; the one just entered leaves it.
    const titles = wrapper
      .findAll(".app-shell__department-mark")
      .map((mark) => mark.attributes("title"));
    expect(titles).toContain("Switch to Rangers");
    expect(titles).not.toContain("Switch to Gate");
  });

  it("keeps the labelled department switch in the user menu", async () => {
    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    await wrapper.get(".app-shell__user-button").trigger("click");

    const entries = wrapper.findAll(".app-shell__department-switch button");
    expect(entries).toHaveLength(4);
    expect(entries[0]!.text()).toContain("Organizer");
  });

  it("renders a mark for every department when no branding profile has loaded", () => {
    const wrapper = mount(AppShell, { global: { stubs: routerLinkStub } });

    expect(
      wrapper.get(".app-shell__context-mark .brand-mark__lettermark").text(),
    ).toBe("RA");
    expect(wrapper.findAll(".app-shell__department-mark")).toHaveLength(3);
  });
});
