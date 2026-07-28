import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";

import DepartmentBadge from "@/components/DepartmentBadge.vue";

const rangers = {
  id: "dept-rangers",
  name: "Rangers",
};

describe("DepartmentBadge", () => {
  it("prefers a logo over an icon and a lettermark", () => {
    const wrapper = mount(DepartmentBadge, {
      props: {
        department: {
          ...rangers,
          logoUrl: "/branding/assets/logo",
          icon: "R",
        },
      },
    });

    expect(wrapper.find(".department-badge__logo").attributes("src")).toBe(
      "/branding/assets/logo",
    );
    expect(wrapper.find(".department-badge__icon").exists()).toBe(false);
    expect(wrapper.find(".department-badge__lettermark").exists()).toBe(false);
  });

  it("falls back to the icon when there is no logo", () => {
    const wrapper = mount(DepartmentBadge, {
      props: { department: { ...rangers, icon: "R" } },
    });

    expect(wrapper.get(".department-badge__icon").text()).toBe("R");
  });

  it("generates a lettermark from separate words when there is no logo or icon", () => {
    const wrapper = mount(DepartmentBadge, {
      props: {
        department: { id: "dept-dpw", name: "Department of Public Works" },
      },
    });

    expect(wrapper.get(".department-badge__lettermark").text()).toBe("DPW");
  });

  it("uses two letters for a single-word department", () => {
    const wrapper = mount(DepartmentBadge, {
      props: { department: rangers },
    });

    expect(wrapper.get(".department-badge__lettermark").text()).toBe("RA");
  });

  it("prefers a server-generated lettermark when one is supplied", () => {
    const wrapper = mount(DepartmentBadge, {
      props: { department: { ...rangers, lettermark: "RGR" } },
    });

    expect(wrapper.get(".department-badge__lettermark").text()).toBe("RGR");
  });

  it("keeps the full department name in the accessible name behind a short label", () => {
    const wrapper = mount(DepartmentBadge, {
      props: {
        department: {
          id: "dept-dpw",
          name: "Department of Public Works",
          shortLabel: "DPW",
        },
      },
    });

    expect(wrapper.get(".department-badge__label").text()).toBe("DPW");
    expect(wrapper.get(".department-badge").attributes("aria-label")).toBe(
      "DPW (Department of Public Works)",
    );
  });

  it("does not repeat the name when the visible label already is the name", () => {
    const wrapper = mount(DepartmentBadge, {
      props: { department: rangers },
    });

    expect(wrapper.get(".department-badge").attributes("aria-label")).toBe(
      "Rangers",
    );
  });

  it("hides the decorative glyph from assistive technology", () => {
    const wrapper = mount(DepartmentBadge, {
      props: { department: rangers },
    });

    expect(
      wrapper.get(".department-badge__glyph").attributes("aria-hidden"),
    ).toBe("true");
  });

  it("renders the accent as a small identifier rather than a fill", () => {
    const wrapper = mount(DepartmentBadge, {
      props: { department: { ...rangers, accentColor: "#1f5f4b" } },
    });

    const badge = wrapper.get(".department-badge");

    expect(badge.classes()).toContain("department-badge--accented");
    expect(badge.attributes("style")).toContain("#1f5f4b");
  });

  it("omits the accent when the organization has department overrides off", () => {
    // The caller passes showAccent=false; the badge does not invent an accent.
    const wrapper = mount(DepartmentBadge, {
      props: {
        department: { ...rangers, accentColor: "#1f5f4b" },
        showAccent: false,
      },
    });

    const badge = wrapper.get(".department-badge");

    expect(badge.classes()).not.toContain("department-badge--accented");
    expect(badge.attributes("style") ?? "").not.toContain("#1f5f4b");
  });

  it("never applies a department surface background", () => {
    // BRAND-012 scoping is a surface decision. A badge that painted a
    // background would leak the department surface onto IMS and The Briefing.
    const wrapper = mount(DepartmentBadge, {
      props: { department: { ...rangers, accentColor: "#1f5f4b" } },
    });

    expect(
      wrapper.get(".department-badge").attributes("data-department-surface"),
    ).toBeUndefined();
  });

  it("supports the three contract sizes", () => {
    for (const size of ["sm", "md", "lg"] as const) {
      const wrapper = mount(DepartmentBadge, {
        props: { department: rangers, size },
      });

      expect(wrapper.get(".department-badge").classes()).toContain(
        `department-badge--${size}`,
      );
    }
  });

  it("suppresses the logo when showLogo is false", () => {
    const wrapper = mount(DepartmentBadge, {
      props: {
        department: { ...rangers, logoUrl: "/branding/assets/logo" },
        showLogo: false,
      },
    });

    expect(wrapper.find(".department-badge__logo").exists()).toBe(false);
    expect(wrapper.get(".department-badge__lettermark").text()).toBe("RA");
  });
});
