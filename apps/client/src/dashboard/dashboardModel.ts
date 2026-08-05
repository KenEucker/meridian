// The dashboard surfaces' data layer (M18.28; UI contract 13.1 through 13.6;
// dashboard widget spec).
//
// One read fills every dashboard: `staff.dashboard`, `department.dashboard`,
// `organizer.dashboard`, `ims.dashboard`, and the kiosk home screen are five
// presentations of one question — what needs this person's attention in this
// event — and each asks for the groups it renders.
//
// Three properties are load-bearing.
//
//  1. **The node decides what is on it.** Which groups open, which widgets
//     inside them the reader holds, what each one found, and the attention level
//     it carries are all the node's answers. Nothing here re-derives a count or
//     a permission, because a client that worked out its own would be a second
//     rule set that can only drift (CLIENT-006).
//  2. **The quiet sentence comes with the widget.** A quiet widget arrives
//     carrying the contract's own wording, so two surfaces cannot word the same
//     reassurance differently and this module never invents one.
//  3. **Ordering is the only thing decided here.** Widget spec 8 asks the mobile
//     feed to order by attention level; section 17 leaves per-role ordering
//     open. So attention orders the list and the node's contract order breaks
//     ties, on every profile — a rule a reader can learn once rather than a
//     layout that reshuffles when the window narrows.
//
// The read is cached like every other Alpha 1 read, so a device out of coverage
// renders the copy it holds with the staleness disclosed rather than an empty
// page (technical spec 9.3).

import type { RouteLocationRaw } from "vue-router";

import { meridianCachedJson } from "@/api/meridianApi";
import type { ReadFreshness } from "@/offline/readCache";

/**
 * The attention scale (UI contract 9.9; widget spec 5).
 *
 * How urgently the interface should draw attention, and nothing else. It is not
 * IMS priority and not incident state; the IC widgets carry all three and a
 * surface that conflated them would be reporting an incident's seriousness as a
 * property of a card.
 */
export const DASHBOARD_ATTENTION_LEVELS = [
  "restricted",
  "critical",
  "warning",
  "attention",
  "routine",
] as const;

export type DashboardAttention = (typeof DASHBOARD_ATTENTION_LEVELS)[number];

/** The six groups of UI contract 13.1 through 13.6. */
export type DashboardGroupId =
  | "staff"
  | "department_lead"
  | "department_operations"
  | "organizer"
  | "ic"
  | "kiosk";

export interface DashboardGroup {
  readonly group: DashboardGroupId;
  readonly label: string;
  /** Which section of UI contract 13 this group's inventory comes from. */
  readonly contractSection: string;
}

/** One row inside a widget, capped by the node (widget spec 12). */
export interface DashboardWidgetItem {
  readonly label: string;
  readonly detail: string | null;
  readonly status: string | null;
}

export interface DashboardWidget {
  readonly id: string;
  readonly group: DashboardGroupId;
  readonly title: string;
  /** The contract's scope column, e.g. `user/event`. */
  readonly scope: string;
  readonly attention: DashboardAttention;
  readonly attentionLabel: string;
  readonly quiet: boolean;
  /** What the widget says when it found nothing, in the contract's words. */
  readonly quietState: string;
  /** What it found, when it found something. Null while quiet. */
  readonly summary: string | null;
  readonly items: readonly DashboardWidgetItem[];
  readonly metric: { readonly value: number; readonly label: string } | null;
  readonly actionLabel: string | null;
  /** The UI contract section 12 screen id the action opens. */
  readonly actionSurface: string | null;
}

/**
 * One row of the inventory, including the widgets this node cannot compile.
 *
 * Carried from the node rather than compiled in, because which widgets are
 * deferred is a fact about this build. A client holding its own copy would go on
 * describing a Briefing widget as pending after the Briefing shipped.
 */
export interface DashboardInventoryEntry {
  readonly id: string;
  readonly group: DashboardGroupId;
  readonly title: string;
  readonly permission: string;
  readonly quietState: string;
  /** `node` or `device`: who answers it. */
  readonly evaluation: string;
  /** The task that will make it answerable, or null when one already has. */
  readonly deferredTo: string | null;
}

