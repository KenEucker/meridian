// Signing in to a Meridian node from a client application (M16.11; AUTH-018,
// AUTH-019, AUTH-021, AUTH-024; technical spec 11.4; data/API 5.4; UI
// implementation contract 12.1).
//
// Two requests and a device:
//
//   1. The client posts an email address. The node mails a login code and
//      answers the same way whether or not that address is anybody's, so the
//      screen cannot be used to find out who holds a Meridian account.
//   2. The client posts the code back with the device the token will be bound
//      to, and receives the token.
//
// Neither leg leaves the application, which is the point of the API login path:
// a client is not required to be served same-origin by the node it talks to, and
// on mobile there is no browser to hand off to for a code that was going to be
// typed anyway.
//
// Signing in ends by resolving the session, because a token on its own tells the
// client nothing about what its user may do; the session document does, and it
// is what navigation follows (CLIENT-004).
//
// Signing out disposes of the token on both sides — the node revokes it, so it
// stops authenticating on its next use rather than lingering until it expires —
// and then drops everything the session put on the device. A shared
// workstation's sign-out is a different mechanism entirely (`workstationSession`)
// because a workstation holds no token to dispose of.

import { computed, reactive, readonly } from "vue";

import { MeridianApiError, meridianJson } from "@/api/meridianApi";
import { discardSettledCommands } from "@/outbox/commandOutboxRuntime";
import {
  clearApiToken,
  heldApiToken,
  storeApiToken,
  type ApiTokenUser,
} from "@/session/apiToken";
import {
  clearClientSession,
  refreshClientSession,
  registerSessionCredentialRefusedListener,
} from "@/session/clientSession";
import {
  DeviceIdentityError,
  resolveDeviceRegistration,
} from "@/session/deviceIdentity";
import { discardSessionContextData } from "@/session/sessionContext";

export type ApiLoginStatus =
  /** No token held. The client is nobody until somebody signs in. */
  | "signed_out"
  /** A code was requested for an address and is waiting to be entered. */
  | "code_sent"
  /** A token is held. */
  | "signed_in";

export type LoginCodeRequestOutcome =
  | "sent"
  /** The node answered and refused: an unusable address, or too many asks. */
  | "refused"
  | "unreachable";

export type LoginCodeSubmitOutcome =
  | "signed_in"
  /** The node reached a decision: wrong code, spent, expired, device refused. */
  | "refused"
  | "unreachable"
  /** This device cannot present an identity a token could be bound to. */
  | "device_unavailable";

interface ApiLoginState {
  status: ApiLoginStatus;
  /** The address a code was sent to, so the code screen can name it. */
  email: string | null;
  user: ApiTokenUser | null;
  /** When the held token stops working, as the node stamped it. */
  expiresAt: string | null;
  requesting: boolean;
  submitting: boolean;
  /** How long the node says a code stays good, for the code screen to state. */
  codeExpiresInMinutes: number | null;
  /** What to tell the person whose sign-in did not go through. */
  error: string | null;
}

const state = reactive<ApiLoginState>({
  status: "signed_out",
  email: null,
  user: null,
  expiresAt: null,
  requesting: false,
  submitting: false,
  codeExpiresInMinutes: null,
  error: null,
});

export const apiLoginState = readonly(state);

export const signedIn = computed(() => state.status === "signed_in");

/**
 * Adopt the token this device already holds.
 *
 * Called at import so the shell knows who is signed in before anything renders,
 * and again by the specs to reset. A token that has expired is not adopted —
 * `heldApiToken` drops it — so a device that has been closed for longer than a
 * token's lifetime comes up signed out rather than appearing signed in until its
 * first request fails.
 */
export function adoptHeldApiToken(): void {
  const held = heldApiToken();

  if (held === null) {
    state.status = "signed_out";
    state.user = null;
    state.expiresAt = null;

    return;
  }

  state.status = "signed_in";
  state.user = held.user;
  state.expiresAt = held.expiresAt;
  state.error = null;
}

adoptHeldApiToken();

