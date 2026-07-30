// Durable local persistence for the session document (M16.5; CLIENT-007;
// technical spec 11A.4).
//
// A client that cannot reach its node boots from this. Without it a device that
// was restarted out of coverage has no permissions at all and no way to get any,
// which on an event site means a shift lead holding a phone that will not open
// the board until the network comes back.
//
// One entry, not one per user. The read happens before the client knows who is
// signing in — that is the point of it, since a cold boot with no network cannot
// ask the node whose device this is — so a per-user key would need an index of
// users to look the key up by, and the index would be the single entry with
// extra steps. CLIENT-007 asks for "the most recent response", and the most
// recent response is one document. A device shared between two users therefore
// keeps whichever session refreshed last; the document names its own user, so a
// client can always tell whose it is holding, and {@link clearCachedSession} is
// how sign-out and user switching drop it.
//
// `localStorage` is the same device-local seam the Field Report catalog and the
// attendance outbox use. Encrypted local storage is a later Alpha 1 task and
// this moves onto it with them. What is stored here is what the node already
// told this client about itself: role codes, capability codes, and the names of
// its own organizations, events, departments, and teams. No token, no
// credential, and nothing about another user.

import { isSessionDocument, type SessionDocument } from "@/session/sessionDocument";

export const SESSION_CACHE_KEY = "meridian.session.v1";

export interface CachedSession {
  readonly document: SessionDocument;
  /** When this client wrote the copy, by the device's clock. */
  readonly cachedAt: string;
}

interface StoredSessionState {
  readonly version: 1;
  readonly document: SessionDocument;
  readonly cachedAt: string;
}

export interface SessionCache {
  readonly read: () => CachedSession | null;
  readonly write: (document: SessionDocument, cachedAt: string) => void;
  readonly clear: () => void;
}

function readStorage(): Storage | null {
  try {
    return globalThis.localStorage ?? null;
  } catch {
    return null;
  }
}

/**
 * Create a `localStorage`-backed cache, falling back to memory when storage is
 * unavailable.
 *
 * The fallback is not durability and does not pretend to be: a device in private
 * browsing or out of quota keeps its session for the life of the page and loses
 * it on restart. It is still better than failing the write, because the
 * alternative is a client that works until it is reloaded and cannot say why.
 */
export function createSessionCache(
  storage: Storage | null = readStorage(),
): SessionCache {
  let memoryFallback: StoredSessionState | null = null;

  function coerce(state: StoredSessionState | null): CachedSession | null {
    if (
      state === null ||
      state.version !== 1 ||
      typeof state.cachedAt !== "string" ||
      !isSessionDocument(state.document)
    ) {
      return null;
    }

    return Object.freeze({
      document: state.document,
      cachedAt: state.cachedAt,
    });
  }

  return {
    read(): CachedSession | null {
      if (!storage) {
        return coerce(memoryFallback);
      }

      try {
        const raw = storage.getItem(SESSION_CACHE_KEY);

        if (!raw) {
          return null;
        }

        return coerce(JSON.parse(raw) as StoredSessionState);
      } catch {
        // A corrupt entry leaves the client where a device that has never been
        // online is: it refreshes, or it waits. It does not boot from a
        // half-document.
        return null;
      }
    },

    write(document: SessionDocument, cachedAt: string): void {
      const payload: StoredSessionState = { version: 1, document, cachedAt };

      if (!storage) {
        memoryFallback = payload;
        return;
      }

      try {
        storage.setItem(SESSION_CACHE_KEY, JSON.stringify(payload));
      } catch {
        memoryFallback = payload;
      }
    },

    clear(): void {
      memoryFallback = null;

      if (!storage) {
        return;
      }

      try {
        storage.removeItem(SESSION_CACHE_KEY);
      } catch {
        // Nothing to recover: the in-memory copy is already gone.
      }
    },
  };
}

export const sessionCache = createSessionCache();

/** The most recent session document this device stored, if it still validates. */
export function readCachedSession(): CachedSession | null {
  return sessionCache.read();
}

/**
 * Replace the stored document.
 *
 * A replacement, never a merge. Merging is how a capability that was taken away
 * survives a refresh (CLIENT-010), so there is deliberately no code path here
 * that combines an incoming document with the one on disk.
 */
export function writeCachedSession(
  document: SessionDocument,
  cachedAt: string = new Date().toISOString(),
): void {
  sessionCache.write(document, cachedAt);
}

/** Forget the stored session. Sign-out and user switching call this. */
export function clearCachedSession(): void {
  sessionCache.clear();
}
