// The department operations surfaces' data layer (M16.21; CLIENT-015,
// CLIENT-023; SLB-001 through SLB-022; technical spec 20.3, 20.4, 20.5;
// data/API 7.2).
//
// Department Overview, the Logistics Window, the Operations Center, and the
// Planning Table were the last client surfaces still rendering a compiled-in
// fixture. Four staff members, three shifts, two radios and a deployment lived
// in `department-ops/fixtures.ts`, and every rule the server enforces was
// written out a second time in the browser: who may go off-site and why not,
// what a check-in does to a shift card, which equipment moves to whose hands,
// which shift somebody may be added to, and the whole plan-versus-actual
// arithmetic. None of it reached a node. A check-in it accepted was not
// attendance and a refusal it produced was not the node's refusal.
//
// This module is a translation of the four reads and the commands behind them.
// Four things in it are deliberate:
//
//  1. **One read per surface, and a re-read after every write.** A command
//     answers with the record it changed and not with what that change did to
//     the rest of the screen — a check-in moves someone off the scheduled list,
//     closes their off-site option, and changes an exception count, and none of
//     those come back in the response. So the screen asks again rather than
//     guessing.
//  2. **Authority comes from the response.** The `access` block is the node's
//     own answer and the same one the commands enforce, in place of the
//     fixture's `LOCAL_CAPABILITIES` flags. A client that fails to hide an
//     action is still refused (CLIENT-006).
//  3. **Attendance queues; the rest does not.** Check-in, check-out, and
//     no-show are Alpha 1 offline writes (data/API 7.2) and go through the
//     command outbox with the device-generated operation UUID as their
//     idempotency key. Presence, unscheduled additions, deployments, equipment
//     handoff, and hours correction are connected-only and are refused where
//     they stand (CLIENT-018). Correction is the one of those that writes an
//     attendance operation like its queued siblings and still cannot be held:
//     the grace period it is measured against is the node's, and it closes
//     whether or not a device is reachable.
//  4. **Nothing is derived here that the node already decided.** Shift
//     lifecycle, whether a card offers check-in, whether someone may leave
//     site, and every planning aggregate arrive computed. What is left in this
//     module is presentation: search over what the node sent, grouping cards
//     into active/upcoming/outgoing, and formatting.
//  5. **All four reads are held on the device** (M18.8, M18.9; SLB-021;
//     technical spec 9.3). "The device should cache as much authorized data as
//     possible. Offline data may be stale, but stale authorized data is better
//     than no data." Section 9.3 then names these very payloads: the desk's
//     staff, equipment, and shift indexes; the Overview's selected-shift
//     summaries, assignments, and equipment; the deployment options and current
//     assignments; the identity-free plan-versus-actual rows.
//
//     M18.8 held only the desk, on the grounds that SLB-021 named it and the
//     other three were "worth nothing stale". That was wrong, and it is the
//     reason a lead out of coverage got four pages of "check the connection to
//     this node" instead of the department they were standing in. Each read now
//     carries the freshness of what it returned, so a surface showing a stored
//     copy says which copy it is showing.

import { meridianCachedJson } from "@/api/meridianApi";
import type { ReadFreshness } from "@/offline/readCache";
import { sendConnectedCommand } from "@/outbox/submitCommand";
import { deviceId } from "@/session/deviceIdentity";
import { clientSessionState } from "@/session/clientSession";
import { submitAttendanceOperation } from "@/shift-board/submitAttendanceOperation";
import type {
  DepartmentPresenceState,
  EquipmentAssignmentScope,
  EquipmentCheckoutCandidate,
  EquipmentPresentationState,
  EquipmentReturnCondition,
  EquipmentState,
  EquipmentTracking,
  LogisticsStaffStates,
  LogisticsStatePill,
  ShiftAttendanceState,
  ShiftLifecycle,
} from "@/department-ops/types";

/** The event and department a surface is working in, as the node names them. */
export interface DepartmentOpsContext {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly timeZone: string;
  /** Server time of the read, which every "now" on the screen is measured from. */
  readonly asOf: string;
}

/**
 * What this caller may do here.
 *
 * One flag per command, because that is how the node answers: presence,
 * attendance, equipment, and deployments are separate grants and a person may
 * hold any subset of them.
 */
export interface DepartmentOpsAccess {
  readonly isDepartmentLead: boolean;
  readonly canManagePresence: boolean;
  readonly canManageAttendance: boolean;
  readonly canManageEquipment: boolean;
  readonly canAssignDeployments: boolean;
  readonly canManagePlanning: boolean;
  readonly canAdministerDepartment: boolean;
}

export interface DepartmentOpsShift {
  readonly shiftId: string;
  readonly title: string;
  readonly teamId: string;
  readonly teamLabel: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly lifecycle: ShiftLifecycle;
  readonly capacity: number | null;
}

export interface OverviewException {
  readonly id: string;
  readonly severity: "warning" | "attention";
  readonly label: string;
  readonly detail: string;
}

export interface OverviewAssignment {
  readonly assignmentId: string;
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamLabel: string;
  readonly attendanceState: ShiftAttendanceState;
  readonly checkedInAt: string | null;
  readonly currentDeploymentId: string | null;
  readonly unscheduled: boolean;
}

export interface OverviewEquipment {
  readonly checkoutId: string;
  readonly itemName: string;
  readonly assetTag: string | null;
  readonly staffName: string;
  readonly checkedOutAt: string | null;
  readonly assignmentScope: EquipmentAssignmentScope;
  readonly presentationState: EquipmentPresentationState;
  readonly presentationStateLabel: string;
  readonly dueAt: string | null;
  readonly overdue: boolean;
}

export interface DeploymentOption {
  readonly deploymentId: string;
  readonly name: string;
  readonly description: string | null;
  readonly locationDetails: string | null;
}

export interface DepartmentOverviewRead {
  /** Whether this came from the node or from what the device stored (spec 9.3). */
  readonly freshness: ReadFreshness;
  readonly context: DepartmentOpsContext;
  readonly access: DepartmentOpsAccess;
  readonly shifts: readonly DepartmentOpsShift[];
  readonly selectedShiftId: string | null;
  readonly exceptions: readonly OverviewException[];
  readonly assignments: readonly OverviewAssignment[];
  readonly equipmentOut: readonly OverviewEquipment[];
  readonly deployments: readonly DeploymentOption[];
  readonly onSiteCount: number;
}

/**
 * One shift in a staff member's workspace, with what the desk may do about it.
 *
 * The four `can*` flags are the node's, not conditions this module reconstructs.
 * `canAddToShift` is the loose one and says so on the server: it means the
 * addition is worth offering, and the command still weighs trainings, waivers,
 * and organization status before accepting it.
 */
