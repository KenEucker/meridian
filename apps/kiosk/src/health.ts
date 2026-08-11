/**
 * Health panel model and rendering for the Meridian Electron wrapper.
 *
 * Technical spec 25.3 lists the fields the on-site health panel shows. M19.5
 * finalizes the panel: every field is populated from the server health payload
 * or from what the wrapper itself knows, and the panel warns when node sync
 * needs attention, when severe sync conflicts are open, when the offline read
 * set an event node fails closed on is not servable, and when the server
 * version does not match the expected app version (technical spec 25.3, 26.3).
 *
 * Following the kiosk guide section 12, the panel only reports status and
 * never blocks the wrapped Meridian UI; an unreachable server simply renders
 * server-sourced fields as "Unavailable".
 *
 * These functions are pure so they can be unit tested without an Electron
 * runtime.
 */

/** Sync summary block of the server health payload (technical spec 25.3). */
export interface ServerSyncHealth {
  status?: string | null;
  status_label?: string | null;
  queued?: number | null;
  undelivered?: number | null;
  unapplied?: number | null;
  open_conflicts?: number | null;
  last_sent_at?: string | null;
  last_received_at?: string | null;
}

/** Shape of the server `GET /api/health` payload (technical spec 25.3, 26.3). */
export interface ServerHealth {
  status?: string | null;
  environment?: string | null;
  server_version?: string | null;
  config_schema_version?: number | null;
  timestamp?: string | null;
  /**
   * Node identity, used to decide whether this install is locked to an event
   * and whose organization it serves (BRAND-003A). Null on an install whose
   * node is not configured, or whose database is unreachable — the endpoint
   * stays a liveness probe first and degrades these rather than failing.
   */
  node_id?: string | null;
  node_name?: string | null;
  node_role?: string | null;
  organization_id?: string | null;
  event_id?: string | null;
  event_name?: string | null;
  /** Null when the server could not read the tables behind each fact. */
  sync?: ServerSyncHealth | null;
  offline_read_set?: { required?: boolean | null; servable?: boolean | null } | null;
  connected_devices?: { count?: number | null; window_minutes?: number | null } | null;
}

export type HealthFieldSource = "server" | "app" | "placeholder";

export interface HealthPanelField {
  label: string;
  value: string;
  source: HealthFieldSource;
}

export interface HealthPanelModel {
  /** Whether the server health endpoint was reachable and parseable. */
  reachable: boolean;
  /** Human-readable connection summary for the panel header. */
  connectionLabel: string;
  /** The local Meridian web UI URL the wrapper is showing. */
  appUrl: string;
  /** Health panel fields in technical spec 25.3 order. */
  fields: HealthPanelField[];
  /**
   * Conditions that ask for a human: sync failures, severe conflicts, an
   * unservable offline read set on an event node, and a server/app version
   * mismatch (technical spec 25.3). Empty when nothing needs attention.
   */
  warnings: string[];
  /** ISO-8601 timestamp the panel model was built. */
  generatedAt: string;
}

/** Explain where the wrapper's node setting came from, in the panel. */
function describeNodeUrlSource(source: string, settingsPath: string | null): string {
  if (source === "environment") {
    return "MERIDIAN_SERVER_URL";
  }

  if (source === "stored") {
    return settingsPath === null ? "Set on this device" : `Set on this device (${settingsPath})`;
  }

  return settingsPath === null
    ? "Default. No node has been set."
    : `Default. No node has been set (${settingsPath}).`;
}

/**
 * Value shown for a field the reachable server did not report. After M19.5
 * every field has an owner, so a missing value means an older server build or
 * a degraded probe, not an unbuilt milestone.
 */
export const PLACEHOLDER_VALUE = "Not reported by this server";

/** Value shown for server-sourced fields when the server is unreachable. */
export const UNAVAILABLE_VALUE = "Unavailable (server unreachable)";

/**
 * Build the health panel model from the local Meridian web UI URL, the
 * wrapper/client versions, and the latest server health payload (or `null`
 * when the server could not be reached).
 */
