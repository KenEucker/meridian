// The Logistics Desk, composed on the device (M18.53; SLB-003, SLB-017,
// SLB-018, SLB-021; CLIENT-021; technical spec 9.3, 9.4, 20.2, 20.5).
//
// M18.47 put the desk's rows in the offline read set — the staff index,
// presence, the shift index, assignments, attendance, equipment, and open
// checkouts — and no read model was moved onto them, so the desk stayed
// connected-only. That mattered more than a missing screen: check-in, check-out,
// and mark-no-show are Alpha 1 offline writes (technical spec 9.4), and the
// surface that issues all three would not render without a node. The writes were
// promised and unreachable.
//
// **This derives verdicts, and that needs saying plainly.** The desk payload
// carries `can_check_in`, `can_check_out`, and `can_mark_no_show` per card, and
// they are the node's answers. What makes deriving them here something other
// than a client inventing authority is that the node derives them too, from four
// facts this device is holding: whether an assignment exists, the presence
// state, the attendance state, and the shift's own window against a clock. The
// rule is copied from `DepartmentOperationsReadController::shiftCards` and the
// off-site block from `DepartmentPresenceService`, in their words, so a desk with
// no signal offers exactly the controls the desk with one offers.
//
// The half that is *not* derived is authority. `access` is read from the session
// document — the node's own answer to what this caller may do, cached with the
// session since M16.4 — and never assembled from anything else. A device that
// invented `can_manage_attendance` would be a device granting itself a
// capability, which is a different act from re-reading a rule.
//
// **Two verdicts are refused offline rather than derived**, each because the
// fact behind it is not on the device:
//
//   - `can_correct_hours` — the correction grace period is the node's clock and
//     closes whether or not a device is reachable.
//   - the equipment handoff controls, which are connected-only (CLIENT-018) and
//     already refuse where they stand.
//
// **`can_add_to_shift` joined the derived side in M18.54**, when the unscheduled
// addition became an offline write (technical spec 9.4). What is derived is the
// node's *offering* rule and only that: no live assignment, the person on-site,
// the shift started, and an eligible team membership — four facts the device now
// holds, the last of them because M18.54 put `eligible_team_ids` on the staff
// index for it. What is not derived, and never was, is eligibility: Do Not
// Staff, department standing, required trainings and waivers are the node's, and
// a queued addition it refuses comes back with its reason on it (CLIENT-017).
// That is the same division the online desk already makes — `can_add_to_shift`
// has always been the loose side of an approximation, with
// `UnscheduledShiftAdditionService` weighing the rest.
//
// **Two queues are unioned into the rows** (M18.50; data/API 5.6). A pending
// on-site mark counts as presence and a pending addition counts as an
// assignment, because the operator who queued them is looking at this screen and
// the work is real — it is simply not at the node yet. Without that, an operator
// with no signal would mark somebody on-site, watch the pill stay off-site, and
// be told the addition cannot be offered because they are not here. Only queued
// and sending commands count; a refusal is not a record (CLIENT-017).
//
// What is on screen is disclosed as a stored copy through the freshness the seam
// reports, exactly as every other projection's is.

import {
  unionPendingByDeviceId,
  type OfflineReadProjection,
  type OfflineReadSource,
} from "@/offline/offlineReadProjection";
import {
  departmentHasCapability,
  sessionDepartmentAccesses,
} from "@/session/sessionAccess";

/*
 * The refusals in the node's own words (SLB-017, SLB-018), so an operator reads
 * the same sentence whether or not there is a node behind it.
 */
const CHECKED_IN_BLOCK =
  "Staff must be checked out from department shifts before being marked off-site.";
const OPEN_EQUIPMENT_BLOCK =
  "Staff must return or resolve checked-out department equipment before being marked off-site.";
/*
 * The two reasons the node prints when it does not offer the addition
 * (`DepartmentOperationsReadController::addToShiftBlockedReason`), in its words.
 * The archived-team variant stays the node's alone: the device holds no archived
 * flag for a team, and guessing at one would name the wrong obstacle.
 */
const NOT_STARTED_BLOCK =
  "This shift has not started yet. Staff can be added once it is running.";
const NOT_ELIGIBLE_BLOCK = "This shift is for another team.";

/** The node's team-named variant of the same refusal, when the shift names one. */
function notEligibleBlock(team: string | null): string {
  return team === null
    ? NOT_ELIGIBLE_BLOCK
    : `This shift is for the ${team} team, and they are not a member of it.`;
}

