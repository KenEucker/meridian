import type { StatusPillTone } from "@/components/StatusPill.vue";

export const SHIFT_ATTENDANCE_STATES = [
  "scheduled",
  "checked_in",
  "checked_out",
  "no_show",
  "excused",
  "corrected",
] as const;

export type ShiftAttendanceState = (typeof SHIFT_ATTENDANCE_STATES)[number];

export const DEPARTMENT_PRESENCE_STATES = ["on_site", "off_site"] as const;

export type DepartmentPresenceState =
  (typeof DEPARTMENT_PRESENCE_STATES)[number];

export const EQUIPMENT_STATES = [
  "available",
  "checked_out",
  "returned",
  "missing",
  "damaged",
] as const;

export type EquipmentState = (typeof EQUIPMENT_STATES)[number];

export type EquipmentReturnCondition = Extract<
  EquipmentState,
  "returned" | "missing" | "damaged"
>;

/**
 * What an operator reads on an outstanding checkout, which is more than the
 * five stored states (EQUIP-005; UI contract 9.6).
 *
 * `overdue` and `unknown` are derived by the node from the stored checkout plus
 * the window it was issued against, and are deliberately not stored: overdue
 * becomes true as a clock passes a time, and a column would say "checked out"
 * for hours after it stopped being so. `unknown` is the honest answer for a
 * checkout with no window end to measure against — Meridian saying it cannot
 * tell, rather than reporting on-time as if it knew.
 */
export const EQUIPMENT_PRESENTATION_STATES = [
  "checked_out",
  "overdue",
  "unknown",
  "returned",
  "missing",
  "damaged",
] as const;

export type EquipmentPresentationState =
  (typeof EQUIPMENT_PRESENTATION_STATES)[number];

/**
 * Whether a checkout was issued for a shift or for the event (EQUIP-009).
 *
 * Shift kit is owed back when that shift ends; event kit is out until its holder
 * leaves site, and it is the second kind that quietly stays out for a week.
 */
export const EQUIPMENT_ASSIGNMENT_SCOPES = ["shift", "event"] as const;

export type EquipmentAssignmentScope =
  (typeof EQUIPMENT_ASSIGNMENT_SCOPES)[number];

/**
 * How a record is tracked (EQUIP-010; UI contract 9.6A).
 *
 * `individual` is one physical unit with an identifier on it, found at checkout
 * by entering, scanning, or searching. `pooled` is a quantity of interchangeable
 * units handed out by choosing a count. Checkout surfaces present the two
 * differently and never mix them into one list.
 */
export const EQUIPMENT_TRACKING_KINDS = ["individual", "pooled"] as const;

export type EquipmentTracking = (typeof EQUIPMENT_TRACKING_KINDS)[number];

export type ShiftLifecycle = "upcoming" | "active" | "completed" | "cancelled";

export interface DepartmentOpsContext {
  readonly eventId: string;
  readonly eventLabel: string;
  readonly departmentId: string;
  readonly departmentLabel: string;
  readonly timeZone: string;
  readonly selectedTeamId: string | null;
  readonly selectedTeamLabel: string | null;
  readonly asOf: string;
}

export interface CapabilityContext {
  readonly isDepartmentLead: boolean;
  readonly hasLogistics: boolean;
  readonly hasOperations: boolean;
  readonly hasPlanning: boolean;
  readonly hasFieldReportPermission: boolean;
  readonly hasIncidentCommand: boolean;
  readonly hasEquipmentVisibility: boolean;
}

