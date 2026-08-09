// The Logistics Desk composed on the device (M18.53; SLB-003, SLB-017,
// SLB-018; technical spec 9.3, 9.4, 20.2, 20.5).
//
// What is under test is that a desk with no node offers exactly the controls a
// desk with one offers. The verdicts on a card — check in, check out, mark
// no-show — are the node's answers, and the only thing that makes deriving them
// here legitimate is that the derivation is the node's own, from rows this
// device is holding. So each case fixes the four facts the rule reads and asserts
// the verdict that follows.
//
// The other half is what the device refuses to answer. Adding somebody to a
// shift, correcting hours, and handing equipment over are connected-only, and a
// stored desk that offered any of them would be a desk promising work the node
// will not accept.
//
// No server runs for any of it (CLIENT-024).

import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { configureMeridianApi } from "@/api/meridianApi";
import {
  addStaffToShift,
  getLogisticsDesk,
  setDepartmentPresence,
} from "@/department-ops/departmentOpsReadModel";
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import {
  installOfflineReadSet,
  offlineReadSetPayload,
} from "@/offline/offlineReadSetFixture";
import { clearOfflineReadSet } from "@/offline/offlineReadSetRuntime";
import { clearClientSession } from "@/session/clientSession";
import {
  installLocalFieldSession,
  LOCAL_FIELD_DEPARTMENT_IDS,
  LOCAL_FIELD_FIXTURE,
  LOCAL_FIELD_ORGANIZATION_ID,
} from "@/session/localFieldSessionFixture";

const EVENT_ID = LOCAL_FIELD_FIXTURE.eventId;
const DEPARTMENT_ID = LOCAL_FIELD_DEPARTMENT_IDS.rangers;
const STAFF_ID = "aaaa0001-0000-4000-8000-000000000001";
const SHIFT_ID = "bbbb0001-0000-4000-8000-000000000001";
const ASSIGNMENT_ID = "cccc0001-0000-4000-8000-000000000001";

/** Mid-shift: the window opened an hour ago and closes in three. */
const NOW = "2027-07-04T19:00:00.000Z";

function unreachableNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async () => {
      throw new TypeError("Failed to fetch");
    }),
  );
}

/**
 * @param eligibleTeamIds `null` composes the row the way a set from before
 *   M18.54 carries it: with no eligible teams on it at all.
 */
function staffRow(eligibleTeamIds: readonly string[] | null = ["team-1"]) {
  return {
    id: `${EVENT_ID}:${DEPARTMENT_ID}:${STAFF_ID}`,
    event_id: EVENT_ID,
    department_id: DEPARTMENT_ID,
    staff_id: STAFF_ID,
    legal_name: "Robin Field",
    preferred_name: "Robin",
    handle: "robin",
    team_label: "Dirt",
    // `TeamMembership::onEligibleShiftTeam`, as the node resolved it when the
    // set was composed (M18.54). The shift below is this team's.
    ...(eligibleTeamIds === null
      ? {}
      : { eligible_team_ids: eligibleTeamIds }),
    archived_at: null,
  };
}

function shiftRow() {
  return {
    id: SHIFT_ID,
    event_id: EVENT_ID,
    department_id: DEPARTMENT_ID,
    eligible_team_id: "team-1",
    title: "Gate A — Day",
    team_name_snapshot: "Dirt",
    starts_at: "2027-07-04T18:00:00+00:00",
    ends_at: "2027-07-04T22:00:00+00:00",
    capacity: 4,
    cancelled_at: null,
  };
}

interface DeskOptions {
  readonly presence?: string;
  readonly assigned?: boolean;
  readonly attendanceState?: string | null;
  readonly checkouts?: readonly Record<string, unknown>[];
  /**
   * The teams whose shifts this person may be added to (M18.54; SLB-008).
   * `null` leaves the field off the row, as a pre-M18.54 set does.
   */
  readonly eligibleTeamIds?: readonly string[] | null;
}

