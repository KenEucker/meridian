import { describe, expect, it } from "vitest";

import capacitorConfig from "../capacitor.config";

describe("capacitor baseline config", () => {
  it("declares a reverse-domain application id", () => {
    expect(capacitorConfig.appId).toMatch(/^[a-z][a-z0-9]*(\.[a-z][a-z0-9]*)+$/);
  });

  it("names the installable mobile application", () => {
    expect(capacitorConfig.appName).toBe("Meridian Field");
  });

  it("packages the fixed Meridian Field build output", () => {
    expect(capacitorConfig.webDir).toBe("../client/dist/field");
  });

  /*
   * The app is served from https://localhost and an on-site node answers plain
   * HTTP at its home.arpa name, so every request to one is mixed content the
   * WebView drops before it reaches the network — an unreachable node with
   * nothing in the node's own logs. What may leave the device in the clear is
   * still decided by the network security configuration, which permits
   * home.arpa and nothing else (technical spec 8.4, 8.5).
   */
  it("lets the WebView reach a plain-HTTP on-site node", () => {
    expect(capacitorConfig.android?.allowMixedContent).toBe(true);
  });
});
