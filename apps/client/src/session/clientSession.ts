// The session a client application is operating under (M16.5; CLIENT-007
// through CLIENT-010; technical spec 11A.4; UI implementation contract 19A.2).
//
// Four behaviors live here and they belong together because each one is the
// reason the next is safe:
//
//  1. **Boot.** The durable copy is installed synchronously before any network
//     call, so a device that has been here before comes up knowing what its user
//     may do rather than blank (CLIENT-007). A device that has never been here
//     comes up holding nothing, which is what login is for.
//  2. **Staleness.** A cached document grants access only while the event window
//     it was cached for is open (CLIENT-008). Re-evaluated on every refresh
//     attempt, not only at boot, so an application left running past the end of
//     its event loses access where it stands instead of at its next restart.
//  3. **Disclosure.** The state says whether permissions are cached and when the
//     node last answered, which is what the surfaces read (CLIENT-009).
//  4. **Reduction.** A successful refresh replaces the document outright, in
//     memory and on disk. There is no merge anywhere in this module, because a
//     merge is precisely how a capability that was taken away survives
//     (CLIENT-010).
//
// What this module does not do is decide which screens exist. It publishes the
// codes and the access verdict; `sessionAccess` scopes them to a department and
// `workflowLinks` turns them into navigation (M16.6). Nothing here interprets a
// capability code or maps one to a surface.

import { computed, reactive, readonly } from "vue";

import { MeridianApiError, meridianJson } from "@/api/meridianApi";
import type { ConnectivityState } from "@/offline/syncStatus";
import {
  clearCachedSession,
  readCachedSession,
  writeCachedSession,
} from "@/session/sessionCache";
import { isSessionDocument, type SessionDocument } from "@/session/sessionDocument";
import {
  evaluateSessionDocument,
  type SessionRefreshReason,
} from "@/session/sessionStaleness";

/**
 * Where the client's permissions came from, and whether they may be used.
 *
 * `expired` is a document the client holds and will not act on. It is kept
 * rather than discarded so the surface can name the event whose window ended and
 * offer a refresh, instead of presenting the same blank state as a device that
 * has never signed in.
 */
export type ClientSessionStatus = "unresolved" | "live" | "cached" | "expired";

export type SessionRefreshOutcome =
  /** The node answered and its answer replaced whatever was held. */
  | "refreshed"
  /** The node could not be reached. A usable cached session stands. */
  | "unreachable"
  /** The node refused the credential. The session and its cache are dropped. */
  | "unauthenticated"
  /** The node answered with something this client cannot establish a session from. */
  | "unusable"
  /** There was no session to refresh. */
  | "skipped";

interface ClientSessionState {
  document: SessionDocument | null;
  status: ClientSessionStatus;
  /** Why access is refused, when it is. */
  refreshReason: SessionRefreshReason | null;
  /** The window end the verdict was decided against, when there is one. */
  windowEndsAt: string | null;
  /** Server time the held document was resolved at (CLIENT-009). */
  refreshedAt: string | null;
  /** Device time of the last refresh that did not reach the node. */
  refreshFailedAt: string | null;
  refreshing: boolean;
}

const state = reactive<ClientSessionState>({
  document: null,
  status: "unresolved",
  refreshReason: null,
  windowEndsAt: null,
  refreshedAt: null,
  refreshFailedAt: null,
  refreshing: false,
});

export const clientSessionState = readonly(state);

/**
 * Whether the client may act on the permissions it holds.
 *
 * Presentation only, in both directions. A false answer must not be read as
 * "the server would refuse this" and a true answer must not be read as
 * authority: every endpoint enforces its own authorization regardless of what
 * the client rendered (technical spec 11A.2).
 */
export const sessionAccessGranted = computed(
  () => state.status === "live" || state.status === "cached",
);

/** Whether the held permissions came from cache rather than from the node. */
export const sessionIsCached = computed(() => state.status === "cached");

/**
 * Every capability code the session carries, or none while access is refused.
 *
 * Returning nothing for a refused session is what makes "require a successful
 * refresh before granting access" (CLIENT-008) true of the read path rather than
 * only of a banner. A surface that forgets to check the status still finds no
 * capability to render against.
 */
