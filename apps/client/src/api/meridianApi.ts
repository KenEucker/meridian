// Minimal Meridian server HTTP client for Field Report command uploads (M9.8).
//
// The node this client talks to is resolved by `@/app/nodeConnection`, which
// lets a device be pointed at a node rather than only inheriting the one that
// served it or the one baked in at build time.

import { resolveNodeUrl } from "@/app/nodeConnection";

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

  return {
    baseUrl: resolveNodeUrl(),
    bearerToken: env.VITE_MERIDIAN_LOCAL_FIELD_API_TOKEN ?? null,
  };
}

let configOverride: MeridianApiConfig | null = null;

/** Test helper: override API base URL / token. */
export function configureMeridianApi(config: MeridianApiConfig | null): void {
  configOverride = config;
}

/**
 * Headers carrying a credential this client holds that is not a bearer token.
 *
 * A shared-workstation session is exactly that: AUTH-030 forbids a code entry
 * from issuing a personal device token, so the Kiosk authenticates with a session
 * key in its own header and there is no token for `bearerToken` to hold.
 *
 * A registration hook rather than an import, because the module that owns the
 * credential calls this one to send its requests. Registering the source keeps
 * the dependency pointing one way.
 */
export type MeridianCredentialSource = () => Readonly<Record<string, string>>;

let credentialSource: MeridianCredentialSource | null = null;

/** Register the non-bearer credential every request should carry. */
export function registerMeridianCredentialSource(
  source: MeridianCredentialSource | null,
): void {
  credentialSource = source;
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

  if (!headers.has("Accept")) {
    headers.set("Accept", "application/json");
  }

  // A multipart body carries its own content type with a generated boundary.
  // Setting `application/json` over it — or even setting `multipart/form-data`
  // without the boundary — makes the request unparseable on the server.
  if (
    !headers.has("Content-Type") &&
    init.body !== undefined &&
    !(init.body instanceof FormData)
  ) {
    headers.set("Content-Type", "application/json");
  }

  if (config.bearerToken) {
    headers.set("Authorization", `Bearer ${config.bearerToken}`);
  }

  // A header the caller set explicitly wins, so a request can be made
  // deliberately unauthenticated or under a different credential.
  for (const [name, value] of Object.entries(credentialSource?.() ?? {})) {
    if (!headers.has(name)) {
      headers.set(name, value);
    }
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
