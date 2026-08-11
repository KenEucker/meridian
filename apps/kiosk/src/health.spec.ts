import { describe, expect, it } from "vitest";

import {
  PLACEHOLDER_VALUE,
  UNAVAILABLE_VALUE,
  buildHealthPanelModel,
  buildHealthWarnings,
  describeConnectedDevices,
  describeHttpsStatus,
  describeLocalDiscovery,
  describeOfflineReadSet,
  describeSyncStatus,
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

/** A fully populated M19.5 payload from a healthy onsite node. */
const health: ServerHealth = {
  status: "ok",
  environment: "production",
  server_version: "1.2.3",
  config_schema_version: 1,
  timestamp: "2026-06-17T00:00:00+00:00",
  node_id: "0198e6a2-0000-7000-8000-000000000001",
  node_name: "onsite-command-1",
  node_role: "onsite",
  organization_id: null,
  event_id: "0198e6a2-0000-7000-8000-000000000002",
  event_name: "Signal Camp 2026",
  sync: {
    status: "healthy",
    status_label: "Syncing",
    queued: 0,
    undelivered: 0,
    unapplied: 0,
    open_conflicts: 0,
    last_sent_at: "2026-06-17T00:00:00+00:00",
    last_received_at: null,
  },
  offline_read_set: { required: true, servable: true },
  connected_devices: { count: 3, window_minutes: 15 },
};

const node = {
  url: "https://onsite-command.local/",
  source: "environment",
  settingsPath: null,
};

function build(overrides: Partial<Parameters<typeof buildHealthPanelModel>[0]> = {}) {
  return buildHealthPanelModel({
    appUrl: "https://onsite.local/",
    appVersion: "1.2.3",
    clientVersion: "1.2.3",
    health,
    node,
    ...overrides,
  });
}

function byLabel(model: ReturnType<typeof buildHealthPanelModel>) {
  return Object.fromEntries(model.fields.map((field) => [field.label, field]));
}

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

  it("populates every 25.3 field from the payload and the wrapper", () => {
    const model = build();
    const fields = byLabel(model);

    expect(fields["Local node name"]).toMatchObject({
      value: "onsite-command-1",
      source: "server",
    });
    expect(fields["Node role"]).toMatchObject({ value: "onsite", source: "server" });
    expect(fields["Event name"]).toMatchObject({
      value: "Signal Camp 2026",
      source: "server",
    });
    expect(fields["Sync status"]).toMatchObject({
      value: "Syncing normally (last sent 2026-06-17T00:00:00+00:00)",
      source: "server",
    });
    expect(fields["Offline read set status"]).toMatchObject({
      value: "Servable (required in event mode)",
      source: "server",
    });
    expect(fields["Connected devices"]).toMatchObject({
      value: "3 devices seen in the last 15 minutes",
      source: "server",
    });
    expect(fields["Local discovery status"]).toMatchObject({
      value: "mDNS (.local) name in use: onsite-command.local",
      source: "app",
    });
    expect(fields["Server version"]).toMatchObject({ value: "1.2.3", source: "server" });
    expect(fields["Config schema version"]).toMatchObject({ value: "1", source: "server" });
    expect(fields["Client version"]).toMatchObject({ value: "1.2.3", source: "app" });
    expect(fields["Electron wrapper version"]).toMatchObject({ value: "1.2.3", source: "app" });
    expect(model.reachable).toBe(true);
    expect(model.connectionLabel).toContain("Connected to");
    expect(model.warnings).toEqual([]);
  });

  it("states meaningful absences on a node without an event or a name", () => {
    const model = build({
      health: {
        ...health,
        node_name: null,
        node_role: null,
        event_id: null,
        event_name: null,
      },
    });
    const fields = byLabel(model);

    expect(fields["Local node name"]).toMatchObject({
      value: "No node configured",
      source: "server",
    });
    expect(fields["Node role"]).toMatchObject({ value: "No node configured", source: "server" });
    expect(fields["Event name"]).toMatchObject({
      value: "No event locked to this node",
      source: "server",
    });
  });

  it("falls back to the event id when the server knows the id but not the name", () => {
    const model = build({ health: { ...health, event_name: null } });

    expect(byLabel(model)["Event name"]).toMatchObject({
      value: "0198e6a2-0000-7000-8000-000000000002",
      source: "server",
    });
  });

  it("derives certificate/HTTPS status from the app URL scheme", () => {
    const httpsModel = build();
    const httpModel = build({ appUrl: "http://localhost:8000/" });

    expect(byLabel(httpsModel)["Certificate / HTTPS status"].value).toBe("HTTPS");
    expect(byLabel(httpModel)["Certificate / HTTPS status"].value).toBe(
      "HTTP (development only)",
    );
  });

  it("shows a placeholder when an older server omits a panel fact", () => {
    const model = build({
      health: { ...health, sync: null, offline_read_set: null, connected_devices: null },
    });
    const fields = byLabel(model);

    for (const label of ["Sync status", "Offline read set status", "Connected devices"]) {
      expect(fields[label]).toMatchObject({ value: PLACEHOLDER_VALUE, source: "placeholder" });
    }
  });

  it("marks server-sourced fields unavailable when the server is unreachable", () => {
    const model = build({ appUrl: "http://localhost:8000/", health: null });
    const fields = byLabel(model);

    expect(model.reachable).toBe(false);
    expect(model.connectionLabel).toContain("unreachable");
    expect(model.warnings).toEqual([]);
    for (const label of [
      "Local node name",
      "Node role",
      "Event name",
      "Sync status",
      "Offline read set status",
      "Connected devices",
      "Server version",
      "Config schema version",
    ]) {
      expect(fields[label].value).toBe(UNAVAILABLE_VALUE);
    }
    // App-known fields are still available offline.
    expect(fields["Client version"].value).toBe("1.2.3");
    expect(fields["Electron wrapper version"].value).toBe("1.2.3");
    expect(fields["Certificate / HTTPS status"].value).toBe("HTTP (development only)");
    expect(fields["Local discovery status"].value).toBe(
      "mDNS (.local) name in use: onsite-command.local",
    );
  });

  it("warns when the server version does not match the expected app version", () => {
    const model = build({ clientVersion: "1.2.4", appVersion: "1.2.4" });

    expect(model.warnings).toEqual([
      "Server version 1.2.3 does not match the expected app version 1.2.4.",
    ]);
  });

  it("warns on sync attention, open conflicts, and an unservable read set", () => {
    const model = build({
      health: {
        ...health,
        sync: { ...(health.sync ?? {}), status: "attention", undelivered: 2, open_conflicts: 1 },
        offline_read_set: { required: true, servable: false },
      },
    });

    expect(model.warnings).toHaveLength(3);
    expect(model.warnings[0]).toContain("Node sync needs attention");
    expect(model.warnings[1]).toContain("1 open sync conflict");
    expect(model.warnings[2]).toContain("offline read set is not servable");
    expect(byLabel(model)["Sync status"].value).toBe(
      "Needs attention: 2 undelivered, 0 unapplied, 1 open conflict",
    );
  });
});