export function buildHealthPanelModel(input: {
  appUrl: string;
  appVersion: string;
  clientVersion: string;
  health: ServerHealth | null;
  /**
   * The node this wrapper reads health from, and where that setting came from.
   * Optional so existing callers keep working; omitted, the node fields are
   * left off rather than guessed at.
   */
  node?: { url: string; source: string; settingsPath: string | null };
  generatedAt?: string;
}): HealthPanelModel {
  const { appUrl, appVersion, clientVersion, health, node } = input;
  const reachable = health !== null;
  const generatedAt = input.generatedAt ?? new Date().toISOString();

  const serverValue = (
    value: string | number | null | undefined,
    absent?: string,
  ): { value: string; source: HealthFieldSource } => {
    if (!reachable) {
      return { value: UNAVAILABLE_VALUE, source: "server" };
    }
    const text = value === null || value === undefined || value === "" ? null : String(value);
    if (text !== null) {
      return { value: text, source: "server" };
    }
    // `absent` is a meaningful null: the server answered and the answer is
    // that there is no such thing (no node configured, no event locked).
    return absent === undefined
      ? { value: PLACEHOLDER_VALUE, source: "placeholder" }
      : { value: absent, source: "server" };
  };

  const fields: HealthPanelField[] = [
    { label: "Local node name", ...serverValue(health?.node_name, "No node configured") },
    { label: "Node role", ...serverValue(health?.node_role, "No node configured") },
    {
      label: "Event name",
      ...serverValue(
        health?.event_name ?? health?.event_id,
        "No event locked to this node",
      ),
    },
    {
      label: "Sync status",
      ...(reachable && health?.sync
        ? { value: describeSyncStatus(health.sync), source: "server" as HealthFieldSource }
        : serverValue(null)),
    },
    {
      label: "Offline read set status",
      ...(reachable && health?.offline_read_set
        ? {
            value: describeOfflineReadSet(health.offline_read_set),
            source: "server" as HealthFieldSource,
          }
        : serverValue(null)),
    },
    {
      label: "Connected devices",
      ...(reachable && health?.connected_devices
        ? {
            value: describeConnectedDevices(health.connected_devices),
            source: "server" as HealthFieldSource,
          }
        : serverValue(null)),
    },
    // Discovery is described from how this wrapper reaches its node, because
    // that is the discovery that matters at this machine: the exact discovery
    // implementation is a spec open question, and the wrapper reporting the
    // shape of its own node URL is the honest status it can state.
    {
      label: "Local discovery status",
      value: node === undefined ? "Unknown (no node URL resolved)" : describeLocalDiscovery(node.url),
      source: "app",
    },
    { label: "Certificate / HTTPS status", value: describeHttpsStatus(appUrl), source: "app" },
    // Which node this install belongs to, and who decided. An on-site laptop
    // pointed at the wrong node looks identical to one that cannot reach its
    // own, and this is the field that tells those apart.
    ...(node === undefined
      ? []
      : [
          { label: "Node URL", value: node.url, source: "app" as HealthFieldSource },
          {
            label: "Node URL source",
            value: describeNodeUrlSource(node.source, node.settingsPath),
            source: "app" as HealthFieldSource,
          },
        ]),
    { label: "Server version", ...serverValue(health?.server_version) },
    // Beside the server version because it is the node's other build fact
    // (technical spec 26.3). A schema mismatch does not block startup in Alpha
    // 1, so the panel is where a technician finds out one exists at all.
    { label: "Config schema version", ...serverValue(health?.config_schema_version) },
    { label: "Client version", value: clientVersion, source: "app" },
    { label: "Electron wrapper version", value: appVersion, source: "app" },
  ];

  return {
    reachable,
    connectionLabel: reachable
      ? `Connected to ${appUrl}`
      : `Server unreachable at ${appUrl}`,
    appUrl,
    fields,
    warnings: buildHealthWarnings({ clientVersion, health }),
    generatedAt,
  };
}

