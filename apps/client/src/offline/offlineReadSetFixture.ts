// Read sets for the specs, shaped like the ones the node composes (M18.48).
//
// Not production data and not reachable from production code: the fixture
// isolation walk proves no module a user boots into imports this
// (CLIENT-023, CLIENT-024). What it is for is stating a set once, in the shape
// `GET /api/offline-read-set` returns, so a store test and a pull test cannot
// drift apart about what a set looks like.
//
// The index generators exist for the scale question M18.47 left open. It
// measured the seeded development scenario — 74 Logistics rows, 25 kB — and said
// the measurement still to take is a department of four hundred staff and four
// hundred tracked units. These build exactly that, so the search a Logistics
// operator types is exercised at the size the department nobody has seeded yet
// would produce rather than at the size the seeder happens to make.

import type {
  OfflineReadSetPayload,
  OfflineReadSetRow,
} from "@/offline/offlineReadSet";
import { pullOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import type { OfflineReadSetContext } from "@/offline/offlineReadSetStorage";

export const FIXTURE_EVENT_ID = "11111111-1111-4111-8111-111111111111";
export const FIXTURE_ORGANIZATION_ID = "22222222-2222-4222-8222-222222222222";
export const FIXTURE_DEPARTMENT_ID = "33333333-3333-4333-8333-333333333333";

/** The scale M18.47 named as the next measurement to take. */
export const MEASURED_DEPARTMENT_STAFF = 400;
export const MEASURED_DEPARTMENT_EQUIPMENT = 400;

const GIVEN_NAMES = [
  "Dana",
  "Sam",
  "Tess",
  "Björn",
  "Amara",
  "Kai",
  "Rosa",
  "Ines",
] as const;

const FAMILY_NAMES = [
  "Reyes",
  "Okafor",
  "Lindqvist",
  "Nakamura",
  "Silva",
  "Abara",
  "Novak",
  "Ferreira",
] as const;

const TEAM_LABELS = ["Gate Crew", "Night Patrol", "Logistics", "Comms"] as const;

/**
 * A `logistics_staff_index` section, in the shape
 * `DepartmentLogisticsSections::staffIndex` composes.
 */
export function logisticsStaffIndexRows(
  count: number = MEASURED_DEPARTMENT_STAFF,
): readonly OfflineReadSetRow[] {
  return Array.from({ length: count }, (_unused, index) => {
    const given = GIVEN_NAMES[index % GIVEN_NAMES.length]!;
    const family = FAMILY_NAMES[index % FAMILY_NAMES.length]!;

    return {
      id: `${FIXTURE_EVENT_ID}:${FIXTURE_DEPARTMENT_ID}:staff-${index}`,
      event_id: FIXTURE_EVENT_ID,
      department_id: FIXTURE_DEPARTMENT_ID,
      staff_id: `staff-${index}`,
      legal_name: `${given} ${family} ${index}`,
      preferred_name: index % 3 === 0 ? given : null,
      handle: `${given.toLowerCase()}${index}`,
      team_label: TEAM_LABELS[index % TEAM_LABELS.length]!,
      // The teams whose shifts this person may be added to (M18.54; SLB-008).
      // One apiece here, which is what the measurement is meant to reflect: a
      // department member is usually on one crew.
      eligible_team_ids: [`team-${index % TEAM_LABELS.length}`],
      archived_at: null,
    };
  });
}

/**
 * A `logistics_equipment_index` section, in the shape
 * `DepartmentLogisticsSections::equipment` composes.
 */
export function logisticsEquipmentIndexRows(
  count: number = MEASURED_DEPARTMENT_EQUIPMENT,
): readonly OfflineReadSetRow[] {
  return Array.from({ length: count }, (_unused, index) => ({
    id: `equipment-${index}`,
    department_id: FIXTURE_DEPARTMENT_ID,
    name: index % 2 === 0 ? `Handheld radio ${index}` : `Lantern ${index}`,
    tracking: "individual",
    asset_tag: `MRD-${String(index).padStart(4, "0")}`,
    serial_number: `SN${String(index * 7).padStart(6, "0")}`,
    state: "available",
  }));
}

export function offlineReadSetReadiness(
  overrides: Partial<OfflineReadSetPayload["readiness"]> = {},
): OfflineReadSetPayload["readiness"] {
  return {
    composed_at: "2027-06-01T12:00:00+00:00",
    context_event_id: FIXTURE_EVENT_ID,
    node_locked_event_id: null,
    usable_until: "2027-06-08T12:00:00+00:00",
    effective_role_codes: ["department_logistics"],
    active_modules: ["scheduling", "equipment"],
    deferred_sections: [],
    aggregate_freshness: [],
    counts: {},
    ...overrides,
  };
}

/**
 * One composed set.
 *
 * `counts` is derived from the sections rather than passed, because the node
 * derives it and a fixture that let the two disagree would be teaching the specs
 * something untrue about the payload.
 */
export function offlineReadSetPayload(
  overrides: {
    readonly version?: string;
    readonly sections?: Readonly<Record<string, readonly OfflineReadSetRow[]>>;
    readonly readiness?: Partial<OfflineReadSetPayload["readiness"]>;
  } = {},
): OfflineReadSetPayload {
  const sections = overrides.sections ?? {
    staff: [{ id: "staff-self", legal_name: "Dana Reyes", handle: "dana" }],
    shifts: [
      {
        id: "shift-1",
        event_id: FIXTURE_EVENT_ID,
        department_id: FIXTURE_DEPARTMENT_ID,
        starts_at: "2027-06-01T18:00:00+00:00",
      },
    ],
  };

  const counts: Record<string, number> = {};

  for (const [name, rows] of Object.entries(sections)) {
    counts[name] = rows.length;
  }

  return {
    version: overrides.version ?? "version-1",
    sections,
    readiness: offlineReadSetReadiness({ counts, ...overrides.readiness }),
  };
}

/** A set the size of the department M18.47 said still had to be measured. */
export function measuredDepartmentReadSet(
  version = "measured-department",
): OfflineReadSetPayload {
  return offlineReadSetPayload({
    version,
    sections: {
      logistics_staff_index: logisticsStaffIndexRows(),
      logistics_equipment_index: logisticsEquipmentIndexRows(),
    },
  });
}

/**
 * Put a set on the device the way the device gets one (M18.50).
 *
 * Through the pull rather than into the store, so a spec that seeds a set gets
 * the same record a device that refreshed would hold — including the fact that it
 * came off the wire, which is what 11A.4 reads to decide whether an unbounded set
 * may still be served. Seeding the store directly would produce a record no
 * refresh could ever have produced.
 *
 * The stub is installed and removed around the pull alone, so a spec that has its
 * own `fetch` mock for the endpoints under test keeps it.
 */
export async function installOfflineReadSet(
  payload: OfflineReadSetPayload,
  context: OfflineReadSetContext = {
    organizationId: FIXTURE_ORGANIZATION_ID,
    eventId: FIXTURE_EVENT_ID,
  },
): Promise<void> {
  const held = globalThis.fetch;

  globalThis.fetch = (async () =>
    new Response(JSON.stringify(payload), {
      status: 200,
      headers: { "content-type": "application/json" },
    })) as typeof fetch;

  try {
    await pullOfflineReadSet(context);
  } finally {
    globalThis.fetch = held;
  }
}
