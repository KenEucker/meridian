import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import ReadinessView from "@/views/ReadinessView.vue";
import { routes } from "@/router";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

async function mountAt(path: string) {
  const router = buildRouter();
  await router.push(path);
  await router.isReady();

  return mount(App, {
    global: {
      plugins: [router],
    },
  });
}

describe("readiness surface", () => {
  it("is reachable at the /readiness route", async () => {
    const wrapper = await mountAt("/readiness");

    expect(wrapper.get("#readiness-heading").text()).toBe("Device readiness");
  });

  it("renders every section 14 readiness item", async () => {
    const wrapper = await mountAt("/readiness");

    const rows = wrapper.findAll(".readiness-checklist__item");
    expect(rows).toHaveLength(8);
    const text = wrapper.text();
    for (const label of [
      "Logged in",
      "Device trusted",
      "Event selected",
      "Local cache complete",
      "Encryption active",
      "Last sync completed",
      "Trusted server known",
      "Device signing available",
    ]) {
      expect(text).toContain(label);
    }
  });

  it("states that readiness is advisory and user-private, not organizer-visible", async () => {
    const wrapper = await mountAt("/readiness");

    const lede = wrapper.get(".readiness__lede").text();
    expect(lede).toContain("only for you");
    expect(lede).toContain("advisory");
    expect(lede).toContain("not shared with");
    expect(lede).toContain("not required");
  });

  it("summarizes ready checks out of the total", async () => {
    const wrapper = await mountAt("/readiness");

    expect(wrapper.get(".readiness__summary").text()).toMatch(
      /^\d+ of 8 checks ready\.$/,
    );
  });

  it("links to the readiness surface from the home placeholder", async () => {
    const wrapper = await mountAt("/");

    const link = wrapper
      .findAll(".home__links a")
      .find((item) => item.text() === "Check device readiness");

    expect(link?.text()).toBe("Check device readiness");
    expect(link?.attributes("href")).toBe("/readiness");
  });

  it("probes the current client scope when the surface renders", () => {
    const wrapper = mount(ReadinessView);

    expect(wrapper.findAll(".readiness-checklist__item")).toHaveLength(8);
  });
});
