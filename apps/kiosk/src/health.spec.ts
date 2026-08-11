import { describe, expect, it } from "vitest";

import {
  PLACEHOLDER_VALUE,
  UNAVAILABLE_VALUE,
  buildHealthPanelModel,
  describeHttpsStatus,
  escapeHtml,
  fetchServerHealth,
  renderHealthPanelHtml,
  type ServerHealth,
} from "./health";

const SPEC_25_3_FIELDS = [
  "Local node name",
  "Node role",
  "Event name",
  "Sync status",
  "Offline read set status",
  "Connected devices",
  "Local discovery status",
  "Certificate / HTTPS status",
  "Server version",
  // The node's other build fact, beside the version it reports (M19.1;
  // technical spec 26.3).
  "Config schema version",
  "Client version",
  "Electron wrapper version",
];

const health: ServerHealth = {
  status: "ok",
  environment: "onsite",
  server_version: "1.2.3",
  config_schema_version: 1,
  timestamp: "2026-06-17T00:00:00+00:00",
};

describe("buildHealthPanelModel", () => {
  it("includes all technical spec 25.3 fields in order", () => {
    const model = buildHealthPanelModel({
      appUrl: "https://onsite.local/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health,
    });

    expect(model.fields.map((field) => field.label)).toEqual(SPEC_25_3_FIELDS);
  });

  it("populates server-sourced fields from the health payload", () => {
    const model = buildHealthPanelModel({
      appUrl: "https://onsite.local/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health,
    });

    const byLabel = Object.fromEntries(model.fields.map((field) => [field.label, field]));
    expect(byLabel["Server version"]).toMatchObject({ value: "1.2.3", source: "server" });
    expect(byLabel["Config schema version"]).toMatchObject({ value: "1", source: "server" });
    expect(byLabel["Node role"]).toMatchObject({ value: "onsite", source: "server" });
    expect(byLabel["Client version"]).toMatchObject({ value: "0.2.0", source: "app" });
    expect(byLabel["Electron wrapper version"]).toMatchObject({ value: "0.1.0", source: "app" });
    expect(model.reachable).toBe(true);
    expect(model.connectionLabel).toContain("Connected to");
  });

  it("derives certificate/HTTPS status from the app URL scheme", () => {
    const httpsModel = buildHealthPanelModel({
      appUrl: "https://onsite.local/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health,
    });
    const httpModel = buildHealthPanelModel({
      appUrl: "http://localhost:8000/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health,
    });

    const https = httpsModel.fields.find((field) => field.label === "Certificate / HTTPS status");
    const http = httpModel.fields.find((field) => field.label === "Certificate / HTTPS status");
    expect(https?.value).toBe("HTTPS");
    expect(http?.value).toBe("HTTP (development only)");
  });

  it("shows placeholders for fields owned by later milestones", () => {
    const model = buildHealthPanelModel({
      appUrl: "https://onsite.local/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health,
    });

    const byLabel = Object.fromEntries(model.fields.map((field) => [field.label, field]));
    for (const label of [
      "Local node name",
      "Event name",
      "Sync status",
      "Offline read set status",
      "Connected devices",
      "Local discovery status",
    ]) {
      expect(byLabel[label]).toMatchObject({ value: PLACEHOLDER_VALUE, source: "placeholder" });
    }
  });

  it("marks server-sourced fields unavailable when the server is unreachable", () => {
    const model = buildHealthPanelModel({
      appUrl: "http://localhost:8000/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health: null,
    });

    const byLabel = Object.fromEntries(model.fields.map((field) => [field.label, field]));
    expect(model.reachable).toBe(false);
    expect(model.connectionLabel).toContain("unreachable");
    expect(byLabel["Server version"].value).toBe(UNAVAILABLE_VALUE);
    expect(byLabel["Config schema version"].value).toBe(UNAVAILABLE_VALUE);
    expect(byLabel["Node role"].value).toBe(UNAVAILABLE_VALUE);
    // App-known fields are still available offline.
    expect(byLabel["Client version"].value).toBe("0.2.0");
    expect(byLabel["Electron wrapper version"].value).toBe("0.1.0");
    expect(byLabel["Certificate / HTTPS status"].value).toBe("HTTP (development only)");
  });

  it("falls back to a placeholder when the server omits a value", () => {
    const model = buildHealthPanelModel({
      appUrl: "https://onsite.local/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health: { ...health, server_version: null },
    });

    const serverVersion = model.fields.find((field) => field.label === "Server version");
    expect(serverVersion).toMatchObject({ value: PLACEHOLDER_VALUE, source: "placeholder" });
  });
});

describe("describeHttpsStatus", () => {
  it("returns unavailable for an unparseable URL", () => {
    expect(describeHttpsStatus("not a url")).toBe(UNAVAILABLE_VALUE);
  });
});

describe("escapeHtml", () => {
  it("escapes HTML-significant characters", () => {
    expect(escapeHtml(`<script>"&'`)).toBe("&lt;script&gt;&quot;&amp;&#39;");
  });
});

describe("renderHealthPanelHtml", () => {
  it("renders every field label and value", () => {
    const model = buildHealthPanelModel({
      appUrl: "https://onsite.local/",
      appVersion: "0.1.0",
      clientVersion: "0.2.0",
      health,
      generatedAt: "2026-06-17T12:00:00.000Z",
    });

    const html = renderHealthPanelHtml(model);
    for (const label of SPEC_25_3_FIELDS) {
      expect(html).toContain(label);
    }
    expect(html).toContain("1.2.3");
    expect(html).toContain("0.2.0");
    expect(html).toContain("Meridian On-site Health");
    expect(html).toContain("2026-06-17T12:00:00.000Z");
  });
});

describe("fetchServerHealth", () => {
  it("returns the parsed payload on a 2xx response", async () => {
    const fetchImpl = (async () =>
      new Response(JSON.stringify(health), {
        status: 200,
        headers: { "content-type": "application/json" },
      })) as unknown as typeof fetch;

    const result = await fetchServerHealth("http://localhost:8000/api/health", { fetchImpl });
    expect(result).toMatchObject({ server_version: "1.2.3", environment: "onsite" });
  });

  it("returns null on a non-2xx response", async () => {
    const fetchImpl = (async () => new Response("nope", { status: 503 })) as unknown as typeof fetch;
    expect(await fetchServerHealth("http://localhost:8000/api/health", { fetchImpl })).toBeNull();
  });

  it("returns null when the request throws", async () => {
    const fetchImpl = (async () => {
      throw new Error("connection refused");
    }) as unknown as typeof fetch;
    expect(await fetchServerHealth("http://localhost:8000/api/health", { fetchImpl })).toBeNull();
  });

  it("returns null when the body is not an object", async () => {
    const fetchImpl = (async () =>
      new Response("42", { status: 200 })) as unknown as typeof fetch;
    expect(await fetchServerHealth("http://localhost:8000/api/health", { fetchImpl })).toBeNull();
  });
});
