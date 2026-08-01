// The organization's incident type list, and how an organizer maintains it
// (M18.14A; ORG-018, ORG-020; INC-007).
//
// Incident types have been configurable per organization since M11.7A — the
// table is keyed by `organization_id` and carries `archived_at` — and until this
// task there was no way to configure them. The incident form created them as a
// side effect of being filled in, so an organization's vocabulary was whatever
// had been typed into an incident, one spelling at a time.
//
// This module is the translation of the administration endpoints. Two things
// about it are worth stating:
//
//  1. **Archived types are part of the read.** Every other list in this client
//     hides archived rows; this one shows them, because restoring one is the
//     reason a maintainer is here and a row you cannot see is a row you cannot
//     restore. `archived` on each row is what tells them apart.
//  2. **Every write is followed by a re-read.** The commands answer with the
//     row they changed, but a rename can move a row's place in the sort and an
//     archive changes what the incident form will offer. Asking again is one
//     request and keeps the screen and the node in agreement.

import { meridianJson } from "@/api/meridianApi";
import { sendConnectedCommand } from "@/outbox/submitCommand";

/** One incident type, as the administration read reports it. */
export interface OrganizationIncidentType {
  readonly id: string;
  readonly name: string;
  readonly archived: boolean;
  readonly archivedAt: string | null;
  readonly createdAt: string | null;
  /** How many incidents already carry it, so archiving is not a silent act. */
  readonly incidentCount: number;
}

export interface OrganizationIncidentTypeList {
  readonly organizationId: string;
  readonly incidentTypes: readonly OrganizationIncidentType[];
}

interface IncidentTypePayload {
  readonly id: string;
  readonly name: string;
  readonly archived?: boolean;
  readonly archived_at?: string | null;
  readonly created_at?: string | null;
  readonly incident_count?: number;
}

interface IncidentTypeListPayload {
  readonly organization_id?: string;
  readonly incident_types?: IncidentTypePayload[];
}

export async function getOrganizationIncidentTypes(
  organizationId: string,
): Promise<OrganizationIncidentTypeList> {
  const payload = await meridianJson<IncidentTypeListPayload>(
    `/api/organizations/${encodeURIComponent(organizationId)}/incident-types`,
  );

  return {
    organizationId: payload.organization_id ?? organizationId,
    incidentTypes: (payload.incident_types ?? []).map((type) => ({
      id: type.id,
      name: type.name,
      archived: type.archived ?? type.archived_at !== null,
      archivedAt: type.archived_at ?? null,
      createdAt: type.created_at ?? null,
      incidentCount: type.incident_count ?? 0,
    })),
  };
}

export async function createIncidentType(
  organizationId: string,
  name: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "create-incident-type",
    idempotencyKey: commandIdempotencyKey(),
    payload: { organization_id: organizationId, name: name.trim() },
  });
}

export async function renameIncidentType(
  incidentTypeId: string,
  name: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "rename-incident-type",
    idempotencyKey: commandIdempotencyKey(),
    payload: { incident_type_id: incidentTypeId, name: name.trim() },
  });
}

export async function archiveIncidentType(
  incidentTypeId: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "archive-incident-type",
    idempotencyKey: commandIdempotencyKey(),
    payload: { incident_type_id: incidentTypeId },
  });
}

export async function restoreIncidentType(
  incidentTypeId: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "restore-incident-type",
    idempotencyKey: commandIdempotencyKey(),
    payload: { incident_type_id: incidentTypeId },
  });
}

function commandIdempotencyKey(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  return typeof cryptoScope?.randomUUID === "function"
    ? cryptoScope.randomUUID()
    : `incident-type-command-${Date.now()}-${Math.random().toString(16).slice(2)}`;
}
