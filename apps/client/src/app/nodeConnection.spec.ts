import { afterEach, beforeEach, describe, expect, it } from "vitest";

import {
  clearNodeUrl,
  DEFAULT_NODE_URL,
  nodeConnection,
  normalizeNodeUrl,
  NodeUrlError,
  refreshNodeConnection,
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
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
  refreshNodeConnection();
});

afterEach(() => {
  window.localStorage.clear();
  clearNodeUrl();
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
