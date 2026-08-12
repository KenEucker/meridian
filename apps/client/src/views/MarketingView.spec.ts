// The public marketing surface (M18.23; PUBLIC-001 through PUBLIC-006).

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError } from "@/api/meridianApi";
import { INTEREST_TRAP_FIELD } from "@/marketing/marketingModel";
import { MARKETING_FEATURE_TOUR } from "@/marketing/marketingTour";
import MarketingView from "@/views/MarketingView.vue";

vi.mock("vue-router", () => ({
  useRouter: () => ({ replace: vi.fn() }),
  RouterLink: { template: "<a><slot /></a>" },
}));

const getMarketingSurface = vi.fn();
const submitOrganizationInterest = vi.fn();

vi.mock("@/marketing/marketingModel", async () => {
  const actual =
    await vi.importActual<typeof import("@/marketing/marketingModel")>(
      "@/marketing/marketingModel",
    );

  return {
    ...actual,
    getMarketingSurface: () => getMarketingSurface(),
    submitOrganizationInterest: (...args: unknown[]) =>
      submitOrganizationInterest(...args),
  };
});

const available = { formToken: "token-from-the-node", minimumSecondsOnForm: 3 };

beforeEach(() => {
  getMarketingSurface.mockReset();
  submitOrganizationInterest.mockReset();
  getMarketingSurface.mockResolvedValue(available);
  submitOrganizationInterest.mockResolvedValue(undefined);
});

async function mountSurface() {
  const wrapper = mount(MarketingView);
  await flushPromises();

  return wrapper;
}

async function fillInterestForm(wrapper: Awaited<ReturnType<typeof mountSurface>>) {
  const inputs = wrapper.findAll("input");
  await inputs[0].setValue("Harborlight Collective");
  await inputs[1].setValue("Rowan Vale");
  await inputs[2].setValue("rowan@harborlight.test");
  await wrapper.find("textarea").setValue("A three-day waterfront festival.");
}

