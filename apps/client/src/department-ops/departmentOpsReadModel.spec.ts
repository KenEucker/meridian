import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  assertNoStaffIdentities,
  currentLogisticsShifts,
  deploymentLabel,
  getPlanningTable,
  isShiftCurrentlyGoing,
  logisticsShiftSections,
  logisticsStaffOnShift,
  logisticsStaffStates,
  logisticsStatePills,
  planningSummary,
  queueCheckIn,
  searchLogisticsDesk,
  setDepartmentPresence,
  type DepartmentOpsContext,
  type LogisticsDeskRead,
  type LogisticsEquipmentItem,
  type LogisticsShiftCard,
  type LogisticsStaffWorkspace,
  type PlanningRow,
} from "@/department-ops/departmentOpsReadModel";
import { commandOutbox } from "@/outbox/commandOutboxRuntime";
import { clearClientSession } from "@/session/clientSession";
import { installLocalFieldSession } from "@/session/localFieldSession";

const CONTEXT: DepartmentOpsContext = {
  eventId: "11111111-1111-4111-8111-111111111111",
  eventLabel: "Idaho Decompression",
  departmentId: "66666666-6666-4666-8666-666666666666",
  departmentLabel: "Rangers",
  timeZone: "America/Los_Angeles",
  asOf: "2027-07-04T18:00:00.000Z",
};

function workspace(
  overrides: Partial<LogisticsStaffWorkspace> = {},
): LogisticsStaffWorkspace {
  return {
    staffId: "staff-1",
    displayName: "Vera Staff",
    handle: "vera",
    teamLabel: "Dirt",
    presenceState: "on_site",
    canGoOffSite: true,
    offSiteBlockedReason: null,
    shiftCards: [],
    openEquipment: [],
    availableEquipment: [],
    futureSignups: [],
    ...overrides,
  };
}

function card(
  overrides: Partial<LogisticsShiftCard> = {},
): LogisticsShiftCard {
  return {
    shiftId: "shift-day",
    title: "Ranger Dirt Day Shift",
    teamId: "team-dirt",
    teamLabel: "Dirt",
    startsAt: "2027-07-04T16:00:00.000Z",
    endsAt: "2027-07-04T22:00:00.000Z",
    lifecycle: "active",
    attendanceState: null,
    assignmentId: null,
    canCheckIn: false,
    canCheckOut: false,
    canMarkNoShow: false,
    canAddToShift: false,
    addToShiftBlockedReason: null,
    ...overrides,
  };
}

function equipment(
  overrides: Partial<LogisticsEquipmentItem> = {},
): LogisticsEquipmentItem {
  return {
    checkoutId: "checkout-1",
    equipmentItemId: "equipment-radio-12",
    name: "Radio 12",
    assetTag: "RDO-12",
    status: "checked_out",
    checkedOutAt: "2027-07-04T16:05:00.000Z",
    shiftId: null,
    ...overrides,
  };
}

function desk(overrides: Partial<LogisticsDeskRead> = {}): LogisticsDeskRead {
  return {
    context: CONTEXT,
    access: {
      isDepartmentLead: false,
      canManagePresence: true,
      canManageAttendance: true,
      canManageEquipment: true,
      canAssignDeployments: false,
      canManagePlanning: false,
      canAdministerDepartment: false,
    },
    searchableStaff: [
      {
        staffId: "staff-1",
        displayName: "Vera Staff",
        handle: "vera",
        teamLabel: "Dirt",
        presenceState: "on_site",
      },
    ],
    searchableEquipment: [
      {
        equipmentItemId: "equipment-radio-12",
        name: "Radio 12",
        assetTag: "RDO-12",
        status: "checked_out",
        statusLabel: "Checked out",
        holderStaffId: "staff-1",
        holderName: "Vera Staff",
      },
    ],
    searchableShifts: [
      {
        shiftId: "shift-day",
        title: "Ranger Dirt Day Shift",
        teamId: "team-dirt",
        teamLabel: "Dirt",
        startsAt: "2027-07-04T16:00:00.000Z",
        endsAt: "2027-07-04T22:00:00.000Z",
        lifecycle: "active",
        capacity: 4,
      },
      {
        shiftId: "shift-swing",
        title: "Ranger Dirt Swing Shift",
        teamId: "team-dirt",
        teamLabel: "Dirt",
        startsAt: "2027-07-05T04:00:00.000Z",
        endsAt: "2027-07-05T10:00:00.000Z",
        lifecycle: "upcoming",
        capacity: 3,
      },
    ],
    staffWorkspaces: {},
    ...overrides,
  };
}

