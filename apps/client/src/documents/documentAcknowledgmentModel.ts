// The document acknowledgment path's data layer (M18.6; POL-023 through
// POL-027, POL-043 through POL-047; CLIENT-023).
//
// Three surfaces read from here and they are three readings of two endpoints,
// not three featuresets:
//
//   `signup.policy-acknowledgment`     the signup-context requirements a person
//                                      has not answered yet, with the text
//   `staff.document-acknowledgments`   the same list, every context, answered
//                                      and unanswered alike
//   `organizer.document-acknowledgments` who was asked and who answered
//
// Two properties are load-bearing.
//
//  1. **Outstanding is the node's answer, not arithmetic done here.** POL-045
//     says a document that changed does not re-require its acknowledgment, and
//     the node applies that rule when it builds the row. A client that compared
//     `acknowledgedVersion` to `documentVersion` and called the difference
//     outstanding would be reinstating the requirement POL-045 removes.
//  2. **It is not a gate.** The read carries `gating`, and both halves of it are
//     false: POL-026 and POL-027 keep acknowledgment out of shift signup and
//     credential eligibility. The surfaces print it, because a list of
//     outstanding items is exactly the shape a reader assumes is blocking
//     something.
//
// Every write is connected-only. Acceptance most of all: the record names the
// version that was read (POL-043), and one held on a device would name whatever
// version that device last cached.

import { computed } from "vue";

import { meridianCachedJson } from "@/api/meridianApi";
import { sendConnectedCommand } from "@/outbox/submitCommand";
import type { MeridianCommandType } from "@/outbox/commandCatalog";
import { CAPABILITY_DOCUMENT_ACKNOWLEDGMENTS_REVIEW } from "@/session/permissionCodes";
import { selectedSessionDepartment } from "@/session/sessionAccess";
import { sessionOrganizationId } from "@/session/sessionContext";

/** One thing this person has been asked to acknowledge. */
export interface AcknowledgmentRequirement {
  readonly requirementId: string;
  readonly organizationId: string;
  readonly scopeType: string;
  readonly scopeLabel: string;
  /** `signup` or `training` (POL-024, POL-025). */
  readonly context: string;
  readonly contextLabel: string;
  readonly documentType: string;
  readonly documentId: string;
  readonly documentTitle: string;
  /** The version standing now, as `major.minor`. */
  readonly documentVersion: string;
  /** The node's render, with referenced fragments inline (POL-022). */
  readonly renderedHtml: string;
  readonly acknowledged: boolean;
  readonly acknowledgedAt: string | null;
  /** The version that was accepted (POL-043), or null for an unanswered one. */
  readonly acknowledgedVersion: string | null;
  /** Whether the document moved since (POL-045). Never makes a row outstanding. */
  readonly documentChangedSince: boolean;
}

/** What an outstanding acknowledgment does not do (POL-026, POL-027). */
export interface AcknowledgmentGating {
  readonly blocksShiftSignup: boolean;
  readonly blocksCredentialEligibility: boolean;
  readonly explanation: string;
}

export interface MyAcknowledgments {
  readonly requirements: readonly AcknowledgmentRequirement[];
  readonly outstandingCount: number;
  readonly gating: AcknowledgmentGating;
}

interface RequirementPayload {
  readonly requirement_id: string;
  readonly organization_id?: string;
  readonly scope_type?: string;
  readonly scope_label?: string;
  readonly requirement_context?: string;
  readonly requirement_context_label?: string;
  readonly document_type?: string;
  readonly document_id?: string;
  readonly document_title?: string;
  readonly document_version?: string;
  readonly rendered_html?: string;
  readonly acknowledged?: boolean;
  readonly acknowledged_at?: string | null;
  readonly acknowledged_version?: string | null;
  readonly document_changed_since?: boolean;
}

interface MyAcknowledgmentsPayload {
  readonly requirements?: readonly RequirementPayload[];
  readonly outstanding_count?: number;
  readonly gating?: {
    readonly blocks_shift_signup?: boolean;
    readonly blocks_credential_eligibility?: boolean;
    readonly explanation?: string;
  };
}

const GATING_FALLBACK: AcknowledgmentGating = {
  blocksShiftSignup: false,
  blocksCredentialEligibility: false,
  explanation:
    "Acknowledgments are recorded for the record. An outstanding one does not block shift signup or event credential eligibility.",
};

function toRequirement(payload: RequirementPayload): AcknowledgmentRequirement {
  return {
    requirementId: payload.requirement_id,
    organizationId: payload.organization_id ?? "",
    scopeType: payload.scope_type ?? "organization",
    scopeLabel: payload.scope_label ?? "",
    context: payload.requirement_context ?? "signup",
    contextLabel: payload.requirement_context_label ?? "",
    documentType: payload.document_type ?? "policy",
    documentId: payload.document_id ?? "",
    documentTitle: payload.document_title ?? "Untitled document",
    documentVersion: payload.document_version ?? "",
    renderedHtml: payload.rendered_html ?? "",
    acknowledged: payload.acknowledged ?? false,
    acknowledgedAt: payload.acknowledged_at ?? null,
    acknowledgedVersion: payload.acknowledged_version ?? null,
    documentChangedSince: payload.document_changed_since ?? false,
  };
}

