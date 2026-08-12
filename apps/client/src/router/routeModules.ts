// Which product module owns each client route, and what happens when an
// organization does not run it (M19.16; MOD-012, MOD-013, MOD-015; technical
// spec 15A.5; data/API 5.9).
//
// This is the client half of data/API 5.9's ownership table. The node declares
// its module once per route group and refuses an inactive one with `404` and a
// `module_inactive` reason; the table below declares the same ownership for the
// addresses those endpoints sit behind, so "a disabled module has no nav entry
// and no reachable route" (technical spec 15A.5) is true of the second half as
// well as the first.
//
// A declaration table rather than a flag on each route record, for the same
// reason the server groups its routes: the question "who owns this" is asked
// from outside the route — by the guard, by the navigation builders, and by the
// coverage spec that reads this file against the router — and a rule spread
// across forty route objects is a rule nobody can read in one sitting.
//
// An undeclared route is core. That is the safe direction and the same one
// `DomainNamespace` takes on the server: forgetting a declaration leaves a page
// reachable and refused by the node, rather than making a page vanish from
// organizations that run the module it was quietly assigned to.
//
// What is deliberately absent matters as much as what is here:
//
//  - **The department operations surfaces.** Overview, the Logistics Desk, the
//    Operations Center, and the Planning Table read from several modules and
//    none may fail because one is off (MOD-019). They are core endpoints on the
//    node too, and their omission is M19.18's work rather than a gate here.
//  - **The export surfaces.** Both offer five files across core and module-owned
//    records, so the page stands and the individual export goes (M19.18).
//  - **The Event Horizon, the Directory, credits, roster, and branding.** Core
//    outright, or gated by something that is not a module.
//  - **The Briefing and Insights.** Owning modules with no client route in Alpha
//    1. They belong in this table the day their surfaces are built.

import {
  MODULE_DOCUMENTS,
  MODULE_EQUIPMENT,
  MODULE_EVENT_GEOGRAPHY,
  MODULE_INCIDENT_MANAGEMENT,
  MODULE_QUALIFICATIONS,
  MODULE_SCHEDULING,
  moduleActive,
  moduleActiveForDepartment,
  type ModuleKey,
} from "@/session/sessionModules";

/** Where an address owned by an inactive module lands. */
export const MODULE_UNAVAILABLE_ROUTE = "module.unavailable";

