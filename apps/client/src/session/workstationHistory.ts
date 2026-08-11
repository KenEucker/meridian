// Where this login has signed in at a shared workstation (M18.71; AUTH-030;
// technical spec 13.3; UI contract 12.3).
//
// One read, and it is about the caller and nobody else — the request names no
// subject, so there is no version of this that could ask about somebody else's
// history. It answers two questions a person actually has: am I still signed in
// at a machine I walked away from, and was that me.
//
// Cached like every other read surface (technical spec 9.3), so somebody who
// has lost signal still sees what this device last knew rather than an empty
// list. An empty list and an unanswered read are different facts and the
// surface says which it has.

import { meridianCachedJson } from "@/api/meridianApi";
import type { ReadFreshness } from "@/offline/readFreshness";

/**
 * How a workstation session ended, as the node records it (technical spec
 * 13.3).
 *
 * Null while it has not ended, which includes a session that timed out without
 * anybody observing it — `active` is the field that answers whether it is live,
 * because a workstation whose user walked away is over five minutes later even
 * though no request arrived to say so.
 */
export type WorkstationSessionEnd =
  | "signed_out"
  | "timed_out"
  | "superseded"
  | null;

export interface WorkstationSession {
  readonly id: string;
  /** Null when the workstation record it named is gone. */
  readonly workstationName: string | null;
  readonly eventName: string | null;
  readonly startedAt: string | null;
  readonly lastActivityAt: string | null;
  readonly endedAt: string | null;
  readonly endedReason: WorkstationSessionEnd;
  /** The node's answer, never this client's arithmetic (CLIENT-006). */
  readonly active: boolean;
}

export interface WorkstationHistoryRead {
  readonly freshness: ReadFreshness;
  readonly sessions: readonly WorkstationSession[];
}

interface WorkstationSessionPayload {
  readonly id?: unknown;
  readonly workstation_name?: unknown;
  readonly event_name?: unknown;
  readonly started_at?: unknown;
  readonly last_activity_at?: unknown;
  readonly ended_at?: unknown;
  readonly ended_reason?: unknown;
  readonly active?: unknown;
}

interface WorkstationHistoryPayload {
  readonly sessions?: readonly WorkstationSessionPayload[];
}

function optionalText(value: unknown): string | null {
  return typeof value === "string" && value !== "" ? value : null;
}

function toSession(payload: WorkstationSessionPayload): WorkstationSession {
  const reason = optionalText(payload.ended_reason);

  return {
    id: typeof payload.id === "string" ? payload.id : "",
    workstationName: optionalText(payload.workstation_name),
    eventName: optionalText(payload.event_name),
    startedAt: optionalText(payload.started_at),
    lastActivityAt: optionalText(payload.last_activity_at),
    endedAt: optionalText(payload.ended_at),
    endedReason:
      reason === "signed_out" || reason === "timed_out" || reason === "superseded"
        ? reason
        : null,
    active: payload.active === true,
  };
}

export async function getMyWorkstationSessions(): Promise<WorkstationHistoryRead> {
  const read = await meridianCachedJson<WorkstationHistoryPayload>(
    "/api/me/workstation-sessions",
  );

  return {
    freshness: read.freshness,
    sessions: (read.data.sessions ?? []).map(toSession),
  };
}

/**
 * How a finished session is described in one phrase.
 *
 * "Timed out" rather than "ended" for the five-minute case, because those are
 * different things to the person reading: one is them walking away and the
 * other is them signing out, and somebody auditing their own history is looking
 * for exactly that difference.
 */
export function workstationSessionOutcome(session: WorkstationSession): string {
  if (session.active) {
    return "Signed in now";
  }

  switch (session.endedReason) {
    case "signed_out":
      return "Signed out";
    case "timed_out":
      return "Timed out";
    case "superseded":
      return "Replaced by a later sign-in";
    default:
      // No recorded end and not live: the five-minute timeout passed with
      // nothing arriving to write it down. Saying "ended" would claim a moment
      // nobody observed.
      return "Ended";
  }
}
