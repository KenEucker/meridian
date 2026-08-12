// The modules an organization runs, as the client reads them (M19.16; MOD-015,
// MOD-022; CLIENT-001 through CLIENT-004; technical spec 11A.3, 15A.8).
//
// The catalogue is fixed in Meridian's own code (MOD-003) and the server's copy
// is `App\Domain\Modules\ModuleKey`. This file spells the same keys so a typo
// becomes a compile error rather than a route that quietly gates nothing, in
// exactly the way `permissionCodes` spells capability codes. It is not a second
// source of truth: which of these an organization runs is the node's answer,
// carried on each organization in the session document, and nothing here
// computes module state.
//
// Three properties are load-bearing.
//
//  1. **Gating is per organization, not per session.** A person can belong to
//     departments in more than one organization, and each answers for itself.
//     The organization asked about is the one the surface belongs to — the
//     selected department's, falling back to the resolved context — because
//     that is the organization the node would evaluate the request against.
//  2. **Not knowing is not the same as running nothing.** A document from a
//     build older than this field, or a client with no context resolved yet,
//     yields no answer, and no answer means the surface stays offered. The
//     failure mode of a missing set is a page that is reachable and refused by
//     the node, not a product that silently disappears — the same direction
//     `DomainNamespace` takes for an undeclared namespace on the server.
//  3. **It is presentation, like every other client-side check.** The module
//     gate on the node is the enforcement boundary (technical spec 15A.4,
//     15A.5), and a client that fails to hide a surface still gets `404` with
//     `module_inactive` when it asks (MOD-012, MOD-013).
//
// Absence is not denial, and the copy below is where that distinction becomes
// words. A member of an organization that does not run Scheduling is not being
// refused anything — there is nobody for whom those pages are present — so the
// sentence they read says what their organization runs, not what they may do.

import { computed } from "vue";

import { clientSessionState, sessionAccessGranted } from "@/session/clientSession";
import { selectedSessionDepartment } from "@/session/sessionAccess";
import type { SessionDocument } from "@/session/sessionDocument";

/**
 * The Alpha 1 module catalogue (MOD-002), by key.
 *
 * The keys the node publishes and gates on. They are identifiers and never
 * labels: MOD-022 keeps them out of the interface, which is what
 * {@link MODULE_NAMES} is for.
 */
export const MODULE_KEYS = [
  "scheduling",
  "ims",
  "documents",
  "qualifications",
  "equipment",
  "geography",
  "briefing",
  "insights",
] as const;

export type ModuleKey = (typeof MODULE_KEYS)[number];

/*
 * All eight, including the two Alpha 1 builds no client surface for yet. The
 * catalogue is a fixed set (MOD-003) and a partial list of it would read as a
 * judgement about which modules matter rather than as which surfaces exist.
 */
export const MODULE_SCHEDULING = "scheduling";
export const MODULE_INCIDENT_MANAGEMENT = "ims";
export const MODULE_DOCUMENTS = "documents";
export const MODULE_QUALIFICATIONS = "qualifications";
export const MODULE_EQUIPMENT = "equipment";
export const MODULE_EVENT_GEOGRAPHY = "geography";
export const MODULE_BRIEFING = "briefing";
export const MODULE_INSIGHTS = "insights";

/**
 * Meridian's own name for each module (MOD-002, MOD-022).
 *
 * Held here rather than read from the node because these are needed before any
 * read succeeds: the sentence explaining that a module is absent is precisely
 * the one shown when the surface behind it was refused. The organizer's
 * configuration surface is the other way round — it prints the node's own names
 * and summaries, because what an organizer reads before making a choice and
 * what the product does with the choice must not be able to drift apart.
 */
export const MODULE_NAMES: Record<ModuleKey, string> = {
  scheduling: "Scheduling",
  ims: "Incident Management",
  documents: "Documents",
  qualifications: "Qualifications",
  equipment: "Equipment",
  geography: "Event Geography",
  briefing: "The Briefing",
  insights: "Insights",
};

function isModuleKey(value: unknown): value is ModuleKey {
  return (
    typeof value === "string" && (MODULE_KEYS as readonly string[]).includes(value)
  );
}

/** The name to print for a module, or the key when a build does not know it. */
export function moduleName(module: string): string {
  return isModuleKey(module) ? MODULE_NAMES[module] : module;
}

/** The document module state may be read from, or null while access is refused. */
const grantedDocument = computed<SessionDocument | null>(() =>
  sessionAccessGranted.value ? clientSessionState.document : null,
);

