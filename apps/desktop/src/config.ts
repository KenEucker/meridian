/**
 * Configuration resolution for the Meridian Electron on-site wrapper.
 *
 * The wrapper does not own server process management in Alpha 1 (technical
 * spec 25.1). It only needs to know which local Meridian web UI to open and
 * where to read the server health payload from (technical spec 3.3, 25.2).
 *
 * All functions here are pure so they can be unit tested without an Electron
 * runtime; the Electron main process consumes them at startup.
 */

/**
 * Default local Meridian web UI URL.
 *
 * The local Laravel server serves the Meridian web UI and the health endpoint
 * on the same origin during development (`php artisan serve` listens on 8000).
 * On-site deployments override this with `MERIDIAN_APP_URL`.
 */
export const DEFAULT_APP_URL = "http://localhost:8000/";

/** Relative path of the server health endpoint (technical spec 25.3, 26.3). */
export const HEALTH_PATH = "/api/health";

type EnvLike = Record<string, string | undefined>;

/**
 * Resolve the local Meridian web UI URL the wrapper should open.
 *
 * Reads `MERIDIAN_APP_URL` when present, otherwise falls back to the local
 * development default. Only `http`/`https` URLs are accepted so the wrapper
 * cannot be pointed at an unexpected scheme.
 */
export function resolveAppUrl(env: EnvLike = {}): string {
  const raw = env.MERIDIAN_APP_URL?.trim();
  if (!raw) {
    return DEFAULT_APP_URL;
  }

  return normalizeHttpUrl(raw, "MERIDIAN_APP_URL");
}

/**
 * Resolve the server health URL.
 *
 * Uses `MERIDIAN_HEALTH_URL` when explicitly provided, otherwise derives the
 * health endpoint from the resolved app URL so the wrapper reads health from
 * the same local server it is wrapping.
 */
export function resolveHealthUrl(env: EnvLike = {}, appUrl: string = resolveAppUrl(env)): string {
  const raw = env.MERIDIAN_HEALTH_URL?.trim();
  if (raw) {
    return normalizeHttpUrl(raw, "MERIDIAN_HEALTH_URL");
  }

  return new URL(HEALTH_PATH, appUrl).toString();
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
