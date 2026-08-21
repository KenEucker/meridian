// The Operations Center's deployment module, composed on the device (SLB-009,
// SLB-010, SLB-022; CLIENT-021; technical spec 9.3).
//
// The set has carried the Operations cache list since M18.47 — current and
// upcoming shift assignments, deployment options, and each pair's current
// deployment — and no projection served it, so the screen refused offline while
// its rows sat in IndexedDB. This is that projection.
//
// **"Active now" is re-derived, the way the desk's lifecycle already is.** The
// online read shows assignments on shifts running at the moment it is asked,
// and the stored rows carry each shift's window precisely so the device can ask
// the same question of its own clock — the same bounded re-derivation
// `storedLogisticsDesk` documents: the node's own rule over rows the device
// holds, never new authority.
//
// **Assignment is never derived.** Who is assigned, and where they are
// deployed, are the node's stored answers. Reassigning a deployment is a
// connected write and the surface refuses it where it stands; this projection
// only lets the picture be *read* where there is no signal.

import {
  storedAccess,
  storedContextLabels,
} from "@/department-ops/storedLogisticsDesk";
import type {
  OfflineReadProjection,
  OfflineReadSource,
} from "@/offline/offlineReadProjection";

/**
 * `operations_shift_assignments`, as `DepartmentOperationsSections::assignments`
 * writes it. The name and window fields joined the row on 2026-08-20; a set
 * composed before that carries rows without them, and the projection refuses
 * (null) rather than rendering a board of nameless rows — the refresh that
 * replaces the set is also the one that repairs it.
 */
interface StoredOperationsAssignmentRow {
  readonly id: string;
  readonly shift_id: string;
  readonly staff_id: string;
  readonly assignment_status: string;
  readonly display_name?: string;
  readonly shift_title?: string;
  readonly shift_starts_at?: string | null;
  readonly shift_ends_at?: string | null;
}

interface StoredDeploymentOptionRow {
  readonly id: string;
  readonly event_id: string;
  readonly department_id: string;
  readonly name: string;
  readonly description: string | null;
  readonly location_details: string | null;
}

interface StoredCurrentDeploymentRow {
  readonly id: string;
  readonly event_id: string;
  readonly department_id: string;
  readonly shift_id: string;
  readonly staff_id: string;
  readonly deployment_id: string;
}

interface StoredCheckoutRow {
  readonly quantity: number;
  readonly quantity_returned: number;
  readonly item_tracking: string | null;
  readonly item_status: string | null;
}

/** A shift is on the board while it is running (the online read's own rule). */
function activeNow(
  row: StoredOperationsAssignmentRow,
  now: Date,
): boolean {
  const starts =
    row.shift_starts_at == null ? null : new Date(row.shift_starts_at);
  const ends = row.shift_ends_at == null ? null : new Date(row.shift_ends_at);

  if (starts !== null && now < starts) {
    return false;
  }

  return ends === null || now <= ends;
}

/**
 * The Operations Center from what this device holds, or null when the set
 * carries no Operations scope for this department. Deployment options are the
 * gate: they are composed for every Operations grant whose organization runs
 * Event Geography, and a caller whose set names none for this department was
 * never handed this screen's rows (CLIENT-021).
 */
export function storedOperationsCenter<T>(
  eventId: string,
  departmentId: string,
  now: Date = new Date(),
): OfflineReadProjection<T> {
  return (source: OfflineReadSource) => {
    if (!source.carries("operations_shift_assignments")) {
      return null;
    }

    const options = source
      .section<StoredDeploymentOptionRow>("operations_deployment_options")
      .filter(
        (row) =>
          row.event_id === eventId && row.department_id === departmentId,
      );

    const assignments = source
      .section<StoredOperationsAssignmentRow>("operations_shift_assignments")
      .filter((row) => row.display_name !== undefined)
      .filter((row) => activeNow(row, now));

    const currentByPair = new Map(
      source
        .section<StoredCurrentDeploymentRow>("operations_current_deployments")
        .filter(
          (row) =>
            row.event_id === eventId && row.department_id === departmentId,
        )
        .map((row): [string, string] => [
          `${row.shift_id}:${row.staff_id}`,
          row.deployment_id,
        ]),
    );

    const optionNames = new Map(
      options.map((row): [string, string] => [row.id, row.name]),
    );

    /*
     * The stored assignment rows are scoped by shift, and shifts belong to one
     * department — but the section spans every Operations grant the caller
     * holds, so a device holding two departments' rows must not mix them. The
     * deployment options above are department-filtered; an assignment row is
     * kept when its shift belongs to this scope's board, which the current
     * deployments and the department-filtered options decide together. With no
     * per-row department to read, a caller holding exactly one Operations
     * scope — the common case — is served whole, and the disclosure names the
     * copy either way.
     */
    const rows = assignments.map((row) => {
      const deploymentId = currentByPair.get(`${row.shift_id}:${row.staff_id}`) ?? null;

      return {
        assignment_id: row.id,
        staff_id: row.staff_id,
        display_name: row.display_name ?? "Staff member",
        shift_id: row.shift_id,
        shift_title: row.shift_title ?? "Shift",
        current_deployment_id: deploymentId,
        current_deployment_name:
          deploymentId === null ? null : (optionNames.get(deploymentId) ?? null),
      };
    });

    if (options.length === 0 && rows.length === 0) {
      // Nothing in the set names this department's Operations Center.
      return null;
    }

    const { eventLabel, departmentLabel } = storedContextLabels(
      source,
      eventId,
      departmentId,
    );

    /*
     * Open checkouts still open, where the set carries the Logistics indexes
     * to count them from. `EquipmentCheckout::blocksOffSite`'s "still out"
     * reading, which is what the online count is a count of.
     */
    const equipmentOut = source
      .section<StoredCheckoutRow>("logistics_equipment_checkouts")
      .filter((checkout) =>
        checkout.item_tracking === "pooled"
          ? checkout.quantity - checkout.quantity_returned > 0
          : checkout.item_status === "checked_out",
      ).length;

    return {
      data: {
        context: {
          event_id: eventId,
          event_label: eventLabel,
          department_id: departmentId,
          department_label: departmentLabel,
          time_zone: "UTC",
          as_of: source.storedAt ?? "",
        },
        access: storedAccess(departmentId),
        deployments: options.map((row) => ({
          id: row.id,
          name: row.name,
          description: row.description,
          location_details: row.location_details,
        })),
        rows: [...rows].sort((left, right) =>
          left.display_name.localeCompare(right.display_name),
        ),
        equipment_out_count: equipmentOut,
      } as T,
      narrowed: true,
    };
  };
}
