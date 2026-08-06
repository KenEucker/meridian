// Credit review for one department at one event (M18.30; UI contract 12.4
// `department.credits`; CREDIT-004, CREDIT-005).
//
// One read, and nothing this module writes. Credit calculation belongs to
// organizers (ORG-010) and runs from the credit policy surface; this is the
// reading somebody does before they export the file, and before they have to
// explain a number to the person who earned it.
//
// Every rate and policy name on an entry is the one frozen into the entry at
// calculation, not the one the policy carries today. A policy may be renamed or
// re-rated afterwards, and a page that read the live row would quietly restate
// a finished event every time somebody edited a multiplier. That decision is
// the node's; this module carries its answer and does no arithmetic of its own.

import { meridianJson } from "@/api/meridianApi";

export interface CreditEntry {
  readonly id: string;
  readonly staffId: string;
  readonly staffName: string;
  readonly staffHandle: string | null;
  readonly shiftTitle: string | null;
  readonly shiftStartsAt: string | null;
  readonly shiftEndsAt: string | null;
  readonly team: string | null;
  readonly entryType: string;
  readonly status: string;
  readonly hours: string;
  readonly credits: string;
  /** The frozen basis (CREDIT-005); null where the entry recorded none. */
  readonly creditPolicyName: string | null;
  readonly creditMultiplier: string | null;
  readonly policySource: string | null;
  readonly minutesWorked: string | null;
  readonly calculatedAt: string | null;
  readonly hoursCorrectedAt: string | null;
  readonly frozenAt: string | null;
}

export interface CreditStaffTotal {
  readonly staffId: string;
  readonly staffName: string;
  readonly staffHandle: string | null;
  readonly entryCount: number;
  readonly hours: string;
  readonly credits: string;
}

export interface DepartmentCreditReview {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  /** Whether the reader's authority reaches the event or only this department. */
  readonly organizationWide: boolean;
  readonly totals: {
    readonly entryCount: number;
    readonly staffCount: number;
    readonly hours: string;
    readonly credits: string;
  };
  /**
   * Work this department has done that the ledger does not answer for yet.
   *
   * Reported so a lead reading a total that looks low is told why rather than
   * left to conclude the event under-credited them.
   */
  readonly outstanding: {
    readonly openHoursCount: number;
    readonly uncreditedHoursCount: number;
    readonly graceClosesAt: string | null;
    readonly graceClosed: boolean;
  };
  readonly staff: readonly CreditStaffTotal[];
  readonly entries: readonly CreditEntry[];
}

interface EntryPayload {
  readonly id?: string;
  readonly staff_id?: string;
  readonly staff_name?: string;
  readonly staff_handle?: string | null;
  readonly shift_title?: string | null;
  readonly shift_starts_at?: string | null;
  readonly shift_ends_at?: string | null;
  readonly team?: string | null;
  readonly entry_type?: string;
  readonly status?: string;
  readonly hours?: string;
  readonly credits?: string;
  readonly credit_policy_name?: string | null;
  readonly credit_multiplier?: string | null;
  readonly policy_source?: string | null;
  readonly minutes_worked?: string | null;
  readonly calculated_at?: string | null;
  readonly hours_corrected_at?: string | null;
  readonly frozen_at?: string | null;
}

interface ReviewPayload {
  readonly context?: {
    event_id?: string;
    event_label?: string;
    department_id?: string;
    department_label?: string;
  };
  readonly access?: { organization_wide?: boolean };
  readonly totals?: {
    entry_count?: number;
    staff_count?: number;
    hours?: string;
    credits?: string;
  };
  readonly outstanding?: {
    open_hours_count?: number;
    uncredited_hours_count?: number;
    grace_closes_at?: string | null;
    grace_closed?: boolean;
  };
  readonly staff?: readonly {
    staff_id?: string;
    staff_name?: string;
    staff_handle?: string | null;
    entry_count?: number;
    hours?: string;
    credits?: string;
  }[];
  readonly entries?: readonly EntryPayload[];
}

export async function getDepartmentCreditReview(
  eventId: string,
  departmentId: string,
): Promise<DepartmentCreditReview> {
  const payload = await meridianJson<ReviewPayload>(
    `/api/events/${encodeURIComponent(eventId)}/departments/${encodeURIComponent(departmentId)}/credits`,
  );

  return {
    eventId: payload?.context?.event_id ?? eventId,
    eventLabel: payload?.context?.event_label ?? "",
    departmentId: payload?.context?.department_id ?? departmentId,
    departmentLabel: payload?.context?.department_label ?? "",
    organizationWide: payload?.access?.organization_wide ?? false,
    totals: {
      entryCount: payload?.totals?.entry_count ?? 0,
      staffCount: payload?.totals?.staff_count ?? 0,
      hours: payload?.totals?.hours ?? "0.00",
      credits: payload?.totals?.credits ?? "0.00",
    },
    outstanding: {
      openHoursCount: payload?.outstanding?.open_hours_count ?? 0,
      uncreditedHoursCount: payload?.outstanding?.uncredited_hours_count ?? 0,
      graceClosesAt: payload?.outstanding?.grace_closes_at ?? null,
      graceClosed: payload?.outstanding?.grace_closed ?? false,
    },
    staff: (payload?.staff ?? []).map((row) => ({
      staffId: row.staff_id ?? "",
      staffName: row.staff_name ?? "",
      staffHandle: row.staff_handle ?? null,
      entryCount: row.entry_count ?? 0,
      hours: row.hours ?? "0.00",
      credits: row.credits ?? "0.00",
    })),
    entries: (payload?.entries ?? []).map((entry) => ({
      id: entry.id ?? "",
      staffId: entry.staff_id ?? "",
      staffName: entry.staff_name ?? "",
      staffHandle: entry.staff_handle ?? null,
      shiftTitle: entry.shift_title ?? null,
      shiftStartsAt: entry.shift_starts_at ?? null,
      shiftEndsAt: entry.shift_ends_at ?? null,
      team: entry.team ?? null,
      entryType: entry.entry_type ?? "",
      status: entry.status ?? "",
      hours: entry.hours ?? "0.00",
      credits: entry.credits ?? "0.00",
      creditPolicyName: entry.credit_policy_name ?? null,
      creditMultiplier: entry.credit_multiplier ?? null,
      policySource: entry.policy_source ?? null,
      minutesWorked: entry.minutes_worked ?? null,
      calculatedAt: entry.calculated_at ?? null,
      hoursCorrectedAt: entry.hours_corrected_at ?? null,
      frozenAt: entry.frozen_at ?? null,
    })),
  };
}
