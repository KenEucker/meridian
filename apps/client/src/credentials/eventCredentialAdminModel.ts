// The event credential administration surface's data layer (M18.5; CRED-009
// through CRED-014; CLIENT-023).
//
// `organizer.credentials` has carried the eligibility export since M16.22 and a
// note saying revocation was not available from it. This module is the other
// half: the list of an event's credentials, and the one command that revokes
// one.
//
// Two properties are load-bearing and both are about not deciding anything here:
//
//  1. **The cost of the act is the node's arithmetic, not the screen's.** A row
//     arrives carrying how many future shifts revocation would remove and how
//     many completed shifts and recorded minutes it would leave standing
//     (CRED-012, CRED-013), counted with the same rule the service applies when
//     it acts. A client that re-derived "future" from its own clock would show a
//     number the node then disagreed with, and the disagreement would surface as
//     shifts that vanished unannounced.
//  2. **Refusals are the node's sentences.** "You are not authorized to revoke
//     event credentials" and "This staff member has no event credential or shift
//     history to revoke" are its words, printed as given.
//
// Connected-only, through the outbox's registered command. There is nothing to
// queue: revocation removes shifts other people are scheduling around.

import { computed } from "vue";

import { meridianJson } from "@/api/meridianApi";
import { sendConnectedCommand } from "@/outbox/submitCommand";
import { CAPABILITY_EVENT_CREDENTIALS_REVOKE } from "@/session/permissionCodes";
import {
  selectedSessionDepartment,
  sessionEventContext,
} from "@/session/sessionAccess";

/** The event this client may administer credentials for, and as what. */
export interface CredentialAdminAuthority {
  readonly eventId: string;
  readonly eventLabel: string;
  /** The roles carrying it, named as the node named them. */
  readonly roleLabel: string;
}

/**
 * What this client may revoke, or null when it may revoke nothing.
 *
 * The same shape `reportingExportAuthority` has, resolved separately because it
 * is a different authority over the same records: a department lead exports
 * their own department's eligibility and holds nothing here, and an Incident
 * Command lead holds this and no export at all. The surface asks both and shows
 * whichever halves answer.
 *
 * The event is required rather than optional because a credential is
 * event-specific (CRED-001): with no event there is no list to ask for.
 */
export const credentialAdminAuthority = computed<CredentialAdminAuthority | null>(
  () => {
    const department = selectedSessionDepartment.value;
    const event = sessionEventContext.value;

    if (department === null || event === null) {
      return null;
    }

    const granting = department.roles.filter((role) =>
      role.capabilities.includes(CAPABILITY_EVENT_CREDENTIALS_REVOKE),
    );

    if (granting.length === 0) {
      return null;
    }

    const roleNames = [
      ...new Set(
        granting
          .map((role) => role.role_name)
          .filter(
            (name): name is string => typeof name === "string" && name !== "",
          ),
      ),
    ];

    return {
      eventId: event.eventId,
      eventLabel: event.eventLabel ?? "This event",
      roleLabel: roleNames.length > 0 ? roleNames.join(", ") : "Your role",
    };
  },
);

/** One staff member's credential standing for an event. */
export interface EventCredentialRow {
  readonly staffId: string;
  readonly legalName: string | null;
  readonly preferredName: string | null;
  readonly displayName: string;
  readonly handle: string | null;
  /** The departments this event's shifts for them belong to. */
  readonly departments: readonly string[];
  /**
   * `eligible`, `blocked`, `revoked`, or null (CRED-009).
   *
   * Null is a real state and not a synonym for Blocked: it is somebody the
   * domain has recorded nothing for yet, and the surface says so rather than
   * inventing a status the node never wrote.
   */
  readonly status: string | null;
  readonly statusReason: string | null;
  /** The reason in words, or null for one this node has no label for. */
  readonly statusReasonLabel: string | null;
  readonly revokedAt: string | null;
  /** Shifts revocation would remove, and shifts it would leave alone. */
  readonly futureShiftCount: number;
  readonly completedShiftCount: number;
  /** Recorded minutes that survive revocation (CRED-013). */
  readonly recordedMinutes: number;
  readonly canRevoke: boolean;
  readonly revokeBlockedReason: string | null;
}

