// The Directory's data layer (M18.75; DIR-001 through DIR-016, DIR-027
// through DIR-030; technical spec 21E; UI contract 12.3, 19D).
//
// One read fills the chart: the organization Directory or the event Directory,
// whichever the session's context resolves (DIR-006, DIR-007). The node
// composes everything — which departments and teams are drawn, who appears
// within them, and which locations a person entry may list — and this module
// re-derives none of it, because the visibility rule is the node's and a
// client copy could only drift from it (DIR-017; CLIENT-006).
//
// The payload is the purpose-built projection DIR-028 requires: handle,
// profile picture reference, authorized locations, years of service. No legal
// name, email, or phone exists in the shape to be stored, cached, or rendered
// by mistake — the property that makes this module boring is the requirement.
//
// The module also keeps the one piece of state navigation needs: whether the
// resolved organization has the Directory at all (DIR-004, DIR-005). Disabled
// means absent — no menu entry, no palette entry — and the menu reads a
// reactive summary this module maintains from live answers, the same pattern
// the Event Horizon's presence uses. A 404 from either Directory route is the
// node saying the organization has none, and the summary remembers that until
// a later answer says otherwise.

import { computed, reactive, type ComputedRef } from "vue";

import {
  holdsMeridianCredential,
  meridianCachedJson,
  MeridianApiError,
} from "@/api/meridianApi";
import type { ReadFreshness } from "@/offline/readFreshness";
import { sessionEventContext } from "@/session/sessionAccess";
import { sessionOrganizationId } from "@/session/sessionContext";

/** One authorized chart location of one person (DIR-030). */
export interface DirectoryLocation {
  readonly departmentId: string;
  readonly teamId: string | null;
  readonly kind:
    | "department_lead"
    | "team_lead"
    | "team_member"
    | "prospective";
}

/** A person entry: the complete list of what it may carry (DIR-029). */
export interface DirectoryPerson {
  readonly id: string;
  readonly handle: string;
  readonly profilePictureUrl: string | null;
  readonly yearsOfService: number;
  readonly locations: readonly DirectoryLocation[];
}

export interface DirectoryTeam {
  readonly id: string;
  readonly name: string;
  readonly leads: readonly string[];
  readonly members: readonly string[];
}

export interface DirectoryDepartment {
  readonly id: string;
  readonly name: string;
  readonly isOrganizers: boolean;
  readonly leads: readonly string[];
  readonly teams: readonly DirectoryTeam[];
  readonly prospectives: readonly string[];
}

export interface DirectoryChart {
  readonly scope: "organization" | "event";
  readonly organizationId: string;
  readonly organizationLabel: string | null;
  readonly eventId: string | null;
  readonly eventLabel: string | null;
  readonly departments: readonly DirectoryDepartment[];
  readonly people: readonly DirectoryPerson[];
  readonly freshness: ReadFreshness;
}

/** One search result row: a handle at one authorized location (DIR-034). */
export interface DirectorySearchRow {
  readonly staffId: string;
  readonly handle: string;
  readonly breadcrumb: string;
  readonly location: DirectoryLocation;
}

interface DirectoryPayload {
  readonly context?: {
    readonly scope?: string;
    readonly organization_id?: string;
    readonly organization_label?: string | null;
    readonly event_id?: string | null;
    readonly event_label?: string | null;
  };
  readonly departments?: readonly {
    readonly id: string;
    readonly name: string;
    readonly is_organizers?: boolean;
    readonly leads?: readonly string[];
    readonly teams?: readonly {
      readonly id: string;
      readonly name: string;
      readonly leads?: readonly string[];
      readonly members?: readonly string[];
    }[];
    readonly prospectives?: readonly string[];
  }[];
  readonly people?: readonly {
    readonly id: string;
    readonly handle?: string | null;
    readonly profile_picture_url?: string | null;
    readonly years_of_service?: number;
    readonly locations?: readonly {
      readonly department_id: string;
      readonly team_id?: string | null;
      readonly kind?: string;
    }[];
  }[];
}

interface DirectorySearchPayload {
  readonly results?: readonly {
    readonly staff_id: string;
    readonly handle: string;
    readonly breadcrumb?: string;
    readonly location?: {
      readonly department_id?: string;
      readonly team_id?: string | null;
      readonly kind?: string;
    };
  }[];
}

/**
 * Whether the resolved organization has a Directory (DIR-004, DIR-005), as
 * the node last answered. Navigation and the palette read this; they never
 * fetch on their own.
 *
 * Absent until a live answer establishes it, the way the Event Horizon's menu
 * presence is: a menu entry this client offers unprompted has to be one the
 * node has actually confirmed, because the alternative is offering a page a
 * disabled organization would answer 404 to.
 */
export const directoryPresence = reactive<{
  organizationId: string | null;
  enabled: boolean;
}>({
  organizationId: null,
  enabled: false,
});

/** Forget the presence summary. Tests and sign-out use this; nothing else should. */
export function resetDirectoryPresence(): void {
  directoryPresence.organizationId = null;
  directoryPresence.enabled = false;
}

function rememberPresence(organizationId: string | null, enabled: boolean): void {
  if (organizationId === null) {
    return;
  }

  directoryPresence.organizationId = organizationId;
  directoryPresence.enabled = enabled;
}

/**
 * Whether navigation offers the Directory for the session's organization
 * (DIR-002, DIR-005): present only where a live answer said the organization
 * has one. Absent — not disabled, and never an explanatory entry — otherwise.
 */
