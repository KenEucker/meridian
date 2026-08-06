<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import StaleReadNotice from "@/components/StaleReadNotice.vue";
import {
  currentLogisticsShifts,
  getLogisticsDesk,
  queueCheckIn,
  queueCheckOut,
  queueMarkNoShow,
  type DepartmentOpsShift,
  type LogisticsDeskRead,
  type LogisticsShiftCard,
  type LogisticsStaffWorkspace,
} from "@/department-ops/departmentOpsReadModel";
import {
  attendanceStateLabel,
  formatTimestamp,
} from "@/department-ops/labels";
import {
  kioskContextState,
  kioskPinnedDepartmentId,
  kioskPinnedEventId,
} from "@/session/kioskContext";
import { sessionDepartmentAccesses } from "@/session/sessionAccess";

/*
 * `kiosk.shift-board` — the shift board at the desk (M18.32; UI implementation
 * contract 12.8; UI-016; kiosk guide 3, 5, 6; SLB-003, SLB-004, SLB-029).
 *
 * This is not `staff.shift-board`. That one is somebody browsing shifts and
 * signing themselves up (M18.2); this is the shared machine at the gate, and UI
 * contract 12.8 gives it to an authorized shift or department lead. What happens
 * here is people arriving and leaving: check in, check out, no-show. Kiosk guide
 * 5 is explicit that default staff do not self check-in in Alpha 1, so nothing
 * on this screen is self-service — every operation is somebody with attendance
 * authority recording it for somebody else.
 *
 * Four properties are load-bearing.
 *
 *  1. **The scope is the machine's, not the URL's.** The event is the
 *     workstation's pinned event and the department is its pinned department. A
 *     Kiosk that could read another department by changing an address would be a
 *     Kiosk whose scope the person at the keyboard chose (UI-019, UI-020). Where
 *     no department is pinned the signed-in user picks from their own — that is
 *     their scope, not an inference about the machine.
 *  2. **Authority is the node's answer.** `access.canManageAttendance` comes
 *     back with the read and is the same grant the commands enforce, so the
 *     actions are absent for somebody who may not use them rather than present
 *     and refused (CLIENT-005, CLIENT-006). Trusted workstation state permits
 *     the surface; individual authority permits the action (UI contract 18.2).
 *  3. **It works with the node down.** Check-in, check-out, and no-show are
 *     Alpha 1 offline writes (data/API 7.2, UI-024) and go through the same
 *     command outbox the Logistics Desk uses, keyed by the device-generated
 *     operation UUID. A queued operation survives a session ending, which is
 *     what the timeout and switch-user screens promise (kiosk guide 4.4).
 *  4. **The cards are the roster the desk is looking at.** Shifts running now,
 *     each with the people on it, in one column, at touch size (kiosk guide 6).
 *     Search, equipment, and corrections are the Logistics Desk's, on a machine
 *     somebody is sitting at.
 */

const desk = ref<LogisticsDeskRead | null>(null);
const loadError = ref<string | null>(null);
const status = ref<string | null>(null);
const busyKey = ref<string | null>(null);
const chosenDepartmentId = ref<string | null>(null);

const eventId = computed(() => kioskPinnedEventId.value);
const eventName = computed(() => kioskContextState.context?.event?.name ?? null);

/**
 * The departments this signed-in user could work in here.
 *
 * Offered only when the workstation pins no department of its own. It is the
 * user's own scope from the session document — not a guess about the machine,
 * which is the thing UI-020 forbids.
 */
const choosableDepartments = computed(() =>
  kioskPinnedDepartmentId.value === null ? sessionDepartmentAccesses.value : [],
);

const departmentId = computed<string | null>(
  () => kioskPinnedDepartmentId.value ?? chosenDepartmentId.value,
);

const timeZone = computed(
  () => desk.value?.context.timeZone ?? kioskContextState.context?.event?.timeZone ?? "UTC",
);

const canManageAttendance = computed(
  () => desk.value?.access.canManageAttendance === true,
);

/** The shifts the desk is holding open right now (SLB-003). */
const shifts = computed<readonly DepartmentOpsShift[]>(() =>
  desk.value === null ? [] : currentLogisticsShifts(desk.value),
);

interface ShiftRoster {
  readonly shift: DepartmentOpsShift;
  readonly rows: readonly {
    readonly workspace: LogisticsStaffWorkspace;
    readonly card: LogisticsShiftCard;
  }[];
}

/**
 * Who is on each of those shifts, from the workspaces the read already carries.
 *
 * Derived rather than fetched: every fact is on the desk read, and asking the
 * node a second question it has already answered is how a screen and a server
 * start disagreeing.
 */
