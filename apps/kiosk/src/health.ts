/**
 * Health panel model and rendering for the Meridian Electron wrapper.
 *
 * Technical spec 25.3 lists the fields the on-site health panel should show.
 * For the M2.4 placeholder, only the values that the current server health
 * endpoint can supply (server version, node role/environment, status) and
 * values the wrapper itself knows (expected app version, HTTPS status) are
 * populated. Every other field is shown as a clearly labeled placeholder that
 * will be filled in by its owning Alpha 1 milestone (node identity M3.5,
 * events M4.2, PowerSync M8, devices M3.7, node sync M12).
 *
 * Following the kiosk guide section 12, the panel only reports status and
 * never blocks the wrapped Meridian UI; an unreachable server simply renders
 * server-sourced fields as "Unavailable".
 *
 * These functions are pure so they can be unit tested without an Electron
 * runtime.
 */

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
  node_role?: string | null;
  organization_id?: string | null;
  event_id?: string | null;
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

/** Value shown for fields whose source milestone has not been built yet. */
export const PLACEHOLDER_VALUE = "Pending later Alpha 1 milestone";

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

  const serverValue = (value: string | null | undefined): { value: string; source: HealthFieldSource } => {
    if (!reachable) {
      return { value: UNAVAILABLE_VALUE, source: "server" };
    }
    const text = value === null || value === undefined || value === "" ? null : String(value);
    return { value: text ?? PLACEHOLDER_VALUE, source: text === null ? "placeholder" : "server" };
  };

  const fields: HealthPanelField[] = [
    { label: "Local node name", value: PLACEHOLDER_VALUE, source: "placeholder" },
    { label: "Node role", ...serverValue(health?.environment) },
    { label: "Event name", value: PLACEHOLDER_VALUE, source: "placeholder" },
    { label: "Sync status", value: PLACEHOLDER_VALUE, source: "placeholder" },
    { label: "PowerSync status", value: PLACEHOLDER_VALUE, source: "placeholder" },
    { label: "Connected devices", value: PLACEHOLDER_VALUE, source: "placeholder" },
    { label: "Local discovery status", value: PLACEHOLDER_VALUE, source: "placeholder" },
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
    generatedAt,
  };
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
 * The Electron main process loads this as a data URL so the placeholder panel
 * needs no preload, IPC, or bundled renderer. Colors avoid pure black to suit
 * night operations (kiosk guide section 13).
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
    <p class="connection ${statusClass}">${escapeHtml(model.connectionLabel)}</p>
    <table>
      <thead>
        <tr><th scope="col">Field</th><th scope="col">Value</th><th scope="col">Source</th></tr>
      </thead>
      <tbody>${rows}
      </tbody>
    </table>
    <footer>
      Placeholder panel for Alpha 1 (technical spec 25.3). Values marked
      "placeholder" are filled in by later milestones. Generated
      ${escapeHtml(model.generatedAt)}.
    </footer>
  </body>
</html>`;
}
