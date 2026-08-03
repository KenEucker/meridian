import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi, meridianApiConfig } from "@/api/meridianApi";
import { commandOutbox, resetCommandOutbox } from "@/outbox/commandOutboxRuntime";
import {
  adoptHeldApiToken,
  apiLoginState,
  requestLoginCode,
  resetApiLoginForTests,
  signOut,
  submitLoginCode,
} from "@/session/apiLogin";
import {
  API_TOKEN_STORAGE_KEY,
  apiBearerToken,
  clearApiToken,
  storeApiToken,
} from "@/session/apiToken";
import {
  clearClientSession,
  clientSessionState,
  refreshClientSession,
} from "@/session/clientSession";
import { configureDeviceIdentity, type DeviceKeyStore } from "@/session/deviceIdentity";
import { fixtureSessionDocument } from "@/session/sessionDocumentFixture";

/*
 * Signing in to a node from a client application (M16.11; AUTH-018, AUTH-019,
 * AUTH-021).
 *
 * No server runs (CLIENT-024). A fake node answers the two login endpoints,
 * `GET /api/me`, and token revocation, and records what it was sent — because
 * half of what matters here is what goes on the wire: the device the token is
 * bound to, and the token itself on every request afterwards.
 */

const EMAIL = "dana@example.test";
const CODE = "K3M7PQRS";
const TOKEN = "mrdn_at_issued";

/** The one session this node knows how to resolve, and the event it belongs to. */
const SERVED_DOCUMENT = fixtureSessionDocument();

interface RecordedRequest {
  readonly method: string;
  readonly path: string;
  /** The query the client sent, which is where `GET /api/me` carries its scope. */
  readonly query: URLSearchParams;
  readonly authorization: string | null;
  readonly body: Record<string, unknown> | null;
}

let requests: RecordedRequest[] = [];

/** Set when the node should refuse the next login request. */
let refusal: { status: number; message: string } | null = null;

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function answer(request: RecordedRequest): Response {
  if (refusal !== null) {
    const { status, message } = refusal;

    return json({ message }, status);
  }

  switch (request.path) {
    case "/api/auth/magic-link":
      return json({ status: "sent", expires_in_minutes: 15 }, 202);
    case "/api/auth/magic-link/verify":
      return json(
        {
          token: TOKEN,
          token_type: "Bearer",
          expires_at: "2027-06-01T12:00:00+00:00",
          user: { id: "user-1", name: "Dana Reyes", email: EMAIL },
          device: { id: "11111111-1111-4111-8111-111111111111", label: "Meridian Admin (web)", platform: "web" },
        },
        201,
      );
    case "/api/auth/session":
      return json({ status: "revoked" });
    case "/api/me": {
      if (request.authorization !== `Bearer ${TOKEN}`) {
        return json({ message: "Unauthenticated." }, 401);
      }

      /*
       * The node resolves roles at the event it was asked about, and refuses
       * when the caller holds no association with it — `SessionResolver` raises
       * a `SessionContextException` and `SessionController` answers its status,
       * which for an unassociated event is 409.
       *
       * Modelled here because it is the whole of the bug this fake exists to
       * catch: a 409 is neither a bad credential nor an unreachable node, so a
       * client that asks about the wrong event installs no document at all and
       * silently keeps the one it had.
       */
      const scope = request.query.get("event_id");

      if (scope !== null && scope !== SERVED_DOCUMENT.context.event_id) {
        return json(
          {
            message: "You hold no association with that event.",
            reason_code: "event_not_associated",
            node_locked_event_id: null,
          },
          409,
        );
      }

      return json(SERVED_DOCUMENT);
    }
    default:
      return json({ message: "No route." }, 404);
  }
}

function stubNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const url = new URL(String(input));
      const headers = new Headers(init?.headers);
      const request: RecordedRequest = {
        method: (init?.method ?? "GET").toUpperCase(),
        path: url.pathname,
        query: url.searchParams,
        authorization: headers.get("Authorization"),
        body:
          typeof init?.body === "string"
            ? (JSON.parse(init.body) as Record<string, unknown>)
            : null,
      };

      requests.push(request);

      return answer(request);
    }),
  );
}

function memoryStorage(): Storage {
  const entries = new Map<string, string>();

  return {
    get length() {
      return entries.size;
    },
    clear: () => entries.clear(),
    getItem: (key: string) => entries.get(key) ?? null,
    key: (index: number) => [...entries.keys()][index] ?? null,
    removeItem: (key: string) => void entries.delete(key),
    setItem: (key: string, value: string) => void entries.set(key, value),
  } as Storage;
}

function stubDevice(keys: DeviceKeyStore | null = memoryKeyStore()): void {
  configureDeviceIdentity({
    storage: memoryStorage(),
    keys,
    crypto: {
      randomUUID: () => "11111111-1111-4111-8111-111111111111",
      subtle: {
        generateKey: async () => ({ privateKey: {}, publicKey: {} }) as unknown as CryptoKeyPair,
        exportKey: async () => new Uint8Array([1, 2, 3, 4]).buffer,
      },
    } as unknown as Crypto,
  });
}