export interface DashboardContext {
  readonly eventId: string;
  readonly eventLabel: string | null;
  readonly organizationId: string | null;
  readonly departmentId: string | null;
  readonly departmentLabel: string | null;
  readonly timeZone: string;
  readonly asOf: string;
}

export interface Dashboard {
  readonly context: DashboardContext;
  readonly groups: readonly DashboardGroup[];
  readonly widgets: readonly DashboardWidget[];
  readonly inventory: readonly DashboardInventoryEntry[];
  readonly freshness: ReadFreshness;
}

interface DashboardItemPayload {
  readonly label?: string | null;
  readonly detail?: string | null;
  readonly status?: string | null;
}

interface DashboardWidgetPayload {
  readonly id: string;
  readonly group: string;
  readonly title: string;
  readonly scope?: string | null;
  readonly attention: string;
  readonly attention_label?: string | null;
  readonly quiet: boolean;
  readonly quiet_state?: string | null;
  readonly summary?: string | null;
  readonly items?: readonly DashboardItemPayload[];
  readonly metric?: { readonly value: number; readonly label: string } | null;
  readonly action_label?: string | null;
  readonly action_surface?: string | null;
}

interface DashboardPayload {
  readonly context: {
    readonly event_id: string;
    readonly event_label?: string | null;
    readonly organization_id?: string | null;
    readonly department_id?: string | null;
    readonly department_label?: string | null;
    readonly time_zone?: string | null;
    readonly as_of: string;
  };
  readonly groups?: readonly {
    readonly group: string;
    readonly label: string;
    readonly contract_section?: string | null;
  }[];
  readonly widgets?: readonly DashboardWidgetPayload[];
  readonly inventory?: readonly {
    readonly id: string;
    readonly group: string;
    readonly title: string;
    readonly permission?: string | null;
    readonly quiet_state?: string | null;
    readonly evaluation?: string | null;
    readonly deferred_to?: string | null;
  }[];
}

function isAttention(value: string): value is DashboardAttention {
  return (DASHBOARD_ATTENTION_LEVELS as readonly string[]).includes(value);
}

/**
 * An attention level this client does not know is read as Routine.
 *
 * A node that grew a level this build has never heard of should not be able to
 * make a card render as nothing. Routine is the honest fallback: the widget is
 * shown, with its own words, and it does not claim an urgency this client cannot
 * interpret.
 */
function toAttention(value: string): DashboardAttention {
  return isAttention(value) ? value : "routine";
}

function toWidget(payload: DashboardWidgetPayload): DashboardWidget {
  return {
    id: payload.id,
    group: payload.group as DashboardGroupId,
    title: payload.title,
    scope: payload.scope ?? "",
    attention: toAttention(payload.attention),
    attentionLabel: payload.attention_label ?? "",
    quiet: payload.quiet,
    quietState: payload.quiet_state ?? "",
    summary: payload.summary ?? null,
    items: (payload.items ?? []).map((item) => ({
      label: item.label ?? "",
      detail: item.detail ?? null,
      status: item.status ?? null,
    })),
    metric: payload.metric ?? null,
    actionLabel: payload.action_label ?? null,
    actionSurface: payload.action_surface ?? null,
  };
}

/**
 * Read one event's dashboard, optionally narrowed to a department.
 *
 * The department narrows only the two department-scoped groups; the rest of the
 * answer is the same either way. A Kiosk sends none and gets its workstation's
 * pinned department, which is the machine's own context rather than a request's.
 */
export async function fetchDashboard(
  eventId: string,
  departmentId: string | null = null,
): Promise<Dashboard> {
  const query = departmentId === null ? "" : `?department_id=${departmentId}`;
  const { data, freshness } = await meridianCachedJson<DashboardPayload>(
    `/api/events/${eventId}/dashboard${query}`,
  );

  return {
    context: {
      eventId: data.context.event_id,
      eventLabel: data.context.event_label ?? null,
      organizationId: data.context.organization_id ?? null,
      departmentId: data.context.department_id ?? null,
      departmentLabel: data.context.department_label ?? null,
      timeZone: data.context.time_zone ?? "UTC",
      asOf: data.context.as_of,
    },
    groups: (data.groups ?? []).map((group) => ({
      group: group.group as DashboardGroupId,
      label: group.label,
      contractSection: group.contract_section ?? "",
    })),
    widgets: (data.widgets ?? []).map(toWidget),
    inventory: (data.inventory ?? []).map((entry) => ({
      id: entry.id,
      group: entry.group as DashboardGroupId,
      title: entry.title,
      permission: entry.permission ?? "",
      quietState: entry.quiet_state ?? "",
      evaluation: entry.evaluation ?? "node",
      deferredTo: entry.deferred_to ?? null,
    })),
    freshness,
  };
}

