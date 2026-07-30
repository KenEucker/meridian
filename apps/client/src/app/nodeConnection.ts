// Which Meridian node this device talks to.
//
// A Meridian deployment is a set of nodes — a central node and one or more
// on-site nodes (technical spec 7.1, 8.1) — and a device has to know which one
// it is working against. Three clients answer that differently, and only one of
// them could answer it at all before this module existed:
//
//   - The browser client is served by a node, which injects its own origin as
//     `window.__MERIDIAN_RUNTIME_CONFIG__.apiBaseUrl`. It already knows.
//   - The desktop wrapper serves a packaged client from disk, so nothing
//     injects an origin.
//   - The mobile app ships the client inside the app bundle, so its node was
//     whatever was baked in at build time.
//
// So the last two had no way to be pointed at a node without rebuilding, which
// is not a thing a technician setting up an on-site node can do.
//
// Resolution order, highest first:
//
//   1. `configured` — a node URL this device has been set to. Explicit and
//      persistent, and it is what the setup surface writes.
//   2. `served`     — the node that served this client.
//   3. `build`      — `VITE_MERIDIAN_API_BASE_URL`, baked in at build time.
//   4. `default`    — local development.
//
// Configuring a node deliberately outranks the node that served the client.
// That is the point for the desktop and mobile clients, and it is a real
// decision for the browser client: pointing a browser at a different origin
// than the one that served it means the session cookie for the serving node no
// longer applies, so the setup surface says so rather than letting someone
// discover it by being signed out.

import { computed, ref } from "vue";

/** Local development node, used when nothing else is known. */
export const DEFAULT_NODE_URL = "http://127.0.0.1:8000";

const nodeUrlStorageKey = "meridian.node.url";

export type NodeUrlSource = "configured" | "served" | "build" | "default";

export interface NodeConnection {
  /** Normalized base URL every API request is made against. */
  readonly url: string;
  /** Where that URL came from. */
  readonly source: NodeUrlSource;
  /** The node that served this client, when one did. */
  readonly servedUrl: string | null;
  /**
   * True when this device has been pointed at a node other than the one that
   * served it, which is the case where session cookies will not follow.
   */
  readonly overridesServingNode: boolean;
}

export class NodeUrlError extends Error {
  constructor(message: string) {
    super(message);
    this.name = "NodeUrlError";
  }
}

const configuredNodeUrl = ref<string | null>(readConfiguredNodeUrl());

/**
 * Bumped whenever something outside the configured value changes.
 *
 * The served and built-in sources are fixed for the life of the page — the
 * serving node injects its origin into the document before any script runs, and
 * the built-in value is compiled in — so only the configured value is reactive
 * on its own. `refreshNodeConnection` exists for the cases where that
 * assumption does not hold, which today is tests.
 */
const revision = ref(0);

export const nodeConnection = computed<NodeConnection>(() => {
  void revision.value;

  const served = servedNodeUrl();
  const configured = configuredNodeUrl.value;

  if (configured !== null) {
    return {
      url: configured,
      source: "configured",
      servedUrl: served,
      overridesServingNode: served !== null && served !== configured,
    };
  }

  if (served !== null) {
    return {
      url: served,
      source: "served",
      servedUrl: served,
      overridesServingNode: false,
    };
  }

  const built = buildTimeNodeUrl();

  if (built !== null) {
    return {
      url: built,
      source: "build",
      servedUrl: null,
      overridesServingNode: false,
    };
  }

  return {
    url: DEFAULT_NODE_URL,
    source: "default",
    servedUrl: null,
    overridesServingNode: false,
  };
});

/** Re-read the sources that are not reactive on their own. */
export function refreshNodeConnection(): void {
  revision.value += 1;
}

/** The base URL API requests are made against. */
export function resolveNodeUrl(): string {
  return nodeConnection.value.url;
}

/**
 * Point this device at a node, persistently.
 *
 * @throws NodeUrlError when the value is not an absolute http(s) URL.
 */
export function setNodeUrl(raw: string): string {
  const normalized = normalizeNodeUrl(raw);

  configuredNodeUrl.value = normalized;
  writeConfiguredNodeUrl(normalized);
  refreshNodeConnection();

  return normalized;
}

/** Forget the configured node and fall back to whatever else is known. */
export function clearNodeUrl(): void {
  configuredNodeUrl.value = null;
  refreshNodeConnection();

  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.removeItem(nodeUrlStorageKey);
  } catch {
    // A device that cannot persist still runs against the resolved node.
  }
}

/**
 * Normalize a node URL, or refuse it.
 *
 * Refusing is the useful behavior here. A typo in a node URL on an on-site
 * device does not fail at configuration time — it fails later, as sync that
 * never connects, which is a much harder thing to diagnose in the field.
 */
export function normalizeNodeUrl(raw: string): string {
  const trimmed = raw.trim();

  if (trimmed === "") {
    throw new NodeUrlError("Enter the node's address.");
  }

  let parsed: URL;

  try {
    parsed = new URL(trimmed);
  } catch {
    throw new NodeUrlError(
      "That is not a complete address. Include http:// or https://.",
    );
  }

  if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
    throw new NodeUrlError("A node address must be http:// or https://.");
  }

  if (parsed.search !== "" || parsed.hash !== "") {
    throw new NodeUrlError(
      "A node address is an origin and an optional path, with no query or fragment.",
    );
  }

  const path = parsed.pathname.replace(/\/+$/, "");

  return `${parsed.origin}${path}`;
}

function servedNodeUrl(): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  const injected = window.__MERIDIAN_RUNTIME_CONFIG__?.apiBaseUrl;

  return safeNormalize(injected);
}

function buildTimeNodeUrl(): string | null {
  const env = import.meta.env as Record<string, string | undefined>;

  return safeNormalize(env.VITE_MERIDIAN_API_BASE_URL);
}

/**
 * Normalize a value this device did not type. A malformed injected or built-in
 * value falls through to the next source rather than throwing: the app has to
 * start even when its deployment configuration is wrong.
 */
function safeNormalize(value: string | undefined | null): string | null {
  if (value === undefined || value === null || value.trim() === "") {
    return null;
  }

  try {
    return normalizeNodeUrl(value);
  } catch {
    return null;
  }
}

function readConfiguredNodeUrl(): string | null {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    return safeNormalize(window.localStorage.getItem(nodeUrlStorageKey));
  } catch {
    return null;
  }
}

function writeConfiguredNodeUrl(url: string): void {
  if (typeof window === "undefined") {
    return;
  }

  try {
    window.localStorage.setItem(nodeUrlStorageKey, url);
  } catch {
    // The node is still resolved for this session; it just will not survive a
    // restart. Storage being unavailable is not a reason to refuse the change.
  }
}