function row(overrides: Partial<PlanningRow> = {}): PlanningRow {
  return {
    shiftId: "shift-day",
    title: "Ranger Dirt Day Shift",
    teamId: "team-dirt",
    teamLabel: "Dirt",
    startsAt: "2027-07-04T16:00:00.000Z",
    endsAt: "2027-07-04T22:00:00.000Z",
    lifecycle: "active",
    capacity: 4,
    signedUpOrAssignedCount: 3,
    checkedInCount: 2,
    noShowCount: 0,
    unscheduledCount: 0,
    plannedHours: 24,
    actualHours: 4.2,
    varianceHours: -19.8,
    statusLabel: "Under target",
    ...overrides,
  };
}

/**
 * Drive the device-network signal.
 *
 * The event matters as much as the property: `deviceConnectivity` is a module
 * ref that only moves on `online`/`offline`, so a test that drops the network
 * and does not put it back leaves every later test offline.
 */
function setNavigatorOnline(onLine: boolean): void {
  Object.defineProperty(window.navigator, "onLine", {
    configurable: true,
    value: onLine,
  });

  window.dispatchEvent(new Event(onLine ? "online" : "offline"));
}

afterEach(() => {
  clearClientSession();
  commandOutbox.clear();
  configureMeridianApi(null);
  vi.unstubAllGlobals();
});

