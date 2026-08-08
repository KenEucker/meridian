import { afterEach, describe, expect, it, vi } from "vitest";

import {
  configureMeridianApi,
  meridianApiConfig,
  meridianFetch,
} from "@/api/meridianApi";
import { clearNodeUrl, setNodeUrl } from "@/app/nodeConnection";
import {
  centralReachability,
  resetCentralReachability,
} from "@/offline/centralReachability";
import {
  nodeReachability,
  resetNodeReachability,
} from "@/offline/nodeReachability";

afterEach(() => {
  configureMeridianApi(null);
  clearNodeUrl();
  resetNodeReachability();
  resetCentralReachability();
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

  /*
   * The second tier rides the same responses (M18.52). The device cannot ask
   * central anything, so the node it is pointed at reports what it can reach on
   * every answer it gives, and both tiers are recorded off the one response.
   */
  it("records what the node says it can reach, from the same response", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response("{}", {
            status: 200,
            headers: { "Meridian-Central-Reach": "unreachable" },
          }),
      ),
    );

    await meridianFetch("/api/me");

    expect(nodeReachability.value).toBe("reachable");
    expect(centralReachability.value).toBe("unreachable");
  });

  it("takes the report off a refusal as readily as off a success", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(
        async () =>
          new Response("", {
            status: 401,
            headers: { "Meridian-Central-Reach": "not_applicable" },
          }),
      ),
    );

    await meridianFetch("/api/me");

    expect(centralReachability.value).toBe("not_applicable");
  });

  it("says nothing about central when the response carries no report", async () => {
    // A node that does not report the tier, or a browser that cannot read the
    // header cross-origin. Neither is a reason to invent an answer.
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => new Response("{}", { status: 200 })),
    );

    await meridianFetch("/api/me");

    expect(centralReachability.value).toBe("unreported");
  });
});
