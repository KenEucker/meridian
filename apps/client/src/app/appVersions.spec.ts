import { afterEach, describe, expect, it } from "vitest";

import { appConfigForDeploymentTarget } from "@/app/appConfig";
import {
  describeVersions,
  readDesktopAppVersion,
  readNativePlatform,
  VERSION_UNAVAILABLE,
  type VersionSources,
} from "@/app/appVersions";

function sources(overrides: Partial<VersionSources> = {}): VersionSources {
  return {
    config: appConfigForDeploymentTarget("server"),
    clientVersion: "0.0.133",
    desktopAppVersion: null,
    nativePlatform: null,
    serverVersion: "0.0.133",
    configSchemaVersion: 1,
    ...overrides,
  };
}

function valueOf(rows: ReturnType<typeof describeVersions>, label: string) {
  return rows.find((row) => row.label === label)?.value;
}

function labels(rows: ReturnType<typeof describeVersions>) {
  return rows.map((row) => row.label);
}

afterEach(() => {
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
  delete window.Capacitor;
});

describe("version metadata", () => {
  it("reports the artifact, its bundle version, and the node's answer", () => {
    const rows = describeVersions(sources());

    expect(valueOf(rows, "App")).toBe("Meridian Admin");
    expect(valueOf(rows, "Client bundle version")).toBe("0.0.133");
    expect(valueOf(rows, "UI mode")).toBe("Admin");
    expect(valueOf(rows, "Deployment target")).toBe("server");
    expect(valueOf(rows, "Server version")).toBe("0.0.133");
    expect(valueOf(rows, "Config schema version")).toBe("1");
  });

  it("names the server rows unavailable when the node did not answer", () => {
    const rows = describeVersions(
      sources({ serverVersion: null, configSchemaVersion: null }),
    );

    expect(valueOf(rows, "Server version")).toBe(VERSION_UNAVAILABLE);
    expect(valueOf(rows, "Config schema version")).toBe(VERSION_UNAVAILABLE);
    // The bundle is still known with the node down: it is this device's own fact.
    expect(valueOf(rows, "Client bundle version")).toBe("0.0.133");
  });

  it("reports the mobile app version with the platform it is installed on", () => {
    const rows = describeVersions(
      sources({
        config: appConfigForDeploymentTarget("mobile"),
        nativePlatform: "android",
      }),
    );

    expect(valueOf(rows, "App")).toBe("Meridian Field");
    expect(valueOf(rows, "Mobile app version")).toBe("0.0.133 (android)");
  });

  it("reports the desktop app version the wrapper stated, not the bundle's", () => {
    // The two are separate artifacts. A wrapper serving an older bundle is
    // exactly the state this row exists to make visible.
    const rows = describeVersions(
      sources({
        config: appConfigForDeploymentTarget("desktop"),
        clientVersion: "0.0.130",
        desktopAppVersion: "0.0.133",
      }),
    );

    expect(valueOf(rows, "Desktop app version")).toBe("0.0.133");
    expect(valueOf(rows, "Client bundle version")).toBe("0.0.130");
  });

  it("omits the wrapper rows rather than calling them unavailable", () => {
    // Absent means "not running inside that app", which is a different
    // statement from "the value could not be read".
    const rows = describeVersions(sources());

    expect(labels(rows)).not.toContain("Mobile app version");
    expect(labels(rows)).not.toContain("Desktop app version");
  });
});

describe("wrapper version readers", () => {
  it("reads the desktop version the Electron preload exposed", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = { desktopAppVersion: "0.0.133" };

    expect(readDesktopAppVersion()).toBe("0.0.133");
  });

  it("treats a missing or blank desktop version as no wrapper", () => {
    expect(readDesktopAppVersion()).toBeNull();

    window.__MERIDIAN_RUNTIME_CONFIG__ = { desktopAppVersion: "  " };

    expect(readDesktopAppVersion()).toBeNull();
  });

  it("reads the Capacitor platform when the installed app is running it", () => {
    window.Capacitor = { getPlatform: () => "ios" };

    expect(readNativePlatform()).toBe("ios");
  });

  it("does not treat a browser as the installed mobile app", () => {
    expect(readNativePlatform()).toBeNull();

    // Capacitor's own answer for a browser. The Field build opened in a phone
    // browser has no mobile app version to report.
    window.Capacitor = { getPlatform: () => "web" };

    expect(readNativePlatform()).toBeNull();
  });
});
