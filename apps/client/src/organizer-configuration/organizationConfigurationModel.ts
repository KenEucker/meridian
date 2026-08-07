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

/**
 * One of the four staff profile approval policies, with the words that explain
 * it (VOL-027).
 *
 * The list and its wording come from the node rather than being written into
 * this client, so the choice an organizer reads and the rule the server
 * enforces cannot drift, and a policy added later reaches this surface without
 * a client release.
 */
export interface ProfileChangePolicyOption {
  readonly value: string;
  readonly label: string;
  readonly handleDescription: string;
  readonly pictureDescription: string;
}

/** The configured values as the node holds them. */
export interface OrganizationConfigurationValues {
  readonly activeInactiveThresholdYears: number | null;
  readonly prospectiveInactiveThresholdYears: number | null;
  readonly calendarYearStartMonth: number | null;
  readonly calendarYearStartDay: number | null;
  readonly hoursCorrectionGracePeriodDays: number;
  /** Days before the active window start the Event Horizon opens (HORIZON-011). */
  readonly eventHorizonLeadDays: number;
  readonly defaultCreditPolicyId: string | null;
  readonly organizersDepartmentId: string | null;
  readonly defaultIcDepartmentId: string | null;
  readonly defaultPlacementDepartmentId: string | null;
  /** How handle and picture changes are decided here (VOL-027). */
  readonly handleChangePolicy: string;
  readonly profilePictureChangePolicy: string;
  /** Handle changes applied without review while the policy allows any (VOL-028). */
  readonly handleSelfServiceChangeLimit: number;
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
  readonly changePolicies: readonly ProfileChangePolicyOption[];
  readonly governance: OrganizationConfigurationGovernance;
}

/** The keys an update may carry; a present null clears the value. */
export interface OrganizationConfigurationUpdate {
  readonly active_inactive_threshold_years?: number | null;
  readonly prospective_inactive_threshold_years?: number | null;
  readonly calendar_year_start_month?: number | null;
  readonly calendar_year_start_day?: number | null;
  readonly hours_correction_grace_period_days?: number;
  readonly event_horizon_lead_days?: number;
  readonly default_credit_policy_id?: string | null;
  readonly organizers_department_id?: string | null;
  readonly default_ic_department_id?: string | null;
  readonly default_placement_department_id?: string | null;
  readonly handle_change_policy?: string | null;
  readonly profile_picture_change_policy?: string | null;
  readonly handle_self_service_change_limit?: number | null;
}

interface ConfigurationPayload {
  readonly organization_id?: string;
  readonly configuration?: {
    readonly active_inactive_threshold_years?: number | null;
    readonly prospective_inactive_threshold_years?: number | null;
    readonly calendar_year_start_month?: number | null;
    readonly calendar_year_start_day?: number | null;
    readonly hours_correction_grace_period_days?: number;
    readonly event_horizon_lead_days?: number;
    readonly default_credit_policy_id?: string | null;
    readonly organizers_department_id?: string | null;
    readonly default_ic_department_id?: string | null;
    readonly default_placement_department_id?: string | null;
    readonly handle_change_policy?: string;
    readonly profile_picture_change_policy?: string;
    readonly handle_self_service_change_limit?: number;
  };
  readonly options?: {
    readonly departments?: { id: string; name: string }[];
    readonly credit_policies?: { id: string; name: string }[];
    readonly change_policies?: {
      value: string;
      label: string;
      handle_description: string;
      picture_description: string;
    }[];
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
      eventHorizonLeadDays: values.event_horizon_lead_days ?? 30,
      defaultCreditPolicyId: values.default_credit_policy_id ?? null,
      organizersDepartmentId: values.organizers_department_id ?? null,
      defaultIcDepartmentId: values.default_ic_department_id ?? null,
      defaultPlacementDepartmentId:
        values.default_placement_department_id ?? null,
      handleChangePolicy: values.handle_change_policy ?? "organizer_only",
      profilePictureChangePolicy:
        values.profile_picture_change_policy ?? "organizer_only",
      handleSelfServiceChangeLimit:
        values.handle_self_service_change_limit ?? 2,
    },
    departments: payload.options?.departments ?? [],
    creditPolicies: payload.options?.credit_policies ?? [],
    changePolicies: (payload.options?.change_policies ?? []).map((option) => ({
      value: option.value,
      label: option.label,
      handleDescription: option.handle_description,
      pictureDescription: option.picture_description,
    })),
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