export interface ShiftOption {
  readonly shiftId: string;
  readonly title: string;
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

export interface OverviewEquipmentSummary {
  readonly checkoutId: string;
  readonly itemName: string;
  readonly assetTag: string | null;
  readonly staffName: string;
  readonly checkedOutAt: string;
}

export interface DepartmentOverview {
  readonly context: DepartmentOpsContext;
  readonly shifts: readonly ShiftOption[];
  readonly selectedShiftId: string;
  readonly exceptions: readonly OverviewException[];
  readonly assignments: readonly OverviewAssignment[];
  readonly equipmentOut: readonly OverviewEquipmentSummary[];
  readonly deploymentOptions: readonly DeploymentOption[];
  readonly onSiteCount: number;
  readonly drillThrough: {
    readonly logisticsRouteName: string;
    readonly operationsRouteName: string;
    readonly planningRouteName: string;
  };
}

export interface DeploymentOption {
  readonly deploymentId: string;
  readonly name: string;
  readonly description: string | null;
  readonly locationDetails: string | null;
}

/**
 * What a Logistics operator needs to know about somebody before they say a word.
 *
 * Four facts, and each one changes what the desk does next. On-site and on-shift
 * decide whether there is anything to check in, check out, or add. The two
 * equipment facts decide what the desk is still owed and when it comes back:
 * kit signed out with a shift returns when that shift ends, kit signed out for
 * the event is out until the person leaves site, and it is the second kind that
 * quietly stays out for a week.
 */
export interface LogisticsStaffStates {
  readonly onSite: boolean;
  readonly onShift: boolean;
  readonly hasShiftEquipment: boolean;
  readonly hasEventEquipment: boolean;
}

/** One state, ready to render. */
export interface LogisticsStatePill {
  readonly key: keyof LogisticsStaffStates;
  readonly label: string;
  readonly tone: StatusPillTone;
}

export interface LogisticsSearchHit {
  readonly id: string;
  readonly kind: "staff" | "equipment" | "shift";
  readonly label: string;
  readonly detail: string;
  /**
   * Quick-read states for a hit that has any, highest priority first.
   *
   * Optional because only staff hits carry them, and the search component
   * renders whatever it is given rather than knowing which kinds those are.
   */
  readonly pills?: readonly LogisticsStatePill[];
}

export interface LogisticsSearchContext {
  readonly id: string;
  readonly kind: LogisticsSearchHit["kind"];
  readonly label: string;
  readonly detail: string;
  readonly relatedStaffIds: readonly string[];
  readonly emptyReason: string | null;
}

export interface LogisticsShiftCard {
  readonly shiftId: string;
  readonly title: string;
  // The team is carried by id as well as label because a shift card is what
  // tells the rest of the app which team a staff member is working for right
  // now — a Field Report filed on shift records that team, and a label cannot
  // be recorded against anything.
  readonly teamId: string;
  readonly teamLabel: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly lifecycle: ShiftLifecycle;
  readonly attendanceState: ShiftAttendanceState | null;
  readonly assignmentId: string | null;
  readonly canCheckIn: boolean;
  readonly canCheckOut: boolean;
  readonly canAddToShift: boolean;
}

export interface LogisticsEquipmentItem {
  readonly checkoutId: string | null;
  readonly equipmentItemId: string;
  readonly name: string;
  readonly assetTag: string | null;
  readonly status: EquipmentState;
  readonly checkedOutAt: string | null;
}

/**
 * One kind of equipment the desk may hand out right now (EQUIP-012, EQUIP-014).
 *
 * The same row serves both presentations because the tracking kind decides which
 * one it gets: a pooled kind becomes a quantity stepper, a tracked unit becomes
 * a lookup result. `quantityAvailable` is the node's derivation, never a stored
 * count (EQUIP-016).
 */
export interface EquipmentCheckoutCandidate {
  readonly equipmentItemId: string;
  readonly name: string;
  readonly tracking: EquipmentTracking;
  readonly trackingLabel: string;
  readonly assetTag: string | null;
  readonly serialNumber: string | null;
  readonly quantityTotal: number;
  readonly quantityAvailable: number;
}

/** A line an operator has staged for this handoff, before committing it. */
export interface EquipmentCheckoutLine {
  readonly equipmentItemId: string;
  readonly name: string;
  readonly tracking: EquipmentTracking;
  readonly assetTag: string | null;
  readonly quantity: number;
}

export interface LogisticsFutureSignup {
  readonly signupId: string;
  readonly shiftId: string;
  readonly shiftTitle: string;
  readonly startsAt: string;
  readonly endsAt: string;
  readonly state: "signed_up" | "assigned";
}

export interface LogisticsStaffWorkspace {
  readonly staffId: string;
  readonly displayName: string;
  readonly handle: string | null;
  readonly teamLabel: string;
  readonly presenceState: DepartmentPresenceState;
  readonly canGoOffSite: boolean;
  readonly offSiteBlockedReason: string | null;
  readonly shiftCards: readonly LogisticsShiftCard[];
  readonly openEquipment: readonly LogisticsEquipmentItem[];
  readonly futureSignups: readonly LogisticsFutureSignup[];
  readonly provisionsExtensionNote: string;
}

export interface LogisticsSearchCache {
  readonly state: "offline_usable" | "stale" | "sync_failed";
  readonly scopeLabel: string;
  readonly indexedAt: string;
  readonly includes: readonly LogisticsSearchHit["kind"][];
  readonly note: string;
}

export interface LogisticsDeskModel {
  readonly context: DepartmentOpsContext;
  readonly searchCache: LogisticsSearchCache;
  readonly searchableStaff: readonly {
    readonly staffId: string;
    readonly displayName: string;
    readonly handle: string | null;
    readonly teamLabel: string;
    readonly presenceState: DepartmentPresenceState;
  }[];
  readonly searchableEquipment: readonly {
    readonly equipmentItemId: string;
    readonly name: string;
    readonly assetTag: string | null;
    readonly status: EquipmentState;
    readonly presentationState: EquipmentPresentationState;
    readonly holderName: string | null;
  }[];
  readonly searchableShifts: readonly ShiftOption[];
  readonly checkoutInventory: readonly EquipmentCheckoutCandidate[];
  readonly staffWorkspaces: Readonly<Record<string, LogisticsStaffWorkspace>>;
  readonly selectedStaffId: string | null;
  readonly selectedSearchContext: LogisticsSearchContext | null;
}

export interface OperationsDeploymentRow {
  readonly assignmentId: string;
  readonly staffId: string;
  readonly displayName: string;
  readonly shiftTitle: string;
  readonly currentDeploymentId: string | null;
}

export interface OperationsModule {
  readonly id:
    | "deployments"
    | "field_reports"
    | "incidents"
    | "equipment"
    | "maintenance";
  readonly title: string;
  readonly available: boolean;
  readonly unavailableReason: string | null;
  readonly summary: string;
}

export interface OperationsCenterModel {
  readonly context: DepartmentOpsContext;
  readonly capabilities: CapabilityContext;
  readonly modules: readonly OperationsModule[];
  readonly deploymentOptions: readonly DeploymentOption[];
  readonly deploymentRows: readonly OperationsDeploymentRow[];
}

export interface PlanningAggregateRow {
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

export interface PlanningTableFilters {
  readonly teamId: string | null;
  readonly date: string | null;
}

export interface PlanningTableModel {
  readonly context: DepartmentOpsContext;
  readonly rows: readonly PlanningAggregateRow[];
  readonly selectedFilters: PlanningTableFilters;
  readonly availableTeams: readonly {
    readonly teamId: string;
    readonly teamLabel: string;
  }[];
  readonly syncState: "fresh" | "stale" | "offline";
}