describe("logistics desk presentation", () => {
  it("counts a shift as current inside the fifteen-minute window on either side", () => {
    const shift = desk().searchableShifts[0]!;

    expect(isShiftCurrentlyGoing(shift, "2027-07-04T18:00:00.000Z")).toBe(true);
    expect(isShiftCurrentlyGoing(shift, "2027-07-04T15:50:00.000Z")).toBe(true);
    expect(isShiftCurrentlyGoing(shift, "2027-07-04T22:10:00.000Z")).toBe(true);
    expect(isShiftCurrentlyGoing(shift, "2027-07-04T15:40:00.000Z")).toBe(false);
    expect(isShiftCurrentlyGoing(shift, "2027-07-04T22:20:00.000Z")).toBe(false);
  });

  it("lists only the shifts running around the read's own clock", () => {
    expect(currentLogisticsShifts(desk()).map((shift) => shift.title)).toEqual([
      "Ranger Dirt Day Shift",
    ]);
  });

  /*
   * The four facts an operator decides on before they say a word: is this
   * person here, are they working, and is the desk owed anything back.
   */
  it("reads a staff member's states off the workspace the node already sent", () => {
    const states = logisticsStaffStates(
      desk({
        staffWorkspaces: {
          "staff-1": workspace({
            shiftCards: [card({ attendanceState: "checked_in" })],
            openEquipment: [
              equipment({ checkoutId: "checkout-1", shiftId: "shift-day" }),
              equipment({ checkoutId: "checkout-2", shiftId: null }),
            ],
          }),
        },
      }),
      "staff-1",
    );

    expect(states).toEqual({
      onSite: true,
      onShift: true,
      hasShiftEquipment: true,
      hasEventEquipment: true,
    });
  });

  it("tells shift kit from event kit, because they come back at different times", () => {
    const shiftOnly = logisticsStaffStates(
      desk({
        staffWorkspaces: {
          "staff-1": workspace({
            openEquipment: [equipment({ shiftId: "shift-day" })],
          }),
        },
      }),
      "staff-1",
    );

    expect(shiftOnly.hasShiftEquipment).toBe(true);
    expect(shiftOnly.hasEventEquipment).toBe(false);
  });

  it("renders only the states that are true, highest priority first", () => {
    expect(
      logisticsStatePills({
        onSite: true,
        onShift: false,
        hasShiftEquipment: false,
        hasEventEquipment: true,
      }).map((pill) => pill.label),
    ).toEqual(["On-site", "Event kit"]);

    // A wall of grey pills saying "not this, not that" teaches a reader to stop
    // looking at them.
    expect(
      logisticsStatePills({
        onSite: false,
        onShift: false,
        hasShiftEquipment: false,
        hasEventEquipment: false,
      }),
    ).toEqual([]);
  });

  it("keeps presence and shift when a tight row only has space for two", () => {
    // The priority order is what makes a cap safe: whichever two survive are the
    // two the operator is deciding on, not whichever two happened to be true.
    expect(
      logisticsStatePills(
        {
          onSite: true,
          onShift: true,
          hasShiftEquipment: true,
          hasEventEquipment: true,
        },
        2,
      ).map((pill) => pill.label),
    ).toEqual(["On-site", "On-shift"]);
  });

  it("carries a staff hit's states into the search dropdown", () => {
    const hits = searchLogisticsDesk(
      desk({
        staffWorkspaces: {
          "staff-1": workspace({
            shiftCards: [card({ attendanceState: "checked_in" })],
            openEquipment: [equipment({ shiftId: null })],
          }),
        },
      }),
      "vera",
    );

    const staffHit = hits.find((hit) => hit.kind === "staff")!;

    // Two on the row, and the team stays in the detail line — printing presence
    // twice on one row is what makes a dense list unreadable.
    expect(staffHit.pills?.map((pill) => pill.label)).toEqual([
      "On-site",
      "On-shift",
    ]);
    expect(staffHit.detail).toBe("Dirt");
  });

  it("gives equipment and shift hits no pills to render", () => {
    const hits = searchLogisticsDesk(desk(), "rdo-12");

    expect(hits[0]!.pills).toBeUndefined();
  });

  it("searches staff, equipment, and shifts in the node's index", () => {
    const hits = searchLogisticsDesk(desk(), "rdo-12");

    expect(hits).toHaveLength(1);
    expect(hits[0]).toMatchObject({
      kind: "equipment",
      label: "Radio 12 (RDO-12)",
      detail: "Checked out to Vera Staff",
    });

    expect(searchLogisticsDesk(desk(), "swing").map((hit) => hit.kind)).toEqual([
      "shift",
    ]);
    expect(searchLogisticsDesk(desk(), "")).toEqual([]);
  });

  it("holds a shift open for whoever is checked in and not yet out", () => {
    const onShift = logisticsStaffOnShift(
      desk({
        staffWorkspaces: {
          "staff-1": workspace({
            shiftCards: [
              {
                shiftId: "shift-day",
                title: "Ranger Dirt Day Shift",
                teamId: "team-dirt",
                teamLabel: "Dirt",
                startsAt: "2027-07-04T16:00:00.000Z",
                endsAt: "2027-07-04T22:00:00.000Z",
                lifecycle: "active",
                attendanceState: "checked_in",
                assignmentId: "assignment-1",
                canCheckIn: false,
                canCheckOut: true,
                canMarkNoShow: false,
                canAddToShift: false,
                addToShiftBlockedReason: null,
              },
            ],
          }),
          "staff-2": workspace({
            staffId: "staff-2",
            displayName: "Sam Scheduled",
            shiftCards: [
              {
                shiftId: "shift-day",
                title: "Ranger Dirt Day Shift",
                teamId: "team-dirt",
                teamLabel: "Dirt",
                startsAt: "2027-07-04T16:00:00.000Z",
                endsAt: "2027-07-04T22:00:00.000Z",
                lifecycle: "active",
                attendanceState: "scheduled",
                assignmentId: "assignment-2",
                canCheckIn: true,
                canCheckOut: false,
                canMarkNoShow: true,
                canAddToShift: false,
                addToShiftBlockedReason: null,
              },
            ],
          }),
        },
      }),
    );

    expect(onShift.map((member) => member.displayName)).toEqual(["Vera Staff"]);
    expect(onShift[0]!.canCheckOut).toBe(true);
  });

  it("groups a workspace's cards into active, upcoming, and outgoing", () => {
    const sections = logisticsShiftSections(
      workspace({
        shiftCards: [
          {
            shiftId: "a",
            title: "Active",
            teamId: "t",
            teamLabel: "Dirt",
            startsAt: "",
            endsAt: "",
            lifecycle: "active",
            attendanceState: "checked_in",
            assignmentId: "1",
            canCheckIn: false,
            canCheckOut: true,
            canMarkNoShow: false,
            canAddToShift: false,
            addToShiftBlockedReason: null,
          },
          {
            shiftId: "b",
            title: "Upcoming",
            teamId: "t",
            teamLabel: "Dirt",
            startsAt: "",
            endsAt: "",
            lifecycle: "upcoming",
            attendanceState: "scheduled",
            assignmentId: "2",
            canCheckIn: false,
            canCheckOut: false,
            canMarkNoShow: false,
            canAddToShift: false,
            addToShiftBlockedReason: null,
          },
          {
            shiftId: "c",
            title: "Outgoing",
            teamId: "t",
            teamLabel: "Dirt",
            startsAt: "",
            endsAt: "",
            lifecycle: "completed",
            attendanceState: "checked_out",
            assignmentId: "3",
            canCheckIn: false,
            canCheckOut: false,
            canMarkNoShow: false,
            canAddToShift: false,
            addToShiftBlockedReason: null,
          },
        ],
      }),
    );

    expect(sections.active.map((card) => card.title)).toEqual(["Active"]);
    expect(sections.upcoming.map((card) => card.title)).toEqual(["Upcoming"]);
    expect(sections.outgoing.map((card) => card.title)).toEqual(["Outgoing"]);
  });

  it("names an unassigned deployment rather than an id", () => {
    const deployments = [
      {
        deploymentId: "deployment-gate-1",
        name: "Gate 1",
        description: null,
        locationDetails: null,
      },
    ];

    expect(deploymentLabel(deployments, "deployment-gate-1")).toBe("Gate 1");
    expect(deploymentLabel(deployments, null)).toBe("Unassigned");
    expect(deploymentLabel(deployments, "deployment-gone")).toBe("Unassigned");
  });
});

