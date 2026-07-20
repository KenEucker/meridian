import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import AutosaveStatus from "@/components/AutosaveStatus.vue";

describe("AutosaveStatus", () => {
  it("renders a non-interruptive saved state", () => {
    const wrapper = mount(AutosaveStatus, {
      props: {
        state: "saved",
        lastSavedAt: "2027-07-04T20:30:00.000Z",
      },
    });

    expect(wrapper.attributes("role")).toBe("status");
    expect(wrapper.text()).toContain("Saved");
    expect(wrapper.text()).toContain("Last saved 2027-07-04T20:30:00.000Z");
  });

  it("renders blocked_offline without implying queued incident creation", () => {
    const wrapper = mount(AutosaveStatus, {
      props: {
        state: "blocked_offline",
      },
    });

    expect(wrapper.text()).toContain("Offline");
    expect(wrapper.text()).toContain(
      "Incident create/edit requires server connection.",
    );
    expect(wrapper.text()).not.toContain("queued");
  });
});
