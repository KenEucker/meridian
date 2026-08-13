// The marketing surface at `/platform`, and the node that does not serve it
// (M20; PUBLIC-006).

import { flushPromises, mount } from "@vue/test-utils";
import { beforeEach, describe, expect, it, vi } from "vitest";

import { MeridianApiError } from "@/api/meridianApi";
import PlatformView from "@/views/PlatformView.vue";

const replace = vi.fn();

vi.mock("vue-router", () => ({
  useRouter: () => ({ replace }),
  RouterLink: { template: "<a><slot /></a>" },
}));

const getMarketingSurface = vi.fn();

vi.mock("@/marketing/marketingModel", async () => {
  const actual =
    await vi.importActual<typeof import("@/marketing/marketingModel")>(
      "@/marketing/marketingModel",
    );

  return {
    ...actual,
    getMarketingSurface: () => getMarketingSurface(),
  };
});

beforeEach(() => {
  replace.mockReset();
  getMarketingSurface.mockReset();
});

describe("the platform route", () => {
  it("renders the marketing surface where the node serves it", async () => {
    getMarketingSurface.mockResolvedValue({
      formToken: "token",
      minimumSecondsOnForm: 3,
    });

    const wrapper = mount(PlatformView);
    await flushPromises();

    expect(wrapper.text()).toContain(
      "Run your event's volunteer operations in one place",
    );
    expect(replace).not.toHaveBeenCalled();
  });

  /**
   * A node that does not serve the surface answers 404, and this route used
   * to render a blank page over it — the view emitted `unavailable` and
   * nothing was listening. The visitor goes to the root instead, which knows
   * what to do with every kind of visitor.
   */
  it("sends the visitor to the root when the node does not serve it", async () => {
    getMarketingSurface.mockRejectedValue(
      new MeridianApiError("Not found.", 404, null),
    );

    mount(PlatformView);
    await flushPromises();

    expect(replace).toHaveBeenCalledWith({ name: "home" });
  });
});
