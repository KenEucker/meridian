import { afterEach, describe, expect, it, vi } from "vitest";

import {
  configureMeridianApi,
  meridianApiConfig,
  meridianFetch,
} from "@/api/meridianApi";
import { clearNodeUrl, setNodeUrl } from "@/app/nodeConnection";
import {
  nodeReachability,
  resetNodeReachability,
} from "@/offline/nodeReachability";

afterEach(() => {
  configureMeridianApi(null);
  clearNodeUrl();
  resetNodeReachability();
  window.localStorage.clear();
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
  vi.unstubAllGlobals();
});

describe("meridianApiConfig", () => {
  it("uses the server-injected API base URL when present", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = {
      apiBaseUrl: "http://localhost:8000/",
    };

    expect(meridianApiConfig().baseUrl).toBe("http://localhost:8000");
  });

  it("keeps explicit test overrides ahead of runtime config", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = {
      apiBaseUrl: "http://localhost:8000/",
    };
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:9000",
      bearerToken: null,
    });

    expect(meridianApiConfig().baseUrl).toBe("http://127.0.0.1:9000");
  });

  it("uses the node this device has been pointed at over the serving node", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = {
      apiBaseUrl: "http://localhost:8000/",
    };
    setNodeUrl("https://onsite.example.org");

    expect(meridianApiConfig().baseUrl).toBe("https://onsite.example.org");
  });
});

/*
 * Every request the client makes passes through `meridianFetch`, which makes it
 * the one place that can learn whether the node is there. Nothing polls; this is
 * what the traffic the client was already sending happens to reveal.
 */
describe("what a request teaches the client about its node", () => {
  it("counts any response as an answer, a refusal included", async () => {
    // A 401 from a revoked token proves there is a Meridian at that address as
    // surely as a 200 does. This is the same distinction `meridianCachedJson`
    // draws for its cache fallback: whether the node spoke, not what it said.
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response("", { status: 401 })),
    );

    await meridianFetch("/api/me");

    expect(nodeReachability.value).toBe("reachable");
  });

  it("counts a request that never completed as no node reachable", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    await expect(meridianFetch("/api/me")).rejects.toThrow("Failed to fetch");

    expect(nodeReachability.value).toBe("unreachable");
  });

  it("lets a later answer replace an earlier failure", async () => {
    // A node that comes back up is reachable again on the next request rather
    // than on a reload. There is nothing else to correct it: the client does
    // not probe.
    const fetchMock = vi
      .fn<typeof fetch>()
      .mockRejectedValueOnce(new TypeError("Failed to fetch"))
      .mockResolvedValueOnce(new Response("{}", { status: 200 }));

    vi.stubGlobal("fetch", fetchMock);

    await expect(meridianFetch("/api/me")).rejects.toThrow();
    expect(nodeReachability.value).toBe("unreachable");

    await meridianFetch("/api/me");
    expect(nodeReachability.value).toBe("reachable");
  });
});