const rosters = computed<readonly ShiftRoster[]>(() => {
  const read = desk.value;

  if (read === null) {
    return [];
  }

  return shifts.value.map((shift) => ({
    shift,
    rows: Object.values(read.staffWorkspaces)
      .flatMap((workspace) =>
        workspace.shiftCards
          .filter((card) => card.shiftId === shift.shiftId)
          .map((card) => ({ workspace, card })),
      )
      .sort((left, right) =>
        left.workspace.displayName.localeCompare(right.workspace.displayName),
      ),
  }));
});

async function loadDesk(): Promise<void> {
  const event = eventId.value;
  const department = departmentId.value;

  if (event === null || department === null) {
    desk.value = null;

    return;
  }

  loadError.value = null;

  try {
    desk.value = await getLogisticsDesk(event, department);
  } catch (error) {
    desk.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "This board could not be read. Check the connection to the node and try again.",
    );
  }
}

/**
 * Record one operation and re-read.
 *
 * The operation is queued rather than sent, so it holds whether or not the node
 * is reachable; the re-read is what makes the card honest afterwards, and falls
 * back to the stored copy on its own when there is nothing to re-read from.
 */
async function record(
  key: string,
  workspace: LogisticsStaffWorkspace,
  card: LogisticsShiftCard,
  operation: "check_in" | "check_out" | "mark_no_show",
): Promise<void> {
  const read = desk.value;

  if (read === null || busyKey.value !== null) {
    return;
  }

  busyKey.value = key;
  status.value = null;

  const input = {
    context: read.context,
    shiftId: card.shiftId,
    staffId: workspace.staffId,
  };

  try {
    if (operation === "check_in") {
      queueCheckIn(input);
    } else if (operation === "check_out") {
      queueCheckOut(input);
    } else {
      queueMarkNoShow(input);
    }

    status.value = `${workspace.displayName} — ${
      operation === "check_in"
        ? "checked in"
        : operation === "check_out"
          ? "checked out"
          : "marked no-show"
    }. Recorded on this workstation and sent when the node is reachable.`;

    await loadDesk();
  } catch (error) {
    status.value = meridianErrorMessage(
      error,
      error instanceof Error ? error.message : "That could not be recorded.",
    );
  } finally {
    busyKey.value = null;
  }
}

function windowLabel(shift: DepartmentOpsShift): string {
  return `${formatTimestamp(shift.startsAt, timeZone.value)} – ${formatTimestamp(
    shift.endsAt,
    timeZone.value,
  )}`;
}

watch([eventId, departmentId], () => {
  void loadDesk();
});

void loadDesk();
</script>

