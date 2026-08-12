// Which modules the organization runs, and how an organizer changes it
// (M19.15; ORG-018, ORG-020, ORG-021; MOD-008, MOD-010, MOD-011; data/API 6.8,
// 10.1A).
//
// One read answers with the modules the platform has made available to this
// organization, whether each is switched on, and the governance state — the
// same pair of rules the rest of the ORG-018 surface answers to, so the section
// can explain a freeze before an organizer discovers it by having a save
// refused.
//
// The list is entitled modules only (MOD-008). A module Meridian does not offer
// this organization is absent rather than present and disabled, because
// entitlement is not the organizer's to change and a control that could never
// be used is worse than no control.
//
// The update is partial: a module the save does not name is left alone, and a
// module already in the state asked for is not written at all. This is a
// connected-only surface, on the same footing as the operational settings
// beside it: every write is followed by the node's own answer, and a refusal is
// the node's sentence rather than a second copy of its rules.

import { meridianJson } from "@/api/meridianApi";
import type { OrganizationConfigurationGovernance } from "@/organizer-configuration/organizationConfigurationModel";

/** One module the organization may choose to run. */
export interface OrganizationModuleChoice {
  readonly key: string;
  /** Meridian's own name for the module (MOD-022); never the key. */
  readonly name: string;
  /** What MOD-002 says the module covers, in the node's words. */
  readonly summary: string;
  readonly enabled: boolean;
  readonly changedAt: string | null;
  readonly changedBy: string | null;
}

/** Everything the modules featureset renders, from one read. */
export interface OrganizationModuleSelection {
  readonly organizationId: string;
  readonly modules: readonly OrganizationModuleChoice[];
  readonly governance: OrganizationConfigurationGovernance;
}

interface ModulePayload {
  readonly organization_id?: string;
  readonly modules?: {
    key?: string;
    name?: string;
    summary?: string;
    enabled?: boolean;
    changed_at?: string | null;
    changed_by?: string | null;
  }[];
  readonly governance?: {
    readonly editable?: boolean;
    readonly holds_authority?: boolean;
    readonly frozen_by_event?: { id: string; name: string } | null;
  };
}

function toSelection(
  organizationId: string,
  payload: ModulePayload,
): OrganizationModuleSelection {
  return {
    organizationId: payload.organization_id ?? organizationId,
    modules: (payload.modules ?? [])
      .filter((module): module is { key: string } & typeof module =>
        typeof module.key === "string" && module.key !== "",
      )
      .map((module) => ({
        key: module.key,
        // A node that named no module rather than no key is not one this
        // client should invent a label for: the key is an identifier and
        // MOD-022 keeps it out of the interface, so fall back to nothing
        // readable rather than to something wrong.
        name: module.name ?? "",
        summary: module.summary ?? "",
        enabled: module.enabled ?? true,
        changedAt: module.changed_at ?? null,
        changedBy: module.changed_by ?? null,
      })),
    governance: {
      editable: payload.governance?.editable ?? true,
      holdsAuthority: payload.governance?.holds_authority ?? true,
      frozenByEvent: payload.governance?.frozen_by_event ?? null,
    },
  };
}

export async function getOrganizationModules(
  organizationId: string,
): Promise<OrganizationModuleSelection> {
  const payload = await meridianJson<ModulePayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/modules`,
  );

  return toSelection(organizationId, payload);
}

export async function updateOrganizationModules(
  organizationId: string,
  modules: Record<string, boolean>,
  reason: string | null,
): Promise<OrganizationModuleSelection> {
  const payload = await meridianJson<ModulePayload>(
    "/api/commands/update-organization-modules",
    {
      method: "POST",
      body: JSON.stringify({
        organization_id: organizationId,
        modules,
        ...(reason === null ? {} : { reason }),
      }),
    },
  );

  return toSelection(organizationId, payload);
}

/**
 * When the organization last moved this module, said the way the rest of the
 * configuration surface says such things.
 *
 * The audit trail is the record; this is the line beside the control that saves
 * an organizer opening it to answer "did somebody already do this".
 */
export function moduleHistoryLabel(module: OrganizationModuleChoice): string {
  if (module.changedAt === null) {
    return "Never changed. Running by default.";
  }

  const when = new Date(module.changedAt).toLocaleString(undefined, {
    dateStyle: "medium",
    timeStyle: "short",
  });

  return module.changedBy === null
    ? `Last changed ${when}.`
    : `Last changed ${when} by ${module.changedBy}.`;
}
