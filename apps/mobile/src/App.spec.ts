import { describe, expect, it } from "vitest";
import { mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import App from "@/App.vue";
import { routes } from "@/router";

function buildRouter() {
  return createRouter({
    history: createWebHistory(),
    routes,
  });
}

describe("field app shell", () => {
  it("renders the app shell with the home placeholder", async () => {
    const router = buildRouter();
    await router.push("/");
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    });

    expect(wrapper.find(".app-shell").exists()).toBe(true);
    expect(wrapper.get("#home-heading").text()).toBe("Meridian Field");
  });

  it("renders the not-found placeholder for unknown routes", async () => {
    const router = buildRouter();
    await router.push("/does-not-exist");
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [router],
      },
    });

    expect(wrapper.get("#not-found-heading").text()).toBe("Page not found");
  });
});