/**
 * The organization whose module state governs what is on screen.
 *
 * The selected department's organization first, because a department-scoped
 * surface is evaluated by the node against the organization that department
 * belongs to, and somebody working across two organizations would otherwise be
 * shown one organization's answer about the other's pages. The resolved context
 * organization otherwise, which is what an organization-level or personal
 * surface belongs to.
 */
export const sessionModuleOrganizationId = computed<string | null>(() => {
  const department = selectedSessionDepartment.value;

  if (department !== null && department.organizationId !== null) {
    return department.organizationId;
  }

  return grantedDocument.value?.context.organization_id ?? null;
});

/**
 * The modules one organization runs, or null when this client cannot say.
 *
 * Null is the honest answer in three cases: access is refused so there is no
 * document, the document does not list that organization, or it lists it
 * without a module set — a document written by a build from before MOD-015.
 * Every caller reads null as "do not gate", never as "nothing is active".
 */
export function activeModulesIn(
  organizationId: string | null,
): readonly ModuleKey[] | null {
  const document = grantedDocument.value;

  if (document === null || organizationId === null) {
    return null;
  }

  const organization = document.organizations.find(
    (candidate) => candidate.id === organizationId,
  );
  const modules = organization?.modules;

  // Validated here rather than in `isSessionDocument`, which deliberately
  // accepts a document carrying no module set at all. A malformed one is the
  // same fact — this client cannot read what the organization runs — and
  // discarding a whole session's permissions over it would sign a device out to
  // tidy a menu.
  if (!Array.isArray(modules)) {
    return null;
  }

  return modules.filter(isModuleKey);
}

/** Whether one organization runs one module, presuming yes when unknown. */
export function moduleActiveIn(
  organizationId: string | null,
  module: ModuleKey,
): boolean {
  const active = activeModulesIn(organizationId);

  return active === null || active.includes(module);
}

/**
 * The organization a department belongs to, or null when the session document
 * does not carry that department.
 *
 * The router needs this because a department-scoped address is answered before
 * the department it names has been selected: the route guard runs ahead of the
 * `beforeEnter` that records the selection, so a deep link into an organization
 * the client is not currently working in would otherwise be gated on the module
 * state of the one it just left.
 */
function organizationIdOfDepartment(departmentId: string | null): string | null {
  if (departmentId === null) {
    return null;
  }

  const department = grantedDocument.value?.departments.find(
    (candidate) => candidate.id === departmentId,
  );

  return department?.organization_id ?? null;
}

/**
 * The modules the organization on screen runs, or null when this client cannot
 * say.
 */
export const sessionActiveModules = computed<readonly ModuleKey[] | null>(() =>
  activeModulesIn(sessionModuleOrganizationId.value),
);

/**
 * Whether a module is active for the organization on screen.
 *
 * The one predicate navigation and the router guard both answer to, so a link
 * that is absent and a route that is unreachable are absent and unreachable for
 * the same reason (technical spec 15A.5).
 */
export function moduleActive(module: ModuleKey): boolean {
  return moduleActiveIn(sessionModuleOrganizationId.value, module);
}

/**
 * Whether a module is active for the organization a named department belongs
 * to, falling back to the organization on screen when the department is not one
 * the session carries.
 *
 * What the router asks, because an address names its department before the
 * client has selected it.
 */
export function moduleActiveForDepartment(
  departmentId: string | null,
  module: ModuleKey,
): boolean {
  const organizationId =
    organizationIdOfDepartment(departmentId) ?? sessionModuleOrganizationId.value;

  return moduleActiveIn(organizationId, module);
}

/**
 * What to tell a member of an organization that does not run this module
 * (MOD-013, MOD-022).
 *
 * Deliberately not the sentence a denied surface uses. A permission denial says
 * something about the reader — that the authority they hold does not reach this
 * — and invites them to ask somebody for it. This says something about the
 * organization: the capability is not part of what it runs, it is absent for
 * everyone here including whoever they would have asked, and there is nothing
 * for them to be granted. The module is named because module state is an
 * organization's own configuration rather than a secret from its members.
 */
export interface ModuleAbsenceCopy {
  readonly moduleName: string;
  readonly heading: string;
  readonly message: string;
  /** Where the absence is resolved, for whoever holds the authority to do it. */
  readonly resolution: string;
}

export function moduleAbsenceCopy(module: string): ModuleAbsenceCopy {
  const name = moduleName(module);

  return {
    moduleName: name,
    heading: `${name} is not part of this organization`,
    message: `Your organization does not use ${name}, so these pages are not here for anyone in it. This is not a permission problem: there is no role that reaches them and nothing to be granted.`,
    resolution: `An organizer can turn ${name} on from Organization configuration.`,
  };
}
