/**
 * Configuration resolution for the Meridian Electron on-site wrapper.
 *
 * The wrapper does not own server process management in Alpha 1 (technical
 * spec 25.1). It serves the packaged Meridian Kiosk client locally and reads
 * server health from the local Laravel node (technical spec 3.4, 25.2).
 *
 * All functions here are pure so they can be unit tested without an Electron
 * runtime; the Electron main process consumes them at startup.
 */

import { readFileSync } from "node:fs";
import { dirname, resolve } from "node:path";

/**
 * Default local Meridian server URL.
 *
 * The local Laravel server exposes the API and health endpoint during
 * development (`php artisan serve` listens on 8000). On-site deployments
 * override this with `MERIDIAN_SERVER_URL`.
 */
export const DEFAULT_SERVER_URL = "http://localhost:8000/";
export const DEFAULT_CLIENT_DEV_SERVER_URL = "http://localhost:5173/";
const ROOT_PACKAGE_NAME = "meridian";

/** Relative path of the server health endpoint (technical spec 25.3, 26.3). */
export const HEALTH_PATH = "/api/health";

type EnvLike = Record<string, string | undefined>;
type ReadFile = typeof readFileSync;
type DevUiMode = "admin" | "field" | "kiosk";

/** Resolve the local server URL used for API health checks. */
export function resolveServerUrl(env: EnvLike = {}): string {
  const raw = env.MERIDIAN_SERVER_URL?.trim();
  if (!raw) {
    return DEFAULT_SERVER_URL;
  }

  return normalizeHttpUrl(raw, "MERIDIAN_SERVER_URL");
}

/**
 * Name of the settings file the wrapper reads its node from, inside Electron's
 * per-user application data directory.
 *
 * A packaged desktop app is not started from a shell, so an environment
 * variable is not a way to tell it which node it belongs to — somebody
 * installing an on-site laptop has no place to put one. A file in the app's own
 * data directory is somewhere they can reach, and it survives an app update.
 */
export const NODE_SETTINGS_FILE = "node.json";

export type ServerUrlSource = "environment" | "stored" | "default";

export interface ResolvedServerUrl {
  /** The node the wrapper reads health and branding from. */
  readonly url: string;
  /** Where that value came from. */
  readonly source: ServerUrlSource;
  /** The settings file consulted, whether or not it existed. */
  readonly settingsPath: string | null;
}

/**
 * Read the stored node URL, or `null` when there is none to read.
 *
 * A missing file is the normal case and is not an error. A malformed one is
 * also treated as absent: the wrapper's job is to open the UI, and refusing to
 * start because a settings file has a stray comma would strand an operator with
 * no way in.
 */
export function readStoredServerUrl(
  settingsPath: string,
  readFile: ReadFile = readFileSync,
): string | null {
  let contents: string;

  try {
    contents = readFile(settingsPath, "utf8") as string;
  } catch {
    return null;
  }

  let raw: string;

  try {
    const parsed = JSON.parse(contents) as { serverUrl?: unknown };
    raw = typeof parsed.serverUrl === "string" ? parsed.serverUrl.trim() : "";
  } catch {
    return null;
  }

  if (raw === "") {
    return null;
  }

  try {
    return normalizeHttpUrl(raw, "serverUrl");
  } catch {
    // Same policy as a malformed file: an address the wrapper cannot use is
    // no address. It falls back to the default and says so in the health
    // panel, which is a state an operator can see and correct.
    return null;
  }
}

/**
 * Resolve the node this wrapper works against, with its provenance.
 *
 * `MERIDIAN_SERVER_URL` stays highest so a scripted or containerized
 * deployment keeps deciding, and an operator's stored setting is next. The
 * source travels with the value because "which node is this and who said so"
 * is the question the health panel exists to answer.
 */
export function resolveServerUrlSetting(
  env: EnvLike = {},
  settingsPath: string | null = null,
  readFile: ReadFile = readFileSync,
): ResolvedServerUrl {
  const fromEnvironment = env.MERIDIAN_SERVER_URL?.trim();

  if (fromEnvironment) {
    return {
      url: normalizeHttpUrl(fromEnvironment, "MERIDIAN_SERVER_URL"),
      source: "environment",
      settingsPath,
    };
  }

  const stored = settingsPath === null ? null : readStoredServerUrl(settingsPath, readFile);

  if (stored !== null) {
    return { url: stored, source: "stored", settingsPath };
  }

  return { url: DEFAULT_SERVER_URL, source: "default", settingsPath };
}