function memoryKeyStore(): DeviceKeyStore {
  let stored: CryptoKeyPair | null = null;

  return {
    read: async () => stored,
    write: async (pair: CryptoKeyPair) => {
      stored = pair;
    },
  };
}

beforeEach(() => {
  requests = [];
  refusal = null;
  window.localStorage.removeItem(API_TOKEN_STORAGE_KEY);
  clearApiToken();
  clearClientSession();
  resetApiLoginForTests();
  configureMeridianApi(null);
  stubDevice();
  stubNode();
});

afterEach(() => {
  vi.unstubAllGlobals();
  configureDeviceIdentity(null);
  clearApiToken();
  clearClientSession();
  resetCommandOutbox();
  resetApiLoginForTests();
});

async function signIn(): Promise<void> {
  expect(await requestLoginCode(EMAIL)).toBe("sent");
  expect(await submitLoginCode(CODE)).toBe("signed_in");
}

/**
 * Leave the client holding somebody else's session, the way a boot does.
 *
 * Resolved from a node that answers a different document rather than installed
 * by hand, so what the client ends up holding is a document it accepted through
 * its own path — including the context event that is the whole point of the
 * test. The real node is restored before the sign-in under test runs.
 */
async function refreshWithHeldSession(): Promise<void> {
  const foreign = fixtureSessionDocument({
    user: {
      id: "user-somebody-else",
      name: "Somebody Else",
      email: "somebody@example.test",
      staff_ids: ["staff-somebody-else"],
    },
    context: {
      ...SERVED_DOCUMENT.context,
      event_id: "event-somebody-else",
      node_locked_event_id: "event-somebody-else",
    },
  });

  storeApiToken({
    token: TOKEN,
    expiresAt: null,
    user: { id: "user-somebody-else", name: "Somebody Else", email: "somebody@example.test" },
  });

  vi.stubGlobal("fetch", vi.fn(async () => json(foreign)));

  expect(await refreshClientSession()).toBe("refreshed");

  clearApiToken();
  resetApiLoginForTests();
  stubNode();
}

describe("signing in with a login code", () => {
  it("asks the node for a code and waits for it to be entered", async () => {
    expect(await requestLoginCode(EMAIL)).toBe("sent");

    expect(apiLoginState.status).toBe("code_sent");
    expect(apiLoginState.email).toBe(EMAIL);
    expect(apiLoginState.codeExpiresInMinutes).toBe(15);
    expect(requests[0]?.body).toEqual({ email: EMAIL });
  });

  /*
   * AUTH-021: the token is bound to a device, and the request is what carries
   * the device identity the node binds it to.
   */
  it("exchanges the code for a token bound to this device", async () => {
    await signIn();

    const verify = requests.find(
      (request) => request.path === "/api/auth/magic-link/verify",
    );

    expect(verify?.body?.email).toBe(EMAIL);
    expect(verify?.body?.code).toBe(CODE);
    expect(verify?.body?.device).toMatchObject({
      id: "11111111-1111-4111-8111-111111111111",
      platform: "web",
    });
    expect(String((verify?.body?.device as Record<string, string>).public_key)).toContain(
      "BEGIN PUBLIC KEY",
    );

    expect(apiLoginState.status).toBe("signed_in");
    expect(apiLoginState.user?.email).toBe(EMAIL);
    expect(apiBearerToken(new Date("2027-05-01T00:00:00Z"))).toBe(TOKEN);
  });

  /*
   * The token says who this is; the session says what they may do, and every
   * surface reads the session. Signing in without resolving it would leave a
   * signed-in user looking at an empty shell (CLIENT-004).
   */
  it("resolves the session with the token it was just issued", async () => {
    await signIn();

    const me = requests.find((request) => request.path === "/api/me");

    expect(me?.authorization).toBe(`Bearer ${TOKEN}`);
    expect(clientSessionState.status).toBe("live");
    expect(clientSessionState.document?.user.name).toBe("Dana Departmentlead");
  });

  /*
   * The bug this branch was reported for (M18.8 follow-up; CLIENT-014).
   *
   * `refreshClientSession` scopes `GET /api/me` to the context event it is
   * already holding, which is right for a reconnect and wrong for a sign-in.
   * A client holding somebody else's session — in development, the local field
   * session installed at boot; in the field, whoever used the device last —
   * asked the node to resolve this user at an event they hold no association
   * with. The node answered 409, which is neither a refused credential nor an
   * unreachable node, so nothing installed and every surface kept rendering the
   * previous occupant's event and department until the page was reloaded.
   */
  it("resolves at the new user's own event rather than the previous occupant's", async () => {
    await refreshWithHeldSession();

    expect(clientSessionState.document?.context.event_id).toBe(
      "event-somebody-else",
    );

    requests = [];

    expect(await requestLoginCode(EMAIL)).toBe("sent");
    expect(await submitLoginCode(CODE)).toBe("signed_in");

    const me = requests.find((request) => request.path === "/api/me");

    expect(me?.query.get("event_id")).toBeNull();
    expect(clientSessionState.status).toBe("live");
    expect(clientSessionState.document?.context.event_id).toBe(
      SERVED_DOCUMENT.context.event_id,
    );
    expect(clientSessionState.document?.user.name).toBe("Dana Departmentlead");
  });

  it("carries the token on every later request", async () => {
    await signIn();

    expect(meridianApiConfig().bearerToken).toBe(TOKEN);
  });

  it("keeps the node's refusal and signs nobody in", async () => {
    await requestLoginCode(EMAIL);

    refusal = { status: 422, message: "That login code is not valid." };

    expect(await submitLoginCode(CODE)).toBe("refused");
    expect(apiLoginState.error).toBe("That login code is not valid.");
    expect(apiLoginState.status).toBe("code_sent");
    expect(apiBearerToken()).toBeNull();
  });

  it("tells an unreachable node apart from a refusal", async () => {
    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    expect(await requestLoginCode(EMAIL)).toBe("unreachable");
    expect(apiLoginState.status).toBe("signed_out");
  });

  /*
   * A device that cannot present a signing key cannot be issued a token, and a
   * code is single use — so it is never spent on a refusal the client could see
   * coming.
   */
  it("refuses before spending the code when the device cannot register", async () => {
    await requestLoginCode(EMAIL);

    stubDevice(null);
    requests = [];

    expect(await submitLoginCode(CODE)).toBe("device_unavailable");
    expect(requests).toHaveLength(0);
    expect(apiBearerToken()).toBeNull();
  });

  it("asks for an address before it will submit a code", async () => {
    expect(await submitLoginCode(CODE)).toBe("refused");
    expect(requests).toHaveLength(0);
  });
});