export function directoryMenuPresent(): boolean {
  return (
    sessionOrganizationId.value !== null &&
    directoryPresence.organizationId === sessionOrganizationId.value &&
    directoryPresence.enabled
  );
}

export function useDirectoryMenuPresence(): ComputedRef<boolean> {
  return computed(directoryMenuPresent);
}

function kindOf(value: string | undefined): DirectoryLocation["kind"] {
  switch (value) {
    case "department_lead":
    case "team_lead":
    case "prospective":
      return value;
    default:
      return "team_member";
  }
}

/**
 * The chart route for the session's resolved context: the event Directory
 * where the interface is resolved to an event, the organization Directory
 * otherwise (DIR-006, DIR-007; technical spec 11A.3). Null when the session
 * has resolved neither — a client with no context has no population to ask
 * about.
 */
function directoryPath(suffix = ""): string | null {
  const eventId = sessionEventContext.value?.eventId ?? null;

  if (eventId !== null) {
    return `/api/events/${eventId}/directory${suffix}`;
  }

  const organizationId = sessionOrganizationId.value;

  return organizationId === null
    ? null
    : `/api/organizations/${organizationId}/directory${suffix}`;
}

function toChart(payload: DirectoryPayload, freshness: ReadFreshness): DirectoryChart {
  return {
    scope: payload.context?.scope === "event" ? "event" : "organization",
    organizationId: payload.context?.organization_id ?? "",
    organizationLabel: payload.context?.organization_label ?? null,
    eventId: payload.context?.event_id ?? null,
    eventLabel: payload.context?.event_label ?? null,
    departments: (payload.departments ?? []).map((department) => ({
      id: department.id,
      name: department.name,
      isOrganizers: department.is_organizers ?? false,
      leads: department.leads ?? [],
      teams: (department.teams ?? []).map((team) => ({
        id: team.id,
        name: team.name,
        leads: team.leads ?? [],
        members: team.members ?? [],
      })),
      prospectives: department.prospectives ?? [],
    })),
    people: (payload.people ?? []).map((person) => ({
      id: person.id,
      handle: person.handle ?? "",
      profilePictureUrl: person.profile_picture_url ?? null,
      yearsOfService: person.years_of_service ?? 0,
      locations: (person.locations ?? []).map((location) => ({
        departmentId: location.department_id,
        teamId: location.team_id ?? null,
        kind: kindOf(location.kind),
      })),
    })),
    freshness,
  };
}

/** The node said this organization has no Directory (DIR-005). */
export class DirectoryAbsentError extends Error {
  constructor() {
    super("This page does not exist.");
    this.name = "DirectoryAbsentError";
  }
}

/**
 * Read the chart for the session's resolved context.
 *
 * A 404 is the node saying the organization has no Directory (DIR-005): the
 * presence summary records it so the menu entry disappears, and the caller
 * gets a typed absence rather than a transport error, because the surface
 * renders "page not found" for it — never an empty chart, and never an
 * explanation that the feature is switched off.
 */
export async function fetchDirectoryChart(): Promise<DirectoryChart> {
  const path = directoryPath();

  if (path === null) {
    throw new Error("No organization or event context is resolved.");
  }

  try {
    const { data, freshness } = await meridianCachedJson<DirectoryPayload>(path);
    const chart = toChart(data, freshness);

    if (freshness.source === "node") {
      rememberPresence(chart.organizationId || sessionOrganizationId.value, true);
    }

    return chart;
  } catch (error) {
    if (error instanceof MeridianApiError && error.status === 404) {
      rememberPresence(sessionOrganizationId.value, false);

      throw new DirectoryAbsentError();
    }

    throw error;
  }
}

/**
 * Search handles over the authorized set (M18.74 endpoint; DIR-031 through
 * DIR-034). The node builds the index from what the visibility rule
 * authorized, so nothing here filters anything.
 */
export async function searchDirectory(
  query: string,
): Promise<readonly DirectorySearchRow[]> {
  const trimmed = query.trim();

  if (trimmed === "") {
    return [];
  }

  const path = directoryPath(`/search?q=${encodeURIComponent(trimmed)}`);

  if (path === null) {
    return [];
  }

  try {
    const { data } = await meridianCachedJson<DirectorySearchPayload>(path);

    return (data.results ?? []).map((row) => ({
      staffId: row.staff_id,
      handle: row.handle,
      breadcrumb: row.breadcrumb ?? "",
      location: {
        departmentId: row.location?.department_id ?? "",
        teamId: row.location?.team_id ?? null,
        kind: kindOf(row.location?.kind),
      },
    }));
  } catch (error) {
    if (error instanceof MeridianApiError && error.status === 404) {
      rememberPresence(sessionOrganizationId.value, false);

      throw new DirectoryAbsentError();
    }

    throw error;
  }
}

/**
 * Refresh the navigation's presence summary, quietly.
 *
 * Called from the shell when the session resolves (DIR-002 puts the entry in
 * the Workflows menu, and a menu cannot offer what nothing has confirmed).
 * Failures leave the summary as it stands: a menu entry is not worth an
 * error, and the surface reports its own reads. A client holding no
 * credential asks nothing, for the same reason the Event Horizon's refresh
 * does not.
 */
export async function refreshDirectoryPresence(): Promise<void> {
  if (!holdsMeridianCredential()) {
    return;
  }

  try {
    await fetchDirectoryChart();
  } catch {
    // Either the organization has no Directory — recorded already — or the
    // node could not be reached, and the menu simply keeps what it knew.
  }
}