export interface LogisticsShiftCard {
  readonly shiftId: string;
  readonly title: string;
  readonly teamId: string;
  readonly teamLabel: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly lifecycle: ShiftLifecycle;
  /** Null when this person holds no assignment on the shift. */
  readonly attendanceState: ShiftAttendanceState | null;
  readonly assignmentId: string | null;
  readonly canCheckIn: boolean;
  readonly canCheckOut: boolean;
  readonly canMarkNoShow: boolean;
  readonly canAddToShift: boolean;
  /**
   * Why an on-site staff member cannot be added to this shift, or null when they
   * can or already hold an assignment on it.
   *
   * The card used to be absent in exactly these cases, which is how an operator
   * who marked somebody on-site and went to add them to a shift they had just
   * created found nothing on screen at all — no card, no button, no reason.
   */
  readonly addToShiftBlockedReason: string | null;
  /**
   * The hours record a correction would edit, or null until check-out creates
   * one (SLB-031).
   *
   * The recorded times come with it so the dialog opens on what is on file
   * rather than on an empty form: a correction is somebody reading 08:00 and
   * typing 08:15, and a blank field would make them go and find 08:00 first.
   */
  readonly hoursWorkedId: string | null;
  readonly actualStartedAt: string | null;
  readonly actualEndedAt: string | null;
  readonly minutesWorked: number | null;
  readonly canCorrectHours: boolean;
  /**
   * Why these hours cannot be corrected, or null when they can.
   *
   * Two sentences reach here: the frozen one, which is
   * `HoursCorrectionService`'s own and names the date the grace period closed
   * (HOURS-008), and the one for a finished shift nobody was ever checked out
   * of, which names the check-out rather than the correction.
   */
  readonly correctHoursBlockedReason: string | null;
}

export interface LogisticsEquipmentItem {
  readonly checkoutId: string | null;
  readonly equipmentItemId: string;
  readonly name: string;
  readonly tracking: EquipmentTracking;
  readonly assetTag: string | null;
  readonly status: EquipmentState;
  readonly checkedOutAt: string | null;
  /**
   * The shift this item was handed over for, or null when it was signed out for
   * the event rather than for a shift.
   *
   * The distinction is what the desk is owed and when. Shift kit comes back when
   * that shift ends; event kit is out until the person leaves site, and is the
   * kind that quietly stays out for a week.
   */
  readonly shiftId: string | null;
  /** Which of the two EQUIP-009 scopes, as the node derived it from the shift. */
  readonly assignmentScope: EquipmentAssignmentScope;
  /**
   * What this checkout reads as right now — out, overdue, or unknown for an
   * open one, and its return condition for a closed one (EQUIP-005).
   *
   * The node computes it on read, so nothing here has to recompute it and
   * nothing on the device can be stale in a way the node is not. A cached desk
   * read is stale about the whole payload at once, which the freshness banner
   * already says; a locally recomputed "overdue" would be a second, quieter
   * kind of wrong.
   */
  readonly presentationState: EquipmentPresentationState;
  readonly presentationStateLabel: string;
  /** When it is owed back, or null when nothing says (the `unknown` case). */
  readonly dueAt: string | null;
  readonly overdue: boolean;
  /** Units handed out on this checkout; 1 for a tracked unit (EQUIP-011). */
  readonly quantity: number;
  readonly quantityReturned: number;
  /** Units still owed after any partial return (data/API 10.13). */
  readonly quantityOutstanding: number;
}

export interface LogisticsFutureSignup {
  readonly signupId: string;
  readonly shiftId: string;
  readonly shiftTitle: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly state: string;
}

export interface LogisticsStaffWorkspace {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  /** The current profile picture, for checking a face against the record (VOL-013). */
  readonly profilePictureUrl: string | null;
  readonly teamLabel: string;
  readonly presenceState: DepartmentPresenceState;
  readonly canGoOffSite: boolean;
  readonly offSiteBlockedReason: string | null;
  readonly shiftCards: readonly LogisticsShiftCard[];
  readonly openEquipment: readonly LogisticsEquipmentItem[];
  readonly futureSignups: readonly LogisticsFutureSignup[];
}

export interface LogisticsStaffRow {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamLabel: string;
  readonly presenceState: DepartmentPresenceState;
}

export interface LogisticsEquipmentRow {
  readonly equipmentItemId: string;
  readonly name: string;
  readonly tracking: EquipmentTracking;
  readonly assetTag: string | null;
  readonly serialNumber: string | null;
  readonly status: EquipmentState;
  readonly statusLabel: string;
  /**
   * What this row reads as, including the derived Overdue and Unknown a stored
   * state cannot express (EQUIP-005). A pool never reads Checked out; it reads
   * Available with the quantity that is left (UI contract 9.6).
   */
  readonly presentationState: EquipmentPresentationState | EquipmentState;
  readonly presentationStateLabel: string;
  readonly quantityTotal: number;
  readonly quantityAvailable: number;
  readonly holderStaffId: string | null;
  readonly holderName: string | null;
}

export interface LogisticsDeskRead {
  /** Whether this came from the node or from what the device stored (spec 9.3). */
  readonly freshness: ReadFreshness;
  readonly context: DepartmentOpsContext;
  readonly access: DepartmentOpsAccess;
  readonly searchableStaff: readonly LogisticsStaffRow[];
  readonly searchableEquipment: readonly LogisticsEquipmentRow[];
  readonly searchableShifts: readonly DepartmentOpsShift[];
  /**
   * What the desk may hand out, and the set lookup resolves against
   * (EQUIP-015).
   *
   * It arrives with the desk read and is held with it, which is the whole point:
   * a checkout at a gate with no signal resolves a scanned asset tag against
   * this rather than against a node it cannot reach. Scope was applied by the
   * node before it was cached, so an item outside this department was never in
   * the copy the device holds and no local search can surface one.
   *
   * One list for the desk rather than one per workspace: an available item is
   * available to whoever is standing at it.
   */
  readonly checkoutInventory: readonly EquipmentCheckoutCandidate[];
  readonly staffWorkspaces: Readonly<Record<string, LogisticsStaffWorkspace>>;
}

export interface OperationsDeploymentRow {
  readonly assignmentId: string;
  readonly staffId: string;
  readonly displayName: string;
  readonly shiftId: string;
  readonly shiftTitle: string;
  readonly currentDeploymentId: string | null;
  readonly currentDeploymentName: string | null;
}

export interface OperationsCenterRead {
  /** Whether this came from the node or from what the device stored (spec 9.3). */
  readonly freshness: ReadFreshness;
  readonly context: DepartmentOpsContext;
  readonly access: DepartmentOpsAccess;
  readonly deployments: readonly DeploymentOption[];
  readonly rows: readonly OperationsDeploymentRow[];
  readonly equipmentOutCount: number;
}

export interface PlanningRow {
  readonly shiftId: string;
  readonly title: string;
  readonly teamId: string;
  readonly teamLabel: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly lifecycle: ShiftLifecycle;
  readonly capacity: number | null;
  readonly signedUpOrAssignedCount: number;
  readonly checkedInCount: number;
  readonly noShowCount: number;
  readonly unscheduledCount: number;
  readonly plannedHours: number;
  readonly actualHours: number;
  readonly varianceHours: number;
  readonly statusLabel: string;
}

export interface PlanningTeamOption {
  readonly teamId: string;
  readonly teamLabel: string;
}

export interface PlanningFilters {
  readonly teamId: string | null;
  readonly date: string | null;
}

