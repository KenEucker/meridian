// The bearer token this client holds (M16.11; AUTH-018, AUTH-021, AUTH-024;
// technical spec 11.4; data/API 5.4).
//
// One credential, one place. Until this task the client read a shared token out
// of its build environment, which meant every install of Meridian Field carried
// the same credential and authenticated as the same seeded user. What it holds
// now is a token issued to the person who signed in, bound to this device, with
// an expiry the node decided.
//
// Durable, because the alternative is asking a staff member to sign in again
// every time the application is reopened — on a phone, at an event, frequently
// without coverage. It lives beside the session document cache (`sessionCache`)
// and moves onto encrypted local storage with it in the later Alpha 1 task.
//
// Two rules keep it honest:
//
//  1. **Nothing renders it.** The token is not in reactive state. What is
//     reactive is whether one is held, who it belongs to, and when it expires —
//     which is all any surface has ever needed.
//  2. **An expired token is not a held one.** A token whose stamped expiry has
//     passed is dropped on read rather than sent. The node would refuse it
//     anyway; refusing to send it keeps the client's own account of whether it
//     is signed in true.

import { registerMeridianBearerTokenSource } from "@/api/meridianApi";

export const API_TOKEN_STORAGE_KEY = "meridian.api-token.v1";

/** Who a held token belongs to, as the issuing response reported it. */
export interface ApiTokenUser {
  readonly id: string;
  readonly name: string;
  readonly email: string;
}

export interface HeldApiToken {
  readonly user: ApiTokenUser;
  /** The device the node bound the token to (AUTH-021). */
  readonly deviceId: string | null;
  /** When the node says the token stops working, when it said. */
  readonly expiresAt: string | null;
}

interface StoredApiToken {
  readonly version: 1;
  readonly token: string;
  readonly user: ApiTokenUser;
  readonly deviceId: string | null;
  readonly expiresAt: string | null;
}

let held: StoredApiToken | null = null;
let loaded = false;

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

function isStoredToken(value: unknown): value is StoredApiToken {
  if (typeof value !== "object" || value === null) {
    return false;
  }

  const candidate = value as Partial<StoredApiToken>;

  return (
    candidate.version === 1 &&
    typeof candidate.token === "string" &&
    candidate.token !== "" &&
    typeof candidate.user === "object" &&
    candidate.user !== null &&
    typeof candidate.user.id === "string"
  );
}

function load(): StoredApiToken | null {
  if (loaded) {
    return held;
  }

  loaded = true;

  const storage = readStorage();

  if (storage === null) {
    return held;
  }

  try {
    const raw = storage.getItem(API_TOKEN_STORAGE_KEY);
    const parsed = raw === null ? null : (JSON.parse(raw) as unknown);

    held = isStoredToken(parsed) ? parsed : null;
  } catch {
    // A credential that cannot be read is one this client does not hold.
    held = null;
  }

  return held;
}

function persist(): void {
  const storage = readStorage();

  if (storage === null) {
    return;
  }

  try {
    if (held === null) {
      storage.removeItem(API_TOKEN_STORAGE_KEY);
    } else {
      storage.setItem(API_TOKEN_STORAGE_KEY, JSON.stringify(held));
    }
  } catch {
    // The in-memory copy still authenticates this run.
  }
}

function expired(token: StoredApiToken, now: Date): boolean {
  if (token.expiresAt === null) {
    return false;
  }

  const expiresAt = Date.parse(token.expiresAt);

  return !Number.isNaN(expiresAt) && expiresAt <= now.getTime();
}

/**
 * The token to authenticate with, or null when this client holds none.
 *
 * Registered with `meridianApi` at import, so every request carries it without
 * any caller knowing where it came from.
 */
export function apiBearerToken(now: Date = new Date()): string | null {
  const token = load();

  if (token === null) {
    return null;
  }

  if (expired(token, now)) {
    clearApiToken();

    return null;
  }

  return token.token;
}

registerMeridianBearerTokenSource(() => apiBearerToken());

/** What is known about the held token, for the surfaces that say who is signed in. */
export function heldApiToken(now: Date = new Date()): HeldApiToken | null {
  // Through `apiBearerToken` so an expired token is dropped here too, rather
  // than reported as a sign-in that no longer works.
  if (apiBearerToken(now) === null) {
    return null;
  }

  const token = load() as StoredApiToken;

  return {
    user: token.user,
    deviceId: token.deviceId,
    expiresAt: token.expiresAt,
  };
}

/** Whether this client currently holds a usable token. */
export function holdsApiToken(now: Date = new Date()): boolean {
  return apiBearerToken(now) !== null;
}

/** Keep a freshly issued token. Replaces whatever was held. */
export function storeApiToken(issued: {
  readonly token: string;
  readonly user: ApiTokenUser;
  readonly deviceId?: string | null;
  readonly expiresAt?: string | null;
}): void {
  held = {
    version: 1,
    token: issued.token,
    user: issued.user,
    deviceId: issued.deviceId ?? null,
    expiresAt: issued.expiresAt ?? null,
  };
  loaded = true;

  persist();
}

/** Drop the token, in memory and on disk. Sign-out and refusal both call this. */
export function clearApiToken(): void {
  held = null;
  loaded = true;

  persist();
}

/** Reset module state between tests. */
export function resetApiTokenForTests(): void {
  held = null;
  loaded = false;
}
