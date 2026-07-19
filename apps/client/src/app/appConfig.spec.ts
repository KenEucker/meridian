import { afterEach, describe, expect, it } from "vitest";

import {
  appConfigForDeploymentTarget,
  appConfigForUiMode,
  meridianAppConfig,
  resolveMeridianAppConfig,
} from "@/app/appConfig";

afterEach(() => {
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
  window.history.replaceState({}, "", "/");
});

describe("Meridian app config", () => {
  it("maps deployment targets to fixed UI modes and product names", () => {
    expect(appConfigForDeploymentTarget("server")).toEqual({
      deploymentTarget: "server",
      uiMode: "admin",
      productName: "Meridian Admin",
    });
    expect(appConfigForDeploymentTarget("mobile")).toEqual({
      deploymentTarget: "mobile",
      uiMode: "field",
      productName: "Meridian Field",
    });
    expect(appConfigForDeploymentTarget("desktop")).toEqual({
      deploymentTarget: "desktop",
      uiMode: "kiosk",
      productName: "Meridian Kiosk",
    });
  });

  it("maps UI modes back to their fixed deployment targets", () => {
    expect(appConfigForUiMode("admin").deploymentTarget).toBe("server");
    expect(appConfigForUiMode("field").deploymentTarget).toBe("mobile");
    expect(appConfigForUiMode("kiosk").deploymentTarget).toBe("desktop");
  });

  it("uses the server/Admin profile in Vitest by default", () => {
    expect(meridianAppConfig).toEqual(appConfigForUiMode("admin"));
  });

  it("uses an explicit runtime UI mode ahead of the Vite build profile", () => {
    expect(resolveMeridianAppConfig({ uiMode: "kiosk" })).toEqual(
      appConfigForUiMode("kiosk"),
    );
  });

  it("uses the runtime config injected by a host shell", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = {
      deploymentTarget: "server",
      uiMode: "admin",
    };
    window.history.replaceState({}, "", "/?meridianUiMode=kiosk");

    expect(resolveMeridianAppConfig()).toEqual(appConfigForUiMode("admin"));
  });

  it("uses the runtime URL query requested by the desktop shell", () => {
    window.history.replaceState({}, "", "/?meridianUiMode=kiosk");

    expect(resolveMeridianAppConfig()).toEqual(appConfigForUiMode("kiosk"));
  });

  it("can resolve from an explicit runtime deployment target", () => {
    expect(resolveMeridianAppConfig({ deploymentTarget: "mobile" })).toEqual(
      appConfigForUiMode("field"),
    );
  });

  it("prefers runtime UI mode when both runtime hints are present", () => {
    expect(
      resolveMeridianAppConfig({
        deploymentTarget: "server",
        uiMode: "kiosk",
      }),
    ).toEqual(appConfigForUiMode("kiosk"));
  });

  it("ignores invalid runtime hints", () => {
    expect(
      resolveMeridianAppConfig({
        deploymentTarget: "invalid",
        uiMode: "invalid",
      }),
    ).toEqual(appConfigForUiMode("admin"));
  });
});