export interface PlanningTableRead {
  /** Whether this came from the node or from what the device stored (spec 9.3). */
  readonly freshness: ReadFreshness;
  readonly context: DepartmentOpsContext;
  readonly access: DepartmentOpsAccess;
  readonly teams: readonly PlanningTeamOption[];
  readonly filters: PlanningFilters;
  readonly rows: readonly PlanningRow[];
}

interface ContextPayload {
  readonly event_id: string;
  readonly event_label: string;
  readonly department_id: string;
  readonly department_label: string;
  readonly time_zone: string;
  readonly as_of: string;
}

interface AccessPayload {
  readonly is_department_lead?: boolean;
  readonly can_manage_presence?: boolean;
  readonly can_manage_attendance?: boolean;
  readonly can_manage_equipment?: boolean;
  readonly can_assign_deployments?: boolean;
  readonly can_manage_planning?: boolean;
  readonly can_administer_department?: boolean;
}

interface ShiftPayload {
  readonly shift_id: string;
  readonly title: string;
  readonly team_id: string;
  readonly team_label: string | null;
  readonly starts_at: string | null;
  readonly ends_at: string | null;
  readonly lifecycle: ShiftLifecycle;
  readonly capacity: number | null;
}

interface EnvelopePayload {
  readonly context: ContextPayload;
  readonly access?: AccessPayload;
}

function toContext(payload: ContextPayload): DepartmentOpsContext {
  return {
    eventId: payload.event_id,
    eventLabel: payload.event_label,
    departmentId: payload.department_id,
    departmentLabel: payload.department_label,
    timeZone: payload.time_zone,
    asOf: payload.as_of,
  };
}

function toAccess(payload: AccessPayload | undefined): DepartmentOpsAccess {
  return {
    isDepartmentLead: payload?.is_department_lead ?? false,
    canManagePresence: payload?.can_manage_presence ?? false,
    canManageAttendance: payload?.can_manage_attendance ?? false,
    canManageEquipment: payload?.can_manage_equipment ?? false,
    canAssignDeployments: payload?.can_assign_deployments ?? false,
    canManagePlanning: payload?.can_manage_planning ?? false,
    canAdministerDepartment: payload?.can_administer_department ?? false,
  };
}

function toShift(payload: ShiftPayload): DepartmentOpsShift {
  return {
    shiftId: payload.shift_id,
    title: payload.title,
    teamId: payload.team_id,
    teamLabel: payload.team_label ?? "",
    startsAt: payload.starts_at ?? "",
    endsAt: payload.ends_at ?? "",
    lifecycle: payload.lifecycle,
    capacity: payload.capacity,
  };
}

function base(path: string, eventId: string, departmentId: string): string {
  return `/api/events/${eventId}/departments/${departmentId}/${path}`;
}

/** Department Overview for one shift, or for the one running now (SLB-001). */
export async function getDepartmentOverview(
  eventId: string,
  departmentId: string,
  shiftId: string | null = null,
): Promise<DepartmentOverviewRead> {
  const query = shiftId === null ? "" : `?shift_id=${encodeURIComponent(shiftId)}`;
  const read = await meridianCachedJson<
    EnvelopePayload & {
      readonly shifts?: ShiftPayload[];
      readonly selected_shift_id?: string | null;
      readonly exceptions?: OverviewException[];
      readonly assignments?: {
        readonly assignment_id: string;
        readonly staff_id: string;
        readonly display_name: string;
        readonly handle: string | null;
        readonly team_label: string | null;
        readonly attendance_state: ShiftAttendanceState;
        readonly checked_in_at: string | null;
        readonly current_deployment_id: string | null;
        readonly unscheduled: boolean;
      }[];
      readonly equipment_out?: ({
        readonly checkout_id: string;
        readonly item_name: string;
        readonly asset_tag: string | null;
        readonly staff_name: string;
        readonly checked_out_at: string | null;
      } & Partial<DerivedCheckoutPayload>)[];
      readonly deployments?: DeploymentPayload[];
      readonly on_site_count?: number;
    }
  >(base("overview", eventId, departmentId) + query);
  const payload = read.data;

  return {
    freshness: read.freshness,
    context: toContext(payload.context),
    access: toAccess(payload.access),
    shifts: (payload.shifts ?? []).map(toShift),
    selectedShiftId: payload.selected_shift_id ?? null,
    exceptions: payload.exceptions ?? [],
    assignments: (payload.assignments ?? []).map((row) => ({
      assignmentId: row.assignment_id,
      staffId: row.staff_id,
      displayName: row.display_name,
      handle: row.handle,
      teamLabel: row.team_label ?? "",
      attendanceState: row.attendance_state,
      checkedInAt: row.checked_in_at,
      currentDeploymentId: row.current_deployment_id,
      unscheduled: row.unscheduled,
    })),
    equipmentOut: (payload.equipment_out ?? []).map((row) => ({
      checkoutId: row.checkout_id,
      itemName: row.item_name,
      assetTag: row.asset_tag,
      staffName: row.staff_name,
      checkedOutAt: row.checked_out_at,
      ...toDerivedCheckout(row),
    })),
    deployments: (payload.deployments ?? []).map(toDeployment),
    onSiteCount: payload.on_site_count ?? 0,
  };
}

interface DeploymentPayload {
  readonly id: string;
  readonly name: string;
  readonly description: string | null;
  readonly location_details: string | null;
}

function toDeployment(payload: DeploymentPayload): DeploymentOption {
  return {
    deploymentId: payload.id,
    name: payload.name,
    description: payload.description,
    locationDetails: payload.location_details,
  };
}

interface WorkspacePayload {
  readonly staff_id: string;
  readonly display_name: string;
  readonly handle: string | null;
  readonly profile_picture_url?: string | null;
  readonly team_label: string | null;
  readonly presence_state: DepartmentPresenceState;
  readonly can_go_off_site: boolean;
  readonly off_site_blocked_reason: string | null;
  readonly shift_cards?: {
    readonly shift_id: string;
    readonly title: string;
    readonly team_id: string;
    readonly team_label: string | null;
    readonly starts_at: string | null;
    readonly ends_at: string | null;
    readonly lifecycle: ShiftLifecycle;
    readonly attendance_state: ShiftAttendanceState | null;
    readonly assignment_id: string | null;
    readonly can_check_in: boolean;
    readonly can_check_out: boolean;
    readonly can_mark_no_show: boolean;
    readonly can_add_to_shift: boolean;
    readonly add_to_shift_blocked_reason?: string | null;
    readonly hours_worked_id?: string | null;
    readonly actual_started_at?: string | null;
    readonly actual_ended_at?: string | null;
    readonly minutes_worked?: number | null;
    readonly can_correct_hours?: boolean;
    readonly correct_hours_blocked_reason?: string | null;
  }[];
  readonly open_equipment?: EquipmentPayload[];
  readonly future_signups?: {
    readonly signup_id: string;
    readonly shift_id: string;
    readonly shift_title: string;
    readonly starts_at: string | null;
    readonly ends_at: string | null;
    readonly state: string;
  }[];
}

