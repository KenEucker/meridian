import { flushPromises, mount } from "@vue/test-utils";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { resetToMeridian } from "@/branding/brandingProfile";
import OrganizationBrandingView from "@/views/OrganizationBrandingView.vue";

function jsonResponse(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

const failingPalette = {
  message: "This color combination does not meet WCAG 2.1 AA and was not saved.",
  failures: [
    {
      pair: "muted foreground on surface",
      usage: "normal text",
      foreground: "#c9cdd1",
      background: "#ffffff",
      measured_ratio: 1.98,
      required_ratio: 4.5,
    },
  ],
};

describe("OrganizationBrandingView", () => {
  beforeEach(() => {
    resetToMeridian();
  });

  afterEach(() => {
    vi.unstubAllGlobals();
    resetToMeridian();
  });

  it("shows the contrast verdict before anything is saved", async () => {
    // BRAND-018: preview and validation result come before the save.
    const fetchMock = vi.fn(async (_input: unknown, _init?: unknown) =>
      jsonResponse({ valid: true, failures: [] }),
    );
    vi.stubGlobal("fetch", fetchMock);

    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor" },
    });

    expect(wrapper.text()).toContain("Run the contrast check");

    await wrapper.get("[data-action='preview']").trigger("click");
    await flushPromises();

    expect(wrapper.find("[data-result='pass']").exists()).toBe(true);

    // The claim is about which command ran, not about how many requests the
    // screen made: mounting also loads the event list (BRAND-028). Checking a
    // total would break every time the screen reads something else.
    const requested = fetchMock.mock.calls.map((call) => String(call[0]));
    const asked = (path: string) =>
      requested.some((url) => url.includes(path));

    expect(asked("/api/commands/preview-branding")).toBe(true);
    expect(asked("/api/commands/update-organization-branding")).toBe(false);
  });

  it("lists the failing pair with measured and required ratios", async () => {
    // BRAND-015: the refusal names the pair and both ratios, so an organizer
    // knows which of ten colors to change.
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => jsonResponse(failingPalette, 422)),
    );

    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor" },
    });

    await wrapper.get("form").trigger("submit");
    await flushPromises();

    const failure = wrapper.get("[data-result='fail']").text();

    expect(failure).toContain("muted foreground on surface");
    expect(failure).toContain("#c9cdd1");
    expect(failure).toContain("1.98:1");
    expect(failure).toContain("4.5:1");
    expect(failure).toContain("does not adjust submitted colors");
  });

  it("reports a governance block differently from a color problem", async () => {
    // BRAND-021: a 409 is about where or when the edit was attempted, and no
    // amount of editing the palette will fix it.
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        jsonResponse(
          {
            message:
              'Policy, procedure, and fragment edits are blocked while Decompression is in its active event window.',
          },
          409,
        ),
      ),
    );

    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor" },
    });

    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(wrapper.get("[role='alert']").text()).toContain(
      "active event window",
    );
    // No per-pair failures, so the contrast block stays away rather than
    // rendering "these pairs need changing" above an empty list.
    expect(wrapper.find("[data-result='fail']").exists()).toBe(false);
  });

  it("shows the server's reason when a refusal carries no failing pairs", async () => {
    // A 403 is a permission problem, not a colour problem. Before this, the
    // screen rendered an empty "these pairs need changing" list and said
    // nothing about why the save was refused.
    vi.stubGlobal(
      "fetch",
      vi.fn(async () =>
        jsonResponse(
          { message: "You do not have permission to edit this organization's branding." },
          403,
        ),
      ),
    );

    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor" },
    });

    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(wrapper.get("[role='alert']").text()).toContain(
      "do not have permission",
    );
    expect(wrapper.find("[data-result='fail']").exists()).toBe(false);
  });

  it("invalidates a previous pass when a color changes", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => jsonResponse({ valid: true, failures: [] })),
    );

    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor" },
    });

    await wrapper.get("[data-action='preview']").trigger("click");
    await flushPromises();
    expect(wrapper.find("[data-result='pass']").exists()).toBe(true);

    await wrapper.get("input[name='primary']").setValue("#123a5c");
    await flushPromises();

    expect(wrapper.find("[data-result='pass']").exists()).toBe(false);
    expect(wrapper.text()).toContain("Run the contrast check");
  });

  it("offers all ten settable values and nothing else", () => {
    // BRAND-006/BRAND-007: status, severity, and attention colors are derived
    // and must not appear as controls.
    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor" },
    });

    const colorInputs = wrapper
      .findAll("input[type='color']")
      .map((input) => input.attributes("name"));

    expect(colorInputs).toEqual([
      "primary",
      "secondary",
      "tertiary",
      "accent",
      "canvas",
      "surface",
      "foreground",
      "muted_foreground",
      "border",
      "focus",
    ]);
  });

  it("disables editing for a user who may only view", () => {
    // BRAND-019: organization branding is organizer-only.
    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor", canManage: false },
    });

    expect(wrapper.text()).toContain("Only organizers and Lead Organizers");
    expect(
      wrapper.get("[data-action='save']").attributes("disabled"),
    ).toBeDefined();
    expect(wrapper.get("[data-section='palette']").attributes("disabled")).toBeDefined();
  });

  it("explains what the department override switch does", () => {
    const wrapper = mount(OrganizationBrandingView, {
      props: { organizationId: "org-harbor" },
    });

    const label = wrapper
      .get("input[name='department_branding_enabled']")
      .element.closest("label")?.textContent;

    expect(label).toContain("keeps department logos and accents");
    expect(label).toContain("Nothing a department has already set is deleted");
  });
});