describe("the public marketing surface", () => {
  /** PUBLIC-001: what Meridian is, for somebody who does not use it yet. */
  it("describes the platform and offers the interest form", async () => {
    const wrapper = await mountSurface();

    expect(wrapper.text()).toContain(
      "Run your event's volunteer operations in one place",
    );
    expect(wrapper.text()).toContain("Tell us about your organization");
    expect(wrapper.find("form").exists()).toBe(true);
  });

  /**
   * PUBLIC-001, BRAND-003: this is the one public surface that must not wear
   * an organization's identity. Unlike the participation page it resolves no
   * branding profile, because there is no organization in the request.
   */
  it("carries Meridian identity and resolves no organization branding", async () => {
    const wrapper = await mountSurface();

    expect(wrapper.text()).toContain("Meridian");
    expect(wrapper.find(".participate__mark").exists()).toBe(false);
    expect(wrapper.find(".marketing__mark").exists()).toBe(true);
  });

  /**
   * PUBLIC-006: an on-site node and a node locked to an event answer 404, and
   * the surface is not shown. Whoever mounted it decides where to send the
   * visitor.
   */
  it("reports itself unavailable when the node does not serve it", async () => {
    getMarketingSurface.mockRejectedValue(
      new MeridianApiError("Not found.", 404, null),
    );

    const wrapper = await mountSurface();

    expect(wrapper.emitted("unavailable")).toHaveLength(1);
    expect(wrapper.find("form").exists()).toBe(false);
    expect(wrapper.text()).not.toContain("Tell us about your organization");
  });

  /** PUBLIC-002, PUBLIC-003. */
  it("sends the four fields and the node's form token", async () => {
    const wrapper = await mountSurface();
    await fillInterestForm(wrapper);

    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(submitOrganizationInterest).toHaveBeenCalledWith(
      {
        organizationName: "Harborlight Collective",
        contactName: "Rowan Vale",
        contactEmail: "rowan@harborlight.test",
        description: "A three-day waterfront festival.",
      },
      "token-from-the-node",
      "",
    );
  });

  /**
   * PUBLIC-003, PUBLIC-004: the confirmation must not imply an account is
   * waiting, because none was created and creating an organization is a
   * deliberate God Mode step.
   */
  it("confirms without promising an account", async () => {
    const wrapper = await mountSurface();
    await fillInterestForm(wrapper);

    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain("Thank you");
    expect(wrapper.text()).toContain("Nothing has been created yet");
    expect(wrapper.find("form").exists()).toBe(false);
  });

  /**
   * PUBLIC-005: the hidden field is present, out of the tab order, and hidden
   * from assistive technology — protection that asks the visitor to solve
   * nothing.
   */
  it("carries a hidden field a visitor never reaches, and no challenge", async () => {
    const wrapper = await mountSurface();

    const trap = wrapper.find(".marketing__trap");
    expect(trap.exists()).toBe(true);
    expect(trap.attributes("aria-hidden")).toBe("true");
    expect(trap.find("input").attributes("tabindex")).toBe("-1");
    expect(trap.find("input").attributes("autocomplete")).toBe("off");
    expect(wrapper.text().toLowerCase()).not.toContain("captcha");
  });

  it("names the trap field the same thing the node reads", async () => {
    expect(INTEREST_TRAP_FIELD).toBe("organization_reference_code");
  });

  /**
   * PUBLIC-007: the landing page introduces each major feature area, one
   * section per catalogue entry, in the catalogue's order.
   */
  it("renders one feature tour section per catalogue entry", async () => {
    const wrapper = await mountSurface();

    const sections = wrapper.findAll(".marketing__feature");
    expect(sections).toHaveLength(MARKETING_FEATURE_TOUR.length);

    MARKETING_FEATURE_TOUR.forEach((feature, index) => {
      expect(sections[index].find("h3").text()).toBe(feature.title);
      expect(sections[index].text()).toContain(feature.description);
    });
  });

  /**
   * PUBLIC-008 and the accessibility checklist: every screenshot is the
   * committed Northwood asset, and every one of them carries alt text that
   * says what it shows. The page also states outright that the organization
   * pictured is fictional.
   */
  it("illustrates every feature with its Northwood screenshot and alt text", async () => {
    const wrapper = await mountSurface();

    const screenshots = wrapper.findAll("img.marketing__screenshot");
    expect(screenshots).toHaveLength(MARKETING_FEATURE_TOUR.length);

    MARKETING_FEATURE_TOUR.forEach((feature, index) => {
      expect(screenshots[index].attributes("src")).toBe(feature.screenshot);
      expect(screenshots[index].attributes("alt")).toBe(feature.screenshotAlt);
      expect(feature.screenshotAlt.trim().length).toBeGreaterThan(0);
    });

    expect(wrapper.text()).toContain("Northwood Collective");
    expect(wrapper.text()).toContain(
      "No real organization's data appears on this page.",
    );
  });

  /** PUBLIC-009: the three offerings, described. */
  it("describes the three platform offerings", async () => {
    const wrapper = await mountSurface();
    const offerings = wrapper.findAll(".marketing__offering");

    expect(offerings).toHaveLength(3);
    expect(offerings[0].text()).toContain("free and open source");
    expect(offerings[1].text()).toContain("without support");
    expect(offerings[1].text()).toContain("lower fee");
    expect(offerings[2].text()).toContain("full support");
    expect(offerings[2].text()).toContain("on-site technician");
  });

  /**
   * PUBLIC-009, PUBLIC-004: the offerings are descriptive only. No payment,
   * billing, or self-service signup path exists anywhere on the page — the
   * only form is the interest form, the only button submits it, and the only
   * links the tour and offerings carry point at that form.
   */
  it("offers no payment or self-service signup path", async () => {
    const wrapper = await mountSurface();

    expect(wrapper.findAll("form")).toHaveLength(1);

    // Every button on the page is either the interest form's submit or a
    // screenshot viewer control. Nothing buys, subscribes, or signs up.
    for (const button of wrapper.findAll("button")) {
      const isSubmit = button.attributes("type") === "submit";
      const isViewer = button.classes("marketing__shot");

      expect(isSubmit || isViewer).toBe(true);
    }
    expect(
      wrapper.findAll('button[type="submit"]'),
    ).toHaveLength(1);

    const offerings = wrapper.find('[aria-labelledby="marketing-offerings"]');
    expect(offerings.findAll("form")).toHaveLength(0);
    expect(offerings.findAll("button")).toHaveLength(0);
    expect(offerings.findAll("input")).toHaveLength(0);

    for (const link of wrapper
      .find('[aria-labelledby="marketing-tour"]')
      .findAll("a")
      .concat(offerings.findAll("a"))) {
      expect(link.attributes("href")).toBe("#marketing-interest");
    }

    // No price, no billing language. "Checkout" is deliberately not in this
    // list — equipment checkout is a feature the tour describes.
    expect(wrapper.text()).not.toMatch(
      /sign up|billing|subscribe|per month|credit card|\$|€|£/i,
    );
  });

  /**
   * A screenshot small enough for a column is too small to read an interface
   * in, so every one opens larger in an in-page viewer: same committed asset,
   * a dialog role, the alt text carried along, and Escape or the close button
   * to come back.
   */
  it("opens a screenshot larger, and closes the viewer again", async () => {
    const wrapper = await mountSurface();

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);

    await wrapper.find("button.marketing__shot").trigger("click");

    const viewer = wrapper.find('[role="dialog"]');
    expect(viewer.exists()).toBe(true);
    expect(viewer.attributes("aria-modal")).toBe("true");

    const first = MARKETING_FEATURE_TOUR[0];
    expect(viewer.find("img").attributes("src")).toBe(first.screenshot);
    expect(viewer.find("img").attributes("alt")).toBe(first.screenshotAlt);
    expect(viewer.text()).toContain(first.title);

    await viewer.trigger("keydown.esc");
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);

    await wrapper.find("button.marketing__shot").trigger("click");
    await wrapper.find(".marketing__viewer-close").trigger("click");
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
  });

  /**
   * M20.5: a reader who has just finished the tour or the offerings can act
   * on what they read — both sections end at the interest form, and the
   * anchor they use exists.
   */
  it("routes the tour and the offerings to the interest form", async () => {
    const wrapper = await mountSurface();

    const tourLink = wrapper
      .find('[aria-labelledby="marketing-tour"]')
      .find('a[href="#marketing-interest"]');
    const offeringsLink = wrapper
      .find('[aria-labelledby="marketing-offerings"]')
      .find('a[href="#marketing-interest"]');

    expect(tourLink.exists()).toBe(true);
    expect(offeringsLink.exists()).toBe(true);
    expect(wrapper.find("#marketing-interest").exists()).toBe(true);
  });

  /**
   * PUBLIC-005: a refusal is recoverable. The visitor keeps everything they
   * wrote, and a fresh form token is collected so the next press is not
   * refused for the same reason.
   */
  it("keeps what was written when a submission is refused, and takes a new token", async () => {
    const wrapper = await mountSurface();
    await fillInterestForm(wrapper);

    submitOrganizationInterest.mockRejectedValueOnce(
      new MeridianApiError("This form has been open too long.", 422, {
        message: "This form has been open too long.",
        errors: {
          form_token: [
            "This form has been open too long. Reload the page and send it again.",
          ],
        },
      }),
    );
    getMarketingSurface.mockResolvedValue({
      formToken: "a-fresh-token",
      minimumSecondsOnForm: 3,
    });

    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(wrapper.text()).toContain(
      "This form has been open too long. Reload the page and send it again.",
    );
    expect(wrapper.find("form").exists()).toBe(true);
    expect(
      (wrapper.findAll("input")[0].element as HTMLInputElement).value,
    ).toBe("Harborlight Collective");

    await wrapper.find("form").trigger("submit");
    await flushPromises();

    expect(submitOrganizationInterest).toHaveBeenLastCalledWith(
      expect.anything(),
      "a-fresh-token",
      "",
    );
  });
});
