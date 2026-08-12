import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

import {
  DEFAULT_CLIENT_DEV_SERVER_URL,
  DEFAULT_SERVER_URL,
  HEALTH_PATH,
  resolveAppUrlOverride,
  resolveAppIconPath,
  resolveClientDevAppUrl,
  resolveClientDevServerUrl,
  resolveClientPort,
  resolveClientVersion,
  resolveHealthUrl,
  resolvePackagedClientDistPath,
  resolveServerUrl,
  resolveSharedWorkstationId,
  resolveWindowedMode,
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

describe("resolvePackagedClientDistPath", () => {
  it("defaults to the kiosk client build inside the installed resources directory", () => {
    // Compared through `resolve` rather than against a literal, because these
    // helpers return native paths and the separator differs by platform.
    expect(resolvePackagedClientDistPath({}, "/install/resources")).toBe(
      resolve("/install/resources/client/kiosk"),
    );
  });

  it("uses MERIDIAN_CLIENT_DIST_DIR when provided", () => {
    expect(
      resolvePackagedClientDistPath({ MERIDIAN_CLIENT_DIST_DIR: "/known-good/kiosk" }, "/install/resources"),
    ).toBe(resolve("/known-good/kiosk"));
  });
});

describe("resolveAppIconPath", () => {
  it("defaults to the Meridian desktop icon asset beside the desktop app", () => {
    expect(resolveAppIconPath({}, "/repo/apps/kiosk")).toBe(
      resolve("/repo/apps/kiosk/assets/icon.png"),
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
      if (String(path) === resolve("/repo/package.json")) {
        return JSON.stringify({ name: "meridian", version: "2.0.0" });
      }

      throw new Error("missing");
    }) as unknown as typeof import("node:fs").readFileSync;

    expect(resolveClientVersion({}, "/repo/apps/kiosk", readFile)).toBe("2.0.0");
  });

  it("returns unknown when the root package version cannot be read", () => {
    const readFile = (() => {
      throw new Error("missing");
    }) as unknown as typeof import("node:fs").readFileSync;

    expect(resolveClientVersion({}, "/repo/apps/kiosk", readFile)).toBe("unknown");
  });

  it("returns unknown when the root package version is not numeric", () => {
    const readFile = ((path: Parameters<typeof import("node:fs").readFileSync>[0]) => {
      if (String(path) === resolve("/repo/package.json")) {
        return JSON.stringify({ name: "meridian", version: "2.0.0-alpha" });
      }

      throw new Error("missing");
    }) as unknown as typeof import("node:fs").readFileSync;

    expect(resolveClientVersion({}, "/repo/apps/kiosk", readFile)).toBe("unknown");
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

describe("resolveSharedWorkstationId", () => {
  it("is null on a desktop install that is not a shared workstation", () => {
    // The ordinary case, and the one that has to stay inert: the Kiosk falls
    // back to whatever a technician configured on the machine, and shows setup
    // when there is nothing to fall back to (UI-019, UI-020).
    expect(resolveSharedWorkstationId({})).toBeNull();
    expect(resolveSharedWorkstationId({ MERIDIAN_SHARED_WORKSTATION_ID: "   " })).toBeNull();
  });

  it("carries the workstation the wrapper was told this machine is", () => {
    expect(
      resolveSharedWorkstationId({ MERIDIAN_SHARED_WORKSTATION_ID: " onsite-command-1 " }),
    ).toBe("onsite-command-1");
  });
});

describe("resolveWindowedMode", () => {
  it("is fullscreen kiosk unless something says otherwise", () => {
    // An on-site workstation is fullscreen and locked down. That is what the
    // wrapper is for, so it is what an unset environment gets.
    expect(resolveWindowedMode({})).toBe(false);
    expect(resolveWindowedMode({ MERIDIAN_DESKTOP_WINDOWED: "false" })).toBe(false);
    expect(resolveWindowedMode({ MERIDIAN_DESKTOP_WINDOWED: "no" })).toBe(false);
  });

  it("opens windowed when a developer asks for it", () => {
    for (const value of ["1", "true", "TRUE", "yes"]) {
      expect(resolveWindowedMode({ MERIDIAN_DESKTOP_WINDOWED: value })).toBe(true);
    }
  });
});
