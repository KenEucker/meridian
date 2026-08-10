import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { flushPromises, mount } from "@vue/test-utils";
import { createRouter, createWebHistory } from "vue-router";

import { configureMeridianApi } from "@/api/meridianApi";
import { resetFieldReportRuntime } from "@/field-reports/fieldReportRuntime";
import {
  commandOutbox,
  resetCommandOutbox,
} from "@/outbox/commandOutboxRuntime";
import { routes } from "@/router";
import { clearClientSession, installClientSession } from "@/session/clientSession";
import {
  resetKioskContext,
  resolveKioskContext,
} from "@/session/kioskContext";
import { localFieldSessionDocument } from "@/session/localFieldSessionFixture";
import { configureSharedWorkstationId } from "@/session/workstationIdentity";
import {
  enterWorkstationLoginCode,
  resetWorkstationSession,
  workstationSessionState,
} from "@/session/workstationSession";
import KioskReauthView from "@/views/KioskReauthView.vue";
import KioskSetupView from "@/views/KioskSetupView.vue";
import KioskShiftBoardView from "@/views/KioskShiftBoardView.vue";
import KioskSwitchUserView from "@/views/KioskSwitchUserView.vue";

/*
 * The Kiosk surfaces M18.32 builds (UI implementation contract 12.8, 18;
 * UI-017, UI-019 through UI-023).
 *
 * Three screens and one rule. The screens are switching users, confirming the
 * active user before a privileged action, and the desk's own shift board. The
 * rule is UI-019 and UI-020: a Kiosk with no pinned organization and event
 * enters setup, and does not work its context out from anything it is holding.
 */

const SESSION_KEY = "kQ7mVt2ZrBdN4xLpWyH3sCfJ8gEaU6nToXvI1bYh";
const WORKSTATION_ID = "workstation-gate-a";
const EVENT_ID = "event-1";
const DEPARTMENT_ID = "department-logistics";
const STAFF_ID = "staff-77";
const SHIFT_ID = "shift-42";

const node = {
  pinned: true,
  department: null as { id: string; name: string } | null,
  canManageAttendance: true,
  reauthStatus: 200,
  /** False makes the pinned-context read a 404: an id this node does not hold. */
  knownWorkstation: true,
};

function json(body: unknown, status = 200): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "content-type": "application/json" },
  });
}

function pinnedContextPayload() {
  return {
    shared_workstation: { id: WORKSTATION_ID, name: "Gate A Workstation" },
    pinned: node.pinned,
    organization: node.pinned
      ? { id: "org-1", name: "Northwood Collective" }
      : null,
    event: node.pinned
      ? {
          id: EVENT_ID,
          name: "Emberfall 2027",
          timezone: "UTC",
          active_event_window_starts_at: null,
          active_event_window_ends_at: null,
        }
      : null,
    department: node.department,
    context_pinned_at: node.pinned ? "2027-05-01T00:00:00+00:00" : null,
    options: [
      {
        id: "event-2",
        name: "Winterlight 2027",
        timezone: "UTC",
        active_event_window_starts_at: null,
        active_event_window_ends_at: null,
        departments: [{ id: DEPARTMENT_ID, name: "Logistics" }],
      },
    ],
  };
}

function sessionPayload(method: string) {
  return {
    ...(method === "POST" ? { session_key: SESSION_KEY } : {}),
    session: {
      id: "session-1",
      started_at: "2027-06-01T12:00:00+00:00",
      last_activity_at: "2027-06-01T12:00:00+00:00",
      expires_at: "2027-06-01T12:05:00+00:00",
      inactivity_timeout_seconds: 300,
      reauthenticated_at: null,
    },
    user: { id: "user-1", name: "Dana Reyes" },
    shared_workstation: {
      id: WORKSTATION_ID,
      name: "Gate A Workstation",
      organization_id: "org-1",
      department_id: null,
    },
    event_id: EVENT_ID,
  };
}