/*
 * A token the node has stopped accepting is not one this client keeps. The
 * session module finds out — a refused refresh is how a revoked token or a
 * revoked device announces itself (AUTH-023) — and this is the disposal.
 */
registerSessionCredentialRefusedListener(() => {
  clearApiToken();
  signedOutState("Your session ended. Sign in again to continue.");
});

/**
 * Ask the node to mail a login code (AUTH-019).
 *
 * The address is remembered so the code screen can name it and so the code can
 * be submitted without asking for it twice. It is not a credential and grants
 * nothing on its own.
 */
export async function requestLoginCode(
  email: string,
): Promise<LoginCodeRequestOutcome> {
  const address = email.trim();

  if (address === "") {
    state.error = "Enter the email address you use for Meridian.";

    return "refused";
  }

  state.requesting = true;
  state.error = null;

  try {
    const response = await meridianJson<unknown>("/api/auth/magic-link", {
      method: "POST",
      body: JSON.stringify({ email: address }),
    });

    state.status = "code_sent";
    state.email = address;
    state.codeExpiresInMinutes = readExpiryMinutes(response);

    return "sent";
  } catch (error) {
    return refusalOutcome(error, "sent no code");
  } finally {
    state.requesting = false;
  }
}

/**
 * Exchange a login code for a token (AUTH-019, AUTH-021).
 *
 * The device is resolved before the request, because a device that cannot
 * present a signing key cannot be issued a token and the person is better told
 * that than told their code was wrong. A code is single use, and spending one on
 * a refusal the client could have predicted is the failure worth avoiding.
 */
export async function submitLoginCode(
  code: string,
  options: { readonly email?: string } = {},
): Promise<LoginCodeSubmitOutcome> {
  const email = (options.email ?? state.email ?? "").trim();
  const entered = code.trim();

  if (email === "") {
    state.error = "Start again from the sign-in screen: this device does not know which address the code was sent to.";

    return "refused";
  }

  if (entered === "") {
    state.error = "Enter the code from your email.";

    return "refused";
  }

  state.submitting = true;
  state.error = null;

  try {
    const device = await resolveDeviceRegistration();
    const response = await meridianJson<unknown>("/api/auth/magic-link/verify", {
      method: "POST",
      body: JSON.stringify({
        email,
        code: entered,
        client_name: device.label,
        device,
      }),
    });

    const issued = readIssuedToken(response);

    if (issued === null) {
      state.error = "This node answered a sign-in this client could not read.";

      return "unreachable";
    }

    storeApiToken(issued);

    state.status = "signed_in";
    state.user = issued.user;
    state.expiresAt = issued.expiresAt;
    state.email = null;
    state.codeExpiresInMinutes = null;

    /*
     * Drop whoever this client was holding before resolving who it is holding
     * now (CLIENT-014).
     *
     * Not housekeeping. `refreshClientSession` scopes `GET /api/me` to the
     * context event it is already holding, which is right for a reconnect —
     * it stops a refresh silently moving a device to a different event — and
     * wrong for a sign-in, where the previous occupant's event has no standing.
     * Left in place it asks the node to resolve this user's roles at an event
     * they may hold no association with, and the node refuses with a 409 that
     * is neither a bad credential nor an unreachable node, so no document
     * installs and the surfaces keep rendering the previous occupant's.
     *
     * In development that previous occupant is the local field session
     * installed at boot, which is how signing in as a real staff member left
     * the fixture's event and department in the navigation until the page was
     * reloaded. The same hole swallows a real user switching to a second
     * account on a shared device.
     */
    clearClientSession();
    /*
     * And everything the previous occupant's context had populated, on the same
     * registry a sign-out and a context switch run. Their department selection,
     * their branding, the Logistics Desk index their device stored: none of it
     * belongs to the person who just signed in. Unsent work survives, because
     * this device is the only copy of it (technical spec 13.3).
     */
    discardSessionContextData();

    // The token says who this is; the session says what they may do, and every
    // surface reads the session (CLIENT-004). Signing in without resolving it
    // would leave a signed-in user looking at an empty shell.
    await refreshClientSession();

    return "signed_in";
  } catch (error) {
    if (error instanceof DeviceIdentityError) {
      state.error = error.message;

      return "device_unavailable";
    }

    return refusalOutcome(error, "signed nobody in");
  } finally {
    state.submitting = false;
  }
}

