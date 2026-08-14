import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { appConfigForDeploymentTarget } from "@/app/appConfig";
import {
  clearNodeUrl,
  DEFAULT_CENTRAL_NODE_URL,
  DEFAULT_NODE_URL,
  discoverNodeUrl,
  nodeConnection,
  normalizeNodeUrl,
  NodeUrlError,
  ON_SITE_NODE_URLS,
  refreshNodeConnection,
  resetNodeDiscovery,
  resolveNodeUrl,
  setNodeUrl,
} from "@/app/nodeConnection";

/**
 * Pretend a node served this client. The injected value is fixed for the life
 * of a real page, so the resolver has to be told it changed.
 */
function servedBy(apiBaseUrl: string): void {
  window.__MERIDIAN_RUNTIME_CONFIG__ = { apiBaseUrl };
  refreshNodeConnection();
}

beforeEach(() => {
  window.localStorage.clear();
  clearNodeUrl();
  resetNodeDiscovery();
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
  refreshNodeConnection();
});

afterEach(() => {
  window.localStorage.clear();
  clearNodeUrl();
  resetNodeDiscovery();
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
  refreshNodeConnection();
});

describe("normalizeNodeUrl", () => {
  it("keeps the origin and drops a trailing slash", () => {
    expect(normalizeNodeUrl("https://onsite.example.org/")).toBe(
      "https://onsite.example.org",
    );
  });

  it("keeps a path prefix, which a node behind a reverse proxy needs", () => {
    expect(normalizeNodeUrl("https://example.org/meridian/")).toBe(
      "https://example.org/meridian",
    );
  });

  it("refuses an address with no scheme", () => {
    // A bare host is the most likely thing somebody types, and it would
    // otherwise fail much later as sync that never connects.
    expect(() => normalizeNodeUrl("onsite.example.org")).toThrow(NodeUrlError);
  });

  it("refuses a scheme that is not http or https", () => {
    expect(() => normalizeNodeUrl("ftp://example.org")).toThrow(NodeUrlError);
  });

  it("refuses an empty address", () => {
    expect(() => normalizeNodeUrl("   ")).toThrow(NodeUrlError);
  });

  it("refuses a query or fragment, which a node address never has", () => {
    expect(() => normalizeNodeUrl("https://example.org/?token=abc")).toThrow(
      NodeUrlError,
    );
    expect(() => normalizeNodeUrl("https://example.org/#/home")).toThrow(
      NodeUrlError,
    );
  });
});

describe("nodeConnection", () => {
  it("falls back to the development default when nothing is known", () => {
    expect(nodeConnection.value).toMatchObject({
      url: DEFAULT_NODE_URL,
      source: "default",
      servedUrl: null,
    });
  });

  it("uses the node that served the client when there is one", () => {
    servedBy("https://onsite.example.org/");

    expect(nodeConnection.value).toMatchObject({
      url: "https://onsite.example.org",
      source: "served",
      overridesServingNode: false,
    });
  });

  it("prefers a configured node over the one that served the client", () => {
    // This is the whole point for desktop and mobile, where nothing serves the
    // client at all.
    servedBy("https://central.example.org");

    setNodeUrl("https://onsite.example.org");

    expect(nodeConnection.value).toMatchObject({
      url: "https://onsite.example.org",
      source: "configured",
      servedUrl: "https://central.example.org",
      overridesServingNode: true,
    });
  });

  it("does not report an override when the configured node is the serving one", () => {
    servedBy("https://onsite.example.org");

    setNodeUrl("https://onsite.example.org/");

    expect(nodeConnection.value.overridesServingNode).toBe(false);
  });

  it("ignores an injected value that is not a usable address", () => {
    // A deployment can be misconfigured, and the app still has to start.
    servedBy("not-a-url");

    expect(nodeConnection.value.source).toBe("default");
  });
});

/*
 * The zero-settings path (technical spec 8.4): a packaged app that knows
 * nothing assumes the on-site convention, and a boot-time probe promotes the
 * first node that actually answers — the numbered on-site names in order,
 * then the central deployment.
 */
describe("the on-site convention and discovery", () => {
  const mobile = appConfigForDeploymentTarget("mobile");

  it("assumes the main on-site name on a packaged app that knows nothing", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = { deploymentTarget: "mobile" };
    refreshNodeConnection();

    expect(nodeConnection.value).toMatchObject({
      url: "http://meridian.home.arpa",
      source: "convention",
    });
  });

  it("keeps the development default in a browser client", () => {
    expect(nodeConnection.value.source).toBe("default");
  });

  it("promotes the first on-site name that answers", async () => {
    const probe = vi.fn(async (url: string) => url === ON_SITE_NODE_URLS[1]);

    const found = await discoverNodeUrl({ probe, config: mobile });

    expect(found).toBe("http://meridian2.home.arpa");
    expect(nodeConnection.value).toMatchObject({
      url: "http://meridian2.home.arpa",
      source: "discovered",
    });
  });

  it("falls back to the central deployment when no on-site node answers", async () => {
    const probe = vi.fn(async (url: string) => url === DEFAULT_CENTRAL_NODE_URL);

    const found = await discoverNodeUrl({ probe, config: mobile });

    expect(found).toBe(DEFAULT_CENTRAL_NODE_URL);
    expect(probe).toHaveBeenCalledTimes(ON_SITE_NODE_URLS.length + 1);
  });

  it("leaves the convention standing when nothing answers anywhere", async () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = { deploymentTarget: "mobile" };
    refreshNodeConnection();

    const found = await discoverNodeUrl({
      probe: async () => false,
      config: mobile,
    });

    expect(found).toBeNull();
    expect(nodeConnection.value.source).toBe("convention");
  });

  it("never second-guesses a node somebody set on purpose", async () => {
    setNodeUrl("https://onsite.example.org");
    const probe = vi.fn(async () => true);

    expect(await discoverNodeUrl({ probe, config: mobile })).toBeNull();
    expect(probe).not.toHaveBeenCalled();
  });

  it("does not probe from a browser client, which its node already serves", async () => {
    const probe = vi.fn(async () => true);

    const found = await discoverNodeUrl({
      probe,
      config: appConfigForDeploymentTarget("server"),
    });

    expect(found).toBeNull();
    expect(probe).not.toHaveBeenCalled();
  });

  it("is outranked by a node configured after discovery", async () => {
    await discoverNodeUrl({ probe: async () => true, config: mobile });

    setNodeUrl("https://onsite.example.org");

    expect(nodeConnection.value.source).toBe("configured");
  });
});

describe("setNodeUrl", () => {
  it("persists across a reload", () => {
    setNodeUrl("https://onsite.example.org");

    expect(window.localStorage.getItem("meridian.node.url")).toBe(
      "https://onsite.example.org",
    );
  });

  it("is what API requests are made against", () => {
    setNodeUrl("https://onsite.example.org");

    expect(resolveNodeUrl()).toBe("https://onsite.example.org");
  });

  it("refuses a malformed address without changing what is stored", () => {
    setNodeUrl("https://onsite.example.org");

    expect(() => setNodeUrl("nope")).toThrow(NodeUrlError);
    expect(resolveNodeUrl()).toBe("https://onsite.example.org");
  });
});

describe("clearNodeUrl", () => {
  it("falls back to the serving node", () => {
    servedBy("https://central.example.org");
    setNodeUrl("https://onsite.example.org");

    clearNodeUrl();

    expect(nodeConnection.value).toMatchObject({
      url: "https://central.example.org",
      source: "served",
    });
  });
});
