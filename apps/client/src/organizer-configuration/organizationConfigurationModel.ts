// Organization configuration, and how an organizer edits it (M18.14; ORG-017,
// ORG-018, ORG-020, ORG-021; data/API 10.1).
//
// One read answers with the current values, the departments and credit
// policies eligible to be chosen, and the governance state — whether this node
// holds configuration authority and whether an active event window is freezing
// edits — so the surface can explain a freeze before an organizer discovers it
// by having a save refused.
//
// The update is partial: only the keys a save carries are touched, and a
// present null clears the value. This is a connected-only surface: every write
// is followed by a re-read of the node's answer, and a refusal is the node's
// own sentence rather than a second copy of its rules.

import { meridianJson } from "@/api/meridianApi";

/** A department eligible to carry one of the three designations. */
export interface ConfigurationDepartmentOption {
  readonly id: string;
  readonly name: string;
}

/** An organization-level credit policy eligible to be the default. */
export interface ConfigurationCreditPolicyOption {
  readonly id: string;
  readonly name: string;
}

/** The configured values as the node holds them. */
export interface OrganizationConfigurationValues {
  readonly activeInactiveThresholdYears: number | null;
  readonly prospectiveInactiveThresholdYears: number | null;
  readonly calendarYearStartMonth: number | null;
  readonly calendarYearStartDay: number | null;
  readonly hoursCorrectionGracePeriodDays: number;
  readonly defaultCreditPolicyId: string | null;
  readonly organizersDepartmentId: string | null;
  readonly defaultIcDepartmentId: string | null;
  readonly defaultPlacementDepartmentId: string | null;
}

/** Whether edits are possible here and now, and why not when they are not. */
export interface OrganizationConfigurationGovernance {
  readonly editable: boolean;
  readonly holdsAuthority: boolean;
  readonly frozenByEvent: { id: string; name: string } | null;
}

/** Everything the configuration featureset renders, from one read. */
export interface OrganizationConfiguration {
  readonly organizationId: string;
  readonly values: OrganizationConfigurationValues;
  readonly departments: readonly ConfigurationDepartmentOption[];
  readonly creditPolicies: readonly ConfigurationCreditPolicyOption[];
  readonly governance: OrganizationConfigurationGovernance;
}

/** The keys an update may carry; a present null clears the value. */
export interface OrganizationConfigurationUpdate {
  readonly active_inactive_threshold_years?: number | null;
  readonly prospective_inactive_threshold_years?: number | null;
  readonly calendar_year_start_month?: number | null;
  readonly calendar_year_start_day?: number | null;
  readonly hours_correction_grace_period_days?: number;
  readonly default_credit_policy_id?: string | null;
  readonly organizers_department_id?: string | null;
  readonly default_ic_department_id?: string | null;
  readonly default_placement_department_id?: string | null;
}

interface ConfigurationPayload {
  readonly organization_id?: string;
  readonly configuration?: {
    readonly active_inactive_threshold_years?: number | null;
    readonly prospective_inactive_threshold_years?: number | null;
    readonly calendar_year_start_month?: number | null;
    readonly calendar_year_start_day?: number | null;
    readonly hours_correction_grace_period_days?: number;
    readonly default_credit_policy_id?: string | null;
    readonly organizers_department_id?: string | null;
    readonly default_ic_department_id?: string | null;
    readonly default_placement_department_id?: string | null;
  };
  readonly options?: {
    readonly departments?: { id: string; name: string }[];
    readonly credit_policies?: { id: string; name: string }[];
  };
  readonly governance?: {
    readonly editable?: boolean;
    readonly holds_authority?: boolean;
    readonly frozen_by_event?: { id: string; name: string } | null;
  };
}

function toConfiguration(
  organizationId: string,
  payload: ConfigurationPayload,
): OrganizationConfiguration {
  const values = payload.configuration ?? {};

  return {
    organizationId: payload.organization_id ?? organizationId,
    values: {
      activeInactiveThresholdYears: values.active_inactive_threshold_years ?? null,
      prospectiveInactiveThresholdYears:
        values.prospective_inactive_threshold_years ?? null,
      calendarYearStartMonth: values.calendar_year_start_month ?? null,
      calendarYearStartDay: values.calendar_year_start_day ?? null,
      hoursCorrectionGracePeriodDays:
        values.hours_correction_grace_period_days ?? 14,
      defaultCreditPolicyId: values.default_credit_policy_id ?? null,
      organizersDepartmentId: values.organizers_department_id ?? null,
      defaultIcDepartmentId: values.default_ic_department_id ?? null,
      defaultPlacementDepartmentId:
        values.default_placement_department_id ?? null,
    },
    departments: payload.options?.departments ?? [],
    creditPolicies: payload.options?.credit_policies ?? [],
    governance: {
      editable: payload.governance?.editable ?? true,
      holdsAuthority: payload.governance?.holds_authority ?? true,
      frozenByEvent: payload.governance?.frozen_by_event ?? null,
    },
  };
}

export async function getOrganizationConfiguration(
  organizationId: string,
): Promise<OrganizationConfiguration> {
  const payload = await meridianJson<ConfigurationPayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/configuration`,
  );

  return toConfiguration(organizationId, payload);
}

export async function updateOrganizationConfiguration(
  organizationId: string,
  update: OrganizationConfigurationUpdate,
): Promise<OrganizationConfiguration> {
  const payload = await meridianJson<ConfigurationPayload>(
    "/api/commands/update-organization-configuration",
    {
      method: "POST",
      body: JSON.stringify({ organization_id: organizationId, ...update }),
    },
  );

  return toConfiguration(organizationId, payload);
}
