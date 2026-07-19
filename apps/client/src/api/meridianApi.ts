// Minimal Meridian server HTTP client for Field Report command uploads (M9.8).

export class MeridianApiError extends Error {
  readonly status: number;
  readonly body: unknown;

  constructor(message: string, status: number, body: unknown = null) {
    super(message);
    this.name = "MeridianApiError";
    this.status = status;
    this.body = body;
  }
}

export interface MeridianApiConfig {
  readonly baseUrl: string;
  readonly bearerToken: string | null;
}

function readConfig(): MeridianApiConfig {
  const env = import.meta.env as Record<string, string | undefined>;
  const runtimeConfig =
    typeof window === "undefined" ? null : window.__MERIDIAN_RUNTIME_CONFIG__;

  return {
    baseUrl: (
      runtimeConfig?.apiBaseUrl ??
      env.VITE_MERIDIAN_API_BASE_URL ??
      "http://127.0.0.1:8000"
    ).replace(/\/$/, ""),
    bearerToken: env.VITE_MERIDIAN_LOCAL_FIELD_API_TOKEN ?? null,
  };
}

let configOverride: MeridianApiConfig | null = null;

/** Test helper: override API base URL / token. */
export function configureMeridianApi(config: MeridianApiConfig | null): void {
  configOverride = config;
}

export function meridianApiConfig(): MeridianApiConfig {
  return configOverride ?? readConfig();
}

export async function meridianFetch(
  path: string,
  init: RequestInit = {},
): Promise<Response> {
  const config = meridianApiConfig();
  const headers = new Headers(init.headers);
  headers.set("Accept", "application/json");

  if (!headers.has("Content-Type") && init.body !== undefined) {
    headers.set("Content-Type", "application/json");
  }

  if (config.bearerToken) {
    headers.set("Authorization", `Bearer ${config.bearerToken}`);
  }

  return fetch(`${config.baseUrl}${path}`, {
    ...init,
    headers,
  });
}

export async function meridianJson<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const response = await meridianFetch(path, init);
  const text = await response.text();
  let body: unknown = null;

  if (text !== "") {
    try {
      body = JSON.parse(text) as unknown;
    } catch {
      body = text;
    }
  }

  if (!response.ok) {
    const message =
      typeof body === "object" &&
      body !== null &&
      "message" in body &&
      typeof (body as { message: unknown }).message === "string"
        ? (body as { message: string }).message
        : `Meridian API request failed (${response.status}).`;

    throw new MeridianApiError(message, response.status, body);
  }

  return body as T;
}