export function sessionCapabilities(): readonly string[] {
  if (!sessionAccessGranted.value || state.document === null) {
    return [];
  }

  return state.document.capabilities;
}

export function sessionHasCapability(code: string): boolean {
  return sessionCapabilities().includes(code);
}

/**
 * Install a document and derive the access verdict from it.
 *
 * A document straight from the node is live and grants access: the node has just
 * answered, so there is no staleness to bound. The event-window rule bounds the
 * *cached* copy, which is the copy that can outlive the answer it came from.
 */
function install(
  document: SessionDocument,
  source: "network" | "cache",
  now: Date,
): void {
  state.document = document;
  state.refreshedAt = document.refreshed_at;

  if (source === "network") {
    state.status = "live";
    state.refreshReason = null;
    state.windowEndsAt = null;

    return;
  }

  const verdict = evaluateSessionDocument(document, now);

  state.status = verdict.access === "granted" ? "cached" : "expired";
  state.refreshReason = verdict.reason;
  state.windowEndsAt = verdict.windowEndsAt;
}

/**
 * Re-decide whether the held cached document still grants access.
 *
 * Called when a refresh fails, because the reason the refresh failed is usually
 * that the device has been offline for a while, and "a while" is how an event
 * window ends underneath a running application.
 */
function reevaluate(now: Date): void {
  if (state.document === null || state.status === "live") {
    return;
  }

  install(state.document, "cache", now);
}

/**
 * What to do when the node refuses the credential this session was resolved
 * with (M16.11).
 *
 * A registration rather than an import, because the credential belongs to
 * `apiLogin` and the session belongs here. A refused token is not this module's
 * to dispose of, but it is this module that finds out — the refusal arrives as
 * the answer to a refresh — and a client that dropped the session while keeping
 * the dead token would sit there re-sending it.
 */
type SessionCredentialRefusedListener = () => void;

let credentialRefused: SessionCredentialRefusedListener | null = null;

export function registerSessionCredentialRefusedListener(
  listener: SessionCredentialRefusedListener | null,
): void {
  credentialRefused = listener;
}

/** Drop the session and its durable copy. */
export function clearClientSession(): void {
  clearCachedSession();

  state.document = null;
  state.status = "unresolved";
  state.refreshReason = null;
  state.windowEndsAt = null;
  state.refreshedAt = null;
  state.refreshFailedAt = null;
  state.refreshing = false;
}

/**
 * Install the durable copy without touching the network.
 *
 * Returns whether a document was installed, so a caller can tell "nothing
 * cached" from "cached but no longer usable" without inspecting the state.
 */
export function bootClientSessionFromCache(now: Date = new Date()): boolean {
  const cached = readCachedSession();

  if (cached === null) {
    return false;
  }

  install(cached.document, "cache", now);

  return true;
}

/**
 * Ask the node who this user is and what they may do.
 *
 * The event context is named on the request when the client has one, so roles
 * resolve at the event being worked rather than at whichever one the node would
 * pick. Held context is reused when the caller names none, which is what keeps a
 * reconnect refresh from silently moving a device to a different event.
 */
export async function refreshClientSession(
  options: {
    readonly eventId?: string | null;
    readonly now?: Date;
    /**
     * Whether the answer may be kept for the next boot. Default yes.
     *
     * A shared workstation says no (M16.9). Its session must lock the moment the
     * application restarts (technical spec 13.3), and a document on disk is
     * exactly how the user who was signed in five minutes ago comes back on the
     * screen for whoever restarts the machine.
     */
    readonly persist?: boolean;
  } = {},
): Promise<SessionRefreshOutcome> {
  const now = options.now ?? new Date();
  const eventId =
    options.eventId ?? state.document?.context.event_id ?? null;
  const path = eventId === null
    ? "/api/me"
    : `/api/me?event_id=${encodeURIComponent(eventId)}`;

  state.refreshing = true;

  try {
    const payload = await meridianJson<unknown>(path);

    if (!isSessionDocument(payload)) {
      state.refreshFailedAt = now.toISOString();
      reevaluate(now);

      return "unusable";
    }

    // Replacement, not merge: this is where a removed capability is removed
    // (CLIENT-010). The durable copy is replaced in the same step so a restart
    // cannot resurrect the authority this answer just took away.
    install(payload, "network", now);

    if (options.persist ?? true) {
      writeCachedSession(payload, now.toISOString());
    }

    state.refreshFailedAt = null;

    return "refreshed";
  } catch (error) {
    state.refreshFailedAt = now.toISOString();

    /*
     * A refused credential is not an unreachable node, and treating it as one
     * would be the hole this cache could open. The node was reached and it said
     * this token is no good — a revoked token or a revoked device is exactly that
     * answer (AUTH-023) — so continuing to work from permissions it once issued
     * would keep a withdrawn credential alive on the device. It is the largest
     * possible reduction, applied the same way as any other: immediately.
     */
    if (
      error instanceof MeridianApiError &&
      (error.status === 401 || error.status === 403)
    ) {
      clearClientSession();
      credentialRefused?.();

      return "unauthenticated";
    }

    reevaluate(now);

    return "unreachable";
  } finally {
    state.refreshing = false;
  }
}