/**
 * The derived reading the node attaches to every checkout it publishes
 * (EQUIP-005, EQUIP-009). Optional on the wire so a payload from a node that
 * predates M18.24 still parses into a sensible record rather than throwing.
 */
interface DerivedCheckoutPayload {
  readonly assignment_scope: EquipmentAssignmentScope;
  readonly assignment_scope_label: string;
  readonly state: EquipmentPresentationState;
  readonly state_label: string;
  readonly due_at: string | null;
  readonly overdue: boolean;
  readonly quantity: number;
  readonly quantity_returned: number;
  readonly quantity_outstanding: number;
}

function toDerivedCheckout(payload: Partial<DerivedCheckoutPayload>): {
  readonly assignmentScope: EquipmentAssignmentScope;
  readonly presentationState: EquipmentPresentationState;
  readonly presentationStateLabel: string;
  readonly dueAt: string | null;
  readonly overdue: boolean;
  readonly quantity: number;
  readonly quantityReturned: number;
  readonly quantityOutstanding: number;
} {
  const state = payload.state ?? "checked_out";

  return {
    assignmentScope: payload.assignment_scope ?? "event",
    presentationState: state,
    presentationStateLabel: payload.state_label ?? "",
    dueAt: payload.due_at ?? null,
    overdue: payload.overdue ?? false,
    quantity: payload.quantity ?? 1,
    quantityReturned: payload.quantity_returned ?? 0,
    quantityOutstanding: payload.quantity_outstanding ?? 1,
  };
}

interface EquipmentPayload extends Partial<DerivedCheckoutPayload> {
  readonly checkout_id: string | null;
  readonly equipment_item_id: string;
  readonly name: string;
  readonly tracking?: EquipmentTracking;
  readonly asset_tag: string | null;
  readonly status: EquipmentState;
  readonly checked_out_at: string | null;
  readonly shift_id?: string | null;
}

function toEquipment(payload: EquipmentPayload): LogisticsEquipmentItem {
  return {
    checkoutId: payload.checkout_id,
    equipmentItemId: payload.equipment_item_id,
    name: payload.name,
    tracking: payload.tracking ?? "individual",
    assetTag: payload.asset_tag,
    status: payload.status,
    checkedOutAt: payload.checked_out_at,
    shiftId: payload.shift_id ?? null,
    ...toDerivedCheckout(payload),
  };
}

interface CheckoutCandidatePayload {
  readonly equipment_item_id: string;
  readonly name: string;
  readonly tracking: EquipmentTracking;
  readonly tracking_label: string;
  readonly asset_tag: string | null;
  readonly serial_number: string | null;
  readonly quantity_total: number;
  readonly quantity_available: number;
}

function toCheckoutCandidate(
  payload: CheckoutCandidatePayload,
): EquipmentCheckoutCandidate {
  return {
    equipmentItemId: payload.equipment_item_id,
    name: payload.name,
    tracking: payload.tracking,
    trackingLabel: payload.tracking_label,
    assetTag: payload.asset_tag,
    serialNumber: payload.serial_number,
    quantityTotal: payload.quantity_total,
    quantityAvailable: payload.quantity_available,
  };
}

/** The Logistics Window's department-scoped index (SLB-003, SLB-021). */
export async function getLogisticsDesk(
  eventId: string,
  departmentId: string,
): Promise<LogisticsDeskRead> {
  const read = await meridianCachedJson<
    EnvelopePayload & {
      readonly searchable_staff?: {
        readonly staff_id: string;
        readonly display_name: string;
        readonly handle: string | null;
        readonly team_label: string | null;
        readonly presence_state: DepartmentPresenceState;
      }[];
      readonly searchable_equipment?: {
        readonly equipment_item_id: string;
        readonly name: string;
        readonly tracking?: EquipmentTracking;
        readonly asset_tag: string | null;
        readonly serial_number?: string | null;
        readonly status: EquipmentState;
        readonly status_label: string;
        readonly presentation_state?: EquipmentPresentationState;
        readonly presentation_state_label?: string;
        readonly quantity_total?: number;
        readonly quantity_available?: number;
        readonly holder_staff_id: string | null;
        readonly holder_name: string | null;
      }[];
      readonly searchable_shifts?: ShiftPayload[];
      readonly checkout_inventory?: CheckoutCandidatePayload[];
      readonly staff_workspaces?: Record<string, WorkspacePayload>;
    }
  >(base("logistics", eventId, departmentId));
  const payload = read.data;

  const workspaces: Record<string, LogisticsStaffWorkspace> = {};

  for (const [staffId, workspace] of Object.entries(
    payload.staff_workspaces ?? {},
  )) {
    workspaces[staffId] = {
      staffId: workspace.staff_id,
      displayName: workspace.display_name,
      handle: workspace.handle,
      profilePictureUrl: workspace.profile_picture_url ?? null,
      teamLabel: workspace.team_label ?? "",
      presenceState: workspace.presence_state,
      canGoOffSite: workspace.can_go_off_site,
      offSiteBlockedReason: workspace.off_site_blocked_reason,
      shiftCards: (workspace.shift_cards ?? []).map((card) => ({
        shiftId: card.shift_id,
        title: card.title,
        teamId: card.team_id,
        teamLabel: card.team_label ?? "",
        startsAt: card.starts_at ?? "",
        endsAt: card.ends_at ?? "",
        lifecycle: card.lifecycle,
        attendanceState: card.attendance_state,
        assignmentId: card.assignment_id,
        canCheckIn: card.can_check_in,
        canCheckOut: card.can_check_out,
        canMarkNoShow: card.can_mark_no_show,
        canAddToShift: card.can_add_to_shift,
        addToShiftBlockedReason: card.add_to_shift_blocked_reason ?? null,
        hoursWorkedId: card.hours_worked_id ?? null,
        actualStartedAt: card.actual_started_at ?? null,
        actualEndedAt: card.actual_ended_at ?? null,
        minutesWorked: card.minutes_worked ?? null,
        canCorrectHours: card.can_correct_hours ?? false,
        correctHoursBlockedReason: card.correct_hours_blocked_reason ?? null,
      })),
      openEquipment: (workspace.open_equipment ?? []).map(toEquipment),
      futureSignups: (workspace.future_signups ?? []).map((signup) => ({
        signupId: signup.signup_id,
        shiftId: signup.shift_id,
        shiftTitle: signup.shift_title,
        startsAt: signup.starts_at ?? "",
        endsAt: signup.ends_at ?? "",
        state: signup.state,
      })),
    };
  }

  return {
    freshness: read.freshness,
    context: toContext(payload.context),
    access: toAccess(payload.access),
    searchableStaff: (payload.searchable_staff ?? []).map((row) => ({
      staffId: row.staff_id,
      displayName: row.display_name,
      handle: row.handle,
      teamLabel: row.team_label ?? "",
      presenceState: row.presence_state,
    })),
    searchableEquipment: (payload.searchable_equipment ?? []).map((row) => ({
      equipmentItemId: row.equipment_item_id,
      name: row.name,
      tracking: row.tracking ?? "individual",
      assetTag: row.asset_tag,
      serialNumber: row.serial_number ?? null,
      status: row.status,
      statusLabel: row.status_label,
      presentationState: row.presentation_state ?? row.status,
      presentationStateLabel: row.presentation_state_label ?? row.status_label,
      quantityTotal: row.quantity_total ?? 1,
      quantityAvailable: row.quantity_available ?? 0,
      holderStaffId: row.holder_staff_id,
      holderName: row.holder_name,
    })),
    searchableShifts: (payload.searchable_shifts ?? []).map(toShift),
    checkoutInventory: (payload.checkout_inventory ?? []).map(
      toCheckoutCandidate,
    ),
    staffWorkspaces: workspaces,
  };
}