/** Resolve the packaged Meridian Kiosk client build directory. */
export function resolveClientDistPath(env: EnvLike = {}, cwd = process.cwd()): string {
  const raw = env.MERIDIAN_CLIENT_DIST_DIR?.trim();
  if (raw) {
    return resolve(cwd, raw);
  }

  return resolve(cwd, "../client/dist/kiosk");
}

/** Resolve the Meridian desktop window/app icon asset. */
export function resolveAppIconPath(_env: EnvLike = {}, cwd = process.cwd()): string {
  return resolve(cwd, "assets/icon.png");
}

/** Resolve the optional local static-server port. Port 0 lets the OS choose. */
export function resolveClientPort(env: EnvLike = {}): number {
  const raw = env.MERIDIAN_CLIENT_PORT?.trim();
  if (!raw) {
    return 0;
  }

  const port = Number(raw);
  if (!Number.isInteger(port) || port < 0 || port > 65535) {
    throw new Error(`MERIDIAN_CLIENT_PORT must be an integer from 0 to 65535, received: ${raw}`);
  }

  return port;
}

/** Resolve the shared Vue Vite dev server URL used by unpackaged Electron. */
export function resolveClientDevServerUrl(env: EnvLike = {}): string {
  const raw = env.MERIDIAN_CLIENT_DEV_SERVER_URL?.trim();
  if (!raw) {
    return DEFAULT_CLIENT_DEV_SERVER_URL;
  }

  return normalizeHttpUrl(raw, "MERIDIAN_CLIENT_DEV_SERVER_URL");
}

/** Resolve the Vite development app URL for a specific shared-client UI mode. */
export function resolveClientDevAppUrl(
  env: EnvLike = {},
  uiMode: DevUiMode = "kiosk",
): string {
  const url = new URL(resolveClientDevServerUrl(env));
  url.searchParams.set("meridianUiMode", uiMode);

  return url.toString();
}

/** Resolve an explicit app URL override, when one has been provided. */
export function resolveAppUrlOverride(env: EnvLike = {}): string | null {
  const raw = env.MERIDIAN_APP_URL?.trim();
  if (!raw) {
    return null;
  }

  return normalizeHttpUrl(raw, "MERIDIAN_APP_URL");
}

/** Resolve the shared Meridian version displayed in desktop health. */
export function resolveClientVersion(
  _env: EnvLike = {},
  cwd = process.cwd(),
  readFile: ReadFile = readFileSync,
): string {
  const repositoryRoot = resolveRepositoryRoot(cwd, readFile);
  if (repositoryRoot === null) {
    return "unknown";
  }

  const packageJsonPath = resolve(repositoryRoot, "package.json");
  try {
    const packageJson = JSON.parse(readFile(packageJsonPath, "utf8")) as { version?: unknown };
    return typeof packageJson.version === "string" && /^\d+\.\d+\.\d+$/.test(packageJson.version)
      ? packageJson.version
      : "unknown";
  } catch {
    return "unknown";
  }
}

/**
 * Resolve the server health URL.
 *
 * Uses `MERIDIAN_HEALTH_URL` when explicitly provided, otherwise derives the
 * health endpoint from the resolved local Meridian server URL.
 */
export function resolveHealthUrl(env: EnvLike = {}, serverUrl: string = resolveServerUrl(env)): string {
  const raw = env.MERIDIAN_HEALTH_URL?.trim();
  if (raw) {
    return normalizeHttpUrl(raw, "MERIDIAN_HEALTH_URL");
  }

  return new URL(HEALTH_PATH, serverUrl).toString();
}

function normalizeHttpUrl(raw: string, name: string): string {
  let parsed: URL;
  try {
    parsed = new URL(raw);
  } catch {
    throw new Error(`${name} is not a valid URL: ${raw}`);
  }

  if (parsed.protocol !== "http:" && parsed.protocol !== "https:") {
    throw new Error(`${name} must use http or https, received: ${raw}`);
  }

  return parsed.toString();
}

function resolveRepositoryRoot(cwd: string, readFile: ReadFile): string | null {
  let current = resolve(cwd);

  for (;;) {
    const packageJsonPath = resolve(current, "package.json");
    try {
      const packageJson = JSON.parse(readFile(packageJsonPath, "utf8")) as { name?: unknown };
      if (packageJson.name === ROOT_PACKAGE_NAME) {
        return current;
      }
    } catch {
      // Keep walking upward until the repository root package is found.
    }

    const parent = dirname(current);
    if (parent === current) {
      return null;
    }

    current = parent;
  }
}