function toMyAcknowledgments(
  payload: MyAcknowledgmentsPayload,
): MyAcknowledgments {
  const requirements = (payload.requirements ?? []).map(toRequirement);

  return {
    requirements,
    outstandingCount:
      payload.outstanding_count ??
      requirements.filter((requirement) => !requirement.acknowledged).length,
    gating: {
      blocksShiftSignup: payload.gating?.blocks_shift_signup ?? false,
      blocksCredentialEligibility:
        payload.gating?.blocks_credential_eligibility ?? false,
      explanation: payload.gating?.explanation ?? GATING_FALLBACK.explanation,
    },
  };
}

export async function getMyAcknowledgments(): Promise<MyAcknowledgments> {
  return toMyAcknowledgments(
    (await meridianCachedJson<MyAcknowledgmentsPayload>(
      "/api/document-acknowledgments/me",
    )).data,
  );
}

/**
 * Record one acknowledgment (POL-043).
 *
 * The answer is the rebuilt row, so a surface replaces what it was showing
 * rather than setting a flag on it: the accepted version comes back with it, and
 * that is the part worth seeing land.
 */
export async function acknowledgeDocument(
  requirementId: string,
): Promise<AcknowledgmentRequirement | null> {
  const result = (await sendCommand("acknowledge-document", {
    requirement_id: requirementId,
  })) as { readonly requirement?: RequirementPayload | null } | null;

  const requirement = result?.requirement;

  return requirement === undefined || requirement === null
    ? null
    : toRequirement(requirement);
}

/** Whether this client may maintain and review requirements in its organization. */
export interface AcknowledgmentReviewAuthority {
  readonly organizationId: string;
  /** The roles carrying it, named as the node named them. */
  readonly roleLabel: string;
}

export const acknowledgmentReviewAuthority =
  computed<AcknowledgmentReviewAuthority | null>(() => {
    const department = selectedSessionDepartment.value;
    const organizationId = sessionOrganizationId.value;

    if (department === null || organizationId === null) {
      return null;
    }

    const granting = department.roles.filter((role) =>
      role.capabilities.includes(CAPABILITY_DOCUMENT_ACKNOWLEDGMENTS_REVIEW),
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
      organizationId,
      roleLabel: roleNames.length > 0 ? roleNames.join(", ") : "Your role",
    };
  });

/** One person's standing against one requirement, as the organizer reads it. */
export interface AcknowledgmentSubject {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly acknowledged: boolean;
  readonly acknowledgedAt: string | null;
  readonly acknowledgedVersion: string | null;
  readonly documentChangedSince: boolean;
}

export interface ReviewedRequirement {
  readonly id: string;
  readonly scopeType: string;
  readonly scopeId: string;
  readonly scopeLabel: string;
  readonly context: string;
  readonly contextLabel: string;
  readonly documentType: string;
  readonly documentId: string;
  readonly documentTitle: string;
  readonly documentVersion: string | null;
  readonly documentPublished: boolean;
  readonly active: boolean;
  readonly subjectCount: number;
  readonly acknowledgedCount: number;
  readonly staff: readonly AcknowledgmentSubject[];
}

/** A choice the create form offers, matched to what the command accepts. */
export interface AcknowledgmentDocumentOption {
  readonly documentType: string;
  readonly documentId: string;
  readonly title: string;
  readonly version: string;
}

export interface AcknowledgmentScopeOption {
  readonly scopeType: string;
  readonly scopeId: string;
  readonly label: string;
}

export interface AcknowledgmentContextOption {
  readonly value: string;
  readonly label: string;
}

export interface AcknowledgmentReview {
  readonly organizationId: string;
  readonly organizationName: string | null;
  readonly requirements: readonly ReviewedRequirement[];
  readonly documents: readonly AcknowledgmentDocumentOption[];
  readonly scopes: readonly AcknowledgmentScopeOption[];
  readonly contexts: readonly AcknowledgmentContextOption[];
}

interface SubjectPayload {
  readonly staff_id: string;
  readonly display_name?: string;
  readonly handle?: string | null;
  readonly acknowledged?: boolean;
  readonly acknowledged_at?: string | null;
  readonly acknowledged_version?: string | null;
  readonly document_changed_since?: boolean;
}

interface ReviewedRequirementPayload {
  readonly id: string;
  readonly scope_type?: string;
  readonly scope_id?: string;
  readonly scope_label?: string;
  readonly requirement_context?: string;
  readonly requirement_context_label?: string;
  readonly document_type?: string;
  readonly document_id?: string;
  readonly document_title?: string;
  readonly document_version?: string | null;
  readonly document_published?: boolean;
  readonly active?: boolean;
  readonly subject_count?: number;
  readonly acknowledged_count?: number;
  readonly staff?: readonly SubjectPayload[];
}