describe("signing out", () => {
  it("revokes the token on the node and drops everything it held", async () => {
    await signIn();
    requests = [];

    await signOut();

    expect(requests[0]).toMatchObject({
      method: "DELETE",
      path: "/api/auth/session",
      authorization: `Bearer ${TOKEN}`,
    });
    expect(apiLoginState.status).toBe("signed_out");
    expect(apiBearerToken()).toBeNull();
    expect(clientSessionState.document).toBeNull();
  });

  it("signs out locally even when the node cannot be reached", async () => {
    await signIn();

    vi.stubGlobal(
      "fetch",
      vi.fn(async () => {
        throw new TypeError("Failed to fetch");
      }),
    );

    await signOut();

    expect(apiBearerToken()).toBeNull();
    expect(apiLoginState.status).toBe("signed_out");
  });

  /*
   * A refusal belongs to the person it refused. It was outliving them: sign out,
   * sign in as somebody else, and the node's sentence about the previous user's
   * Field Report was still on the new user's screen.
   */
  it("drops the node's verdicts and keeps this device's unsent work", async () => {
    await signIn();

    commandOutbox.enqueue({
      idempotencyKey: "11111111-1111-4111-8111-111111111111",
      commandType: "submit-field-report",
      payload: {},
      queuedAt: "2027-07-04T16:00:00.000Z",
    });
    commandOutbox.enqueue({
      idempotencyKey: "22222222-2222-4222-8222-222222222222",
      commandType: "submit-field-report",
      payload: {},
      queuedAt: "2027-07-04T16:00:01.000Z",
    });
    commandOutbox.markSending("22222222-2222-4222-8222-222222222222", "2027-07-04T16:00:02.000Z");
    commandOutbox.markRejected(
      "22222222-2222-4222-8222-222222222222",
      "2027-07-04T16:00:03.000Z",
      "Field Report event does not exist.",
    );

    await signOut();

    expect(commandOutbox.byStatus("rejected")).toHaveLength(0);
    expect(
      commandOutbox.byStatus("queued").map((command) => command.idempotencyKey),
    ).toEqual(["11111111-1111-4111-8111-111111111111"]);
  });
});

describe("a credential the node has stopped accepting", () => {
  /*
   * A revoked token or a revoked device announces itself as a refused refresh
   * (AUTH-023). Dropping the session while keeping the dead token would leave
   * the client re-sending it on every request it makes.
   */
  it("is dropped when a refresh is refused", async () => {
    storeApiToken({
      token: "mrdn_at_revoked",
      user: { id: "user-1", name: "Dana Reyes", email: EMAIL },
    });
    adoptHeldApiToken();

    expect(await refreshClientSession()).toBe("unauthenticated");

    expect(apiBearerToken()).toBeNull();
    expect(apiLoginState.status).toBe("signed_out");
    expect(apiLoginState.error).toBe("Your session ended. Sign in again to continue.");
  });
});
