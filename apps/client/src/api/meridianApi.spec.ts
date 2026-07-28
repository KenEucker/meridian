import { afterEach, describe, expect, it } from "vitest";

import { configureMeridianApi, meridianApiConfig } from "@/api/meridianApi";
import { clearNodeUrl, setNodeUrl } from "@/app/nodeConnection";

afterEach(() => {
  configureMeridianApi(null);
  clearNodeUrl();
  window.localStorage.clear();
  delete window.__MERIDIAN_RUNTIME_CONFIG__;
});

describe("meridianApiConfig", () => {
  it("uses the server-injected API base URL when present", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = {
      apiBaseUrl: "http://localhost:8000/",
    };

    expect(meridianApiConfig().baseUrl).toBe("http://localhost:8000");
  });

  it("keeps explicit test overrides ahead of runtime config", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = {
      apiBaseUrl: "http://localhost:8000/",
    };
    configureMeridianApi({
      baseUrl: "http://127.0.0.1:9000",
      bearerToken: null,
    });

    expect(meridianApiConfig().baseUrl).toBe("http://127.0.0.1:9000");
  });

  it("uses the node this device has been pointed at over the serving node", () => {
    window.__MERIDIAN_RUNTIME_CONFIG__ = {
      apiBaseUrl: "http://localhost:8000/",
    };
    setNodeUrl("https://onsite.example.org");

    expect(meridianApiConfig().baseUrl).toBe("https://onsite.example.org");
  });
});