/** One shift with one person on it, in the shape the desk read answers. */
function logisticsDeskPayload() {
  return {
    context: {
      event_id: EVENT_ID,
      event_label: "Emberfall 2027",
      department_id: DEPARTMENT_ID,
      department_label: "Logistics",
      time_zone: "UTC",
      as_of: "2027-06-01T12:00:00+00:00",
    },
    access: {
      is_department_lead: false,
      can_manage_presence: false,
      can_manage_attendance: node.canManageAttendance,
      can_manage_equipment: false,
      can_assign_deployments: false,
      can_manage_planning: false,
      can_administer_department: false,
    },
    searchable_staff: [],
    searchable_equipment: [],
    searchable_shifts: [
      {
        shift_id: SHIFT_ID,
        title: "Gate A — Day",
        team_id: "team-1",
        team_label: "Gate Crew",
        starts_at: "2027-06-01T08:00:00+00:00",
        ends_at: "2027-06-01T20:00:00+00:00",
        lifecycle: "in_progress",
        capacity: 4,
      },
    ],
    checkout_inventory: [],
    staff_workspaces: {
      [STAFF_ID]: {
        staff_id: STAFF_ID,
        display_name: "Robin Field",
        handle: "robin",
        profile_picture_url: null,
        team_label: "Gate Crew",
        presence_state: "on_site",
        can_go_off_site: true,
        off_site_blocked_reason: null,
        shift_cards: [
          {
            shift_id: SHIFT_ID,
            title: "Gate A — Day",
            team_id: "team-1",
            team_label: "Gate Crew",
            starts_at: "2027-06-01T08:00:00+00:00",
            ends_at: "2027-06-01T20:00:00+00:00",
            lifecycle: "in_progress",
            attendance_state: "scheduled",
            assignment_id: "assignment-1",
            can_check_in: true,
            can_check_out: false,
            can_mark_no_show: true,
            can_add_to_shift: false,
            add_to_shift_blocked_reason: null,
            hours_worked_id: null,
            actual_started_at: null,
            actual_ended_at: null,
            minutes_worked: null,
            can_correct_hours: false,
            correct_hours_blocked_reason: null,
          },
        ],
        open_equipment: [],
        future_signups: [],
      },
    },
  };
}

function stubNode(): void {
  vi.stubGlobal(
    "fetch",
    vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
      const path = new URL(String(input)).pathname;
      const method = (init?.method ?? "GET").toUpperCase();

      if (path === "/api/me") {
        return json(localFieldSessionDocument());
      }

      if (path.endsWith("/reauthentication")) {
        return node.reauthStatus === 200
          ? json(sessionPayload("GET"))
          : json(
              {
                message:
                  "That code belongs to a different user. To hand this workstation over, end the current session and sign in again.",
                reason: "reauthentication_user_mismatch",
              },
              node.reauthStatus,
            );
      }

      if (path.startsWith("/api/kiosk/workstations/")) {
        return node.knownWorkstation
          ? json(pinnedContextPayload())
          : json({ message: "Not found." }, 404);
      }

      if (path.endsWith("/logistics")) {
        return json(logisticsDeskPayload());
      }

      if (path === "/api/auth/shared-workstation-session") {
        return method === "DELETE"
          ? json({ ended: true })
          : json(sessionPayload(method), method === "POST" ? 201 : 200);
      }

      return json({ message: "Unstubbed" }, 404);
    }),
  );
}

function buildRouter() {
  return createRouter({ history: createWebHistory(), routes });
}

async function signIn(): Promise<void> {
  await enterWorkstationLoginCode({
    sharedWorkstationId: WORKSTATION_ID,
    code: "K3M7PQRS",
  });
}

beforeEach(async () => {
  node.pinned = true;
  node.department = { id: DEPARTMENT_ID, name: "Logistics" };
  node.canManageAttendance = true;
  node.reauthStatus = 200;
  node.knownWorkstation = true;

  window.localStorage.clear();
  resetWorkstationSession();
  resetKioskContext();
  clearClientSession();
  configureSharedWorkstationId(WORKSTATION_ID);
  stubNode();
  configureMeridianApi({ baseUrl: "http://node.test", bearerToken: null });
  await resolveKioskContext();
});

afterEach(async () => {
  configureSharedWorkstationId(null);
  resetWorkstationSession();
  resetKioskContext();
  clearClientSession();
  await resetFieldReportRuntime();
  resetCommandOutbox();
  configureMeridianApi(null);
  window.localStorage.clear();
  vi.unstubAllGlobals();
});

