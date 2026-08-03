import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { routes } from "@/router";
import { resetApiLoginForTests } from "@/session/apiLogin";
import { API_TOKEN_STORAGE_KEY, apiBearerToken, clearApiToken } from "@/session/apiToken";
import { clearClientSession } from "@/session/clientSession";
import { configureDeviceIdentity } from "@/session/deviceIdentity";
import { localFieldSessionDocument } from "@/session/localFieldSessionFixture";
import LoginCodeView from "@/views/LoginCodeView.vue";
import LoginView from "@/views/LoginView.vue";

/*
 * The two sign-in surfaces (UI implementation contract 12.1; M16.11; AUTH-018,
 * AUTH-019).
 *
 * A person types an address, is told a code was sent, types the code, and lands
 * signed in. No server runs (CLIENT-024): a fake node answers the two login
 * endpoints and `GET /api/me`.
 */

const EMAIL = "dana@example.test";
const TOKEN = "mrdn_at_issued";

let refusal: { status: number; message: string } | null = null;

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function stubNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL) => {
      const path = new URL(String(input)).pathname;

      if (refusal !== null) {
        return json({ message: refusal.message }, refusal.status);
      }

      if (path === "/api/auth/magic-link") {
        return json({ status: "sent", expires_in_minutes: 15 }, 202);
      }

      if (path === "/api/auth/magic-link/verify") {
        return json(
          {
            token: TOKEN,
            expires_at: null,
            user: { id: "user-1", name: "Dana Reyes", email: EMAIL },
            device: { id: "device-1", label: "Meridian Admin (web)", platform: "web" },
          },
          201,
        );
      }

      if (path === "/api/me") {
        return json(localFieldSessionDocument());
      }

      return json({ message: "No route." }, 404);
    }),
  );
}

function memoryStorage(): Storage {
  const entries = new Map<string, string>();

  return {
    get length() {
      return entries.size;
    },
    clear: () => entries.clear(),
    getItem: (key: string) => entries.get(key) ?? null,
    key: (index: number) => [...entries.keys()][index] ?? null,
    removeItem: (key: string) => void entries.delete(key),
    setItem: (key: string, value: string) => void entries.set(key, value),
  } as Storage;
}

function stubDevice(): void {
  let stored: CryptoKeyPair | null = null;

  configureDeviceIdentity({
    storage: memoryStorage(),
    keys: {
      read: async () => stored,
      write: async (pair: CryptoKeyPair) => {
        stored = pair;
      },
    },
    crypto: {
      randomUUID: () => "11111111-1111-4111-8111-111111111111",
      subtle: {
        generateKey: async () =>
          ({ privateKey: {}, publicKey: {} }) as unknown as CryptoKeyPair,
        exportKey: async () => new Uint8Array([1, 2, 3, 4]).buffer,
      },
    } as unknown as Crypto,
  });
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

beforeEach(() => {
  refusal = null;
  window.localStorage.removeItem(API_TOKEN_STORAGE_KEY);
  clearApiToken();
  clearClientSession();
  resetApiLoginForTests();
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });
  stubDevice();
  stubNode();
});

afterEach(() => {
  vi.unstubAllGlobals();
  configureDeviceIdentity(null);
  configureMeridianApi(null);
  clearApiToken();
  clearClientSession();
  resetApiLoginForTests();
});

describe("auth.login", () => {
  it("asks for an address and sends the person to code entry", async () => {
    const router = buildRouter();
    await router.push("/login");
    await router.isReady();

    const wrapper = mount(LoginView, { global: { plugins: [router] } });

    await wrapper.get("#login-email").setValue(EMAIL);
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("auth.code.entry");
  });

  it("states the node's refusal and goes nowhere", async () => {
    refusal = { status: 429, message: "Too many sign-in attempts. Try again shortly." };

    const router = buildRouter();
    await router.push("/login");
    await router.isReady();

    const wrapper = mount(LoginView, { global: { plugins: [router] } });

    await wrapper.get("#login-email").setValue(EMAIL);
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(wrapper.get(".login__error").text()).toBe(
      "Too many sign-in attempts. Try again shortly.",
    );
    expect(router.currentRoute.value.path).toBe("/login");
  });
});

describe("auth.code-entry", () => {
  async function requestCode(router: ReturnType<typeof buildRouter>): Promise<void> {
    await router.push("/login");
    await router.isReady();

    const login = mount(LoginView, { global: { plugins: [router] } });

    await login.get("#login-email").setValue(EMAIL);
    await login.get("form").trigger("submit");
    await flushPromises();
  }

  it("names the address the code went to and signs the person in", async () => {
    const router = buildRouter();

    await requestCode(router);

    const wrapper = mount(LoginCodeView, { global: { plugins: [router] } });

    expect(wrapper.text()).toContain(EMAIL);
    expect(wrapper.text()).toContain("15 minutes");

    await wrapper.get("#login-code-field").setValue("K3M7PQRS");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(apiBearerToken()).toBe(TOKEN);
    expect(router.currentRoute.value.name).toBe("home");
  });

  /*
   * A refused code is spent, wrong, or expired. The field is cleared either way
   * so the next attempt is not the same one again.
   */
  it("states a refusal, clears the field, and stays put", async () => {
    const router = buildRouter();

    await requestCode(router);

    const wrapper = mount(LoginCodeView, { global: { plugins: [router] } });

    refusal = { status: 422, message: "That login code is not valid." };

    await wrapper.get("#login-code-field").setValue("K3M7PQRS");
    await wrapper.get("form").trigger("submit");
    await flushPromises();

    expect(wrapper.get(".login-code__error").text()).toBe(
      "That login code is not valid.",
    );
    expect(
      (wrapper.get("#login-code-field").element as HTMLInputElement).value,
    ).toBe("");
    expect(apiBearerToken()).toBeNull();
  });

  it("offers no field when this device does not know which address to check", async () => {
    const router = buildRouter();
    await router.push("/login/code");
    await router.isReady();

    const wrapper = mount(LoginCodeView, { global: { plugins: [router] } });

    expect(wrapper.find("#login-code-field").exists()).toBe(false);
    expect(wrapper.text()).toContain("Start from the sign-in screen.");
  });
});