interface ReviewPayload {
  readonly organization_id?: string;
  readonly organization_name?: string | null;
  readonly requirements?: readonly ReviewedRequirementPayload[];
  readonly documents?: readonly {
    readonly document_type?: string;
    readonly document_id?: string;
    readonly title?: string;
    readonly version?: string;
  }[];
  readonly scopes?: readonly {
    readonly scope_type?: string;
    readonly scope_id?: string;
    readonly label?: string;
  }[];
  readonly contexts?: readonly {
    readonly value?: string;
    readonly label?: string;
  }[];
}

function toReviewedRequirement(
  payload: ReviewedRequirementPayload,
): ReviewedRequirement {
  return {
    id: payload.id,
    scopeType: payload.scope_type ?? "organization",
    scopeId: payload.scope_id ?? "",
    scopeLabel: payload.scope_label ?? "",
    context: payload.requirement_context ?? "signup",
    contextLabel: payload.requirement_context_label ?? "",
    documentType: payload.document_type ?? "policy",
    documentId: payload.document_id ?? "",
    documentTitle: payload.document_title ?? "Untitled document",
    documentVersion: payload.document_version ?? null,
    documentPublished: payload.document_published ?? false,
    active: payload.active ?? false,
    subjectCount: payload.subject_count ?? 0,
    acknowledgedCount: payload.acknowledged_count ?? 0,
    staff: (payload.staff ?? []).map((subject) => ({
      staffId: subject.staff_id,
      displayName: subject.display_name ?? "Unknown staff member",
      handle: subject.handle ?? null,
      acknowledged: subject.acknowledged ?? false,
      acknowledgedAt: subject.acknowledged_at ?? null,
      acknowledgedVersion: subject.acknowledged_version ?? null,
      documentChangedSince: subject.document_changed_since ?? false,
    })),
  };
}

export async function getAcknowledgmentReview(
  organizationId: string,
): Promise<AcknowledgmentReview> {
  const payload = (await meridianCachedJson<ReviewPayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/document-acknowledgments`,
  )).data;

  return {
    organizationId: payload.organization_id ?? organizationId,
    organizationName: payload.organization_name ?? null,
    requirements: (payload.requirements ?? []).map(toReviewedRequirement),
    documents: (payload.documents ?? []).map((option) => ({
      documentType: option.document_type ?? "policy",
      documentId: option.document_id ?? "",
      title: option.title ?? "Untitled document",
      version: option.version ?? "",
    })),
    scopes: (payload.scopes ?? []).map((option) => ({
      scopeType: option.scope_type ?? "organization",
      scopeId: option.scope_id ?? "",
      label: option.label ?? "",
    })),
    contexts: (payload.contexts ?? []).map((option) => ({
      value: option.value ?? "",
      label: option.label ?? "",
    })),
  };
}

export interface NewRequirementInput {
  readonly organizationId: string;
  readonly documentType: string;
  readonly documentId: string;
  readonly scopeType: string;
  readonly scopeId: string;
  readonly context: string;
}

export async function createAcknowledgmentRequirement(
  input: NewRequirementInput,
): Promise<ReviewedRequirement | null> {
  const result = (await sendCommand(
    "create-document-acknowledgment-requirement",
    {
      organization_id: input.organizationId,
      document_type: input.documentType,
      document_id: input.documentId,
      scope_type: input.scopeType,
      scope_id: input.scopeId,
      requirement_context: input.context,
    },
  )) as { readonly requirement?: ReviewedRequirementPayload | null } | null;

  return result?.requirement == null
    ? null
    : toReviewedRequirement(result.requirement);
}

export async function setAcknowledgmentRequirementActive(
  requirementId: string,
  active: boolean,
): Promise<ReviewedRequirement | null> {
  const result = (await sendCommand(
    "set-document-acknowledgment-requirement-active",
    { requirement_id: requirementId, active },
  )) as { readonly requirement?: ReviewedRequirementPayload | null } | null;

  return result?.requirement == null
    ? null
    : toReviewedRequirement(result.requirement);
}

/** The moment something was accepted, in the reader's own time zone. */
export function formatAcknowledgedAt(acknowledgedAt: string | null): string {
  if (acknowledgedAt === null) {
    return "Not yet";
  }

  const moment = new Date(acknowledgedAt);

  return Number.isNaN(moment.getTime())
    ? acknowledgedAt
    : moment.toLocaleString([], {
        dateStyle: "medium",
        timeStyle: "short",
      });
}

async function sendCommand(
  commandType: MeridianCommandType,
  payload: Readonly<Record<string, unknown>>,
): Promise<unknown> {
  return sendConnectedCommand({
    commandType,
    idempotencyKey: commandIdempotencyKey(commandType),
    payload,
  });
}

function commandIdempotencyKey(commandType: string): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `${commandType}-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