/** The Operations Center's deployment module (SLB-009, SLB-010). */
export async function getOperationsCenter(
  eventId: string,
  departmentId: string,
): Promise<OperationsCenterRead> {
  const read = await meridianCachedJson<
    EnvelopePayload & {
      readonly deployments?: DeploymentPayload[];
      readonly rows?: {
        readonly assignment_id: string;
        readonly staff_id: string;
        readonly display_name: string;
        readonly shift_id: string;
        readonly shift_title: string;
        readonly current_deployment_id: string | null;
        readonly current_deployment_name: string | null;
      }[];
      readonly equipment_out_count?: number;
    }
  >(base("operations", eventId, departmentId));
  const payload = read.data;

  return {
    freshness: read.freshness,
    context: toContext(payload.context),
    access: toAccess(payload.access),
    deployments: (payload.deployments ?? []).map(toDeployment),
    rows: (payload.rows ?? []).map((row) => ({
      assignmentId: row.assignment_id,
      staffId: row.staff_id,
      displayName: row.display_name,
      shiftId: row.shift_id,
      shiftTitle: row.shift_title,
      currentDeploymentId: row.current_deployment_id,
      currentDeploymentName: row.current_deployment_name,
    })),
    equipmentOutCount: payload.equipment_out_count ?? 0,
  };
}

/**
 * The Planning Table (SLB-019, SLB-020).
 *
 * The filters go to the node rather than being applied here, so the rows on
 * screen are the rows it computed for that view and the counts on them are that
 * view's counts.
 */
export async function getPlanningTable(
  eventId: string,
  departmentId: string,
  filters: PlanningFilters = { teamId: null, date: null },
): Promise<PlanningTableRead> {
  const query = new URLSearchParams();

  if (filters.teamId !== null) {
    query.set("team_id", filters.teamId);
  }

  if (filters.date !== null) {
    query.set("date", filters.date);
  }

  const endpoint = base("planning", eventId, departmentId);
  const suffix = query.toString() === "" ? "" : `?${query.toString()}`;
  /*
   * With no node in reach, fall back to the unfiltered table this device holds
   * and narrow it here (M18.9). The filters go to the node whenever there is
   * one — the counts on a filtered row are that view's counts, computed by the
   * node — but a lead who has read the table and then loses the node should be
   * able to pick a team without the page going blank.
   */
  const read = await meridianCachedJson<
    EnvelopePayload & {
      readonly teams?: { readonly team_id: string; readonly team_label: string }[];
      readonly filters?: {
        readonly team_id: string | null;
        readonly date: string | null;
      };
      readonly rows?: {
        readonly shift_id: string;
        readonly title: string;
        readonly team_id: string;
        readonly team_label: string | null;
        readonly starts_at: string | null;
        readonly ends_at: string | null;
        readonly lifecycle: ShiftLifecycle;
        readonly capacity: number | null;
        readonly signed_up_or_assigned_count: number;
        readonly checked_in_count: number;
        readonly no_show_count: number;
        readonly unscheduled_count: number;
        readonly planned_hours: number;
        readonly actual_hours: number;
        readonly variance_hours: number;
        readonly status_label: string;
      }[];
    }
  >(endpoint + suffix, { fallbackPath: endpoint });
  const payload = read.data;

  return {
    freshness: read.freshness,
    context: toContext(payload.context),
    access: toAccess(payload.access),
    teams: (payload.teams ?? []).map((team) => ({
      teamId: team.team_id,
      teamLabel: team.team_label,
    })),
    filters: {
      teamId: payload.filters?.team_id ?? null,
      date: payload.filters?.date ?? null,
    },
    rows: narrowPlanningRows(
      (payload.rows ?? []).map((row) => ({
        shiftId: row.shift_id,
        title: row.title,
        teamId: row.team_id,
        teamLabel: row.team_label ?? "",
        startsAt: row.starts_at ?? "",
        endsAt: row.ends_at ?? "",
        lifecycle: row.lifecycle,
        capacity: row.capacity,
        signedUpOrAssignedCount: row.signed_up_or_assigned_count,
        checkedInCount: row.checked_in_count,
        noShowCount: row.no_show_count,
        unscheduledCount: row.unscheduled_count,
        plannedHours: row.planned_hours,
        actualHours: row.actual_hours,
        varianceHours: row.variance_hours,
        statusLabel: row.status_label,
      })),
      read.freshness.narrowed === true ? filters : { teamId: null, date: null },
      toContext(payload.context).timeZone,
    ),
  };
}

/**
 * Apply the team and date filters the node would have applied.
 *
 * Only reached when a filtered read fell back to the unfiltered copy this device
 * holds. The counts on each row are the node's and are not recomputed here: a
 * row's checked-in count is that shift's, whatever set of rows it is shown in,
 * so narrowing the list never has to touch the arithmetic on it.
 */
function narrowPlanningRows(
  rows: readonly PlanningRow[],
  filters: PlanningFilters,
  timeZone: string,
): readonly PlanningRow[] {
  return rows.filter((row) => {
    if (filters.teamId !== null && row.teamId !== filters.teamId) {
      return false;
    }

    return (
      filters.date === null ||
      dateKeyForTimestamp(row.startsAt, timeZone) === filters.date
    );
  });
}

/** The signed-in user, for the attendance operations they record. */
function actingUserId(): string {
  const userId = clientSessionState.document?.user.id;

  if (userId === undefined) {
    throw new Error("Sign in before recording attendance.");
  }

  return userId;
}

export interface AttendanceCommandInput {
  readonly context: DepartmentOpsContext;
  readonly shiftId: string;
  readonly staffId: string;
  /** When it happened, for an operation recorded after the fact (SLB-006). */
  readonly occurredAt?: string | null;
  /**
   * A corrected actual start on check-out (SLB-006).
   *
   * Absent means "leave the recorded check-in alone", which is what
   * `AttendanceCheckOutService` does with a null start; sending one always
   * overwrites it, so the desk only sends what an operator typed.
   */
  readonly startedAt?: string | null;
}

/**
 * Record a check-in (SLB-003).
 *
 * Queued, not sent: check-in is an Alpha 1 offline write (data/API 7.2) and the
 * device is the only copy of it until the node is reachable. The operation UUID
 * the device generates is the idempotency key, so a retried or replayed
 * submission is the same command and applies once (CLIENT-016).
 *
 * The timestamp is the arrival: `AttendanceCheckInService` stamps the record
 * with `device_created_at`, which is what an operator adjusts when they record
 * a check-in for somebody who turned up twenty minutes ago.
 */