/**
 * The widgets of one group, most urgent first (widget spec 8).
 *
 * Ties keep the node's order, which is the contract's order, so two Routine
 * widgets never swap places between reads. `Array.prototype.sort` is stable in
 * every runtime this client supports, which is what makes that true without a
 * second key.
 */
export function orderedWidgets(
  widgets: readonly DashboardWidget[],
  group: DashboardGroupId | null = null,
): DashboardWidget[] {
  const scoped =
    group === null ? [...widgets] : widgets.filter((widget) => widget.group === group);

  return scoped.sort(
    (left, right) =>
      DASHBOARD_ATTENTION_LEVELS.indexOf(left.attention) -
      DASHBOARD_ATTENTION_LEVELS.indexOf(right.attention),
  );
}

/** Whether anything in this dashboard is asking for something. */
export function hasAnythingOutstanding(
  widgets: readonly DashboardWidget[],
): boolean {
  return widgets.some((widget) => !widget.quiet && widget.attention !== "routine");
}

/**
 * Where a widget's primary action goes, or null when this client has no surface
 * for it yet.
 *
 * The node names a UI contract section 12 screen id and this maps it to a route.
 * A screen id with no route resolves to null and the card renders its action as
 * absent rather than as a dead control — CLIENT-005, and the reason a widget for
 * a surface that has not shipped is still worth showing: the reading is useful
 * before the destination exists.
 *
 * Department-scoped destinations need both ids, so one that cannot be built is
 * null too rather than a link into whichever department the router last held.
 */
export function widgetDestination(
  surface: string | null,
  context: DashboardContext,
): RouteLocationRaw | null {
  if (surface === null) {
    return null;
  }

  const departmentParams =
    context.departmentId === null
      ? null
      : { eventId: context.eventId, departmentId: context.departmentId };

  switch (surface) {
    case "staff.shifts":
      return { name: "staff.shifts.index" };
    case "staff.document-acknowledgments":
      return { name: "staff.documents.acknowledgments" };
    case "department.shifts":
      return departmentParams === null
        ? null
        : { name: "events.departments.shifts.index", params: departmentParams };
    case "department.trainings":
      return departmentParams === null
        ? null
        : { name: "events.departments.trainings.index", params: departmentParams };
    case "department.documents":
      return departmentParams === null
        ? null
        : { name: "events.departments.documents.index", params: departmentParams };
    case "department.logistics":
      return departmentParams === null
        ? null
        : { name: "events.departments.logistics", params: departmentParams };
    case "department.operations":
      return departmentParams === null
        ? null
        : { name: "events.departments.operations", params: departmentParams };
    case "department.overview":
      return departmentParams === null
        ? null
        : { name: "events.departments.overview", params: departmentParams };
    case "organizer.departments":
      return { name: "organizer.departments.index" };
    case "organizer.applications":
      return { name: "organizer.applications.index" };
    case "organizer.policy-documents":
      return { name: "organizer.documents.index" };
    case "ims.incidents":
      return { name: "ims.incidents.index" };
    case "ims.field-reports":
      return { name: "ims.field-reports.index" };
    case "readiness":
      return { name: "readiness" };
    case "kiosk.home":
      return { name: "kiosk.home" };
    /*
     * `context.departments`, `organizer.events`, `map.view`, `briefing.hub`, and
     * `kiosk.switch-user` are contract screens this client has not built yet
     * (M18.29 through M18.32, and Milestones 14 and 15). Their widgets still
     * read; their actions are absent until the screen exists.
     */
    default:
      return null;
  }
}
