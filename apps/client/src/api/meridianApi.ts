// Minimal Meridian server HTTP client (M9.8).
//
// The node this client talks to is resolved by `@/app/nodeConnection`, which
// lets a device be pointed at a node rather than only inheriting the one that
// served it or the one baked in at build time.
//
// The credential comes from whoever holds it. Until M16.11 the bearer token was
// read out of the build environment, which is how every install of Meridian
// Field ended up carrying the same shared development token; now the module that
// owns the token registers a source here, the same way a shared workstation
// registers its session key, and this module knows nothing about either.

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

/**
 * The bearer token every request carries, when this client holds one.
 *
 * A registration hook rather than an import, for the same reason the credential
 * source below is one: `apiToken` owns the token's lifecycle and calls this
 * module to send its requests, so the dependency points one way.
 */
export type MeridianBearerTokenSource = () => string | null;

let bearerTokenSource: MeridianBearerTokenSource | null = null;

/** Register where the bearer token comes from. */
export function registerMeridianBearerTokenSource(
  source: MeridianBearerTokenSource | null,
): void {
  bearerTokenSource = source;
}

function readConfig(): MeridianApiConfig {
  return {
    baseUrl: resolveNodeUrl(),
    bearerToken: bearerTokenSource?.() ?? null,
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

/**
 * Whether this client holds anything the node would authenticate.
 *
 * A bearer token or a workstation session key: a signed-in device has the first,
 * a Kiosk has the second (AUTH-030), and a client with neither is signed out.
 * Asked by the outbox before it sends, so queued work waits for a credential
 * instead of being spent on requests that can only be refused.
 */
export function holdsMeridianCredential(): boolean {
  return (
    Boolean(meridianApiConfig().bearerToken) ||
    Object.keys(credentialSource?.() ?? {}).length > 0
  );
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

/**
 * What to show someone when a request did not succeed.
 *
 * A refusal reaches the client in one of three shapes and only one of them is
 * worth printing verbatim. Field validation carries `errors` — a map of field
 * name to messages — and the accompanying `message` collapses that into "The
 * name field is required. (and 1 more error)", which hides the rest. A domain
 * refusal carries `message` alone and it is already the sentence to show. A
 * transport failure carries no body at all, and its `Failed to fetch` says
 * nothing to a person, so the caller's own words stand in.
 */
export function meridianErrorMessage(error: unknown, fallback: string): string {
  if (!(error instanceof MeridianApiError)) {
    return fallback;
  }

  const body = error.body as
    | { message?: unknown; errors?: Record<string, unknown> }
    | null;

  const fieldMessages = Object.values(body?.errors ?? {})
    .flatMap((messages) => (Array.isArray(messages) ? messages : [messages]))
    .filter((message): message is string => typeof message === "string");

  if (fieldMessages.length > 0) {
    return fieldMessages.join(" ");
  }

  return typeof body?.message === "string" && body.message !== ""
    ? body.message
    : fallback;
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
