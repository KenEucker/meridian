import { afterEach, describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import SessionPermissionsNotice from "@/session/SessionPermissionsNotice.vue";
import {
  clearClientSession,
  installClientSession,
} from "@/session/clientSession";
import {
  fixtureSessionDocument,
  fixtureSessionEvent,
} from "@/session/sessionDocumentFixture";

const insideWindow = new Date("2026-09-11T18:35:00+00:00");
const afterWindow = new Date("2026-09-16T18:35:00+00:00");

afterEach(() => {
  clearClientSession();
});

describe("session permissions notice", () => {
  it("says nothing when the client has no session", () => {
    const wrapper = mount(SessionPermissionsNotice);

    expect(wrapper.find(".session-permissions").exists()).toBe(false);
  });

  it("says nothing when the node has just answered", () => {
    installClientSession(
      fixtureSessionDocument(),
      "network",
      insideWindow,
    );

    const wrapper = mount(SessionPermissionsNotice);

    expect(wrapper.find(".session-permissions").exists()).toBe(false);
  });

  it("says permissions are cached and when they were last refreshed", () => {
    installClientSession(
      fixtureSessionDocument(),
      "cache",
      insideWindow,
    );

    const wrapper = mount(SessionPermissionsNotice);
    const notice = wrapper.get(".session-permissions");

    expect(notice.attributes("data-session-status")).toBe("cached");
    expect(notice.attributes("role")).toBe("status");
    expect(notice.text()).toContain("Permissions are cached");
    expect(notice.text()).toContain("Last refreshed Sep 11");
    // Information, not a warning: a device working offline inside its event
    // window is not in a failure state (UI operating guide 18.2A).
    expect(notice.classes()).toContain("session-permissions--info");
  });

  it("renders the refresh time on the event's own clock", () => {
    installClientSession(
      fixtureSessionDocument({
        events: [fixtureSessionEvent({ timezone: "America/Boise" })],
      }),
      "cache",
      insideWindow,
    );

    const wrapper = mount(SessionPermissionsNotice);

    expect(wrapper.get(".session-permissions").text()).toContain(
      "Last refreshed Sep 11, 12:30 PM MDT",
    );
  });

  it("warns and offers a refresh once the event window has ended", () => {
    installClientSession(
      fixtureSessionDocument(),
      "cache",
      afterWindow,
    );

    const wrapper = mount(SessionPermissionsNotice);
    const notice = wrapper.get(".session-permissions");

    expect(notice.attributes("data-session-status")).toBe("expired");
    expect(notice.classes()).toContain("session-permissions--warning");
    expect(notice.text()).toContain("Permissions need a refresh");
    expect(notice.text()).toContain(
      "The event this device cached its permissions for has ended.",
    );
    expect(wrapper.get("button").text()).toBe("Refresh permissions");
  });

  it("explains a missing event context in its own terms", () => {
    installClientSession(
      fixtureSessionDocument({
        context: {
          organization_id: null,
          event_id: null,
          department_id: null,
          node_locked: false,
          node_locked_event_id: null,
          switching_available: true,
        },
      }),
      "cache",
      insideWindow,
    );

    const wrapper = mount(SessionPermissionsNotice);

    expect(wrapper.get(".session-permissions").text()).toContain(
      "This device holds no event context.",
    );
  });
});
