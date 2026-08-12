import { readFileSync } from "node:fs";
import { resolve } from "node:path";

import { describe, expect, it } from "vitest";

import {
  KIOSK_APP_ID,
  KIOSK_CLIENT_DIST,
  KIOSK_ICON,
  KIOSK_PRODUCT_NAME,
  PACKAGED_CLIENT_RESOURCE_DIR,
  buildDesktopPackagingConfig,
  resolveRootPackagingVersion,
} from "./packagingConfig";

const appDir = resolve(__dirname, "..");

/** A fake readFile serving a root package.json two directories above the app. */
function rootPackageReader(contents: string): typeof readFileSync {
  return ((path: string) => {
    if (resolve(String(path)) === resolve(appDir, "..", "..", "package.json")) {
      return contents;
    }
    throw new Error(`unexpected read: ${String(path)}`);
  }) as typeof readFileSync;
}

describe("resolveRootPackagingVersion", () => {
  it("reads the version from the repository root package.json", () => {
    expect(
      resolveRootPackagingVersion(appDir, rootPackageReader('{"name":"meridian","version":"1.2.3"}')),
    ).toBe("1.2.3");
  });

  it("fails when the root package.json cannot be read", () => {
    const readFile = (() => {
      throw new Error("ENOENT");
    }) as unknown as typeof readFileSync;

    expect(() => resolveRootPackagingVersion(appDir, readFile)).toThrow(
      /Cannot read the root package.json/,
    );
  });

  it("fails when the package above the wrapper is not the Meridian root", () => {
    expect(() =>
      resolveRootPackagingVersion(appDir, rootPackageReader('{"name":"other","version":"1.2.3"}')),
    ).toThrow(/not the Meridian root package/);
  });

  it("fails on a non-numeric version rather than stamping it", () => {
    expect(() =>
      resolveRootPackagingVersion(appDir, rootPackageReader('{"name":"meridian","version":"1.2.3-rc.1"}')),
    ).toThrow(/must be numeric major.minor.patch/);
  });

  it("fails when the root declares no version at all", () => {
    expect(() =>
      resolveRootPackagingVersion(appDir, rootPackageReader('{"name":"meridian"}')),
    ).toThrow(/must be numeric major.minor.patch/);
  });
});

describe("buildDesktopPackagingConfig", () => {
  const config = buildDesktopPackagingConfig(appDir);

  it("declares Windows, macOS, and Linux installer targets in the one config", () => {
    expect(config.win.target).toEqual(["nsis"]);
    expect(config.mac.target).toEqual(["dmg"]);
    expect(config.linux.target).toEqual(["AppImage"]);
  });

  it("resolves the package version from the actual root package.json", () => {
    const rootPackageJson = JSON.parse(
      readFileSync(resolve(appDir, "..", "..", "package.json"), "utf8"),
    ) as { version: string };

    expect(config.extraMetadata.version).toBe(rootPackageJson.version);
    expect(config.extraMetadata.version).toMatch(/^\d+\.\d+\.\d+$/);
  });

  it("leaves the wrapper manifest without a version of its own", () => {
    const wrapperPackageJson = JSON.parse(
      readFileSync(resolve(appDir, "package.json"), "utf8"),
    ) as Record<string, unknown>;

    expect("version" in wrapperPackageJson).toBe(false);
  });

  it("carries the Meridian Kiosk identity and icon", () => {
    expect(config.appId).toBe(KIOSK_APP_ID);
    expect(config.appId).toBe("org.meridian.kiosk");
    expect(config.productName).toBe(KIOSK_PRODUCT_NAME);
    expect(config.productName).toBe("Meridian Kiosk");
    expect(config.win.icon).toBe(KIOSK_ICON);
    expect(config.mac.icon).toBe(KIOSK_ICON);
    expect(config.linux.icon).toBe(KIOSK_ICON);
    expect(KIOSK_ICON).toBe("assets/icon.png");
  });

  it("packages the kiosk client build as the packaged resources", () => {
    expect(config.extraResources).toEqual([
      { from: KIOSK_CLIENT_DIST, to: PACKAGED_CLIENT_RESOURCE_DIR },
    ]);
    expect(KIOSK_CLIENT_DIST.endsWith("client/dist/kiosk")).toBe(true);
  });

  it("packages no other UI mode's build", () => {
    // The Admin build ships in the server image and the Field build in the
    // mobile package (technical spec 26.4); nothing in this configuration may
    // reference either.
    const flattened = JSON.stringify(config);

    expect(flattened).not.toMatch(/dist\/admin/);
    expect(flattened).not.toMatch(/dist\/field/);
    expect(flattened.match(/dist\/kiosk/g)).toHaveLength(1);
  });

  it("states the Alpha 1 unsigned policy explicitly", () => {
    expect(config.mac.identity).toBeNull();
    expect(config.forceCodeSigning).toBe(false);
  });
});
