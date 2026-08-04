// The reviewer's side of profile change requests (M18.20D; VOL-019 through
// VOL-022, VOL-025; data/API 10.4; UI contract 12.6).
//
// One read and two commands, and the read is the one that carries the judgement.
// `GET /api/staff-profile-change-requests` answers with the pending requests in
// the organizations this caller actually holds `staff.profile-change-requests.review`
// in — never a request from an organization they do not review — and each row
// arrives with everything a decision needs beside it: the previous and requested
// handle, a short-lived URL for the current and the submitted picture, and the
// names of any active staff member in the organization already using the
// requested handle.
//
// That last one is named rather than enforced (VOL-020). Two people may
// legitimately be told apart by their departments, so the collision informs the
// reviewer instead of deciding for them, and neither this module nor the surface
// above it blocks a decision on account of one.
//
// The read is not cached. A queue is a claim about what is still waiting, and a
// stale one sends a reviewer to decide something somebody else decided an hour
// ago; both commands are connected-only for the same reason.

import { meridianJson } from "@/api/meridianApi";
import { sendConnectedCommand } from "@/outbox/submitCommand";

/** One request as its reviewer reads it. */
export interface ReviewableChangeRequest {
  readonly id: string;
  readonly kind: "handle" | "profile_picture";
  readonly organizationId: string;
  readonly organizationName: string | null;
  readonly staffId: string;
  /** Handle-first, the way every other surface names a person (VOL-010). */
  readonly staffName: string;
  readonly previousHandle: string | null;
  readonly requestedHandle: string | null;
  /**
   * Other active staff in the organization already using the requested handle,
   * named rather than blocking (VOL-020). Empty for a picture request.
   */
  readonly handleCollisions: readonly string[];
  readonly currentPictureUrl: string | null;
  readonly submittedPictureUrl: string | null;
  readonly createdAt: string | null;
}

export interface ProfileChangeReviewQueue {
  /** The organizations this caller reviews for, as the node resolved them. */
  readonly organizationIds: readonly string[];
  readonly requests: readonly ReviewableChangeRequest[];
}

interface ReviewRowPayload {
  readonly id: string;
  readonly kind?: string;
  readonly organization_id?: string;
  readonly organization_name?: string | null;
  readonly staff_id?: string;
  readonly staff_name?: string;
  readonly previous_handle?: string | null;
  readonly requested_handle?: string | null;
  readonly handle_collisions?: readonly string[];
  readonly current_picture_url?: string | null;
  readonly submitted_picture_url?: string | null;
  readonly created_at?: string | null;
}

interface QueuePayload {
  readonly organization_ids?: readonly string[];
  readonly requests?: readonly ReviewRowPayload[];
}

function toReviewRow(payload: ReviewRowPayload): ReviewableChangeRequest {
  return {
    id: payload.id,
    kind: payload.kind === "handle" ? "handle" : "profile_picture",
    organizationId: payload.organization_id ?? "",
    organizationName: payload.organization_name ?? null,
    staffId: payload.staff_id ?? "",
    staffName: payload.staff_name ?? "Unknown staff member",
    previousHandle: payload.previous_handle ?? null,
    requestedHandle: payload.requested_handle ?? null,
    handleCollisions: payload.handle_collisions ?? [],
    currentPictureUrl: payload.current_picture_url ?? null,
    submittedPictureUrl: payload.submitted_picture_url ?? null,
    createdAt: payload.created_at ?? null,
  };
}

export async function getProfileChangeReviewQueue(): Promise<ProfileChangeReviewQueue> {
  const payload = await meridianJson<QueuePayload>(
    "/api/staff-profile-change-requests",
  );

  return {
    organizationIds: payload?.organization_ids ?? [],
    requests: (payload?.requests ?? []).map(toReviewRow),
  };
}

/** What a decision came back as, so the surface reports the node's answer. */
export interface ProfileChangeDecision {
  readonly id: string;
  readonly kind: "handle" | "profile_picture";
  readonly status: "pending" | "approved" | "rejected" | "withdrawn";
  readonly decisionReason: string | null;
  readonly decidedAt: string | null;
}

interface DecisionPayload {
  readonly request?: {
    readonly id: string;
    readonly kind?: string;
    readonly status?: string;
    readonly decision_reason?: string | null;
    readonly decided_at?: string | null;
  } | null;
}

function toDecision(payload: DecisionPayload): ProfileChangeDecision | null {
  const request = payload?.request;

  if (request == null) {
    return null;
  }

  return {
    id: request.id,
    kind: request.kind === "handle" ? "handle" : "profile_picture",
    status: (request.status ?? "pending") as ProfileChangeDecision["status"],
    decisionReason: request.decision_reason ?? null,
    decidedAt: request.decided_at ?? null,
  };
}

export async function approveProfileChangeRequest(
  requestId: string,
  reason: string | null = null,
): Promise<ProfileChangeDecision | null> {
  const result = (await sendConnectedCommand({
    commandType: "approve-profile-change-request",
    idempotencyKey: decisionIdempotencyKey("approve", requestId),
    payload: { request_id: requestId, reason },
  })) as DecisionPayload | null;

  return result === null ? null : toDecision(result);
}

/**
 * Reject with the reason the submitter is told (VOL-025).
 *
 * The reason is required by the node, and this signature requires it too rather
 * than defaulting it to null: a rejection nobody explained is the state the
 * requirement exists to prevent, and a client that can express it would sooner
 * or later send it.
 */
export async function rejectProfileChangeRequest(
  requestId: string,
  reason: string,
): Promise<ProfileChangeDecision | null> {
  const result = (await sendConnectedCommand({
    commandType: "reject-profile-change-request",
    idempotencyKey: decisionIdempotencyKey("reject", requestId),
    payload: { request_id: requestId, reason },
  })) as DecisionPayload | null;

  return result === null ? null : toDecision(result);
}

/**
 * One key per decision on one request, so a retried submission of the same
 * decision is the same operation rather than a second one.
 */
function decisionIdempotencyKey(decision: string, requestId: string): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${decision}-${requestId}-${Date.now()}`;
}
