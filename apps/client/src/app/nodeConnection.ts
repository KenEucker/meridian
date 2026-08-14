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
//   1. `configured`  — a node URL this device has been set to. Explicit and
//      persistent, and it is what the setup surface writes.
//   2. `served`      — the node that served this client.
//   3. `build`       — `VITE_MERIDIAN_API_BASE_URL`, baked in at build time.
//   4. `discovered`  — what this boot's probe of the on-site convention found.
//   5. `convention`  — the standard on-site node name, on packaged apps.
//   6. `default`     — local development.
//
// Configuring a node deliberately outranks the node that served the client.
// That is the point for the desktop and mobile clients, and it is a real
// decision for the browser client: pointing a browser at a different origin
// than the one that served it means the session cookie for the serving node no
// longer applies, so the setup surface says so rather than letting someone
// discover it by being signed out.
//
// The last three tiers are what make a fresh install work with no settings
// (technical spec 8.4's local-discovery path). An on-site node answers at
// `meridian.home.arpa` by convention — `home.arpa` is the RFC 8375 name for
// exactly this kind of network, the event network's own DNS answers it, and
// additional nodes take `meridian2`, `meridian3` in order. A packaged app that
// knows nothing therefore assumes the main on-site name (`convention`), and a
// boot-time probe walks the convention names and then the central deployment,
// promoting the first node that answers (`discovered`). Discovery is
// per-boot on purpose: which network this device is standing on is a fact
// about now, and yesterday's answer persisted would point a phone at a node it
// has walked away from.

import { computed, ref } from "vue";

import {
  resolveMeridianAppConfig,
  type MeridianAppConfig,
} from "@/app/appConfig";

/** Local development node, used when nothing else is known. */
export const DEFAULT_NODE_URL = "http://127.0.0.1:8000";

/**
 * The on-site node names, in the order the convention assigns them. Plain
 * http: `home.arpa` never appears in public DNS, so no public authority will
 * certify it; the packaged apps carry a scoped cleartext allowance for these
 * names instead (spec 8.5's trade, stated where the phone enforces it).
 */
export const ON_SITE_NODE_URLS: readonly string[] = [
  "http://meridian.home.arpa",
  "http://meridian2.home.arpa",
  "http://meridian3.home.arpa",
];

/**
 * Where the platform lives when no on-site node answers. A deployment that
 * owns a different domain bakes it in with `VITE_MERIDIAN_CENTRAL_URL`.
 */
export const DEFAULT_CENTRAL_NODE_URL = "https://meridian-vop.com";

const nodeUrlStorageKey = "meridian.node.url";

export type NodeUrlSource =
  | "configured"
  | "served"
  | "build"
  | "discovered"
  | "convention"
  | "default";

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
 * What this boot's probe found, when it found anything. Deliberately not
 * persisted — see the module comment. `configured` still outranks it, so a
 * device somebody pointed somewhere on purpose stays pointed there.
 */
const discoveredNodeUrl = ref<string | null>(null);

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

  if (discoveredNodeUrl.value !== null) {
    return {
      url: discoveredNodeUrl.value,
      source: "discovered",
      servedUrl: null,
      overridesServingNode: false,
    };
  }

  /*
   * A packaged app that knows nothing assumes the main on-site name rather
   * than a loopback no phone answers. Standing on the convention before the
   * probe returns means the very first requests already go where the node is
   * expected to be — and when nothing answers there, the surfaces name the
   * address somebody on-site can actually check.
   */
  if (resolveMeridianAppConfig().deploymentTarget !== "server") {
    return {
      url: ON_SITE_NODE_URLS[0],
      source: "convention",
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
 * Whether a node answers at `url`, asked the way the app will ask it.
 *
 * `/api/health` is the question every diagnostic here already asks, and a
 * short timeout because this runs against names that may not resolve at all:
 * a phone off the event network asking for `meridian.home.arpa` should fall
 * through to central in seconds, not wait out a TCP handshake nobody will
 * answer.
 */
async function nodeAnswersAt(url: string): Promise<boolean> {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), 4000);

  try {
    const response = await fetch(`${url}/api/health`, {
      method: "GET",
      cache: "no-store",
      signal: controller.signal,
    });

    return response.ok;
  } catch {
    return false;
  } finally {
    clearTimeout(timeout);
  }
}

export interface NodeDiscoveryOptions {
  /** Test seam; the real probe asks `/api/health`. */
  readonly probe?: (url: string) => Promise<boolean>;
  /** Test seam; the application passes its own config. */
  readonly config?: MeridianAppConfig;
}

/**
 * Find this device's node without anybody typing anything.
 *
 * Walks the on-site convention names in order and then the central
 * deployment, promoting the first node that answers. Runs once per boot from
 * `main.ts`, and only where it can help: a packaged app whose answer would
 * otherwise be the bare convention. An explicit answer — configured, served,
 * or built in — is never second-guessed, so discovery cannot move a device
 * somebody has pointed on purpose.
 *
 * Returns the promoted URL, or null when nothing answered and the convention
 * stands.
 */
export async function discoverNodeUrl(
  options: NodeDiscoveryOptions = {},
): Promise<string | null> {
  const config = options.config ?? resolveMeridianAppConfig();
  const probe = options.probe ?? nodeAnswersAt;

  if (config.deploymentTarget === "server") {
    return null;
  }

  const standing = nodeConnection.value.source;

  if (standing !== "convention" && standing !== "default") {
    return null;
  }

  for (const candidate of [...ON_SITE_NODE_URLS, centralNodeUrl()]) {
    if (await probe(candidate)) {
      discoveredNodeUrl.value = candidate;
      refreshNodeConnection();

      return candidate;
    }
  }

  return null;
}

/** Test seam: forget what this boot's probe found. */
export function resetNodeDiscovery(): void {
  discoveredNodeUrl.value = null;
  refreshNodeConnection();
}

/**
 * The central deployment, which is the last candidate discovery tries. The
 * built-in domain is the platform's own; a deployment that owns another bakes
 * it in at build time.
 */
function centralNodeUrl(): string {
  const env = import.meta.env as Record<string, string | undefined>;

  return safeNormalize(env.VITE_MERIDIAN_CENTRAL_URL) ?? DEFAULT_CENTRAL_NODE_URL;
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