/** The module that owns each module-owned route, by route name. */
export const ROUTE_MODULES: Readonly<Record<string, ModuleKey>> = {
  // Scheduling: shift administration and the staff shift board (MOD-002).
  "events.departments.shifts.index": MODULE_SCHEDULING,
  "events.departments.shifts.create": MODULE_SCHEDULING,
  "events.departments.shifts.edit": MODULE_SCHEDULING,
  "staff.shifts.index": MODULE_SCHEDULING,
  "kiosk.shift-board": MODULE_SCHEDULING,

  /*
   * Incident Management: the IC workspace and Field Reports, author's copy
   * included. A Field Report is an IMS record wherever it is written from
   * (`DomainNamespace::FieldReports`), so the personal author surfaces go with
   * the restricted ones rather than counting as core because they are personal.
   */
  "staff.field-reports.index": MODULE_INCIDENT_MANAGEMENT,
  "staff.field-reports.create": MODULE_INCIDENT_MANAGEMENT,
  "staff.field-reports.show": MODULE_INCIDENT_MANAGEMENT,
  "ims.dashboard": MODULE_INCIDENT_MANAGEMENT,
  "ims.incidents.index": MODULE_INCIDENT_MANAGEMENT,
  "ims.incidents.create": MODULE_INCIDENT_MANAGEMENT,
  "ims.incidents.edit": MODULE_INCIDENT_MANAGEMENT,
  "ims.incidents.show": MODULE_INCIDENT_MANAGEMENT,
  "ims.field-reports.index": MODULE_INCIDENT_MANAGEMENT,
  "ims.field-reports.create": MODULE_INCIDENT_MANAGEMENT,
  "ims.field-reports.show": MODULE_INCIDENT_MANAGEMENT,
  /*
   * The IC permission-denied surface is owned too, and that is the point of
   * declaring it. With Incident Management inactive there is no authority to
   * explain: a reader sent here would be told which role opens a workspace that
   * is not part of their organization at all.
   */
  "ims.restricted": MODULE_INCIDENT_MANAGEMENT,

  // Documents: policies, procedures, fragments, acknowledgments, and waivers.
  "events.departments.documents.index": MODULE_DOCUMENTS,
  "events.departments.documents.create": MODULE_DOCUMENTS,
  "events.departments.documents.edit": MODULE_DOCUMENTS,
  "signup.documents.acknowledge": MODULE_DOCUMENTS,
  "staff.documents.acknowledgments": MODULE_DOCUMENTS,
  "staff.documents.index": MODULE_DOCUMENTS,
  "staff.documents.show": MODULE_DOCUMENTS,
  "organizer.documents.index": MODULE_DOCUMENTS,
  "organizer.documents.create": MODULE_DOCUMENTS,
  "organizer.documents.edit": MODULE_DOCUMENTS,
  "organizer.document-acknowledgments.index": MODULE_DOCUMENTS,
  "organizer.waivers.index": MODULE_DOCUMENTS,

  /*
   * Qualifications: trainings and event credential eligibility. The credentials
   * surface is here rather than in core because `DomainNamespace::Credentials`
   * is, and because the node gates both the credential read and the eligibility
   * export on this module.
   */
  "events.departments.trainings.index": MODULE_QUALIFICATIONS,
  "events.departments.trainings.create": MODULE_QUALIFICATIONS,
  "events.departments.trainings.edit": MODULE_QUALIFICATIONS,
  "events.departments.trainings.show": MODULE_QUALIFICATIONS,
  "organizer.credentials.index": MODULE_QUALIFICATIONS,

  // Equipment: inventory, which the Logistics checkout desk feeds from.
  "events.departments.equipment.index": MODULE_EQUIPMENT,

  // Event Geography: the deployment options staff are assigned between.
  "events.departments.deployments.index": MODULE_EVENT_GEOGRAPHY,
};

/** The module owning a route, or null when the route is core. */
export function routeModule(name: unknown): ModuleKey | null {
  return typeof name === "string" ? (ROUTE_MODULES[name] ?? null) : null;
}

/**
 * Whether the organization on screen runs the module owning this route. True
 * for a core route, which is every route this table does not name.
 *
 * What navigation asks. Reading the same table the guard reads is what makes
 * "no nav entry and no reachable route" one fact rather than two rules that can
 * drift: an entry cannot survive a module its destination would bounce off.
 */
export function routeModuleActive(name: unknown): boolean {
  const module = routeModule(name);

  return module === null || moduleActive(module);
}

/**
 * Where a navigation should go instead, when the organization does not run the
 * module owning it.
 *
 * The department in the address decides which organization is asked, because
 * this runs ahead of the `beforeEnter` that records the selection: a deep link
 * into a department of another organization must be answered by that
 * organization's module state, not by the one the client was last working in.
 *
 * Presentation, like every client-side check. The node refuses the reads behind
 * the surface regardless of whether this redirect happened (MOD-012,
 * CLIENT-006), and this exists so the reader gets a sentence about their
 * organization instead of a screen that renders empty.
 */
export function moduleGateRedirect(to: {
  readonly name?: unknown;
  readonly params?: Record<string, string | string[]>;
}): { readonly name: string; readonly params: { readonly moduleKey: string } } | null {
  const module = routeModule(to.name);

  if (module === null) {
    return null;
  }

  const departmentId = to.params?.departmentId;

  if (
    moduleActiveForDepartment(
      typeof departmentId === "string" ? departmentId : null,
      module,
    )
  ) {
    return null;
  }

  return { name: MODULE_UNAVAILABLE_ROUTE, params: { moduleKey: module } };
}
