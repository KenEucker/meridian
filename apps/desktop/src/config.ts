/**
 * Configuration resolution for the Meridian Electron on-site wrapper.
 *
 * The wrapper does not own server process management in Alpha 1 (technical
 * spec 25.1). It serves the packaged shared Vue client locally and reads
 * server health from the local Laravel node (technical spec 3.3, 25.2).
 *
 * All functions here are pure so they can be unit tested without an Electron
 * runtime; the Electron main process consumes them at startup.
 */

import { readFileSync } from "node:fs";
import { resolve } from "node:path";

/**
 * Default local Meridian server URL.
 *
 * The local Laravel server exposes the API and health endpoint during
 * development (`php artisan serve` listens on 8000). On-site deployments
 * override this with `MERIDIAN_SERVER_URL`.
 */
export const DEFAULT_SERVER_URL = "http://localhost:8000/";

/** Relative path of the server health endpoint (technical spec 25.3, 26.3). */
export const HEALTH_PATH = "/api/health";

type EnvLike = Record<string, string | undefined>;
type ReadFile = typeof readFileSync;

/**
 * Resolve an optional app URL override.
 *
 * By default the wrapper serves the packaged shared client with its own local
 * static server. `MERIDIAN_APP_URL` remains as an explicit development escape
 * hatch for smoke testing an externally served client.
 */
export function resolveConfiguredAppUrl(env: EnvLike = {}): string | null {
  const raw = env.MERIDIAN_APP_URL?.trim();
  if (!raw) {
    return null;
  }

  return normalizeHttpUrl(raw, "MERIDIAN_APP_URL");
}

/** Resolve the local server URL used for API health checks. */
export function resolveServerUrl(env: EnvLike = {}): string {
  const raw = env.MERIDIAN_SERVER_URL?.trim();
  if (!raw) {
    return DEFAULT_SERVER_URL;
  }

  return normalizeHttpUrl(raw, "MERIDIAN_SERVER_URL");
}

/** Resolve the packaged shared client build directory. */
export function resolveClientDistPath(env: EnvLike = {}, cwd = process.cwd()): string {
  const raw = env.MERIDIAN_CLIENT_DIST_DIR?.trim();
  if (raw) {
    return resolve(cwd, raw);
  }

  return resolve(cwd, "../client/dist");
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

/** Resolve the shared Vue client version displayed in desktop health. */
export function resolveClientVersion(
  env: EnvLike = {},
  cwd = process.cwd(),
  readFile: ReadFile = readFileSync,
): string {
  const raw = env.MERIDIAN_CLIENT_VERSION?.trim();
  if (raw) {
    return raw;
  }

  try {
    const packageJson = JSON.parse(
      readFile(resolve(cwd, "../client/package.json"), "utf8"),
    ) as { version?: unknown };
    return typeof packageJson.version === "string" ? packageJson.version : "unknown";
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
