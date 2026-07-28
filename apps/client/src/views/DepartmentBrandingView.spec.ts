import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  installBrandingProfileForTests,
  resetToMeridian,
  type BrandingProfilePayload,
} from "@/branding/brandingProfile";
import DepartmentBrandingView from "@/views/DepartmentBrandingView.vue";

const baseProfile: BrandingProfilePayload = {
  organization_id: "org-harbor",
  display_name: "Deep Harbor Collective",
  is_branded: true,
  has_custom_palette: true,
  document_attribute: "applied",
  lettermark: "DHC",
  department_branding_enabled: true,
  palette: {
    primary: "#123a5c",
    secondary: "#1f5f4b",
    tertiary: "#6b4f8a",
    accent: "#8c2f39",
    canvas: "#eef2f6",
    surface: "#ffffff",
    foreground: "#101418",
    muted_foreground: "#565f68",
    border: "#7c858d",
    focus: "#1b4f8f",
  },
  tokens: {},
  full_lockup_url: null,
  compact_mark_url: null,
  departments: [
    {
      department_id: "dept-rangers",
      name: "Rangers",
      accent: "#1f5f4b",
      surface: "#eef6f2",
      lettermark: "RA",
      logo_url: null,
    },
  ],
  teams: [],
  event: null,
};

const props = {
  organizationId: "org-harbor",
  departmentId: "dept-rangers",
  departmentName: "Rangers",
};

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

describe("DepartmentBrandingView", () => {
  beforeEach(() => {
    installBrandingProfileForTests(baseProfile);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    resetToMeridian();
  });

  it("offers only accent and surface background", () => {
    // BRAND-009/BRAND-011: a department sets two colors, and text, border,
    // focus, status, severity, and chart values are not among them.
    const wrapper = mount(DepartmentBrandingView, { props });

    const colorInputs = wrapper
      .findAll("input[type='color']")
      .map((input) => input.attributes("name"));

    expect(colorInputs).toEqual(["accent", "surface"]);
    expect(wrapper.text()).toContain(
      "come from the organization palette and are not set per department",
    );
  });

  it("loads the department's stored values", () => {
    const wrapper = mount(DepartmentBrandingView, { props });

    expect(
      (wrapper.get("input[name='accent']").element as HTMLInputElement).value,
    ).toBe("#1f5f4b");
    expect(
      (wrapper.get("input[name='surface']").element as HTMLInputElement).value,
    ).toBe("#eef6f2");
  });

  it("disables editing and explains why when the organization switch is off", () => {
    // BRAND-013: the values stay visible because they are not deleted.
    installBrandingProfileForTests({
      ...baseProfile,
      department_branding_enabled: false,
    });

    const wrapper = mount(DepartmentBrandingView, { props });

    const notice = wrapper.get("[data-state='overrides-disabled']").text();

    expect(notice).toContain("switched department branding overrides off");
    expect(notice).toContain("logos and accents still show");
    expect(notice).toContain("has been deleted");
    expect(wrapper.get("[data-section='colors']").attributes("disabled")).toBeDefined();
    expect(
      wrapper.get("[data-action='save']").attributes("disabled"),
    ).toBeDefined();
  });

  it("denies editing to a user without department branding authority", () => {
    const wrapper = mount(DepartmentBrandingView, {
      props: { ...props, canManage: false },
    });

    expect(wrapper.text()).toContain("Department leads and department administration");
    expect(wrapper.get("[data-section='colors']").attributes("disabled")).toBeDefined();
  });

  it("says the background does not reach IMS or The Briefing", () => {
    // BRAND-012 is a rule a department lead needs to know before choosing a
    // color, not a surprise they discover on an incident screen.
    const wrapper = mount(DepartmentBrandingView, { props });

    expect(wrapper.text()).toContain("Incident management, The Briefing");
  });

  it("reports a failing background with the measured and required ratios", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        jsonResponse(
          {
            message: "This color combination does not meet WCAG 2.1 AA.",
            failures: [
              {
                pair: "muted foreground on department background",
                usage: "normal text",
                foreground: "#565f68",
                background: "#cc792f",
                measured_ratio: 1.55,
                required_ratio: 4.5,
              },
            ],
          },
          422,
        ),
      ),
    );

    const wrapper = mount(DepartmentBrandingView, { props });

    await wrapper.get("form").trigger("submit");
    await flushPromises();

    const failure = wrapper.get("[data-result='fail']").text();

    expect(failure).toContain("muted foreground on department background");
    expect(failure).toContain("1.55:1");
    expect(failure).toContain("4.5:1");
  });

  it("previews the badge and the surface together", async () => {
    // The accent shows on the badge and the background behind it, which is
    // the pair a department lead is actually choosing between.
    const wrapper = mount(DepartmentBrandingView, { props });

    expect(wrapper.find(".department-badge").exists()).toBe(true);
    expect(wrapper.get(".department-branding__surface").attributes("style")).toContain(
      "#eef6f2",
    );
  });

  it("clears the background without clearing the accent", async () => {
    const wrapper = mount(DepartmentBrandingView, { props });

    await wrapper.get("[data-action='clear-surface']").trigger("click");

    expect(
      (wrapper.get("input[name='accent']").element as HTMLInputElement).value,
    ).toBe("#1f5f4b");
    expect(wrapper.text()).toContain("not set");
  });
});