export function queueCheckIn(input: AttendanceCommandInput): void {
  submitAttendanceOperation({
    operationType: "check_in",
    eventId: input.context.eventId,
    departmentId: input.context.departmentId,
    shiftId: input.shiftId,
    staffId: input.staffId,
    createdByUserId: actingUserId(),
    originDeviceId: deviceId(),
    deviceCreatedAt: input.occurredAt ?? null,
  });
}

/** Record a check-out, with the actual end time the desk entered (SLB-004, SLB-006). */
export function queueCheckOut(input: AttendanceCommandInput): void {
  submitAttendanceOperation({
    operationType: "check_out",
    eventId: input.context.eventId,
    departmentId: input.context.departmentId,
    shiftId: input.shiftId,
    staffId: input.staffId,
    createdByUserId: actingUserId(),
    originDeviceId: deviceId(),
    actualStartedAt: input.startedAt ?? null,
    actualEndedAt: input.occurredAt ?? null,
  });
}

/** Record a no-show (SLB-029). */
export function queueMarkNoShow(input: AttendanceCommandInput): void {
  submitAttendanceOperation({
    operationType: "mark_no_show",
    eventId: input.context.eventId,
    departmentId: input.context.departmentId,
    shiftId: input.shiftId,
    staffId: input.staffId,
    createdByUserId: actingUserId(),
    originDeviceId: deviceId(),
  });
}

/** Mark a department member on-site or off-site (SLB-015 through SLB-018). */
export async function setDepartmentPresence(
  context: DepartmentOpsContext,
  staffId: string,
  state: DepartmentPresenceState,
): Promise<void> {
  await sendConnectedCommand({
    commandType: state === "on_site" ? "mark-staff-on-site" : "mark-staff-off-site",
    idempotencyKey: `${state}-${context.eventId}-${context.departmentId}-${staffId}`,
    payload: {
      event_id: context.eventId,
      department_id: context.departmentId,
      staff_id: staffId,
    },
    eventId: context.eventId,
    detail: staffId,
  });
}

/** Add an on-site staff member to a shift they were not assigned to (SLB-008). */
export async function addStaffToShift(
  context: DepartmentOpsContext,
  staffId: string,
  shiftId: string,
): Promise<readonly string[]> {
  const result = await sendConnectedCommand({
    commandType: "add-staff-to-shift",
    idempotencyKey: `add-to-shift-${shiftId}-${staffId}`,
    payload: { shift_id: shiftId, staff_id: staffId },
    eventId: context.eventId,
    detail: staffId,
  });

  const warnings = (result as { warnings?: { message: string }[] } | null)
    ?.warnings;

  return (warnings ?? []).map((warning) => warning.message);
}

/**
 * Correct the recorded actual start and end on an hours record (SLB-007,
 * SLB-031, SLB-032; HOURS-007).
 *
 * Connected-only, unlike the check-out that created the record. The refusal a
 * closed grace period produces is the node's, and it names the date that period
 * closed on, which is the whole reason this cannot be queued: a correction held
 * on a device is one that may be delivered after the window shut, and the
 * operator would have been told it was captured.
 *
 * The operation UUID is generated here rather than derived from the record, so
 * two corrections to the same hours are two operations in the append-only
 * history (SLB-032). Re-sending the same one is still idempotent on the node.
 */
export async function correctHours(
  context: DepartmentOpsContext,
  hoursWorkedId: string,
  actualStartedAt: string,
  actualEndedAt: string,
): Promise<void> {
  const operationUuid = correctionOperationUuid();

  await sendConnectedCommand({
    commandType: "correct-hours",
    idempotencyKey: operationUuid,
    payload: {
      operation_uuid: operationUuid,
      hours_worked_id: hoursWorkedId,
      actual_started_at: actualStartedAt,
      actual_ended_at: actualEndedAt,
      device_created_at: new Date().toISOString(),
      origin_device_id: deviceId(),
    },
    eventId: context.eventId,
    detail: hoursWorkedId,
  });
}

/**
 * A UUID for one correction operation.
 *
 * The node validates the shape, so a platform with no UUID generator is told
 * here rather than being refused a field it cannot fill. Every device that can
 * hold a signing key has `crypto.randomUUID`, so this is the honest failure of
 * something already broken rather than a case worth working around.
 */
function correctionOperationUuid(): string {
  const cryptoScope = (globalThis as { crypto?: { randomUUID?: () => string } })
    .crypto;

  if (typeof cryptoScope?.randomUUID !== "function") {
    throw new Error(
      "This device cannot generate the operation identifier an hours correction needs.",
    );
  }

  return cryptoScope.randomUUID();
}

/**
 * Hand equipment to a staff member (SLB-012, EQUIP-011).
 *
 * `quantity` is how many units of a pooled kind are going out; a tracked unit is
 * one thing and the node refuses any other count for it. The idempotency key
 * keeps its shape from before pooling — item plus staff member — so a repeated
 * submission of the same handoff still checks nothing out twice (CLIENT-016).
 * That does mean a second, deliberate handoff of the same pooled kind to the
 * same person is the same key, which is correct rather than convenient: the
 * desk should adjust the one open line, not open a second one beside it.
 */
export async function checkoutEquipment(
  context: DepartmentOpsContext,
  staffId: string,
  equipmentItemId: string,
  shiftId: string | null,
  quantity = 1,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "checkout-equipment",
    idempotencyKey: `checkout-${equipmentItemId}-${staffId}`,
    payload: {
      equipment_item_id: equipmentItemId,
      staff_id: staffId,
      shift_id: shiftId,
      /*
       * The event this handoff is made under, for department stock that names
       * no event of its own (EQUIP-009). The node prefers the item's own event
       * where it has one and ignores this for a shift-assigned checkout, so
       * sending it always is safe and saves the desk deciding which case it is
       * in.
       */
      event_id: context.eventId,
      quantity,
    },
    eventId: context.eventId,
    detail: staffId,
  });
}

/**
 * Take equipment back, returned or otherwise (SLB-012, SLB-018, EQUIP-017).
 *
 * A pooled return carries how many came back and, when they came back missing
 * or damaged, the reason the audited pool adjustment records. An omitted
 * quantity returns everything still out on the checkout, which is what taking
 * back what is in front of you means.
 *
 * The quantity is in the idempotency key because two partial returns of the
 * same kind in the same condition are two different events — three radios back
 * this morning and two more this afternoon — and collapsing them would lose the
 * second.
 */
export async function returnEquipment(
  context: DepartmentOpsContext,
  checkoutId: string,
  condition: EquipmentReturnCondition,
  quantity: number | null = null,
  reason: string | null = null,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "return-equipment",
    idempotencyKey:
      quantity === null
        ? `return-${checkoutId}-${condition}`
        : `return-${checkoutId}-${condition}-${quantity}`,
    payload: {
      equipment_checkout_id: checkoutId,
      return_condition: condition,
      ...(quantity === null ? {} : { quantity }),
      ...(reason === null || reason.trim() === "" ? {} : { reason: reason.trim() }),
    },
    eventId: context.eventId,
    detail: checkoutId,
  });
}