/** One line of sync state for the panel (technical spec 25.3; M12.10). */
export function describeSyncStatus(sync: ServerSyncHealth): string {
  const queued = sync.queued ?? 0;
  const undelivered = sync.undelivered ?? 0;
  const unapplied = sync.unapplied ?? 0;
  const conflicts = sync.open_conflicts ?? 0;

  // Failures and open conflicts ask for a human even when the server's own
  // status has not rolled up the conflict count.
  if (sync.status === "attention" || conflicts > 0) {
    return (
      `Needs attention: ${undelivered} undelivered, ${unapplied} unapplied, ` +
      `${conflicts} open conflict${conflicts === 1 ? "" : "s"}`
    );
  }

  if (sync.status === "queued") {
    return `${queued} operation${queued === 1 ? "" : "s"} queued for the peer (expected while offline)`;
  }

  if (sync.status === "healthy") {
    return sync.last_sent_at === null || sync.last_sent_at === undefined
      ? "Syncing normally"
      : `Syncing normally (last sent ${sync.last_sent_at})`;
  }

  if (sync.status === "idle") {
    return "Nothing synced yet";
  }

  if (sync.status === "not_configured") {
    return "No node configured";
  }

  return sync.status_label ?? sync.status ?? PLACEHOLDER_VALUE;
}

/** Offline read set servability, the check event mode fails closed on. */
export function describeOfflineReadSet(readSet: {
  required?: boolean | null;
  servable?: boolean | null;
}): string {
  if (readSet.servable === true) {
    return readSet.required === true ? "Servable (required in event mode)" : "Servable";
  }

  return readSet.required === true
    ? "Not servable — event mode fails closed on this"
    : "Not servable";
}

/** Count of devices recently active against this node. */
export function describeConnectedDevices(devices: {
  count?: number | null;
  window_minutes?: number | null;
}): string {
  const count = devices.count ?? 0;
  const noun = `device${count === 1 ? "" : "s"}`;

  return devices.window_minutes === null || devices.window_minutes === undefined
    ? `${count} ${noun}`
    : `${count} ${noun} seen in the last ${devices.window_minutes} minutes`;
}

/**
 * Describe local discovery from the shape of the node URL this wrapper
 * resolved (technical spec 8.4, 8.5): a `.local` name means mDNS discovery is
 * in use; anything else states plainly that it is not.
 */
export function describeLocalDiscovery(nodeUrl: string): string {
  let host: string;
  try {
    host = new URL(nodeUrl).hostname;
  } catch {
    return "Unknown (node URL is not a valid URL)";
  }

  if (host.toLowerCase().endsWith(".local")) {
    return `mDNS (.local) name in use: ${host}`;
  }

  if (host === "localhost" || host === "127.0.0.1" || host === "::1" || host === "[::1]") {
    return "Local machine (no discovery needed)";
  }

  if (/^\d{1,3}(\.\d{1,3}){3}$/.test(host) || host.startsWith("[")) {
    return `Direct IP address (no local discovery): ${host}`;
  }

  return `DNS name (not using .local discovery): ${host}`;
}

/**
 * The conditions the panel warns on (technical spec 25.3): node sync
 * failures, severe data sync conflicts, an unservable offline read set where
 * event mode requires one, and a server version that does not match the
 * expected app version. File sync failures deliberately do not appear.
 */
export function buildHealthWarnings(input: {
  clientVersion: string;
  health: ServerHealth | null;
}): string[] {
  const { clientVersion, health } = input;

  if (health === null) {
    return [];
  }

  const warnings: string[] = [];
  const sync = health.sync;

  if (sync && sync.status === "attention") {
    warnings.push(
      "Node sync needs attention: failed operations or refused exchanges exist. " +
        "Review node sync on the server console.",
    );
  }

  const conflicts = sync?.open_conflicts ?? 0;
  if (conflicts > 0) {
    warnings.push(
      `${conflicts} open sync conflict${conflicts === 1 ? "" : "s"} await` +
        `${conflicts === 1 ? "s" : ""} resolution in God Mode.`,
    );
  }

  const readSet = health.offline_read_set;
  if (readSet && readSet.required === true && readSet.servable !== true) {
    warnings.push(
      "The offline read set is not servable, and event mode fails closed on it.",
    );
  }

  const serverVersion = health.server_version;
  if (
    serverVersion !== null &&
    serverVersion !== undefined &&
    serverVersion !== "" &&
    clientVersion !== "unknown" &&
    serverVersion !== clientVersion
  ) {
    warnings.push(
      `Server version ${serverVersion} does not match the expected app version ${clientVersion}.`,
    );
  }

  return warnings;
}