/** `logistics_staff_index`, as `DepartmentLogisticsSections::staffIndex` writes it. */
interface StoredStaffRow {
  readonly event_id: string;
  readonly department_id: string;
  readonly staff_id: string;
  readonly legal_name: string | null;
  readonly preferred_name: string | null;
  readonly handle: string | null;
  readonly team_label: string | null;
  /**
   * The teams whose shifts this person may be added to (M18.54; SLB-008).
   *
   * Optional because a set composed before M18.54 does not carry it, and a
   * device holding one of those must read "no eligible team" rather than
   * assuming eligibility it was never told about — the refusal is recoverable
   * by refreshing the set; a wrongly offered addition is a refusal at the node.
   */
  readonly eligible_team_ids?: readonly string[];
  readonly archived_at: string | null;
}

interface StoredPresenceRow {
  readonly event_id: string;
  readonly department_id: string;
  readonly staff_id: string;
  readonly current_state: string;
}

interface StoredShiftRow {
  readonly id: string;
  readonly event_id: string;
  readonly department_id: string;
  readonly eligible_team_id: string | null;
  readonly title: string;
  readonly team_name_snapshot: string | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly capacity: number | null;
  readonly cancelled_at: string | null;
}

interface StoredAssignmentRow {
  readonly id: string;
  readonly shift_id: string;
  readonly staff_id: string;
  readonly assignment_status: string;
}

interface StoredAttendanceRow {
  readonly event_id: string;
  readonly department_id: string;
  readonly shift_id: string;
  readonly staff_id: string;
  readonly current_state: string;
}

interface StoredEquipmentRow {
  readonly id: string;
  readonly department_id: string | null;
  readonly name: string;
  readonly tracking: string;
  readonly asset_tag: string | null;
  readonly serial_number: string | null;
  readonly quantity_total: number;
  readonly status: string;
}

interface StoredCheckoutRow {
  readonly id: string;
  readonly equipment_item_id: string;
  readonly staff_id: string;
  readonly shift_id: string | null;
  readonly quantity: number;
  readonly quantity_returned: number;
  readonly checked_out_at: string | null;
  readonly item_name: string | null;
  readonly item_tracking: string | null;
  readonly item_asset_tag: string | null;
  readonly item_status: string | null;
}

interface StoredSignupRow {
  readonly id: string;
  readonly shift_id: string;
  readonly staff_id: string;
  readonly shift_title: string | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly state?: string;
}

interface StoredNamedRow {
  readonly id: string;
  readonly name: string | null;
}

/**
 * The event and department labels a stored envelope names, from the core
 * sections every set carries. Exported for the same reason {@link storedAccess}
 * is: one derivation, three projections.
 */
export function storedContextLabels(
  source: OfflineReadSource,
  eventId: string,
  departmentId: string,
): { readonly eventLabel: string; readonly departmentLabel: string } {
  return {
    eventLabel:
      source.section<StoredNamedRow>("events").find((row) => row.id === eventId)
        ?.name ?? "",
    departmentLabel:
      source
        .section<StoredNamedRow>("departments")
        .find((row) => row.id === departmentId)?.name ?? "",
  };
}

/**
 * Where a shift is in its own life, as of a moment.
 *
 * `ShiftLifecycle::of` in TypeScript, kept to four answers with no fifth for the
 * reason that class gives: two surfaces deriving this separately would
 * eventually disagree about when a shift stops being upcoming, and the
 * disagreement would surface as a desk offering a control the node refuses.
 */
function lifecycleOf(shift: StoredShiftRow, now: Date): string {
  if (shift.cancelled_at !== null) {
    return "cancelled";
  }

  const starts = shift.starts_at === null ? null : new Date(shift.starts_at);
  const ends = shift.ends_at === null ? null : new Date(shift.ends_at);

  if (starts !== null && now < starts) {
    return "upcoming";
  }

  if (ends !== null && now > ends) {
    return "completed";
  }

  return "active";
}

/** Handle first, which is how a desk addresses somebody (VOL-010). */
function displayName(row: StoredStaffRow): string {
  return row.preferred_name ?? row.legal_name ?? row.handle ?? "Unnamed";
}

/**
 * Whether a checkout is a reason to refuse an off-site mark.
 *
 * `EquipmentCheckout::blocksOffSite`. Everything outstanding is listed on the
 * workspace; only some of it keeps somebody here, because an item written off as
 * missing or damaged is still owed to the department and is no longer a reason
 * to hold them on site (SLB-018).
 */