async function installDesk(options: DeskOptions = {}): Promise<void> {
  await installOfflineReadSet(
    offlineReadSetPayload({
      sections: {
        events: [{ id: EVENT_ID, name: "Local Field Event" }],
        departments: [{ id: DEPARTMENT_ID, name: "Rangers" }],
        logistics_staff_index: [staffRow(options.eligibleTeamIds)],
        logistics_presence: [
          {
            id: "presence-1",
            event_id: EVENT_ID,
            department_id: DEPARTMENT_ID,
            staff_id: STAFF_ID,
            current_state: options.presence ?? "on_site",
            marked_on_site_at: "2027-07-04T17:00:00+00:00",
            marked_off_site_at: null,
          },
        ],
        logistics_shift_index: [shiftRow()],
        logistics_shift_assignments:
          options.assigned === false
            ? []
            : [
                {
                  id: ASSIGNMENT_ID,
                  shift_id: SHIFT_ID,
                  staff_id: STAFF_ID,
                  assignment_status: "confirmed",
                },
              ],
        logistics_attendance:
          options.attendanceState === undefined ||
          options.attendanceState === null
            ? []
            : [
                {
                  id: "attendance-1",
                  event_id: EVENT_ID,
                  department_id: DEPARTMENT_ID,
                  shift_id: SHIFT_ID,
                  staff_id: STAFF_ID,
                  current_state: options.attendanceState,
                  checked_in_at: null,
                  checked_out_at: null,
                  no_show_at: null,
                },
              ],
        logistics_equipment_index: [
          {
            id: "equipment-1",
            organization_id: LOCAL_FIELD_ORGANIZATION_ID,
            event_id: EVENT_ID,
            department_id: DEPARTMENT_ID,
            name: "Handheld radio 1",
            tracking: "individual",
            asset_tag: "MRD-0001",
            serial_number: "SN000001",
            quantity_total: 1,
            status: "available",
          },
        ],
        logistics_equipment_checkouts: options.checkouts ?? [],
        logistics_future_signups: [],
      },
      readiness: {
        context_event_id: EVENT_ID,
        usable_until: "2099-01-01T00:00:00+00:00",
      },
    }),
    { organizationId: LOCAL_FIELD_ORGANIZATION_ID, eventId: EVENT_ID },
  );
}

/** The one card on the one staff workspace. */
async function readCard() {
  const desk = await getLogisticsDesk(EVENT_ID, DEPARTMENT_ID);

  return {
    desk,
    workspace: desk.staffWorkspaces[STAFF_ID]!,
    card: desk.staffWorkspaces[STAFF_ID]!.shiftCards[0]!,
  };
}

beforeEach(() => {
  vi.useFakeTimers();
  vi.setSystemTime(new Date(NOW));
  clearOfflineReadSet();
  installLocalFieldSession();
  configureMeridianApi({
    baseUrl: "http://node.test",
    bearerToken: "device-token",
  });
});

afterEach(() => {
  vi.useRealTimers();
  vi.unstubAllGlobals();
  configureMeridianApi(null);
  clearClientSession();
  clearOfflineReadSet();
});