describe("planning table presentation", () => {
  it("summarizes the rows the node answered with", () => {
    expect(
      planningSummary([
        row(),
        row({
          shiftId: "shift-overnight",
          lifecycle: "completed",
          capacity: null,
          plannedHours: 12,
          actualHours: 13.5,
        }),
      ]),
    ).toEqual({
      shiftCount: 2,
      underTargetCount: 1,
      activeCount: 1,
      completedCount: 1,
      actualHours: 17.7,
      plannedHours: 36,
    });
  });

  it("refuses a row carrying an identity (SLB-019)", () => {
    expect(() => assertNoStaffIdentities([row()])).not.toThrow();
    expect(() =>
      assertNoStaffIdentities([
        { ...row(), staffId: "staff-1" } as unknown as PlanningRow,
      ]),
    ).toThrow(/identity field staffId/);
  });

  it("asks the node for the narrowed view rather than filtering rows here", async () => {
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });

    const fetchMock = vi.fn(
      async (input: RequestInfo | URL): Promise<Response> =>
        new Response(
          JSON.stringify({
            context: {
              event_id: CONTEXT.eventId,
              event_label: CONTEXT.eventLabel,
              department_id: CONTEXT.departmentId,
              department_label: CONTEXT.departmentLabel,
              time_zone: CONTEXT.timeZone,
              as_of: CONTEXT.asOf,
            },
            access: {},
            teams: [],
            filters: { team_id: "team-command", date: "2027-07-04" },
            rows: [],
            requested_url: String(input),
          }),
          { status: 200, headers: { "content-type": "application/json" } },
        ),
    );

    vi.stubGlobal("fetch", fetchMock);

    const table = await getPlanningTable(CONTEXT.eventId, CONTEXT.departmentId, {
      teamId: "team-command",
      date: "2027-07-04",
    });

    const requested = String(fetchMock.mock.calls[0]![0]);
    expect(requested).toContain("team_id=team-command");
    expect(requested).toContain("date=2027-07-04");
    expect(table.filters).toEqual({ teamId: "team-command", date: "2027-07-04" });
  });
});

describe("department operations commands", () => {
  beforeEach(() => {
    installLocalFieldSession();
  });

  it("queues a check-in rather than sending it, keyed by its own operation uuid", () => {
    queueCheckIn({
      context: CONTEXT,
      shiftId: "shift-day",
      staffId: "staff-1",
      occurredAt: "2027-07-04T17:45:00.000Z",
    });

    const queued = commandOutbox.all();
    expect(queued).toHaveLength(1);
    expect(queued[0]!.commandType).toBe("check-in-staff");
    expect(queued[0]!.payload).toMatchObject({
      operation_uuid: queued[0]!.idempotencyKey,
      shift_id: "shift-day",
      staff_id: "staff-1",
      device_created_at: "2027-07-04T17:45:00.000Z",
    });
    // Nothing publishes a node id to a browser, so the command names none and
    // the node that receives it records itself (M16.21).
    expect(queued[0]!.payload.origin_node_id).toBeUndefined();
  });

  it("refuses presence where it cannot be sent rather than queueing it", async () => {
    configureMeridianApi({
      baseUrl: "http://node.test",
      bearerToken: "device-token",
    });

    // Offline: a connected-only command is refused at issue, in the words the
    // catalog states (CLIENT-018).
    setNavigatorOnline(false);

    try {
      await expect(
        setDepartmentPresence(CONTEXT, "staff-1", "on_site"),
      ).rejects.toThrow(/needs a connection to the node/);

      expect(commandOutbox.all()).toHaveLength(0);
    } finally {
      setNavigatorOnline(true);
    }
  });
});