/**
 * Dispose of the token and everything the session put on this device.
 *
 * The node is told first, best effort: revocation is what makes the token stop
 * working on its next use rather than at its expiry. A node that cannot be
 * reached does not keep somebody signed in — the local disposal runs regardless,
 * which is what somebody handing their phone over is entitled to.
 */
export async function signOut(): Promise<void> {
  if (state.status === "signed_in") {
    try {
      await meridianJson<unknown>("/api/auth/session", { method: "DELETE" });
    } catch {
      // Reported nowhere on purpose: see above.
    }
  }

  clearApiToken();
  clearClientSession();
  discardSessionContextData();
  /*
   * The verdicts go with the person, the unsent work stays with the device.
   *
   * A rejected command is the node's sentence about something the person who
   * just signed out did — "Refused by the node: Field Report event does not
   * exist" — and it was outliving them: sign out, sign in as somebody else, and
   * the same refusal was still on screen, attached to nothing the new user had
   * done and impossible for them to act on. Queued work survives, because it is
   * unsent and this device is the only copy of it (technical spec 13.3).
   */
  discardSettledCommands();
  signedOutState(null);
}

/** Abandon a code that was requested and go back to the address screen. */
export function cancelLoginCode(): void {
  state.status = state.status === "code_sent" ? "signed_out" : state.status;
  state.codeExpiresInMinutes = null;
  state.error = null;
}

/** Reset module state between tests. */
export function resetApiLoginForTests(): void {
  state.email = null;
  state.codeExpiresInMinutes = null;
  signedOutState(null);
  adoptHeldApiToken();
}

function signedOutState(error: string | null): void {
  state.status = "signed_out";
  state.user = null;
  state.expiresAt = null;
  state.requesting = false;
  state.submitting = false;
  state.error = error;
}

/**
 * A refusal the node made, told apart from a node that never answered.
 *
 * The node's own message is used when there is one. It is the message written
 * for this refusal — a spent code, a revoked device, too many attempts — and
 * replacing it with something generic would drop the only explanation the person
 * is going to get.
 */
function refusalOutcome(
  error: unknown,
  suffix: string,
): LoginCodeRequestOutcome & LoginCodeSubmitOutcome {
  if (error instanceof MeridianApiError) {
    state.error = error.message;

    return "refused";
  }

  state.error = `This device could not reach the node, so it ${suffix}. Try again when you have a connection.`;

  return "unreachable";
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

function readExpiryMinutes(value: unknown): number | null {
  if (!isRecord(value)) {
    return null;
  }

  const minutes = value.expires_in_minutes;

  return typeof minutes === "number" && Number.isFinite(minutes) ? minutes : null;
}

/**
 * Read an issuance response.
 *
 * The token and the user are both required. A response missing either is not a
 * sign-in this client can complete: a token with nobody attached would leave the
 * shell unable to say who is signed in, and a user with no token would leave it
 * saying somebody is when nothing can authenticate.
 */
function readIssuedToken(value: unknown): {
  readonly token: string;
  readonly user: ApiTokenUser;
  readonly deviceId: string | null;
  readonly expiresAt: string | null;
} | null {
  if (!isRecord(value)) {
    return null;
  }

  const token = asString(value.token);
  const user = isRecord(value.user) ? value.user : null;
  const userId = user === null ? null : asString(user.id);

  if (token === null || user === null || userId === null) {
    return null;
  }

  const device = isRecord(value.device) ? value.device : null;

  return {
    token,
    user: {
      id: userId,
      name: asString(user.name) ?? "",
      email: asString(user.email) ?? "",
    },
    deviceId: device === null ? null : asString(device.id),
    expiresAt: asString(value.expires_at),
  };
}
