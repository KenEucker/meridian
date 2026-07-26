import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";

import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";

describe("ControlBar", () => {
  it("names the band and draws a surface by default", () => {
    const wrapper = mount(ControlBar, {
      props: { label: "Incident list controls" },
      slots: { default: "<form></form>" },
    });

    expect(wrapper.attributes("role")).toBe("group");
    expect(wrapper.attributes("aria-label")).toBe("Incident list controls");
    expect(wrapper.classes()).toContain("control-bar--band");
  });

  it("drops the surface when it already sits inside one", () => {
    const wrapper = mount(ControlBar, {
      props: { label: "Filters", variant: "bare" },
    });

    expect(wrapper.classes()).toContain("control-bar--bare");
    expect(wrapper.classes()).not.toContain("control-bar--band");
  });

  it("keeps sibling forms separate so they still submit separately", () => {
    const wrapper = mount(ControlBar, {
      props: { label: "List controls" },
      slots: {
        default:
          '<form data-control-group="grow" aria-label="Search"></form>' +
          '<form data-control-group aria-label="Filter"></form>',
      },
    });

    const forms = wrapper.findAll("form");
    expect(forms).toHaveLength(2);
    expect(forms[0]!.attributes("data-control-group")).toBe("grow");
    expect(forms[1]!.attributes("aria-label")).toBe("Filter");
  });

  it("pins end actions into their own group", () => {
    const wrapper = mount(ControlBar, {
      props: { label: "List controls" },
      slots: { end: "<button>Reset</button>" },
    });

    expect(wrapper.get(".control-bar__end").text()).toBe("Reset");
  });
});

describe("ControlField", () => {
  it("labels its control and declares a content width", () => {
    const wrapper = mount(ControlField, {
      props: { label: "State", controlId: "state", width: "sm" },
      slots: { default: '<select id="state"></select>' },
    });

    expect(wrapper.attributes("data-width")).toBe("sm");
    const label = wrapper.get("label");
    expect(label.text()).toBe("State");
    expect(label.attributes("for")).toBe("state");
    expect(label.classes()).toContain("control-field__label");
  });

  it("defaults to the medium content width", () => {
    const wrapper = mount(ControlField, {
      props: { label: "Type" },
      slots: { default: "<select></select>" },
    });

    expect(wrapper.attributes("data-width")).toBe("md");
  });

  it("keeps the label available to assistive tech when it is hidden", () => {
    const wrapper = mount(ControlField, {
      props: { label: "Search", controlId: "q", labelHidden: true },
      slots: { default: '<input id="q" />' },
    });

    const label = wrapper.get("label");
    expect(label.text()).toBe("Search");
    expect(label.classes()).toContain("control-field__label-hidden");
  });
});