<template>
  <section class="kiosk-shift-board" aria-labelledby="kiosk-shift-board-heading">
    <h1 id="kiosk-shift-board-heading" class="kiosk-shift-board__heading">
      Shift board
    </h1>
    <p v-if="eventName" class="kiosk-shift-board__event">{{ eventName }}</p>

    <!--
      A Kiosk with no pinned event is in setup and does not reach this screen;
      this is the state where the pin exists and the read has not resolved one.
    -->
    <p v-if="eventId === null" class="kiosk-shift-board__notice" role="status">
      This workstation is not pinned to an event, so there is no shift board to
      show.
    </p>

    <template v-else>
      <!--
        The department the workstation pins, or the one this user chose from
        their own. Not inferred from anything the device is holding (UI-020).
      -->
      <div
        v-if="choosableDepartments.length > 0"
        class="kiosk-shift-board__department"
      >
        <label class="kiosk-shift-board__label" for="kiosk-shift-board-department">
          Department
        </label>
        <select
          id="kiosk-shift-board-department"
          v-model="chosenDepartmentId"
          class="kiosk-shift-board__select"
        >
          <option :value="null">Choose a department</option>
          <option
            v-for="department in choosableDepartments"
            :key="department.departmentId"
            :value="department.departmentId"
          >
            {{ department.departmentLabel }}
          </option>
        </select>
      </div>

      <p
        v-if="departmentId === null"
        class="kiosk-shift-board__notice"
        role="status"
      >
        Choose the department you are working the desk for.
      </p>

      <template v-else>
        <p v-if="loadError" class="kiosk-shift-board__error" role="alert">
          {{ loadError }}
          <button type="button" @click="loadDesk">Try again</button>
        </p>

        <StaleReadNotice
          v-if="desk"
          :freshness="desk.freshness"
          :time-zone="timeZone"
          label="This board"
        />

        <!--
          UI contract 19.3: a Kiosk states what is unavailable for the current
          user and offers a way on, rather than showing controls that only
          refuse.
        -->
        <p
          v-if="desk && !canManageAttendance"
          class="kiosk-shift-board__notice"
          data-testid="kiosk-shift-board-denied"
          role="status"
        >
          Recording attendance is not available for the current user. Return to
          kiosk home, or switch users.
        </p>

        <p v-if="status" class="kiosk-shift-board__status" role="status">
          {{ status }}
        </p>

        <p
          v-if="desk && rosters.length === 0"
          class="kiosk-shift-board__notice"
          role="status"
        >
          No shift is running at this department right now.
        </p>

        <article
          v-for="roster in rosters"
          :key="roster.shift.shiftId"
          class="kiosk-shift-board__shift"
        >
          <header class="kiosk-shift-board__shift-header">
            <h2 class="kiosk-shift-board__shift-title">{{ roster.shift.title }}</h2>
            <span class="kiosk-shift-board__window">
              {{ windowLabel(roster.shift) }}
            </span>
          </header>
          <p class="kiosk-shift-board__team">{{ roster.shift.teamLabel }}</p>

          <p v-if="roster.rows.length === 0" class="kiosk-shift-board__notice">
            Nobody is assigned to this shift.
          </p>

          <div
            v-for="row in roster.rows"
            :key="`${roster.shift.shiftId}:${row.workspace.staffId}`"
            class="kiosk-shift-board__person"
          >
            <div class="kiosk-shift-board__person-identity">
              <strong class="kiosk-shift-board__person-name">
                {{ row.workspace.displayName }}
              </strong>
              <span class="kiosk-shift-board__person-state">
                {{
                  row.card.attendanceState === null
                    ? "No assignment"
                    : attendanceStateLabel(row.card.attendanceState)
                }}
              </span>
            </div>

            <div v-if="canManageAttendance" class="kiosk-shift-board__person-actions">
              <button
                v-if="row.card.canCheckIn"
                class="kiosk-shift-board__action"
                data-variant="primary"
                type="button"
                :disabled="busyKey !== null"
                @click="
                  record(
                    `in:${roster.shift.shiftId}:${row.workspace.staffId}`,
                    row.workspace,
                    row.card,
                    'check_in',
                  )
                "
              >
                Check in
              </button>

              <button
                v-if="row.card.canCheckOut"
                class="kiosk-shift-board__action"
                type="button"
                :disabled="busyKey !== null"
                @click="
                  record(
                    `out:${roster.shift.shiftId}:${row.workspace.staffId}`,
                    row.workspace,
                    row.card,
                    'check_out',
                  )
                "
              >
                Check out
              </button>

              <button
                v-if="row.card.canMarkNoShow"
                class="kiosk-shift-board__action"
                type="button"
                :disabled="busyKey !== null"
                @click="
                  record(
                    `no-show:${roster.shift.shiftId}:${row.workspace.staffId}`,
                    row.workspace,
                    row.card,
                    'mark_no_show',
                  )
                "
              >
                No-show
              </button>
            </div>
          </div>
        </article>
      </template>
    </template>
  </section>
</template>

<style scoped>
.kiosk-shift-board {
  display: grid;
  gap: var(--m-space-3);
  align-content: start;
  width: var(--m-content-workflow);
}

.kiosk-shift-board__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.kiosk-shift-board__event {
  margin: calc(var(--m-space-3) * -1 + var(--m-space-1)) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-transform: uppercase;
}

.kiosk-shift-board__notice,
.kiosk-shift-board__error,
.kiosk-shift-board__status {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-text-secondary);
}

.kiosk-shift-board__error {
  border-color: var(--m-status-critical, var(--m-border-default));
  color: var(--m-status-critical, var(--m-text-primary));
}

.kiosk-shift-board__department {
  display: grid;
  gap: var(--m-space-1);
}

.kiosk-shift-board__label {
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.kiosk-shift-board__select {
  min-height: 3rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
}

.kiosk-shift-board__shift {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
}

.kiosk-shift-board__shift-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.kiosk-shift-board__shift-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.kiosk-shift-board__window,
.kiosk-shift-board__team {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

/* Touch sized, one person per row: read at arm's length with gloves on. */
.kiosk-shift-board__person {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
  padding: var(--m-space-3) 0;
  border-top: 1px solid var(--m-border-subtle, var(--m-border-default));
}

.kiosk-shift-board__person-identity {
  display: grid;
  gap: 0.1rem;
  min-width: 0;
}

.kiosk-shift-board__person-name {
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.kiosk-shift-board__person-state {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.kiosk-shift-board__person-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.kiosk-shift-board__action {
  min-height: 3rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-md);
  font-weight: 900;
  cursor: pointer;
}

.kiosk-shift-board__action[data-variant="primary"] {
  border-color: var(--m-action-primary-bg);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.kiosk-shift-board__action:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.kiosk-shift-board__action:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