describe("the Logistics Desk with no node in reach", () => {
  it("renders the desk from the read set and discloses the stored copy", async () => {
    await installDesk();
    unreachableNode();

    const { desk } = await readCard();

    expect(desk.freshness.source).toBe("cache");
    expect(desk.context.departmentLabel).toBe("Rangers");
    expect(desk.searchableStaff).toHaveLength(1);
    expect(desk.searchableStaff[0]?.displayName).toBe("Robin");
    expect(desk.searchableShifts[0]?.title).toBe("Gate A — Day");
  });

  /*
   * Technical spec 20.2: Logistics checks somebody in once they are on-site for
   * the department. Four facts decide it and all four are in the set.
   */
  it("offers check-in for an assigned staff member who is on site", async () => {
    await installDesk({ presence: "on_site", attendanceState: null });
    unreachableNode();

    const { card } = await readCard();

    expect(card.canCheckIn).toBe(true);
    expect(card.canCheckOut).toBe(false);
    expect(card.assignmentId).toBe(ASSIGNMENT_ID);
    expect(card.attendanceState).toBe("scheduled");
  });

  it("withholds check-in from somebody who is not on site", async () => {
    await installDesk({ presence: "off_site", attendanceState: null });
    unreachableNode();

    const { card } = await readCard();

    expect(card.canCheckIn).toBe(false);
  });

  it("offers check-out once checked in, and not check-in again", async () => {
    await installDesk({ attendanceState: "checked_in" });
    unreachableNode();

    const { card } = await readCard();

    expect(card.canCheckIn).toBe(false);
    expect(card.canCheckOut).toBe(true);
  });

  /*
   * Technical spec 20.5: no-show applies only after a shift has started and
   * only to somebody who has not arrived. The shift here started an hour ago.
   */
  it("offers mark no-show only for a started shift nobody arrived for", async () => {
    await installDesk({ attendanceState: null });
    unreachableNode();

    const { card } = await readCard();

    expect(card.canMarkNoShow).toBe(true);
  });

  it("withholds every verdict from a shift this person is not assigned to", async () => {
    await installDesk({ assigned: false });
    unreachableNode();

    const { card } = await readCard();

    expect(card.attendanceState).toBeNull();
    expect(card.canCheckIn).toBe(false);
    expect(card.canCheckOut).toBe(false);
    expect(card.canMarkNoShow).toBe(false);
  });

  /*
   * SLB-017: somebody checked in to a department shift is held on site until
   * they are checked out, in the words the node's refusal uses.
   */
  it("refuses an off-site mark for somebody checked in, in the node's words", async () => {
    await installDesk({ attendanceState: "checked_in" });
    unreachableNode();

    const { workspace } = await readCard();

    expect(workspace.canGoOffSite).toBe(false);
    expect(workspace.offSiteBlockedReason).toBe(
      "Staff must be checked out from department shifts before being marked off-site.",
    );
  });

  /*
   * SLB-018: outstanding equipment holds somebody on site — but only equipment
   * that is actually still out. An item written off as missing is still owed and
   * is no longer a reason to refuse the mark.
   */
  it("refuses an off-site mark for somebody still holding equipment", async () => {
    await installDesk({
      attendanceState: null,
      checkouts: [
        {
          id: "checkout-1",
          equipment_item_id: "equipment-1",
          event_id: EVENT_ID,
          staff_id: STAFF_ID,
          shift_id: null,
          quantity: 1,
          quantity_returned: 0,
          checked_out_at: "2027-07-04T18:10:00+00:00",
          item_name: "Handheld radio 1",
          item_tracking: "individual",
          item_asset_tag: "MRD-0001",
          item_status: "checked_out",
        },
      ],
    });
    unreachableNode();

    const { workspace } = await readCard();

    expect(workspace.canGoOffSite).toBe(false);
    expect(workspace.offSiteBlockedReason).toBe(
      "Staff must return or resolve checked-out department equipment before being marked off-site.",
    );
    expect(workspace.openEquipment).toHaveLength(1);
  });

  it("lets somebody off site when nothing holds them", async () => {
    await installDesk({ attendanceState: null });
    unreachableNode();

    const { workspace } = await readCard();

    expect(workspace.canGoOffSite).toBe(true);
    expect(workspace.offSiteBlockedReason).toBeNull();
  });

  /*
   * The two the device still refuses to answer rather than deriving: the node's
   * correction grace period, and what is available to hand over. The addition
   * left this list in M18.54 and has its own cases below.
   */
  it("refuses the connected-only controls rather than guessing at them", async () => {
    await installDesk({ assigned: false });
    unreachableNode();

    const { desk, card } = await readCard();

    expect(card.canCorrectHours).toBe(false);
    expect(card.hoursWorkedId).toBeNull();
    expect(desk.checkoutInventory).toEqual([]);
  });

  /*
   * The unscheduled addition, offline (M18.54; SLB-008). The offer is the node's
   * own four-part rule — unassigned, on-site, the shift started, an eligible
   * team membership — and the device now holds every one of those facts.
   */
  it("offers the addition on the node's own rule", async () => {
    await installDesk({ assigned: false, presence: "on_site" });
    unreachableNode();

    const { card } = await readCard();

    expect(card.canAddToShift).toBe(true);
    expect(card.addToShiftBlockedReason).toBeNull();
  });

  it("refuses the addition for a shift belonging to another team, in the node's words", async () => {
    await installDesk({
      assigned: false,
      presence: "on_site",
      eligibleTeamIds: ["team-other"],
    });
    unreachableNode();

    const { card } = await readCard();

    expect(card.canAddToShift).toBe(false);
    expect(card.addToShiftBlockedReason).toBe(
      "This shift is for the Dirt team, and they are not a member of it.",
    );
  });

  /*
   * A set composed before M18.54 carries no eligible teams. The device reads
   * that as "no eligible team" rather than as permission it was never given: the
   * refusal is fixed by refreshing the set, where a wrongly offered addition is
   * a refusal at the node with somebody standing at the desk.
   */
  it("withholds the addition from a set that predates the eligible-team rows", async () => {
    await installDesk({
      assigned: false,
      presence: "on_site",
      eligibleTeamIds: null,
    });
    unreachableNode();

    const { card } = await readCard();

    expect(card.canAddToShift).toBe(false);
  });

  /*
   * The card for an unassigned shift is dropped for somebody who is not on site,
   * which is what `DepartmentOperationsReadController::shiftCards` does — a
   * button offering an addition the node refuses is worse than no card, and the
   * two desks now show the same thing.
   */
  it("drops an unassigned card for somebody who is not on site", async () => {
    await installDesk({ assigned: false, presence: "off_site" });
    unreachableNode();

    const desk = await getLogisticsDesk(EVENT_ID, DEPARTMENT_ID);

    expect(desk.staffWorkspaces[STAFF_ID]!.shiftCards).toEqual([]);
  });

  /*
   * Authority is never derived. It is read from the session document, which is
   * the node's own answer — a device assembling `can_manage_attendance` for
   * itself would be granting itself a capability.
   */
  it("reads what this caller may do from the session rather than the rows", async () => {
    await installDesk();
    unreachableNode();

    const { desk } = await readCard();

    // The fixture's Rangers roles carry department administration and the
    // Logistics capabilities beneath it.
    expect(desk.access.canManageAttendance).toBe(true);
    expect(desk.access.isDepartmentLead).toBe(true);
  });

  it("states the unreachable node when this device holds no desk", async () => {
    unreachableNode();

    await expect(getLogisticsDesk(EVENT_ID, DEPARTMENT_ID)).rejects.toThrow(
      /Failed to fetch/,
    );
  });
});