/** Move a staff member to a deployment for their shift (SLB-010). */
export async function setCurrentDeployment(
  context: DepartmentOpsContext,
  shiftId: string,
  staffId: string,
  deploymentId: string,
): Promise<void> {
  await sendConnectedCommand({
    commandType: "set-current-deployment",
    idempotencyKey: `deployment-${shiftId}-${staffId}-${deploymentId}`,
    payload: {
      shift_id: shiftId,
      staff_id: staffId,
      deployment_id: deploymentId,
    },
    eventId: context.eventId,
    detail: staffId,
  });
}

/** Early/late buffer around a shift window for the desk's "current" listing. */
export const CURRENT_SHIFT_WINDOW_MINUTES = 15;

const CURRENT_SHIFT_WINDOW_MS = CURRENT_SHIFT_WINDOW_MINUTES * 60 * 1000;

/**
 * True when `asOf` is inside [startsAt − 15 minutes, endsAt + 15 minutes].
 *
 * Presentation over data the node sent: which of the shifts in the desk's index
 * the operator is being shown first. The node decides which shifts are in the
 * index and what their lifecycle is; this decides what "right now" means at a
 * desk where somebody is standing fifteen minutes early.
 */
export function isShiftCurrentlyGoing(
  shift: Pick<DepartmentOpsShift, "startsAt" | "endsAt">,
  asOf: string,
): boolean {
  const now = Date.parse(asOf);
  const startsAt = Date.parse(shift.startsAt);
  const endsAt = Date.parse(shift.endsAt);

  if (
    Number.isNaN(now) ||
    Number.isNaN(startsAt) ||
    Number.isNaN(endsAt) ||
    endsAt < startsAt
  ) {
    return false;
  }

  return (
    now >= startsAt - CURRENT_SHIFT_WINDOW_MS &&
    now <= endsAt + CURRENT_SHIFT_WINDOW_MS
  );
}

export function currentLogisticsShifts(
  desk: LogisticsDeskRead,
  asOf: string = desk.context.asOf,
): readonly DepartmentOpsShift[] {
  return desk.searchableShifts
    .filter((shift) => isShiftCurrentlyGoing(shift, asOf))
    .slice()
    .sort((left, right) => left.startsAt.localeCompare(right.startsAt));
}

/**
 * A staff member the Logistics Window is currently holding a shift open for.
 *
 * Checked in and not yet checked out is the whole definition. That is the state
 * the window exists to close: someone standing at the desk is either being sent
 * out with equipment or being taken off shift, and both need the same roster in
 * front of the operator without a search first.
 */
export interface LogisticsStaffOnShift {
  readonly staffId: string;
  readonly displayName: string;
  readonly teamLabel: string;
  readonly shiftId: string;
  readonly shiftTitle: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly openEquipmentCount: number;
  readonly canCheckOut: boolean;
  readonly canCheckOutEquipment: boolean;
}

export function logisticsStaffOnShift(
  desk: LogisticsDeskRead,
): readonly LogisticsStaffOnShift[] {
  const onShift: LogisticsStaffOnShift[] = [];

  for (const workspace of Object.values(desk.staffWorkspaces)) {
    for (const card of workspace.shiftCards) {
      if (card.attendanceState !== "checked_in") {
        continue;
      }

      onShift.push({
        staffId: workspace.staffId,
        displayName: workspace.displayName,
        teamLabel: workspace.teamLabel,
        shiftId: card.shiftId,
        shiftTitle: card.title,
        startsAt: card.startsAt,
        endsAt: card.endsAt,
        openEquipmentCount: workspace.openEquipment.length,
        canCheckOut: card.canCheckOut,
        canCheckOutEquipment: desk.checkoutInventory.length > 0,
      });
    }
  }

  return onShift.sort((left, right) => {
    const startsAt = left.startsAt.localeCompare(right.startsAt);

    return startsAt === 0
      ? left.displayName.localeCompare(right.displayName)
      : startsAt;
  });
}

export type { LogisticsStaffStates, LogisticsStatePill };

/**
 * The states, in the order they earn their space.
 *
 * Presence first and shift second because those two are what an operator is
 * deciding on; equipment is what they follow up with. The order is the whole
 * mechanism behind showing fewer pills in a tight row — a truncated list keeps
 * the front of it, so a two-pill cap in the search dropdown is always presence
 * and shift rather than whichever two happened to be true.
 */
const LOGISTICS_STATE_PILLS: readonly LogisticsStatePill[] = Object.freeze([
  { key: "onSite", label: "On-site", tone: "positive" },
  { key: "onShift", label: "On-shift", tone: "info" },
  { key: "hasShiftEquipment", label: "Shift kit", tone: "caution" },
  { key: "hasEventEquipment", label: "Event kit", tone: "caution" },
]);

/**
 * What is true of one staff member right now, from the desk read.
 *
 * Derived rather than fetched: every fact is already on the workspace the read
 * carries, and asking the node a second question it has already answered is how
 * a screen and a server start disagreeing.
 */
export function logisticsStaffStates(
  desk: LogisticsDeskRead,
  staffId: string,
): LogisticsStaffStates {
  const workspace = desk.staffWorkspaces[staffId] ?? null;

  if (workspace === null) {
    return {
      onSite: false,
      onShift: false,
      hasShiftEquipment: false,
      hasEventEquipment: false,
    };
  }

  return {
    onSite: workspace.presenceState === "on_site",
    onShift: workspace.shiftCards.some(
      (card) => card.attendanceState === "checked_in",
    ),
    // The node's derivation of the EQUIP-009 scope, rather than this module
    // re-deciding it from a null shift column.
    hasShiftEquipment: workspace.openEquipment.some(
      (item) => item.assignmentScope === "shift",
    ),
    hasEventEquipment: workspace.openEquipment.some(
      (item) => item.assignmentScope === "event",
    ),
  };
}

/**
 * The pills to show for those states, highest priority first.
 *
 * Only what is true is rendered. "Off-site" as a pill on every other row would
 * be a wall of grey saying nothing, and the absence of an on-site pill already
 * says it — the one place off-site is worth stating outright is the workspace
 * header, which says it in full rather than by omission.
 */
export function logisticsStatePills(
  states: LogisticsStaffStates,
  limit = LOGISTICS_STATE_PILLS.length,
): readonly LogisticsStatePill[] {
  return LOGISTICS_STATE_PILLS.filter((pill) => states[pill.key]).slice(
    0,
    Math.max(limit, 0),
  );
}

/** One hit from the desk's search over the department index (SLB-021). */
export interface LogisticsSearchHit {
  readonly id: string;
  readonly kind: "staff" | "equipment" | "shift";
  readonly label: string;
  readonly detail: string;
  /** Quick-read states, for a hit that has any. Staff hits do; the rest do not. */
  readonly pills?: readonly LogisticsStatePill[];
}

function normalize(value: string): string {
  return value.trim().toLowerCase();
}

