// The public marketing surface, as the client reads it (M18.23; PUBLIC-001
// through PUBLIC-006).
//
// Like the participation model beside it, everything here runs without a
// session: no token, no cached permission set, no organization context. Unlike
// it, there is no organization at all — this surface is Meridian describing
// itself to people who do not use it yet, so nothing here resolves a branding
// profile and nothing takes an organization slug (PUBLIC-001, BRAND-003).
//
// Nothing is cached. The availability read is a claim about what this node is
// doing right now, and it carries a form token that a stale copy would render
// unusable.

import { meridianJson } from "@/api/meridianApi";

export interface MarketingSurfaceAvailability {
  /**
   * The token a submission has to carry (PUBLIC-005). Issued by the node,
   * opaque to the client, and proof that whoever submits opened the page first.
   */
  readonly formToken: string;
  /** How long a submission must have spent on the form, in seconds. */
  readonly minimumSecondsOnForm: number;
}

export interface OrganizationInterestFields {
  readonly organizationName: string;
  readonly contactName: string;
  readonly contactEmail: string;
  readonly description: string;
}

/**
 * The hidden field name, kept beside the calls that send it so the client and
 * `OrganizationInterestController::TRAP_FIELD` cannot drift apart.
 */
export const INTEREST_TRAP_FIELD = "organization_reference_code";

interface AvailabilityPayload {
  readonly available?: boolean;
  readonly form_token?: string;
  readonly minimum_seconds_on_form?: number;
}

/**
 * Ask whether this node serves the marketing surface, and collect a form token.
 *
 * Throws on an on-site node and on a node locked to an event, where the node
 * answers 404 (PUBLIC-006). The caller treats that as "not here" rather than
 * as an error worth showing: the visitor is sent somewhere useful instead.
 */
export async function getMarketingSurface(): Promise<MarketingSurfaceAvailability> {
  const payload = await meridianJson<AvailabilityPayload>(
    "/api/public/marketing-surface",
  );

  return {
    formToken: String(payload.form_token ?? ""),
    minimumSecondsOnForm: Number(payload.minimum_seconds_on_form ?? 0),
  };
}

/**
 * Tell Meridian about an organization (PUBLIC-002, PUBLIC-003).
 *
 * The trap field is always sent and is always empty from this call: a real
 * visitor never touches the input bound to it, so anything in it came from
 * something filling the form in without looking at it.
 */
export async function submitOrganizationInterest(
  fields: OrganizationInterestFields,
  formToken: string,
  trapValue: string,
): Promise<void> {
  await meridianJson("/api/public/organization-inquiries", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      organization_name: fields.organizationName,
      contact_name: fields.contactName,
      contact_email: fields.contactEmail,
      description: fields.description,
      form_token: formToken,
      [INTEREST_TRAP_FIELD]: trapValue,
    }),
  });
}
