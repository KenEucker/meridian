// Application review, as the reviewer's client reads it (M18.21A; APP-005,
// APP-011, APP-019).
//
// One read, and the read is the scope. `GET /api/applications` answers with
// exactly the applications this caller may see, which is two populations at
// once: the organizations they hold `organization.applications.review` in, and
// — read-only — the submitted applications naming a department they lead
// (APP-011). The client never asks for "all applications" and filters, because
// a client that filtered would be a client deciding scope.
//
// Each row carries its own `canReview`, not the surface. Somebody may review
// for one organization and hold only lead visibility in another, and the same
// list can carry both; a page-level flag would have to pick one and would be
// wrong for half the rows.
//
// Nothing is cached. A review queue is a claim about what is still waiting, and
// a stale one sends somebody to decide what a colleague decided an hour ago.

import { meridianJson } from "@/api/meridianApi";
import { sendConnectedCommand } from "@/outbox/submitCommand";

export type ApplicationScope = "organization" | "event";

export type ApplicationStatus =
  | "submitted"
  | "approved"
  | "rejected"
  | "deferred"
  | "withdrawn"
  | "auto_rejected_dns";

export interface ApplicationDepartmentInterest {
  readonly id: string;
  readonly name: string;
  /** APP-011 preserves interest in a department that has since been archived. */
  readonly archived: boolean;
}

export interface ReviewableApplication {
  readonly id: string;
  /** APP-001: what this application is about. */
  readonly scope: ApplicationScope;
  readonly organizationId: string;
  readonly organizationName: string | null;
  readonly eventId: string | null;
  readonly eventName: string | null;
  readonly applicantLegalName: string;
  readonly applicantEmail: string;
  readonly status: ApplicationStatus;
  readonly statusLabel: string;
  readonly submittedAt: string | null;
  readonly reviewedAt: string | null;
  readonly reviewedBy: string | null;
  readonly decisionReason: string | null;
  readonly departmentInterests: readonly ApplicationDepartmentInterest[];
  /** False for a department lead's read-only visibility (APP-011). */
  readonly canReview: boolean;
}

export interface ApplicationReviewQueue {
  readonly canReview: boolean;
  readonly hasDepartmentLeadVisibility: boolean;
  readonly applications: readonly ReviewableApplication[];
}

interface ApplicationPayload {
  readonly id: string;
  readonly scope?: string;
  readonly organization_id?: string;
  readonly organization_name?: string | null;
  readonly event_id?: string | null;
  readonly event_name?: string | null;
  readonly applicant_legal_name?: string;
  readonly applicant_email?: string;
  readonly status?: string;
  readonly status_label?: string;
  readonly submitted_at?: string | null;
  readonly reviewed_at?: string | null;
  readonly reviewed_by?: string | null;
  readonly decision_reason?: string | null;
  readonly department_interests?: readonly ApplicationDepartmentInterest[];
  readonly can_review?: boolean;
}

function toApplication(payload: ApplicationPayload): ReviewableApplication {
  return {
    id: payload.id,
    scope: payload.scope === "organization" ? "organization" : "event",
    organizationId: payload.organization_id ?? "",
    organizationName: payload.organization_name ?? null,
    eventId: payload.event_id ?? null,
    eventName: payload.event_name ?? null,
    applicantLegalName: payload.applicant_legal_name ?? "Unknown applicant",
    applicantEmail: payload.applicant_email ?? "",
    status: (payload.status ?? "submitted") as ApplicationStatus,
    statusLabel: payload.status_label ?? "Submitted",
    submittedAt: payload.submitted_at ?? null,
    reviewedAt: payload.reviewed_at ?? null,
    reviewedBy: payload.reviewed_by ?? null,
    decisionReason: payload.decision_reason ?? null,
    departmentInterests: payload.department_interests ?? [],
    canReview: payload.can_review === true,
  };
}

export async function getApplicationReviewQueue(): Promise<ApplicationReviewQueue> {
  const payload = await meridianJson<{
    can_review?: boolean;
    has_department_lead_visibility?: boolean;
    applications?: readonly ApplicationPayload[];
  }>("/api/applications");

  return {
    canReview: payload?.can_review === true,
    hasDepartmentLeadVisibility:
      payload?.has_department_lead_visibility === true,
    applications: (payload?.applications ?? []).map(toApplication),
  };
}

/**
 * One application, for the detail surface (M18.29; UI contract 12.6
 * `organizer.application-detail`, 12.10.2).
 *
 * A read of its own rather than a row picked out of the queue. The queue is
 * filtered by status and by department interest, so the row somebody followed a
 * link to is often not in the list this client last held — and a detail page
 * that could only render what a previous read happened to contain would be a
 * page that works from one direction and not the other.
 *
 * The node answers the same two populations here that it answers on the list: a
 * reviewer for the organization, and the department lead APP-011 grants
 * read-only visibility over an application naming their department. Everybody
 * else meets a refusal, which is the honest answer — an application they may
 * not see is not an empty page.
 */
export async function getApplication(
  applicationId: string,
): Promise<ReviewableApplication | null> {
  const payload = await meridianJson<{
    application?: ApplicationPayload;
  }>(`/api/applications/${encodeURIComponent(applicationId)}`);

  return payload?.application == null ? null : toApplication(payload.application);
}

type Decision = "approve" | "reject" | "defer";

const COMMAND_FOR_DECISION = {
  approve: "approve-application",
  reject: "reject-application",
  defer: "defer-application",
} as const;

/**
 * Decide one application (APP-005).
 *
 * Connected-only, like every other review decision in this client: an approval
 * creates a staff record and an organization status, and queueing that offline
 * would let two reviewers decide the same application on two devices with no
 * way to reconcile the result.
 *
 * A reason is optional on all three — the domain supplies a default sentence —
 * but the surface asks for one on a rejection, because a rejection nobody
 * explained is the one the applicant is told about with nothing to act on.
 */
export async function decideApplication(
  decision: Decision,
  applicationId: string,
  reason: string | null = null,
): Promise<ReviewableApplication | null> {
  const result = (await sendConnectedCommand({
    commandType: COMMAND_FOR_DECISION[decision],
    idempotencyKey: decisionIdempotencyKey(decision, applicationId),
    payload: { application_id: applicationId, reason },
  })) as { application?: ApplicationPayload } | null;

  return result?.application == null ? null : toApplication(result.application);
}

function decisionIdempotencyKey(
  decision: string,
  applicationId: string,
): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${decision}-${applicationId}-${Date.now()}`;
}
