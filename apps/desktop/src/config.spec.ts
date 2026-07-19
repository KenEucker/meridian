import { describe, expect, it } from "vitest";

import {
  DEFAULT_CLIENT_DEV_SERVER_URL,
  DEFAULT_SERVER_URL,
  HEALTH_PATH,
  resolveAppUrlOverride,
  resolveAppIconPath,
  resolveClientDevAppUrl,
  resolveClientDevServerUrl,
  resolveClientDistPath,
  resolveClientPort,
  resolveClientVersion,
  resolveHealthUrl,
  resolveServerUrl,
} from "./config";

describe("resolveServerUrl", () => {
  it("returns the local Laravel server default when no override is set", () => {
    expect(resolveServerUrl({})).toBe(DEFAULT_SERVER_URL);
  });

  it("uses MERIDIAN_SERVER_URL when provided", () => {
    expect(resolveServerUrl({ MERIDIAN_SERVER_URL: "  http://10.0.0.5:8000/  " })).toBe(
      "http://10.0.0.5:8000/",
    );
  });

  it("rejects non-http(s) schemes", () => {
    expect(() => resolveServerUrl({ MERIDIAN_SERVER_URL: "file:///etc/passwd" })).toThrow(
      /must use http or https/,
    );
  });

  it("rejects malformed URLs", () => {
    expect(() => resolveServerUrl({ MERIDIAN_SERVER_URL: "not a url" })).toThrow(/not a valid URL/);
  });
});

describe("resolveClientDistPath", () => {
  it("defaults to the fixed Meridian Kiosk dist directory beside the desktop app", () => {
    expect(resolveClientDistPath({}, "/repo/apps/desktop")).toBe(
      "/repo/apps/client/dist/kiosk",
    );
  });

  it("uses MERIDIAN_CLIENT_DIST_DIR when provided", () => {
    expect(resolveClientDistPath({ MERIDIAN_CLIENT_DIST_DIR: "../custom-dist" }, "/repo/apps/desktop")).toBe(
      "/repo/apps/custom-dist",
    );
  });
});

describe("resolveAppIconPath", () => {
  it("defaults to the Meridian desktop icon asset beside the desktop app", () => {
    expect(resolveAppIconPath({}, "/repo/apps/desktop")).toBe(
      "/repo/apps/desktop/assets/icon.png",
    );
  });
});

describe("resolveClientPort", () => {
  it("defaults to an OS-assigned port", () => {
    expect(resolveClientPort({})).toBe(0);
  });

  it("uses MERIDIAN_CLIENT_PORT when provided", () => {
    expect(resolveClientPort({ MERIDIAN_CLIENT_PORT: "47000" })).toBe(47000);
  });

  it("rejects invalid ports", () => {
    expect(() => resolveClientPort({ MERIDIAN_CLIENT_PORT: "70000" })).toThrow(
      /must be an integer/,
    );
  });
});

describe("resolveClientDevServerUrl", () => {
  it("defaults to the shared Vue Vite dev server", () => {
    expect(resolveClientDevServerUrl({})).toBe(DEFAULT_CLIENT_DEV_SERVER_URL);
  });

  it("uses MERIDIAN_CLIENT_DEV_SERVER_URL when provided", () => {
    expect(
      resolveClientDevServerUrl({
        MERIDIAN_CLIENT_DEV_SERVER_URL: "  http://127.0.0.1:5174  ",
      }),
    ).toBe("http://127.0.0.1:5174/");
  });

  it("rejects non-http(s) schemes", () => {
    expect(() =>
      resolveClientDevServerUrl({ MERIDIAN_CLIENT_DEV_SERVER_URL: "file:///tmp/client" }),
    ).toThrow(/must use http or https/);
  });
});

describe("resolveClientDevAppUrl", () => {
  it("adds the Kiosk runtime UI mode to the default Vite dev server URL", () => {
    expect(resolveClientDevAppUrl({})).toBe(
      "http://localhost:5173/?meridianUiMode=kiosk",
    );
  });

  it("preserves existing dev server URL query params", () => {
    expect(
      resolveClientDevAppUrl({
        MERIDIAN_CLIENT_DEV_SERVER_URL: "http://127.0.0.1:5174/?debug=true",
      }),
    ).toBe("http://127.0.0.1:5174/?debug=true&meridianUiMode=kiosk");
  });
});

describe("resolveAppUrlOverride", () => {
  it("returns null when MERIDIAN_APP_URL is unset", () => {
    expect(resolveAppUrlOverride({})).toBeNull();
  });

  it("uses MERIDIAN_APP_URL when provided", () => {
    expect(resolveAppUrlOverride({ MERIDIAN_APP_URL: "  http://localhost:5173/kiosk  " })).toBe(
      "http://localhost:5173/kiosk",
    );
  });

  it("rejects non-http(s) schemes", () => {
    expect(() => resolveAppUrlOverride({ MERIDIAN_APP_URL: "file:///tmp/client" })).toThrow(
      /must use http or https/,
    );
  });
});

describe("resolveClientVersion", () => {
  it("reads the root package version by default", () => {
    const readFile = ((path: Parameters<typeof import("node:fs").readFileSync>[0]) => {
      if (String(path) === "/repo/package.json") {
        return JSON.stringify({ name: "meridian", version: "2.0.0" });
      }

      throw new Error("missing");
    }) as unknown as typeof import("node:fs").readFileSync;

    expect(resolveClientVersion({}, "/repo/apps/desktop", readFile)).toBe("2.0.0");
  });

  it("returns unknown when the root package version cannot be read", () => {
    const readFile = (() => {
      throw new Error("missing");
    }) as unknown as typeof import("node:fs").readFileSync;

    expect(resolveClientVersion({}, "/repo/apps/desktop", readFile)).toBe("unknown");
  });

  it("returns unknown when the root package version is not numeric", () => {
    const readFile = ((path: Parameters<typeof import("node:fs").readFileSync>[0]) => {
      if (String(path) === "/repo/package.json") {
        return JSON.stringify({ name: "meridian", version: "2.0.0-alpha" });
      }

      throw new Error("missing");
    }) as unknown as typeof import("node:fs").readFileSync;

    expect(resolveClientVersion({}, "/repo/apps/desktop", readFile)).toBe("unknown");
  });
});

describe("resolveHealthUrl", () => {
  it("derives the health endpoint from the default server URL", () => {
    expect(resolveHealthUrl({})).toBe(`http://localhost:8000${HEALTH_PATH}`);
  });

  it("derives the health endpoint from a custom server URL", () => {
    expect(resolveHealthUrl({ MERIDIAN_SERVER_URL: "https://onsite.local:9000/" })).toBe(
      "https://onsite.local:9000/api/health",
    );
  });

  it("prefers an explicit MERIDIAN_HEALTH_URL override", () => {
    expect(
      resolveHealthUrl({
        MERIDIAN_SERVER_URL: "https://onsite.local/",
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
