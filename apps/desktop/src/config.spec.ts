import { describe, expect, it } from "vitest";

import { DEFAULT_APP_URL, HEALTH_PATH, resolveAppUrl, resolveHealthUrl } from "./config";

describe("resolveAppUrl", () => {
  it("returns the local development default when no override is set", () => {
    expect(resolveAppUrl({})).toBe(DEFAULT_APP_URL);
  });

  it("uses MERIDIAN_APP_URL when provided", () => {
    expect(resolveAppUrl({ MERIDIAN_APP_URL: "https://onsite.local/" })).toBe(
      "https://onsite.local/",
    );
  });

  it("trims surrounding whitespace from the override", () => {
    expect(resolveAppUrl({ MERIDIAN_APP_URL: "  http://10.0.0.5:8000/  " })).toBe(
      "http://10.0.0.5:8000/",
    );
  });

  it("rejects non-http(s) schemes", () => {
    expect(() => resolveAppUrl({ MERIDIAN_APP_URL: "file:///etc/passwd" })).toThrow(
      /must use http or https/,
    );
  });

  it("rejects malformed URLs", () => {
    expect(() => resolveAppUrl({ MERIDIAN_APP_URL: "not a url" })).toThrow(/not a valid URL/);
  });
});

describe("resolveHealthUrl", () => {
  it("derives the health endpoint from the default app URL", () => {
    expect(resolveHealthUrl({})).toBe(`http://localhost:8000${HEALTH_PATH}`);
  });

  it("derives the health endpoint from a custom app URL", () => {
    expect(resolveHealthUrl({ MERIDIAN_APP_URL: "https://onsite.local:9000/" })).toBe(
      "https://onsite.local:9000/api/health",
    );
  });

  it("prefers an explicit MERIDIAN_HEALTH_URL override", () => {
    expect(
      resolveHealthUrl({
        MERIDIAN_APP_URL: "https://onsite.local/",
        MERIDIAN_HEALTH_URL: "https://health.local/status",
      }),
    ).toBe("https://health.local/status");
  });

  it("rejects a non-http(s) health override", () => {
    expect(() => resolveHealthUrl({ MERIDIAN_HEALTH_URL: "ftp://health.local" })).toThrow(
      /must use http or https/,
    );
  });
});