describe("kiosk pinned context", () => {
  /*
   * UI-020, stated as plainly as the requirement does: "When Kiosk mode starts
   * without a pinned context, it shall enter setup rather than inferring context
   * from the current user, event data, viewport, local network, or last route."
   */
  it("sends an unpinned Kiosk to setup rather than to any operating surface", async () => {
    node.pinned = false;
    node.department = null;
    resetKioskContext();
    await resolveKioskContext();

    const router = buildRouter();

    for (const name of [
      "kiosk.home",
      "kiosk.workstation-login",
      "kiosk.switch-user",
      "kiosk.reauth",
      "kiosk.shift-board",
    ]) {
      await router.push({ name });
      await router.isReady();

      expect(router.currentRoute.value.name).toBe("kiosk.setup");
    }
  });

  it("does not infer a context from a session that already names an event", async () => {
    // The session document carries an event and the device holds a department
    // selection. Neither is the workstation's pinned context, and treating
    // either as one is precisely the inference UI-020 rules out.
    node.pinned = false;
    node.department = null;
    resetKioskContext();
    await resolveKioskContext();

    installClientSession(localFieldSessionDocument());
    window.localStorage.setItem("meridian.session.departmentId", DEPARTMENT_ID);

    const router = buildRouter();
    await router.push({ name: "kiosk.home" });
    await router.isReady();

    expect(router.currentRoute.value.name).toBe("kiosk.setup");
  });

  it("keeps the safe-timeout surface reachable with no pinned context at all", async () => {
    // It holds no name, event, or record, which is what makes it safe — and a
    // machine that just timed out has to be able to land somewhere.
    node.pinned = false;
    resetKioskContext();
    await resolveKioskContext();

    const router = buildRouter();
    await router.push({ name: "kiosk.safe-timeout" });
    await router.isReady();

    expect(router.currentRoute.value.name).toBe("kiosk.safe-timeout");
  });

  it("lets a pinned Kiosk reach its surfaces", async () => {
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.shift-board" });
    await router.isReady();

    expect(router.currentRoute.value.name).toBe("kiosk.shift-board");
  });
});

describe("kiosk.setup", () => {
  it("names the machine and what the node says it is pinned to", async () => {
    const router = buildRouter();
    await router.push({ name: "kiosk.setup" });
    await router.isReady();

    const wrapper = mount(KioskSetupView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.find('[data-testid="kiosk-setup-name"]').text()).toBe(
      "Gate A Workstation",
    );
    expect(wrapper.find('[data-testid="kiosk-setup-event"]').text()).toBe(
      "Emberfall 2027",
    );
  });

  it("says who pins a workstation nobody can sign in to", async () => {
    // No session means no authorization for a change, and an unpinned
    // workstation cannot be signed in to at all: a login code is scoped to a
    // pinned event. So it names God Mode rather than offering a form.
    node.pinned = false;
    node.department = null;
    resetKioskContext();
    await resolveKioskContext();

    const router = buildRouter();
    await router.push({ name: "kiosk.setup" });
    await router.isReady();

    const wrapper = mount(KioskSetupView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.text()).toContain("God Mode operator's on the node");
    expect(wrapper.find("#kiosk-setup-event-select").exists()).toBe(false);
  });

  it("offers the events an organizer may move the workstation to", async () => {
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.setup" });
    await router.isReady();

    const wrapper = mount(KioskSetupView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.find("#kiosk-setup-event-select").text()).toContain(
      "Winterlight 2027",
    );
  });

  it("tells a machine with no identifier to enter one, not that the node refused", async () => {
    // `resolveKioskContext` reports "unknown" both for a 404 and for having no
    // id to ask with, and the two send a person to different fixes: the first
    // is the node's verdict, the second is an empty field on this screen.
    configureSharedWorkstationId(null);
    resetKioskContext();
    await resolveKioskContext();

    const router = buildRouter();
    await router.push({ name: "kiosk.setup" });
    await router.isReady();

    const wrapper = mount(KioskSetupView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.text()).toContain("no workstation identifier yet");
    expect(wrapper.text()).not.toContain(
      "holds no trusted shared workstation with that identifier",
    );
  });

  it("reports the node's refusal of an identifier it does not hold", async () => {
    node.knownWorkstation = false;
    resetKioskContext();
    await resolveKioskContext();

    const router = buildRouter();
    await router.push({ name: "kiosk.setup" });
    await router.isReady();

    const wrapper = mount(KioskSetupView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.text()).toContain(
      "holds no trusted shared workstation with that identifier",
    );
    expect(wrapper.text()).not.toContain("no workstation identifier yet");
  });
});