export function searchLogisticsDesk(
  desk: LogisticsDeskRead,
  query: string,
): readonly LogisticsSearchHit[] {
  const needle = normalize(query);

  if (needle.length === 0) {
    return [];
  }

  const staffHits = desk.searchableStaff
    .filter((staff) =>
      normalize(
        `${staff.displayName} ${staff.handle ?? ""} ${staff.teamLabel}`,
      ).includes(needle),
    )
    .map((staff) => {
      const states = logisticsStaffStates(desk, staff.staffId);

      return {
        id: staff.staffId,
        kind: "staff",
        // The team stays in the detail line and the presence word leaves it: it
        // is now a pill, and printing it twice on one row is the sort of thing
        // that makes a dense list unreadable rather than informative.
        detail: staff.teamLabel,
        label: staff.displayName,
        /*
         * Two, because this is a dropdown row over a search box and not a card.
         * The priority order in `LOGISTICS_STATE_PILLS` is what makes a cap safe:
         * whichever two survive are the two an operator is deciding on.
         */
        pills: logisticsStatePills(states, 2),
      } satisfies LogisticsSearchHit;
    });

  const equipmentHits = desk.searchableEquipment
    .filter((item) =>
      normalize(
        `${item.name} ${item.assetTag ?? ""} ${item.serialNumber ?? ""} ${item.holderName ?? ""}`,
      ).includes(needle),
    )
    .map(
      (item) =>
        ({
          id: item.equipmentItemId,
          kind: "equipment",
          label: item.assetTag ? `${item.name} (${item.assetTag})` : item.name,
          /*
           * A pool is not held by anybody, so it says how much of it is left
           * rather than naming a holder or reading Checked out (EQUIP-016; UI
           * contract 9.6). A tracked unit names whoever has it, and otherwise
           * reads its derived standing — which is where Overdue comes from.
           */
          detail:
            item.tracking === "pooled"
              ? `${item.quantityAvailable} of ${item.quantityTotal} available`
              : item.holderName
                ? `${item.presentationStateLabel} to ${item.holderName}`
                : item.presentationStateLabel,
        }) satisfies LogisticsSearchHit,
    );

  const shiftHits = desk.searchableShifts
    .filter((shift) =>
      normalize(`${shift.title} ${shift.teamLabel}`).includes(needle),
    )
    .map(
      (shift) =>
        ({
          id: shift.shiftId,
          kind: "shift",
          label: shift.title,
          detail: `${shift.teamLabel} / ${shift.lifecycle}`,
        }) satisfies LogisticsSearchHit,
    );

  return [...staffHits, ...equipmentHits, ...shiftHits];
}

/** Active, upcoming, and outgoing shift cards for one workspace. */
export function logisticsShiftSections(workspace: LogisticsStaffWorkspace): {
  readonly active: readonly LogisticsShiftCard[];
  readonly upcoming: readonly LogisticsShiftCard[];
  readonly outgoing: readonly LogisticsShiftCard[];
} {
  return {
    active: workspace.shiftCards.filter(
      (card) =>
        card.lifecycle === "active" || card.attendanceState === "checked_in",
    ),
    upcoming: workspace.shiftCards.filter(
      (card) =>
        card.lifecycle === "upcoming" && card.attendanceState !== "checked_in",
    ),
    outgoing: workspace.shiftCards.filter(
      (card) =>
        card.lifecycle === "completed" ||
        card.attendanceState === "checked_out",
    ),
  };
}

export function overviewSummary(overview: DepartmentOverviewRead): {
  readonly assignmentCount: number;
  readonly checkedInCount: number;
  readonly onSiteCount: number;
  readonly equipmentOutCount: number;
} {
  return {
    assignmentCount: overview.assignments.length,
    checkedInCount: overview.assignments.filter(
      (member) => member.attendanceState === "checked_in",
    ).length,
    onSiteCount: overview.onSiteCount,
    equipmentOutCount: overview.equipmentOut.length,
  };
}

export function checkedInAssignments(
  overview: DepartmentOverviewRead,
): readonly OverviewAssignment[] {
  return overview.assignments.filter(
    (member) => member.attendanceState === "checked_in",
  );
}

export function deploymentLabel(
  deployments: readonly DeploymentOption[],
  deploymentId: string | null,
): string {
  if (deploymentId === null) {
    return "Unassigned";
  }

  return (
    deployments.find((option) => option.deploymentId === deploymentId)?.name ??
    "Unassigned"
  );
}

export function planningSummary(rows: readonly PlanningRow[]): {
  readonly shiftCount: number;
  readonly underTargetCount: number;
  readonly activeCount: number;
  readonly completedCount: number;
  readonly actualHours: number;
  readonly plannedHours: number;
} {
  return {
    shiftCount: rows.length,
    underTargetCount: rows.filter(
      (row) =>
        row.capacity !== null && row.signedUpOrAssignedCount < row.capacity,
    ).length,
    activeCount: rows.filter((row) => row.lifecycle === "active").length,
    completedCount: rows.filter((row) => row.lifecycle === "completed").length,
    actualHours: sumHours(rows, "actualHours"),
    plannedHours: sumHours(rows, "plannedHours"),
  };
}

export function capacityLabel(capacity: number | null): string {
  return capacity === null ? "No target" : String(capacity);
}

export function signedVarianceLabel(hours: number): string {
  if (hours === 0) {
    return "0";
  }

  return hours > 0 ? `+${formatHours(hours)}` : formatHours(hours);
}

/**
 * Refuse to render an aggregate row carrying an identity (SLB-019).
 *
 * The node builds these rows and leaves identities out of them, and there is a
 * server test that says so. This is the second lock, on the screen that must
 * never show one: a field added to the read later that carries a name reaches
 * this before it reaches a table.
 */
export function assertNoStaffIdentities(rows: readonly PlanningRow[]): void {
  const serialized = JSON.stringify(rows);

  for (const key of [
    "displayName",
    "staffId",
    "handle",
    "signupId",
    "assignmentId",
  ]) {
    if (serialized.includes(`"${key}"`)) {
      throw new Error(`Planning Table must not expose identity field ${key}.`);
    }
  }
}

export function dateKeyForTimestamp(
  timestamp: string,
  timeZone: string,
): string {
  const parts = new Intl.DateTimeFormat("en-US", {
    day: "2-digit",
    month: "2-digit",
    timeZone,
    year: "numeric",
  }).formatToParts(new Date(timestamp));

  const part = (type: "day" | "month" | "year"): string => {
    const value = parts.find((candidate) => candidate.type === type)?.value;

    if (!value) {
      throw new Error(`Could not format ${type} for Planning Table date filter.`);
    }

    return value;
  };

  return `${part("year")}-${part("month")}-${part("day")}`;
}

function sumHours(
  rows: readonly PlanningRow[],
  field: "actualHours" | "plannedHours",
): number {
  return Number(rows.reduce((total, row) => total + row[field], 0).toFixed(1));
}

function formatHours(hours: number): string {
  return Number.isInteger(hours) ? String(hours) : hours.toFixed(1);
}
