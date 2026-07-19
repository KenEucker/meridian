import { afterEach, describe, expect, it } from "vitest";

import { configureMeridianApi, meridianApiConfig } from "@/api/meridianApi";

afterEach(() => {
  configureMeridianApi(null);
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
});
