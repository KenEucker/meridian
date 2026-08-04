// The organization's credit policies, and how an organizer maintains them and
// starts calculation runs (M18.16; ORG-009, ORG-020; CREDIT-001 through
// CREDIT-003; data/API 10.12).
//
// The policy table has existed since M13.5 and the resolver has read it since;
// what never existed was a product path for putting a policy in it, so an
// organization could not actually start running credits. This module is the
// translation of that path's endpoints.
//
// Three things are worth stating:
//
//  1. **Archived policies are part of the read.** Restoring one is half of why
//     a maintainer is here, and a shift may still point at one (CREDIT-002) —
//     a row you cannot see is a row you cannot reason about.
//  2. **The events list is the node's answer, not the client's.** Whether an
//     event can be credited — grace period closed, no hours record still open
//     — is decided by the server once and carried on each row (CLIENT-006).
//  3. **Every write is followed by a re-read.** A rename moves a row in the
//     sort, an archive changes what the shift edit form will offer, and a
//     calculation run changes the event counts.

import { meridianJson } from "@/api/meridianApi";
import { sendConnectedCommand } from "@/outbox/submitCommand";

/** One credit policy, as the administration read reports it. */
export interface OrganizationCreditPolicy {
  readonly id: string;
  readonly name: string;
  /** Credits per hour worked, as the node states it (three decimals). */
  readonly creditMultiplier: string;
  readonly archived: boolean;
  readonly archivedAt: string | null;
  /** Whether this policy is the organization default (CREDIT-003). */
  readonly isDefault: boolean;
  /** How many shifts name it, so archiving is not a silent act. */
  readonly shiftCount: number;
}

/** One event with the node's answer on whether it can be credited yet. */
export interface CreditRunEvent {
  readonly id: string;
  readonly name: string;
  readonly endsAt: string | null;
  readonly graceClosesAt: string | null;
  readonly graceClosed: boolean;
  readonly openHoursCount: number;
  readonly uncreditedHoursCount: number;
  readonly creditedHoursCount: number;
  readonly canCalculate: boolean;
}

/** Whether edits are possible here and now, and why not when they are not. */
export interface CreditPolicyGovernance {
  readonly editable: boolean;
  readonly holdsAuthority: boolean;
  readonly frozenByEvent: { id: string; name: string } | null;
}

/** Everything the credit policy featureset renders, from one read. */
export interface OrganizationCreditPolicyList {
  readonly organizationId: string;
  readonly creditPolicies: readonly OrganizationCreditPolicy[];
  readonly events: readonly CreditRunEvent[];
  readonly governance: CreditPolicyGovernance;
}

/** What one calculation run did, in the node's counts. */
export interface CreditCalculationRunResult {
  readonly entriesCreated: number;
  readonly entriesAlreadyCalculated: number;
  readonly hoursWithoutCreditPolicy: number;
  readonly totalHours: string;
  readonly totalCredits: string;
}

interface CreditPolicyPayload {
  readonly id: string;
  readonly name: string;
  readonly credit_multiplier?: string;
  readonly archived?: boolean;
  readonly archived_at?: string | null;
  readonly is_default?: boolean;
  readonly shift_count?: number;
}

interface CreditRunEventPayload {
  readonly id: string;
  readonly name?: string;
  readonly ends_at?: string | null;
  readonly grace_closes_at?: string | null;
  readonly grace_closed?: boolean;
  readonly open_hours_count?: number;
  readonly uncredited_hours_count?: number;
  readonly credited_hours_count?: number;
  readonly can_calculate?: boolean;
}

interface CreditPolicyListPayload {
  readonly organization_id?: string;
  readonly credit_policies?: CreditPolicyPayload[];
  readonly events?: CreditRunEventPayload[];
  readonly governance?: {
    readonly editable?: boolean;
    readonly holds_authority?: boolean;
    readonly frozen_by_event?: { id: string; name: string } | null;
  };
}

interface CalculationRunPayload {
  readonly entries_created?: number;
  readonly entries_already_calculated?: number;
  readonly hours_without_credit_policy?: number;
  readonly total_hours?: string;
  readonly total_credits?: string;
}

export async function getOrganizationCreditPolicies(
  organizationId: string,
): Promise<OrganizationCreditPolicyList> {
  const payload = await meridianJson<CreditPolicyListPayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/credit-policies`,
  );

  return {
    organizationId: payload.organization_id ?? organizationId,
    creditPolicies: (payload.credit_policies ?? []).map((policy) => ({
      id: policy.id,
      name: policy.name,
      creditMultiplier: policy.credit_multiplier ?? "1.000",
      archived: policy.archived ?? policy.archived_at !== null,
      archivedAt: policy.archived_at ?? null,
      isDefault: policy.is_default ?? false,
      shiftCount: policy.shift_count ?? 0,
    })),
    events: (payload.events ?? []).map((event) => ({
      id: event.id,
      name: event.name ?? "",
      endsAt: event.ends_at ?? null,
      graceClosesAt: event.grace_closes_at ?? null,
      graceClosed: event.grace_closed ?? false,
      openHoursCount: event.open_hours_count ?? 0,
      uncreditedHoursCount: event.uncredited_hours_count ?? 0,
      creditedHoursCount: event.credited_hours_count ?? 0,
      canCalculate: event.can_calculate ?? false,
    })),
    governance: {
      editable: payload.governance?.editable ?? true,
      holdsAuthority: payload.governance?.holds_authority ?? true,
      frozenByEvent: payload.governance?.frozen_by_event ?? null,
    },
  };
}

export async function createCreditPolicy(
  organizationId: string,
  name: string,
  creditMultiplier: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "create-credit-policy",
    idempotencyKey: commandIdempotencyKey(),
    payload: {
      organization_id: organizationId,
      name: name.trim(),
      credit_multiplier: creditMultiplier,
    },
  });
}

export async function updateCreditPolicy(
  creditPolicyId: string,
  name: string,
  creditMultiplier: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "update-credit-policy",
    idempotencyKey: commandIdempotencyKey(),
    payload: {
      credit_policy_id: creditPolicyId,
      name: name.trim(),
      credit_multiplier: creditMultiplier,
    },
  });
}

export async function archiveCreditPolicy(creditPolicyId: string): Promise<void> {
  await sendConnectedCommand({
    commandType: "archive-credit-policy",
    idempotencyKey: commandIdempotencyKey(),
    payload: { credit_policy_id: creditPolicyId },
  });
}

export async function restoreCreditPolicy(creditPolicyId: string): Promise<void> {
  await sendConnectedCommand({
    commandType: "restore-credit-policy",
    idempotencyKey: commandIdempotencyKey(),
    payload: { credit_policy_id: creditPolicyId },
  });
}

export async function calculateEventCredits(
  eventId: string,
): Promise<CreditCalculationRunResult> {
  const payload = await meridianJson<CalculationRunPayload>(
    "/api/commands/calculate-event-credits",
    {
      method: "POST",
      body: JSON.stringify({ event_id: eventId }),
    },
  );

  return {
    entriesCreated: payload.entries_created ?? 0,
    entriesAlreadyCalculated: payload.entries_already_calculated ?? 0,
    hoursWithoutCreditPolicy: payload.hours_without_credit_policy ?? 0,
    totalHours: payload.total_hours ?? "0.00",
    totalCredits: payload.total_credits ?? "0.00",
  };
}

function commandIdempotencyKey(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `credit-policy-command-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
