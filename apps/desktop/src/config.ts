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
