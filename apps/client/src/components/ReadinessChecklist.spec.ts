import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import ReadinessChecklist from "@/components/ReadinessChecklist.vue";
import type { ReadinessChecklistItem } from "@/readiness/checklist";

const items: ReadinessChecklistItem[] = [
  { key: "encryptionActive", label: "Encryption active", status: "ready", detail: null },
  {
    key: "deviceSigningAvailable",
    label: "Device signing available",
    status: "not-ready",
    detail: "Device signing is unavailable: secure local signing-key storage is unavailable.",
  },
  {
    key: "loggedIn",
    label: "Logged in",
    status: "pending",
    detail: "Not available yet in this build.",
  },
];

describe("ReadinessChecklist", () => {
  it("renders one row per readiness item with its label", () => {
    const wrapper = mount(ReadinessChecklist, { props: { items } });

    const rows = wrapper.findAll(".readiness-checklist__item");
    expect(rows).toHaveLength(items.length);
    expect(wrapper.text()).toContain("Encryption active");
    expect(wrapper.text()).toContain("Device signing available");
    expect(wrapper.text()).toContain("Logged in");
  });

  it("conveys status with a text label, not color alone", () => {
    const wrapper = mount(ReadinessChecklist, { props: { items } });

    const statusLabels = wrapper
      .findAll(".readiness-checklist__status")
      .map((node) => node.text());
    expect(statusLabels).toEqual(["Ready", "Not ready", "Pending"]);
  });

  it("shows the detail text when present", () => {
    const wrapper = mount(ReadinessChecklist, { props: { items } });

    expect(wrapper.text()).toContain(
      "Device signing is unavailable: secure local signing-key storage is unavailable.",
    );
    expect(wrapper.text()).toContain("Not available yet in this build.");
  });

  it("hides the decorative status glyph from assistive technology", () => {
    const wrapper = mount(ReadinessChecklist, { props: { items } });

    const glyphs = wrapper.findAll(".readiness-checklist__glyph");
    expect(glyphs).toHaveLength(items.length);
    for (const glyph of glyphs) {
      expect(glyph.attributes("aria-hidden")).toBe("true");
    }
  });

  it("exposes each row status for styling and testing", () => {
    const wrapper = mount(ReadinessChecklist, { props: { items } });

    const statuses = wrapper
      .findAll(".readiness-checklist__item")
      .map((node) => node.attributes("data-status"));
    expect(statuses).toEqual(["ready", "not-ready", "pending"]);
  });
});