describe("describeSyncStatus", () => {
  it("reports queued operations as expected offline operation", () => {
    expect(describeSyncStatus({ status: "queued", queued: 5 })).toBe(
      "5 operations queued for the peer (expected while offline)",
    );
  });

  it("treats open conflicts as needing attention even when the status is healthy", () => {
    expect(describeSyncStatus({ status: "healthy", open_conflicts: 2 })).toBe(
      "Needs attention: 0 undelivered, 0 unapplied, 2 open conflicts",
    );
  });

  it("reports idle and unconfigured states plainly", () => {
    expect(describeSyncStatus({ status: "idle" })).toBe("Nothing synced yet");
    expect(describeSyncStatus({ status: "not_configured" })).toBe("No node configured");
  });
});

describe("describeOfflineReadSet", () => {
  it("says event mode fails closed when a required read set is not servable", () => {
    expect(describeOfflineReadSet({ required: true, servable: false })).toBe(
      "Not servable — event mode fails closed on this",
    );
    expect(describeOfflineReadSet({ required: false, servable: false })).toBe("Not servable");
    expect(describeOfflineReadSet({ required: false, servable: true })).toBe("Servable");
  });
});

describe("describeConnectedDevices", () => {
  it("includes the window and singularizes one device", () => {
    expect(describeConnectedDevices({ count: 1, window_minutes: 15 })).toBe(
      "1 device seen in the last 15 minutes",
    );
    expect(describeConnectedDevices({ count: 0 })).toBe("0 devices");
  });
});

describe("describeLocalDiscovery", () => {
  it("recognizes mDNS, loopback, direct IP, and DNS node URLs", () => {
    expect(describeLocalDiscovery("https://onsite-command.local/")).toBe(
      "mDNS (.local) name in use: onsite-command.local",
    );
    expect(describeLocalDiscovery("http://localhost:8000/")).toBe(
      "Local machine (no discovery needed)",
    );
    expect(describeLocalDiscovery("https://192.168.4.10/")).toBe(
      "Direct IP address (no local discovery): 192.168.4.10",
    );
    expect(describeLocalDiscovery("https://meridian.example.org/")).toBe(
      "DNS name (not using .local discovery): meridian.example.org",
    );
    expect(describeLocalDiscovery("not a url")).toBe("Unknown (node URL is not a valid URL)");
  });
});

describe("buildHealthWarnings", () => {
  it("returns no warnings for an unreachable server", () => {
    expect(buildHealthWarnings({ clientVersion: "1.2.3", health: null })).toEqual([]);
  });

  it("does not warn on a version mismatch when the wrapper version is unknown", () => {
    expect(buildHealthWarnings({ clientVersion: "unknown", health })).toEqual([]);
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
    const model = build({ generatedAt: "2026-06-17T12:00:00.000Z" });

    const html = renderHealthPanelHtml(model);
    for (const label of SPEC_25_3_FIELDS) {
      expect(html).toContain(label);
    }
    expect(html).toContain("1.2.3");
    expect(html).toContain("Signal Camp 2026");
    expect(html).toContain("Meridian On-site Health");
    expect(html).toContain("2026-06-17T12:00:00.000Z");
    expect(html).not.toContain('class="warnings"');
  });

  it("renders warnings prominently and escaped", () => {
    const model = build({ clientVersion: "1.2.<4>", appVersion: "1.2.4" });

    const html = renderHealthPanelHtml(model);
    expect(html).toContain('class="warnings"');
    expect(html).toContain("does not match the expected app version 1.2.&lt;4&gt;");
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
    expect(result).toMatchObject({ server_version: "1.2.3", node_name: "onsite-command-1" });
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
