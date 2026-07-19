import { describe, expect, it } from "vitest";

import {
  appConfigForDeploymentTarget,
  appConfigForUiMode,
  meridianAppConfig,
} from "@/app/appConfig";

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
});