/** Describe the certificate/HTTPS status from the app URL scheme. */
export function describeHttpsStatus(appUrl: string): string {
  try {
    const parsed = new URL(appUrl);
    if (parsed.protocol === "https:") {
      return "HTTPS";
    }
    return "HTTP (development only)";
  } catch {
    return UNAVAILABLE_VALUE;
  }
}

/**
 * Fetch and parse the server health payload.
 *
 * Returns the parsed payload on success or `null` on any network error,
 * timeout, non-2xx response, or unparseable body. The wrapper never throws
 * from health polling so the wrapped Meridian UI is never blocked.
 */
export async function fetchServerHealth(
  healthUrl: string,
  options: { fetchImpl?: typeof fetch; timeoutMs?: number } = {},
): Promise<ServerHealth | null> {
  const fetchImpl = options.fetchImpl ?? fetch;
  const timeoutMs = options.timeoutMs ?? 5000;
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);

  try {
    const response = await fetchImpl(healthUrl, { signal: controller.signal });
    if (!response.ok) {
      return null;
    }
    const body = (await response.json()) as unknown;
    if (body === null || typeof body !== "object") {
      return null;
    }
    return body as ServerHealth;
  } catch {
    return null;
  } finally {
    clearTimeout(timer);
  }
}

/** Escape a string for safe interpolation into HTML text/attribute content. */
export function escapeHtml(value: string): string {
  return value
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#39;");
}

/**
 * Render the health panel model as a self-contained HTML document.
 *
 * The Electron main process loads this as a data URL so the panel needs no
 * preload, IPC, or bundled renderer. Colors avoid pure black to suit night
 * operations (kiosk guide section 13).
 */
export function renderHealthPanelHtml(model: HealthPanelModel): string {
  const rows = model.fields
    .map(
      (field) => `
        <tr>
          <th scope="row">${escapeHtml(field.label)}</th>
          <td>${escapeHtml(field.value)}</td>
          <td class="source source-${field.source}">${escapeHtml(field.source)}</td>
        </tr>`,
    )
    .join("");

  const statusClass = model.reachable ? "ok" : "warn";

  const warningsBlock =
    model.warnings.length === 0
      ? ""
      : `
    <ul class="warnings">${model.warnings
      .map((warning) => `
      <li>${escapeHtml(warning)}</li>`)
      .join("")}
    </ul>`;

  return `<!doctype html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Meridian On-site Health</title>
    <style>
      :root { color-scheme: dark; }
      body {
        margin: 0;
        font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        background: #11151c;
        color: #e6e9ef;
        padding: 1.5rem;
      }
      h1 { font-size: 1.15rem; margin: 0 0 0.25rem; }
      .connection { margin: 0 0 1rem; font-size: 0.95rem; }
      .connection.ok { color: #7ee0a8; }
      .connection.warn { color: #f2c14e; }
      .warnings {
        margin: 0 0 1rem;
        padding: 0.75rem 1rem 0.75rem 2rem;
        border: 1px solid #f2c14e;
        border-radius: 6px;
        color: #f2c14e;
      }
      .warnings li { margin: 0.25rem 0; }
      table { width: 100%; border-collapse: collapse; }
      th, td { text-align: left; padding: 0.5rem 0.75rem; border-bottom: 1px solid #2a3140; }
      th[scope="row"] { color: #aab2c0; font-weight: 500; width: 40%; }
      .source { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.04em; color: #8893a5; }
      .source-placeholder { color: #f2c14e; }
      .source-server { color: #7ee0a8; }
      .source-app { color: #8ab4f8; }
      footer { margin-top: 1rem; font-size: 0.75rem; color: #8893a5; }
    </style>
  </head>
  <body>
    <h1>Meridian On-site Health</h1>
    <p class="connection ${statusClass}">${escapeHtml(model.connectionLabel)}</p>${warningsBlock}
    <table>
      <thead>
        <tr><th scope="col">Field</th><th scope="col">Value</th><th scope="col">Source</th></tr>
      </thead>
      <tbody>${rows}
      </tbody>
    </table>
    <footer>
      On-site health panel (technical spec 25.3). Values come from the node's
      health endpoint or from this wrapper. Generated
      ${escapeHtml(model.generatedAt)}.
    </footer>
  </body>
</html>`;
}
