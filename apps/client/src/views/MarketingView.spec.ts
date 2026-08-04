// The public marketing surface (M18.23; PUBLIC-001 through PUBLIC-006).

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError } from "@/api/meridianApi";
import { INTEREST_TRAP_FIELD } from "@/marketing/marketingModel";
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