export interface EventCredentialList {
  readonly eventId: string;
  readonly eventName: string | null;
  readonly credentials: readonly EventCredentialRow[];
}

interface CredentialPayload {
  readonly staff_id: string;
  readonly legal_name?: string | null;
  readonly preferred_name?: string | null;
  readonly display_name?: string | null;
  readonly handle?: string | null;
  readonly departments?: readonly string[];
  readonly status?: string | null;
  readonly status_reason?: string | null;
  readonly status_reason_label?: string | null;
  readonly revoked_at?: string | null;
  readonly future_shift_count?: number;
  readonly completed_shift_count?: number;
  readonly recorded_minutes?: number;
  readonly can_revoke?: boolean;
  readonly revoke_blocked_reason?: string | null;
}

interface CredentialListPayload {
  readonly event_id?: string;
  readonly event_name?: string | null;
  readonly credentials?: readonly CredentialPayload[];
}

function toRow(payload: CredentialPayload): EventCredentialRow {
  return {
    staffId: payload.staff_id,
    legalName: payload.legal_name ?? null,
    preferredName: payload.preferred_name ?? null,
    displayName: payload.display_name ?? payload.legal_name ?? "Unknown staff member",
    handle: payload.handle ?? null,
    departments: payload.departments ?? [],
    status: payload.status ?? null,
    statusReason: payload.status_reason ?? null,
    statusReasonLabel: payload.status_reason_label ?? null,
    revokedAt: payload.revoked_at ?? null,
    futureShiftCount: payload.future_shift_count ?? 0,
    completedShiftCount: payload.completed_shift_count ?? 0,
    recordedMinutes: payload.recorded_minutes ?? 0,
    canRevoke: payload.can_revoke ?? false,
    revokeBlockedReason: payload.revoke_blocked_reason ?? null,
  };
}

export async function listEventCredentials(
  eventId: string,
): Promise<EventCredentialList> {
  const payload = await meridianJson<CredentialListPayload>(
    `/api/events/${encodeURIComponent(eventId)}/credentials`,
  );

  return {
    eventId: payload.event_id ?? eventId,
    eventName: payload.event_name ?? null,
    credentials: (payload.credentials ?? []).map(toRow),
  };
}

/**
 * Revoke one credential (CRED-011).
 *
 * The answer is the staff member's rebuilt row, so the caller replaces what it
 * was showing rather than patching a status onto it and guessing at the rest —
 * the shift counts move too, and the completed ones and the hours are the part
 * a reader needs to see survive.
 *
 * A revocation with no reason sends none. An empty string would be recorded as
 * a reason that says nothing, which reads in an audit log like somebody typed a
 * space rather than like nobody was asked.
 */
export async function revokeEventCredential(
  eventId: string,
  staffId: string,
  reason: string | null,
): Promise<EventCredentialRow | null> {
  const trimmed = reason?.trim() ?? "";

  const result = (await sendConnectedCommand({
    commandType: "revoke-credential",
    idempotencyKey: commandIdempotencyKey(),
    payload: {
      event_id: eventId,
      staff_id: staffId,
      ...(trimmed === "" ? {} : { reason: trimmed }),
    },
  })) as { readonly credential?: CredentialPayload | null } | null;

  const credential = result?.credential;

  return credential === undefined || credential === null
    ? null
    : toRow(credential);
}

/** Recorded time in the words a person uses for it. */
export function formatRecordedHours(minutes: number): string {
  if (minutes <= 0) {
    return "None";
  }

  const wholeHours = Math.floor(minutes / 60);
  const remainder = minutes % 60;

  if (wholeHours === 0) {
    return `${remainder} min`;
  }

  return remainder === 0
    ? `${wholeHours} hr`
    : `${wholeHours} hr ${remainder} min`;
}

function commandIdempotencyKey(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `revoke-credential-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