function blocksOffSite(checkout: StoredCheckoutRow): boolean {
  if (checkout.item_tracking === "pooled") {
    return checkout.quantity - checkout.quantity_returned > 0;
  }

  return checkout.item_status === "checked_out";
}

function groupBy<T>(
  rows: readonly T[],
  key: (row: T) => string,
): Map<string, T[]> {
  const grouped = new Map<string, T[]>();

  for (const row of rows) {
    const id = key(row);
    const held = grouped.get(id);

    if (held === undefined) {
      grouped.set(id, [row]);
    } else {
      held.push(row);
    }
  }

  return grouped;
}

/**
 * What this caller may do at this desk, from the session document.
 *
 * The node's answer, cached with the session (CLIENT-004), rather than anything
 * assembled here. `is_department_lead` follows `department.administer` the same
 * way the node's own envelope does.
 *
 * Exported for the other department-ops projections (Planning Table,
 * Operations Center): the rule is the session document's and stating it once
 * keeps three stored envelopes from disagreeing about what a caller may do.
 */
export function storedAccess(departmentId: string) {
  const department =
    sessionDepartmentAccesses.value.find(
      (entry) => entry.departmentId === departmentId,
    ) ?? null;

  const holds = (capability: string): boolean =>
    departmentHasCapability(department, capability);

  const administers = holds("department.administer");

  return {
    is_department_lead: administers,
    can_manage_presence: administers || holds("department.presence.manage"),
    can_manage_attendance: administers || holds("department.attendance.manage"),
    can_manage_equipment: administers || holds("department.equipment.manage"),
    can_assign_deployments: administers || holds("department.deployments.assign"),
    can_manage_planning: administers || holds("department.schedule.manage"),
    can_administer_department: administers,
  };
}

/**
 * The Logistics Desk from what this device holds.
 *
 * Null when the set carries no staff index for this desk, which is the honest
 * answer for a device that was never handed one — a caller who does not hold a
 * Logistics scope, or an organization not running the modules behind it
 * (MOD-016). The seam turns that into the transport failure the surface would
 * have shown anyway.
 */
