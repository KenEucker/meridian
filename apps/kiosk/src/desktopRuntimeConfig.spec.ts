import { describe, expect, it } from "vitest";

import {
  buildDesktopRuntimeConfig,
  desktopAppVersionArgument,
  DESKTOP_APP_VERSION_ARGUMENT_PREFIX,
} from "./desktopRuntimeConfig";

describe("desktop runtime config", () => {
  it("carries the wrapper version the main process resolved", () => {
    const config = buildDesktopRuntimeConfig({
      argv: ["electron", ".", desktopAppVersionArgument("0.0.133")],
    });

    expect(config).toEqual({ desktopAppVersion: "0.0.133" });
  });

  it("carries the workstation identity and the version together", () => {
    const config = buildDesktopRuntimeConfig({
      env: { MERIDIAN_SHARED_WORKSTATION_ID: " desk-01 " },
      argv: [desktopAppVersionArgument("0.0.133")],
    });

    expect(config).toEqual({
      sharedWorkstationId: "desk-01",
      desktopAppVersion: "0.0.133",
    });
  });

  it("exposes nothing when the wrapper has nothing to say", () => {
    // An unpackaged development run with no workstation configured must look to
    // the client exactly as it did before the wrapper injected anything.
    expect(buildDesktopRuntimeConfig()).toBeNull();
    expect(buildDesktopRuntimeConfig({ env: {}, argv: ["electron", "."] })).toBeNull();
  });

  it("treats a blank version argument as no version", () => {
    const config = buildDesktopRuntimeConfig({
      argv: [`${DESKTOP_APP_VERSION_ARGUMENT_PREFIX}   `],
    });

    expect(config).toBeNull();
  });

  it("keeps the workstation identity when no version was passed", () => {
    const config = buildDesktopRuntimeConfig({
      env: { MERIDIAN_SHARED_WORKSTATION_ID: "desk-01" },
      argv: [],
    });

    expect(config).toEqual({ sharedWorkstationId: "desk-01" });
  });
});
