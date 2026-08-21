// The Planning Table, served from the aggregates the node computed (SLB-019,
// SLB-020; CLIENT-021; technical spec 9.3).
//
// M18.47 put "identity-free plan-versus-actual aggregate rows" in the offline
// read set — `planning_aggregates`, computed by the node's own `PlanVersusActual`
// arithmetic at composition time — and no projection served them, so the
// Planning Table refused offline while the exact rows it renders sat in
// IndexedDB. This is that projection.
//
// **Nothing is recomputed.** The counts on each row are counts *of* identities
// this device deliberately does not hold (SLB-019), so the device could not
// recompute them if it wanted to — and it does not want to: the row is the
// node's answer, disclosed as a stored copy taken at the moment the set was.
// The one thing applied on the device is the team and date narrowing, which the
// read model already does for a narrowed read (`narrowPlanningRows`), because a
// filter is a selection over rows rather than arithmetic on them.

import {
  storedAccess,
  storedContextLabels,
} from "@/department-ops/storedLogisticsDesk";
import type {
  OfflineReadProjection,
  OfflineReadSource,
} from "@/offline/offlineReadProjection";

/** `planning_aggregates`, as `DepartmentPlanningSections::aggregateRows` writes it. */
interface StoredAggregateRow {
  readonly id: string;
  readonly event_id: string;
  readonly department_id: string;
  readonly shift_id: string;
  readonly title: string;
  readonly team_id: string;
  readonly team_label: string | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly lifecycle: string;
  readonly capacity: number | null;
  readonly signed_up_or_assigned_count: number;
  readonly checked_in_count: number;
  readonly no_show_count: number;
  readonly unscheduled_count: number;
  readonly planned_hours: number;
  readonly actual_hours: number;
  readonly variance_hours: number;
  readonly status_label: string;
}

interface StoredTeamRow {
  readonly id: string;
  readonly department_id: string;
  readonly name: string;
}

/**
 * The Planning Table from what this device holds, or null when the set carries
 * no planning scope for this department — a caller without the Planning role
 * here, or an organization not running Scheduling (MOD-016). Null is the honest
 * refusal: the seam turns it into the transport failure the surface would have
 * shown anyway, rather than an empty table presented as a quiet schedule.
 */
export function storedPlanningTable<T>(
  eventId: string,
  departmentId: string,
  filters: { readonly teamId: string | null; readonly date: string | null },
): OfflineReadProjection<T> {
  return (source: OfflineReadSource) => {
    if (!source.carries("planning_aggregates")) {
      return null;
    }

    const teams = source
      .section<StoredTeamRow>("planning_teams")
      .filter((team) => team.department_id === departmentId);

    if (teams.length === 0) {
      /*
       * The set carries a Planning scope, but not this department's. Serving
       * an empty table here would present a department the caller holds no
       * Planning role in as having no schedule (CLIENT-021).
       */
      return null;
    }

    const rows = source
      .section<StoredAggregateRow>("planning_aggregates")
      .filter(
        (row) =>
          row.event_id === eventId && row.department_id === departmentId,
      );

    const { eventLabel, departmentLabel } = storedContextLabels(
      source,
      eventId,
      departmentId,
    );

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
        teams: teams.map((team) => ({
          team_id: team.id,
          team_label: team.name,
        })),
        /*
         * The selection as asked, so the controls render what the reader chose.
         * The rows below are the whole stored table; the read model narrows
         * them by this selection exactly because the answer is narrowed.
         */
        filters: {
          team_id: filters.teamId,
          date: filters.date,
        },
        rows: rows.map((row) => ({
          shift_id: row.shift_id,
          title: row.title,
          team_id: row.team_id,
          team_label: row.team_label,
          starts_at: row.starts_at,
          ends_at: row.ends_at,
          lifecycle: row.lifecycle,
          capacity: row.capacity,
          signed_up_or_assigned_count: row.signed_up_or_assigned_count,
          checked_in_count: row.checked_in_count,
          no_show_count: row.no_show_count,
          unscheduled_count: row.unscheduled_count,
          planned_hours: row.planned_hours,
          actual_hours: row.actual_hours,
          variance_hours: row.variance_hours,
          status_label: row.status_label,
        })),
      } as T,
      narrowed: true,
    };
  };
}