export function storedLogisticsDesk<T>(
  eventId: string,
  departmentId: string,
  now: Date = new Date(),
): OfflineReadProjection<T> {
  return (source: OfflineReadSource) => {
    if (!source.carries("logistics_staff_index")) {
      return null;
    }

    const here = <R extends { readonly event_id?: string; readonly department_id?: string }>(
      rows: readonly R[],
    ): readonly R[] =>
      rows.filter(
        (row) =>
          (row.event_id === undefined || row.event_id === eventId) &&
          (row.department_id === undefined || row.department_id === departmentId),
      );

    const staff = here(
      source.section<StoredStaffRow>("logistics_staff_index"),
    ).filter((row) => row.archived_at === null);

    if (staff.length === 0) {
      return null;
    }

    const presence = new Map(
      here(source.section<StoredPresenceRow>("logistics_presence")).map(
        (row): [string, string] => [row.staff_id, row.current_state],
      ),
    );

    /*
     * On-site marks this device is still holding (M18.54). Later than any stored
     * row by construction — the queue is what happened after the set was handed
     * over — so they overwrite rather than being unioned in. Nothing here can
     * move somebody the other way: off-site is connected-only, so a queued mark
     * only ever says "this person is standing here".
     */
    for (const command of source.pending("mark-staff-on-site")) {
      if (
        command.payload.event_id === eventId &&
        command.payload.department_id === departmentId &&
        typeof command.payload.staff_id === "string"
      ) {
        presence.set(command.payload.staff_id, "on_site");
      }
    }

    const shifts = here(
      source.section<StoredShiftRow>("logistics_shift_index"),
    ).filter((row) => row.cancelled_at === null);

    const shiftById = new Map(shifts.map((row): [string, StoredShiftRow] => [row.id, row]));

    /*
     * Stored assignments plus the additions this device is holding (M18.54;
     * technical spec 17.2; data/API 5.3). A pending addition is an assignment as
     * far as this screen is concerned: the operator made it, the card should
     * stop offering to make it again, and check-in should be offered against it
     * — which is the whole point of adding somebody to a running shift.
     *
     * The identity is the shift and the staff member rather than a row id,
     * because that is what makes the two copies the same assignment. A device
     * that queued an addition and has since been handed a set containing it
     * holds two rows describing one fact, and only one of them is the node's.
     */
    const pendingAssignments: StoredAssignmentRow[] = [];

    for (const command of source.pending("add-staff-to-shift")) {
      const { shift_id: shiftId, staff_id: staffId } = command.payload;

      if (typeof shiftId === "string" && typeof staffId === "string") {
        pendingAssignments.push({
          id: command.key,
          shift_id: shiftId,
          staff_id: staffId,
          assignment_status: "assigned",
        });
      }
    }

    const assignmentsByStaff = groupBy(
      unionPendingByDeviceId(
        source
          .section<StoredAssignmentRow>("logistics_shift_assignments")
          .filter((row) => shiftById.has(row.shift_id)),
        pendingAssignments.filter((row) => shiftById.has(row.shift_id)),
        (row) => `${row.shift_id}:${row.staff_id}`,
      ),
      (row) => row.staff_id,
    );

    const attendanceByStaff = groupBy(
      here(source.section<StoredAttendanceRow>("logistics_attendance")),
      (row) => row.staff_id,
    );

    const checkoutsByStaff = groupBy(
      source.section<StoredCheckoutRow>("logistics_equipment_checkouts"),
      (row) => row.staff_id,
    );

    const signupsByStaff = groupBy(
      source.section<StoredSignupRow>("logistics_future_signups"),
      (row) => row.staff_id,
    );

    const equipment = source
      .section<StoredEquipmentRow>("logistics_equipment_index")
      .filter(
        (row) => row.department_id === null || row.department_id === departmentId,
      );

    /** Who is holding what right now, so the index can name the holder. */
    const holderOf = new Map<string, string>();

    for (const [staffId, checkouts] of checkoutsByStaff) {
      for (const checkout of checkouts) {
        holderOf.set(checkout.equipment_item_id, staffId);
      }
    }

    const nameOf = new Map(
      staff.map((row): [string, string] => [row.staff_id, displayName(row)]),
    );

    const { eventLabel, departmentLabel } = storedContextLabels(
      source,
      eventId,
      departmentId,
    );

    const workspaces: Record<string, unknown> = {};

    for (const member of staff) {
      const staffId = member.staff_id;
      const held = checkoutsByStaff.get(staffId) ?? [];
      const attendance = attendanceByStaff.get(staffId) ?? [];
      const assignments = assignmentsByStaff.get(staffId) ?? [];
      const onSite = presence.get(staffId) === "on_site";

      const checkedIn = attendance.some(
        (row) => row.current_state === "checked_in",
      );
      const holdingBlocks = held.some(blocksOffSite);
      /*
       * `TeamMembership::onEligibleShiftTeam` as the node asked it when the set
       * was composed. A shift naming no eligible team takes nobody by addition,
       * which is the same answer the node gives: its lookup is keyed by team id
       * and a null one matches nothing.
       */
      const eligibleTeamIds = new Set(member.eligible_team_ids ?? []);
      const eligibleForShift = (shift: StoredShiftRow): boolean =>
        shift.eligible_team_id !== null &&
        eligibleTeamIds.has(shift.eligible_team_id);

      const cards = shifts.flatMap((shift) => {
        const assignment =
          assignments.find((row) => row.shift_id === shift.id) ?? null;
        const state =
          attendance.find((row) => row.shift_id === shift.id)?.current_state ??
          null;
        const started =
          shift.starts_at !== null && now >= new Date(shift.starts_at);
        const ended = shift.ends_at !== null && now > new Date(shift.ends_at);

        /*
         * `DepartmentOperationsReadController::shiftCards` drops an unassigned
         * card unless the person is on-site and the shift has not ended, and the
         * offline desk now drops it too (M18.54). Before the addition could be
         * made here there was nothing to offer on those cards anyway; now there
         * is, and a card offering it to somebody who is not on site would be a
         * button the node refuses — while one that simply is not there matches
         * what the same desk shows with a connection.
         */
        if (assignment === null && !(onSite && !ended)) {
          return [];
        }

        return {
          shift_id: shift.id,
          title: shift.title,
          team_id: shift.eligible_team_id ?? "",
          team_label: shift.team_name_snapshot,
          starts_at: shift.starts_at,
          ends_at: shift.ends_at,
          lifecycle: lifecycleOf(shift, now),
          attendance_state:
            assignment === null ? null : (state ?? "scheduled"),
          assignment_id: assignment?.id ?? null,
          // `DepartmentOperationsReadController::shiftCards`, rule for rule.
          can_check_in:
            assignment !== null &&
            onSite &&
            state !== "checked_in" &&
            state !== "checked_out",
          can_check_out: assignment !== null && state === "checked_in",
          can_mark_no_show:
            assignment !== null &&
            started &&
            (state === null || state === "scheduled"),
          /*
           * The node's offering rule, from the four facts this device holds
           * (M18.54; SLB-008). Eligibility beyond the team — Do Not Staff,
           * department standing, required trainings and waivers — is the node's
           * and is decided when the queued command arrives, exactly as it is for
           * an addition made at a connected desk.
           */
          can_add_to_shift:
            assignment === null && onSite && started && eligibleForShift(shift),
          add_to_shift_blocked_reason:
            assignment !== null
              ? null
              : !started
                ? NOT_STARTED_BLOCK
                : eligibleForShift(shift)
                  ? null
                  : notEligibleBlock(shift.team_name_snapshot),
          /*
           * The hours a correction would edit are the node's, and so is the
           * grace period it is measured against — it closes whether or not a
           * device is reachable, so a desk with no signal must not offer it.
           */
          hours_worked_id: null,
          actual_started_at: null,
          actual_ended_at: null,
          minutes_worked: null,
          can_correct_hours: false,
          correct_hours_blocked_reason: null,
        };
      });

      workspaces[staffId] = {
        staff_id: staffId,
        display_name: displayName(member),
        handle: member.handle,
        profile_picture_url: null,
        team_label: member.team_label ?? departmentLabel,
        presence_state: presence.get(staffId) ?? "off_site",
        // The same two blocks `DepartmentPresenceService` enforces, in the words
        // its refusal uses (SLB-017, SLB-018).
        can_go_off_site: !checkedIn && !holdingBlocks,
        off_site_blocked_reason: checkedIn
          ? CHECKED_IN_BLOCK
          : holdingBlocks
            ? OPEN_EQUIPMENT_BLOCK
            : null,
        shift_cards: cards,
        open_equipment: held.map((checkout) => ({
          checkout_id: checkout.id,
          equipment_item_id: checkout.equipment_item_id,
          name: checkout.item_name ?? "",
          tracking: checkout.item_tracking ?? "individual",
          asset_tag: checkout.item_asset_tag,
          quantity: checkout.quantity,
          quantity_returned: checkout.quantity_returned,
          checked_out_at: checkout.checked_out_at,
          shift_id: checkout.shift_id,
        })),
        future_signups: (signupsByStaff.get(staffId) ?? []).map((signup) => ({
          signup_id: signup.id,
          shift_id: signup.shift_id,
          shift_title: signup.shift_title ?? "",
          starts_at: signup.starts_at,
          ends_at: signup.ends_at,
          state: signup.state ?? "confirmed",
        })),
      };
    }

    return {
      data: {
        context: {
          event_id: eventId,
          event_label: eventLabel,
          department_id: departmentId,
          department_label: departmentLabel,
          time_zone: "UTC",
          /*
           * When the device took delivery, not now. The cards were derived
           * against the clock a moment ago, but every row behind them is as old
           * as the set, and the surface's disclosure is about the rows.
           */
          as_of: source.storedAt ?? "",
        },
        access: storedAccess(departmentId),
        searchable_staff: staff.map((row) => ({
          staff_id: row.staff_id,
          display_name: displayName(row),
          handle: row.handle,
          team_label: row.team_label,
          presence_state: presence.get(row.staff_id) ?? "off_site",
        })),
        searchable_equipment: equipment.map((row) => ({
          equipment_item_id: row.id,
          name: row.name,
          tracking: row.tracking,
          asset_tag: row.asset_tag,
          serial_number: row.serial_number,
          status: row.status,
          status_label: row.status === "checked_out" ? "Checked out" : "Available",
          quantity_total: row.quantity_total,
          quantity_available:
            row.status === "checked_out" ? 0 : row.quantity_total,
          holder_staff_id: holderOf.get(row.id) ?? null,
          holder_name: nameOf.get(holderOf.get(row.id) ?? "") ?? null,
        })),
        searchable_shifts: shifts.map((shift) => ({
          shift_id: shift.id,
          title: shift.title,
          team_id: shift.eligible_team_id ?? "",
          team_label: shift.team_name_snapshot,
          starts_at: shift.starts_at,
          ends_at: shift.ends_at,
          lifecycle: lifecycleOf(shift, now),
          capacity: shift.capacity,
        })),
        /*
         * Handing equipment over is connected-only (CLIENT-018), so the desk
         * offers nothing to check out from a stored copy rather than listing
         * candidates whose availability it cannot confirm.
         */
        checkout_inventory: [],
        staff_workspaces: workspaces,
      } as T,
      narrowed: true,
    };
  };
}