describe("kiosk.switch-user", () => {
  it("ends the session and lands on code entry", async () => {
    // "Switching users requires ending the current session first — there is no
    // quiet handover" (kiosk guide 4.4).
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.switch-user" });
    await router.isReady();

    const wrapper = mount(KioskSwitchUserView, { global: { plugins: [router] } });
    await wrapper.find(".switch-user__end").trigger("click");
    await flushPromises();

    expect(workstationSessionState.status).toBe("locked");
  });

  it("says what the handover keeps and what it abandons", async () => {
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.switch-user" });
    await router.isReady();

    const wrapper = mount(KioskSwitchUserView, { global: { plugins: [router] } });

    expect(wrapper.text()).toContain("Anything typed and not yet saved is abandoned");
    expect(wrapper.text()).toContain("Nothing is waiting to be sent");
  });

  it("is unreachable with no session, because there is nobody to switch from", async () => {
    const router = buildRouter();
    await router.push({ name: "kiosk.switch-user" });
    await router.isReady();

    expect(router.currentRoute.value.name).toBe("kiosk.workstation-login");
  });
});

describe("kiosk.reauth", () => {
  it("confirms with a fresh code and returns to the surface that asked", async () => {
    await signIn();

    const router = buildRouter();
    await router.push({
      name: "kiosk.reauth",
      query: { return: "kiosk.shift-board" },
    });
    await router.isReady();

    const wrapper = mount(KioskReauthView, { global: { plugins: [router] } });
    await wrapper.find(".kiosk-reauth__code").setValue("K3M7PQRS");
    await wrapper.find(".kiosk-reauth__form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("kiosk.shift-board");
  });

  it("states the refusal, keeps the session, and clears the field", async () => {
    // A code for somebody else is not a handover. The session stays whose it
    // was, and the refusal names the surface that does hand a workstation over.
    node.reauthStatus = 403;
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.reauth" });
    await router.isReady();

    const wrapper = mount(KioskReauthView, { global: { plugins: [router] } });
    await wrapper.find(".kiosk-reauth__code").setValue("SOMEBODY");
    await wrapper.find(".kiosk-reauth__form").trigger("submit");
    await flushPromises();

    expect(wrapper.find(".kiosk-reauth__error").text()).toContain(
      "belongs to a different user",
    );
    expect(
      (wrapper.find(".kiosk-reauth__code").element as HTMLInputElement).value,
    ).toBe("");
    expect(workstationSessionState.status).toBe("active");
    expect(workstationSessionState.user?.name).toBe("Dana Reyes");
    expect(router.currentRoute.value.name).toBe("kiosk.reauth");
  });

  it("returns to the dashboard when the query names a route that does not exist", async () => {
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.reauth", query: { return: "nowhere" } });
    await router.isReady();

    const wrapper = mount(KioskReauthView, { global: { plugins: [router] } });
    await wrapper.find(".kiosk-reauth__code").setValue("K3M7PQRS");
    await wrapper.find(".kiosk-reauth__form").trigger("submit");
    await flushPromises();

    expect(router.currentRoute.value.name).toBe("kiosk.home");
  });
});

describe("kiosk.shift-board", () => {
  it("records a check-in for the shift the desk is holding open", async () => {
    // Check-in is an Alpha 1 offline write (data/API 7.2), so it goes to the
    // outbox rather than the wire, keyed by the device-generated operation UUID.
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.shift-board" });
    await router.isReady();

    const wrapper = mount(KioskShiftBoardView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.text()).toContain("Robin Field");

    await wrapper.find(".kiosk-shift-board__action").trigger("click");
    await flushPromises();

    const queued = commandOutbox.unsent();

    expect(queued).toHaveLength(1);
    expect(queued[0].commandType).toBe("check-in-staff");
    expect(queued[0].eventId).toBe(EVENT_ID);
  });

  it("offers no attendance controls to somebody the node grants none", async () => {
    // Absent rather than disabled (CLIENT-005), with the calm return path UI
    // contract 19.3 asks a Kiosk for.
    node.canManageAttendance = false;
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.shift-board" });
    await router.isReady();

    const wrapper = mount(KioskShiftBoardView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.find(".kiosk-shift-board__action").exists()).toBe(false);
    expect(wrapper.find('[data-testid="kiosk-shift-board-denied"]').text()).toContain(
      "Return to kiosk home, or switch users",
    );
  });

  it("asks which department when the workstation pins none", async () => {
    // The workstation's department is optional (technical spec 13.1). Where
    // there is none, the signed-in user chooses from their own scope — a
    // deliberate choice rather than a guess about the machine (UI-020).
    node.department = null;
    resetKioskContext();
    await resolveKioskContext();
    await signIn();

    const router = buildRouter();
    await router.push({ name: "kiosk.shift-board" });
    await router.isReady();

    const wrapper = mount(KioskShiftBoardView, { global: { plugins: [router] } });
    await flushPromises();

    expect(wrapper.find("#kiosk-shift-board-department").exists()).toBe(true);
    expect(wrapper.text()).toContain("Choose the department you are working");
  });
});
