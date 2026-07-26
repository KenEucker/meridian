import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import ContentGrid from "@/components/ContentGrid.vue";
import StaffCardList from "@/components/StaffCardList.vue";

describe("ContentGrid", () => {
  it("renders a div with tile columns by default", () => {
    const wrapper = mount(ContentGrid, { slots: { default: "<p>one</p>" } });

    expect(wrapper.element.tagName).toBe("DIV");
    expect(wrapper.classes()).toContain("content-grid");
    expect(wrapper.classes()).toContain("content-grid--tile");
    expect(wrapper.classes()).not.toContain("content-grid--start");
  });

  it("renders a labelled list when the children are records", () => {
    const wrapper = mount(ContentGrid, {
      props: { as: "ul", label: "Shifts", min: "wide" },
      slots: { default: "<li>one</li>" },
    });

    expect(wrapper.element.tagName).toBe("UL");
    expect(wrapper.attributes("aria-label")).toBe("Shifts");
    expect(wrapper.classes()).toContain("content-grid--wide");
  });

  it("stops stretching rows when tile heights carry meaning", () => {
    const wrapper = mount(ContentGrid, {
      props: { stretch: false },
      slots: { default: "<p>one</p>" },
    });

    expect(wrapper.classes()).toContain("content-grid--start");
  });
});

describe("StaffCardList", () => {
  it("tiles its cards through ContentGrid and names the list", () => {
    const wrapper = mount(StaffCardList, {
      props: { label: "Field Reports" },
      slots: { default: "<li>one</li>" },
    });

    const grid = wrapper.get("ul");
    expect(grid.classes()).toContain("content-grid");
    expect(grid.classes()).toContain("content-grid--tile");
    expect(grid.attributes("aria-label")).toBe("Field Reports");
  });

  it("widens the tile when asked", () => {
    const wrapper = mount(StaffCardList, {
      props: { label: "Documents", min: "wide" },
      slots: { default: "<li>one</li>" },
    });

    expect(wrapper.get("ul").classes()).toContain("content-grid--wide");
  });

  it("shows the shared empty state instead of an empty grid", () => {
    const wrapper = mount(StaffCardList, {
      props: { label: "Documents", empty: true, emptyMessage: "Nothing here." },
    });

    expect(wrapper.find("ul").exists()).toBe(false);
    expect(wrapper.get(".staff-card-list__empty").text()).toBe("Nothing here.");
  });
});