/**
 * Establish the session at startup: cached copy first, then the node.
 *
 * Deliberately not blocking on the network anywhere. The cached document is
 * installed synchronously, so a device out of coverage is usable the moment it
 * renders, and the node's answer replaces it when one arrives.
 */
export async function loadClientSession(
  options: { readonly eventId?: string | null; readonly now?: Date } = {},
): Promise<SessionRefreshOutcome> {
  bootClientSessionFromCache(options.now ?? new Date());

  return refreshClientSession(options);
}

/**
 * Refresh on regaining connectivity (CLIENT-010).
 *
 * Only on the transition into `online`: a device that is already online has
 * nothing to regain, and asking on every connectivity event would put a request
 * on the wire each time a phone changes access points.
 *
 * A client with no session skips. There is nothing to reduce and no credential to
 * refresh, and a client that has not signed in must not be given a reason to
 * call `/api/me` on every network change.
 */
export async function refreshClientSessionOnReconnect(
  connectivity: ConnectivityState,
  previous: ConnectivityState | undefined,
): Promise<SessionRefreshOutcome> {
  if (
    connectivity !== "online" ||
    previous === undefined ||
    previous === "online"
  ) {
    return "skipped";
  }

  if (state.document === null) {
    return "skipped";
  }

  return refreshClientSession();
}

/**
 * The shortest gap between two attention-driven refreshes.
 *
 * A person switching between applications generates focus events in bursts, and
 * a request per burst is a request per glance.
 */
const FOCUS_REFRESH_INTERVAL_MS = 60_000;

let lastFocusRefreshAt: number | null = null;

/**
 * Refresh when somebody comes back to the application (AUTH-023, CLIENT-010).
 *
 * Revocation is evaluated on the node at request time, which makes a revoked
 * token stop working immediately — but only tells a client that is making
 * requests. An application left open makes none, so an operator who revokes a
 * token would see nothing change on the device until its next reload. Returning
 * to the screen is the moment that matters: it is when somebody is about to act
 * on what it says, and it is cheap to check.
 *
 * Rate limited, and skipped entirely for a client holding no session — there is
 * nothing to reduce, and a signed-out client must not be given a reason to call
 * `/api/me` every time a window is focused.
 */
export async function refreshClientSessionOnFocus(
  now: Date = new Date(),
): Promise<SessionRefreshOutcome> {
  if (state.document === null || state.refreshing) {
    return "skipped";
  }

  const elapsed =
    lastFocusRefreshAt === null ? Infinity : now.getTime() - lastFocusRefreshAt;

  if (elapsed < FOCUS_REFRESH_INTERVAL_MS) {
    return "skipped";
  }

  lastFocusRefreshAt = now.getTime();

  return refreshClientSession({ now });
}

/** Reset the focus rate limit between tests. */
export function resetFocusRefreshThrottle(): void {
  lastFocusRefreshAt = null;
}

/**
 * Install a document without touching the network or the cache.
 *
 * The seam the specs establish a session through, and the one the local
 * development session document is installed through (M16.6). Deliberately not
 * writing the durable copy: nothing that did not come from the node belongs in
 * the cache the client boots from.
 */
export function installClientSession(
  document: SessionDocument,
  source: "network" | "cache" = "network",
  now: Date = new Date(),
): void {
  install(document, source, now);
}
