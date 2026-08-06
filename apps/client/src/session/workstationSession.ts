// The session a shared-workstation login code produces (M16.9; AUTH-030;
// technical spec 13.3; kiosk guide 4.4).
//
// A shared workstation is the one place in Meridian where the person in front of
// the machine is not the machine's owner. Everything here follows from that:
//
//  1. **No token.** A code entry issues no personal device token and trusts no
//     personal device (AUTH-030). What it produces is a session key, sent in its
//     own header, registered here as a credential source so `meridianApi` can
//     carry it without this module and that one importing each other.
//  2. **Held in memory only.** The key lives in a module-local binding. It is
//     never in the reactive state a devtool can read, never in `localStorage`,
//     never in `sessionStorage`. "If the Electron app restarts, the shared
//     workstation session locks immediately" is therefore a property of where the
//     key is kept rather than a rule someone has to remember to apply on boot.
//  3. **The node owns the deadline.** The five minutes are the server's, arriving
//     as `expires_at` on every session read. This module counts down against that
//     timestamp and hardcodes no duration, so there is one inactivity rule and it
//     is the enforced one.
//  4. **Ending wipes session data, not queued work.** Ending clears the client
//     session and every context-scoped cache, which abandons unsaved form state.
//     Field Reports already queued are not session data — they are the only copy
//     of work somebody did — so they stay in the outbox and sync later.
//
// What this module does not do is decide which kiosk screens exist. It publishes
// who is signed in and whether the session is live; the router and the Kiosk
// shell read that.

import { reactive, readonly } from "vue";

import {
  MeridianApiError,
  meridianJson,
  registerMeridianCredentialSource,
} from "@/api/meridianApi";
import { discardSettledCommands } from "@/outbox/commandOutboxRuntime";
import { clearClientSession, refreshClientSession } from "@/session/clientSession";
import { discardSessionContextData } from "@/session/sessionContext";

/** The header the node reads a shared-workstation session key from. */
const SESSION_KEY_HEADER = "X-Meridian-Workstation-Session";

/**
 * How long before the deadline the workstation says something.
 *
 * The kiosk guide asks for a warning and a way to continue, because "a timeout
 * that lands mid-sentence, in a field, at night, is how people stop trusting the
 * workstation".
 */
const WARNING_LEAD_MS = 60_000;

/**
 * The shortest gap between two activity-driven session reads.
 *
 * Without it, a person typing would put a request on the wire per keystroke. It
 * cannot cause a false timeout: an activity inside the warning window slides the
 * window regardless of when the last read went out.
 */
const ACTIVITY_TOUCH_INTERVAL_MS = 30_000;

export type WorkstationSessionStatus = "locked" | "active";

/** Why the session this workstation last held is over. */
export type WorkstationSessionEndReason = "signed_out" | "timed_out";

export type WorkstationLoginOutcome =
  /** The code was accepted and a session is live. */
  | "signed_in"
  /** The node reached and refused: wrong code, spent, revoked, or throttled. */
  | "refused"
  /** The node could not be reached, or answered something unusable. */
  | "unreachable"
  /** A session is already live. It has to be ended before another may start. */
  | "session_active";

export type WorkstationReauthOutcome =
  /** The node accepted the code for the signed-in user. */
  | "confirmed"
  /** The node refused: wrong code, spent, or somebody else's. */
  | "refused"
  /** The node could not be reached, or answered something unusable. */
  | "unreachable"
  /** There is no live session to confirm. */
  | "no_session";

export interface WorkstationSessionUser {
  readonly id: string;
  readonly name: string;
}

/**
 * The workstation the session is pinned to.
 *
 * It frames the Kiosk shell and grants the active user nothing (technical spec
 * 13.3), so nothing here is ever consulted to decide whether an action is
 * allowed.
 */
export interface PinnedWorkstation {
  readonly id: string;
  readonly name: string;
  readonly organizationId: string | null;
  readonly departmentId: string | null;
}

