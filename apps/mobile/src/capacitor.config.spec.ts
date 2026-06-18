import { describe, expect, it } from "vitest";

import capacitorConfig from "../capacitor.config";

describe("capacitor baseline config", () => {
  it("declares a reverse-domain application id", () => {
    expect(capacitorConfig.appId).toMatch(/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/);
  });

  it("names the installable field application", () => {
    expect(capacitorConfig.appName).toBe("Meridian Field");
  });

  it("points the native web directory at the Vite build output", () => {
    expect(capacitorConfig.webDir).toBe("dist");
  });
});