/*
 * The on-site pairing M18.54 had to resolve (SLB-008, SLB-015).
 *
 * `UnscheduledShiftAdditionService` refuses an addition for anybody not already
 * marked on-site. A desk that queued the addition but not the mark would offer
 * work that rejects every time, so both are offline writes — and the desk has to
 * compose from the queue as well as from the stored rows, or an operator would
 * mark somebody on-site, watch the pill stay off-site, and be told the addition
 * cannot be offered because they are not here.
 */
describe("the desk composed from the queue as well as the rows", () => {
  afterEach(() => {
    resetCommandOutbox();
  });

  it("counts a queued on-site mark as presence, which is what unlocks the addition", async () => {
    await installDesk({ assigned: false, presence: "off_site" });
    unreachableNode();

    // Off-site in the stored rows: no card at all, exactly as the node would
    // have composed it.
    const before = await getLogisticsDesk(EVENT_ID, DEPARTMENT_ID);
    expect(before.staffWorkspaces[STAFF_ID]!.presenceState).toBe("off_site");
    expect(before.staffWorkspaces[STAFF_ID]!.shiftCards).toEqual([]);

    await setDepartmentPresence(
      {
        eventId: EVENT_ID,
        eventLabel: "Local Field Event",
        departmentId: DEPARTMENT_ID,
        departmentLabel: "Rangers",
        timeZone: "UTC",
        asOf: NOW,
      },
      STAFF_ID,
      "on_site",
    );

    const after = await getLogisticsDesk(EVENT_ID, DEPARTMENT_ID);
    const workspace = after.staffWorkspaces[STAFF_ID]!;

    expect(workspace.presenceState).toBe("on_site");
    expect(workspace.shiftCards[0]!.canAddToShift).toBe(true);
  });

  /*
   * And the addition itself, once queued, is an assignment as far as this screen
   * is concerned: the card stops offering to make it again and starts offering
   * the check-in it was made for.
   */
  it("counts a queued addition as an assignment", async () => {
    await installDesk({ assigned: false, presence: "on_site" });
    unreachableNode();

    await addStaffToShift(
      {
        eventId: EVENT_ID,
        eventLabel: "Local Field Event",
        departmentId: DEPARTMENT_ID,
        departmentLabel: "Rangers",
        timeZone: "UTC",
        asOf: NOW,
      },
      STAFF_ID,
      SHIFT_ID,
    );

    const desk = await getLogisticsDesk(EVENT_ID, DEPARTMENT_ID);
    const card = desk.staffWorkspaces[STAFF_ID]!.shiftCards[0]!;

    expect(card.canAddToShift).toBe(false);
    expect(card.attendanceState).toBe("scheduled");
    expect(card.canCheckIn).toBe(true);
  });

  /*
   * A refused addition is not a record (CLIENT-017). The outbox holds it in
   * front of the person who issued it, and the desk goes back to offering the
   * addition rather than showing an assignment the node does not have.
   */
  it("does not count a refused addition as an assignment", async () => {
    await installDesk({ assigned: false, presence: "on_site" });
    unreachableNode();

    await addStaffToShift(
      {
        eventId: EVENT_ID,
        eventLabel: "Local Field Event",
        departmentId: DEPARTMENT_ID,
        departmentLabel: "Rangers",
        timeZone: "UTC",
        asOf: NOW,
      },
      STAFF_ID,
      SHIFT_ID,
    );

    const queued = commandOutbox.all().at(-1)!;
    commandOutbox.markRejected(
      queued.idempotencyKey,
      NOW,
      "Required training must be complete before unscheduled shift addition.",
    );

    const desk = await getLogisticsDesk(EVENT_ID, DEPARTMENT_ID);
    const card = desk.staffWorkspaces[STAFF_ID]!.shiftCards[0]!;

    expect(card.assignmentId).toBeNull();
    expect(card.canAddToShift).toBe(true);
  });
});