interface WorkstationSessionState {
  status: WorkstationSessionStatus;
  /** Shown prominently while a session is live (technical spec 13.3). */
  user: WorkstationSessionUser | null;
  workstation: PinnedWorkstation | null;
  eventId: string | null;
  startedAt: string | null;
  /** The node's inactivity deadline for the live session. */
  expiresAt: string | null;
  /** Whether the deadline is close enough to warn about. */
  expiring: boolean;
  /**
   * When the active user last re-confirmed who they are, or null (M18.32).
   *
   * The node's timestamp, reported rather than interpreted: whether a
   * confirmation is recent enough is the question of whichever action asked for
   * one.
   */
  reauthenticatedAt: string | null;
  endedReason: WorkstationSessionEndReason | null;
  entering: boolean;
  /** What to tell the person whose code was not accepted. */
  entryError: string | null;
}

/**
 * The session key, deliberately outside the reactive state.
 *
 * Nothing renders it, nothing serializes it, and no store persists it.
 */
let sessionKey: string | null = null;

let lastTouchAt: number | null = null;

const state = reactive<WorkstationSessionState>({
  status: "locked",
  user: null,
  workstation: null,
  eventId: null,
  startedAt: null,
  expiresAt: null,
  expiring: false,
  reauthenticatedAt: null,
  endedReason: null,
  entering: false,
  entryError: null,
});

export const workstationSessionState = readonly(state);

/**
 * The session key as request headers, or nothing when there is no session.
 *
 * Registered with `meridianApi` at import, so a module that talks to the node
 * carries the credential without knowing this module exists. Returning an empty
 * set while locked is what keeps that registration inert on a client that is not
 * a shared workstation.
 */
export function workstationCredentialHeaders(): Readonly<Record<string, string>> {
  return sessionKey === null ? {} : { [SESSION_KEY_HEADER]: sessionKey };
}

registerMeridianCredentialSource(workstationCredentialHeaders);

interface WorkstationSessionPayload {
  readonly sessionKey: string | null;
  readonly user: WorkstationSessionUser | null;
  readonly workstation: PinnedWorkstation | null;
  readonly eventId: string | null;
  readonly startedAt: string | null;
  readonly expiresAt: string | null;
  readonly reauthenticatedAt: string | null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null && !Array.isArray(value);
}

function asString(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

/**
 * Read a session response.
 *
 * The deadline is the one required field. A response without it cannot be
 * counted down against, and treating it as a session would leave a shared
 * workstation signed in with no inactivity rule at all.
 */
function readSessionPayload(value: unknown): WorkstationSessionPayload | null {
  if (!isRecord(value) || !isRecord(value.session)) {
    return null;
  }

  const expiresAt = asString(value.session.expires_at);

  if (expiresAt === null) {
    return null;
  }

  const user = isRecord(value.user) ? value.user : null;
  const workstation = isRecord(value.shared_workstation)
    ? value.shared_workstation
    : null;

  return {
    sessionKey: asString(value.session_key),
    user:
      user === null || asString(user.id) === null
        ? null
        : {
            id: String(user.id),
            name: asString(user.name) ?? "",
          },
    workstation:
      workstation === null || asString(workstation.id) === null
        ? null
        : {
            id: String(workstation.id),
            name: asString(workstation.name) ?? "",
            organizationId: asString(workstation.organization_id),
            departmentId: asString(workstation.department_id),
          },
    eventId: asString(value.event_id),
    startedAt: asString(value.session.started_at),
    expiresAt,
    reauthenticatedAt: asString(value.session.reauthenticated_at),
  };
}

function install(payload: WorkstationSessionPayload): void {
  state.status = "active";
  state.user = payload.user;
  state.workstation = payload.workstation;
  state.eventId = payload.eventId;
  state.startedAt = payload.startedAt;
  state.expiresAt = payload.expiresAt;
  state.expiring = false;
  state.reauthenticatedAt = payload.reauthenticatedAt;
  state.endedReason = null;
  state.entryError = null;
}

/**
 * Enter a login code and establish the session it produces.
 *
 * Refused outright while a session is live. Technical spec 13.3 requires the
 * current user to end their session before another signs in, and the refusal
 * belongs here rather than on the node: a node that refused would leave a
 * workstation whose Electron process crashed mid-session unusable until the
 * abandoned session timed out.
 *
 * The client session is refreshed straight afterwards, at the event the session
 * is pinned to, because permissions come entirely from the active user and the
 * session key is what authenticates the request that resolves them.
 */
export async function enterWorkstationLoginCode(input: {
  readonly sharedWorkstationId: string;
  readonly code: string;
}): Promise<WorkstationLoginOutcome> {
  if (state.status === "active") {
    return "session_active";
  }

  state.entering = true;
  state.entryError = null;

  try {
    const payload = readSessionPayload(
      await meridianJson<unknown>("/api/auth/shared-workstation-session", {
        method: "POST",
        body: JSON.stringify({
          shared_workstation_id: input.sharedWorkstationId,
          code: input.code.trim(),
        }),
      }),
    );

    if (payload === null || payload.sessionKey === null) {
      state.entryError = "This workstation could not start a session.";

      return "unreachable";
    }

    sessionKey = payload.sessionKey;
    lastTouchAt = null;
    install(payload);

    // Not persisted. A session document on disk would come back on the next boot
    // and put this user's context on the screen of whoever restarts the machine,
    // which is the one thing "the session locks immediately" has to prevent.
    await refreshClientSession({ eventId: payload.eventId, persist: false });

    return "signed_in";
  } catch (error) {
    if (error instanceof MeridianApiError) {
      state.entryError = error.message;

      return "refused";
    }

    state.entryError = "This workstation could not reach the node.";

    return "unreachable";
  } finally {
    state.entering = false;
  }
}

/**
 * Confirm that the person at the keyboard is still the signed-in user (M18.32;
 * UI-017; UI contract 12.8 `kiosk.reauth`, 18.2).
 *
 * "Privileged actions may require re-authentication", and Alpha 1 has no
 * separate Meridian PIN to require — 18.2 rules one out as an independent
 * central credential. What it has is the login code, already scoped to this user
 * and this workstation and generated in seconds from the phone in their pocket
 * (AUTH-027). So a confirmation is a fresh code, checked by the node against the
 * session's own user.
 *
 * A code belonging to somebody else is refused rather than treated as a
 * handover: switching users requires ending the session first, and the surface
 * that does that is `kiosk.switch-user`.
 *
 * The verdict is the node's and is reported as it arrives. Nothing here decides
 * that a confirmation was recent enough to matter.
 */
export async function reauthenticateWorkstationSession(
  code: string,
): Promise<WorkstationReauthOutcome> {
  if (sessionKey === null || state.status !== "active") {
    return "no_session";
  }

  state.entering = true;
  state.entryError = null;

  try {
    const payload = readSessionPayload(
      await meridianJson<unknown>(
        "/api/auth/shared-workstation-session/reauthentication",
        {
          method: "POST",
          body: JSON.stringify({ code: code.trim() }),
        },
      ),
    );

    if (payload === null) {
      state.entryError = "This workstation could not confirm the session.";

      return "unreachable";
    }

    install(payload);

    return "confirmed";
  } catch (error) {
    if (error instanceof MeridianApiError) {
      state.entryError = error.message;

      return "refused";
    }

    state.entryError = "This workstation could not reach the node.";

    return "unreachable";
  } finally {
    state.entering = false;
  }
}

/**
 * Tell the node the person is still here, and take its deadline back.
 *
 * The "continue" action behind the timeout warning. Reading the session is
 * activity on the node, so answering the warning slides the window by the same
 * mechanism any other interaction would.
 */
export async function continueWorkstationSession(): Promise<void> {
  if (sessionKey === null) {
    return;
  }

  try {
    const payload = readSessionPayload(
      await meridianJson<unknown>("/api/auth/shared-workstation-session"),
    );

    if (payload === null) {
      return;
    }

    install(payload);
  } catch (error) {
    /*
     * A refusal means the node has already ended this session — it timed out
     * while the workstation was not looking, or it was superseded. The
     * workstation locks rather than keeping a dead key and a stale deadline on
     * screen. Anything else is an unreachable node, which is not a reason to
     * sign somebody out mid-shift.
     */
    if (error instanceof MeridianApiError) {
      wipe("timed_out");
    }
  }
}

/**
 * Record that somebody interacted with the workstation.
 *
 * Rate limited, except inside the warning window where every interaction is
 * worth a request: that is the window in which losing one would time out a person
 * who is demonstrably still there.
 *
 * The rate limit is kept against the clock it is passed rather than against
 * `Date.now()`, so it measures the same time the caller is ticking on.
 */
export function recordWorkstationActivity(now: Date = new Date()): void {
  if (state.status !== "active") {
    return;
  }

  const elapsed = lastTouchAt === null ? Infinity : now.getTime() - lastTouchAt;

  if (!state.expiring && elapsed < ACTIVITY_TOUCH_INTERVAL_MS) {
    return;
  }

  lastTouchAt = now.getTime();

  void continueWorkstationSession();
}

/**
 * Decide, against the node's deadline, whether to warn or to lock.
 *
 * Driven by whatever is ticking rather than by a timer this module owns, so the
 * verdict is a function of the clock the caller passes and can be tested without
 * waiting five minutes.
 */
export function evaluateWorkstationSession(now: Date = new Date()): void {
  if (state.status !== "active" || state.expiresAt === null) {
    return;
  }

  const remaining = Date.parse(state.expiresAt) - now.getTime();

  if (Number.isNaN(remaining)) {
    return;
  }

  if (remaining <= 0) {
    wipe("timed_out");

    return;
  }

  state.expiring = remaining <= WARNING_LEAD_MS;
}

/**
 * End the session and wipe what it put on the workstation.
 *
 * The node is told first, best effort, so the session is closed there even
 * though its deadline would close it anyway; a workstation that is signed out
 * should be signed out on both sides immediately. A node that cannot be reached
 * does not keep somebody signed in — the local wipe runs regardless, which is
 * the behavior somebody walking away from a shared machine is entitled to.
 *
 * A timeout skips the request. The node stamps a timed-out session at the moment
 * it expired, whenever it next looks, so there is nothing for the workstation to
 * report.
 */
export async function endWorkstationSession(
  reason: WorkstationSessionEndReason = "signed_out",
): Promise<void> {
  if (sessionKey !== null && reason === "signed_out") {
    try {
      await meridianJson<unknown>("/api/auth/shared-workstation-session", {
        method: "DELETE",
      });
    } catch {
      // Reported nowhere on purpose: see above.
    }
  }

  wipe(reason);
}

/**
 * Drop the session and everything it made visible.
 *
 * `discardSessionContextData` runs the same registry an event switch runs, which
 * is what keeps queued Field Reports: that registry's Field Report reset keeps
 * anything still pending sync whatever context it belongs to, because unsent work
 * is not context-scoped data (technical spec 13.3; kiosk guide 4.4).
 *
 * Settled commands are the exception, and go for the reason the queued ones
 * stay. A rejection is the node's verdict on what the person who was standing
 * here did, and a shared workstation is precisely the machine where the next
 * person to stand at it is somebody else.
 */
function wipe(reason: WorkstationSessionEndReason): void {
  lock(reason);

  clearClientSession();
  discardSessionContextData();
  discardSettledCommands();
}

function lock(reason: WorkstationSessionEndReason | null): void {
  sessionKey = null;
  lastTouchAt = null;

  state.status = "locked";
  state.user = null;
  state.workstation = null;
  state.eventId = null;
  state.startedAt = null;
  state.expiresAt = null;
  state.expiring = false;
  state.reauthenticatedAt = null;
  state.endedReason = reason;
  state.entering = false;
  state.entryError = null;
}

/** Reset module state between tests. */
export function resetWorkstationSession(): void {
  lock(null);
}
