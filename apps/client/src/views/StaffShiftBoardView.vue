<script setup lang="ts">
import { computed, ref, watch } from "vue";

import StaleReadNotice from "@/components/StaleReadNotice.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { useLocalNodeReachable } from "@/offline/useConnectivity";
import {
  sessionEventContext,
  sessionEventTimeZone,
} from "@/session/sessionAccess";
import {
  getShiftBoard,
  shiftBoardCapacityLabel,
  shiftBoardStateLabel,
  shiftBoardWindowLabel,
  signUpForShift,
  withdrawFromShift,
  type ShiftBoard,
  type ShiftBoardEntry,
} from "@/shift-board/staffShiftBoardModel";

/**
 * `staff.shift-board` — where a staff member browses the shifts in their event
 * and signs up (M18.2; SHIFT-011 through SHIFT-015, SHIFT-018; requirements
 * 3.12, 5.5; UI contract 12.3).
 *
 * Until this task the domain could accept a signup and nobody could ask it to:
 * an ordinary shift had no path from a screen to `ShiftSignupService`, so a
 * staff member could read their department's schedule and not join it.
 *
 * Three properties of the page are load-bearing:
 *
 *  1. **Every shift is on the board, including the ones that refuse.** SHIFT-018
 *     requires an unavailable shift to say why it is unavailable, so a shift
 *     that will not take somebody is shown with the node's reason rather than
 *     filtered out — a schedule with a hole in it teaches nobody anything.
 *  2. **The reasons are the node's.** `canSignUp` and `unavailableReason` are
 *     answers from the read, not rules re-derived here (CLIENT-006). The button
 *     and the command therefore never disagree, and the sentence on screen is
 *     the sentence the command would refuse with.
 *  3. **An overlap is a warning, not a wall** (SHIFT-014). It is printed beside
 *     a shift that is still offered, and again beside the signup that took it.
 */
const nodeReachable = useLocalNodeReachable();
const eventContext = computed(() => sessionEventContext.value);

const board = ref<ShiftBoard | null>(null);

/**
 * Whether the board on screen is the copy this device holds (M18.50).
 *
 * Read by the rows as well as by the disclosure: a stored copy carries no
 * verdict from the node, so a row must not print one it inferred from the
 * absence of it.
 */
const stale = computed(() => board.value?.freshness.source === "cache");
const timeZone = computed(() => sessionEventTimeZone.value);
const loadError = ref<string | null>(null);
const status = ref<string | null>(null);
const busyShiftId = ref<string | null>(null);

/**
 * Signup and withdrawal are connected-only, so with no node there is nothing
 * to press. The list still reads from the last response rather than emptying:
 * knowing what you are down for is worth more than a blank screen.
 */
const isOfflineBlocked = computed(() => !nodeReachable.value);

const lede = computed(() =>
  eventContext.value === null
    ? ""
    : `Shifts your departments are running at ${
        board.value?.eventName ?? eventContext.value.eventLabel ?? "this event"
      }.`,
);

const shifts = computed<readonly ShiftBoardEntry[]>(
  () => board.value?.shifts ?? [],
);

/**
 * Read the board.
 *
 * A failed read clears it rather than leaving the previous answer on screen: a
 * board that could not be read must not look like an event with no shifts in it.
 */
async function loadBoard(): Promise<void> {
  const event = eventContext.value;

  if (event === null) {
    board.value = null;

    return;
  }

  loadError.value = null;

  try {
    board.value = await getShiftBoard(event.eventId);
  } catch (error) {
    board.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load the shift board. Check the connection to this node and try again.",
    );
  }
}

/**
 * Run one command and re-read.
 *
 * The re-read is what makes the row honest afterwards: capacity, the cutoff, and
 * whether anybody else took the last place are the node's to report, and this
 * page holds no copy of them to update on its own.
 */
async function run(
  shift: ShiftBoardEntry,
  work: () => Promise<readonly string[] | void>,
): Promise<void> {
  if (busyShiftId.value !== null) {
    return;
  }

  busyShiftId.value = shift.id;
  status.value = null;

  try {
    const warnings = (await work()) ?? [];

    await loadBoard();

    status.value =
      warnings.length > 0
        ? `${shift.title}: ${warnings.join(" ")}`
        : `${shift.title} updated.`;
  } catch (error) {
    status.value = meridianErrorMessage(
      error,
      error instanceof Error
        ? error.message
        : "Unable to change this shift. Try again.",
    );
  } finally {
    busyShiftId.value = null;
  }
}

function signUp(shift: ShiftBoardEntry): void {
  const event = eventContext.value;

  if (event === null) return;

  void run(shift, () => signUpForShift(event.eventId, shift.id));
}

function withdraw(shift: ShiftBoardEntry): void {
  const event = eventContext.value;

  if (event === null) return;

  void run(shift, () => withdrawFromShift(event.eventId, shift.id));
}

/** What this shift asks of somebody, when it asks anything (SHIFT-005, SHIFT-006). */
function requirementsLabel(shift: ShiftBoardEntry): string | null {
  const requirements = [
    ...shift.requiredTrainingNames,
    ...shift.requiredWaiverNames,
  ];

  return requirements.length > 0 ? requirements.join(", ") : null;
}

watch(eventContext, () => {
  void loadBoard();
});

void loadBoard();
</script>

<template>
  <StaffPageShell
    heading-id="staff-shift-board-heading"
    title="Shift Board"
    eyebrow="Your schedule"
    :lede="lede"
  >
    <!--
      Event-scoped, so with no event there is no board to read. Stated rather
      than rendered empty: an empty list would say there is no work, and what is
      true is that this device is not working in an event yet.
    -->
    <p
      v-if="eventContext === null"
      class="shift-board__notice"
      role="status"
    >
      The shift board opens once this device is working in an event.
    </p>

    <template v-else>
      <p v-if="loadError" class="shift-board__error" role="alert">
        {{ loadError }}
        <button type="button" @click="loadBoard">Try again</button>
      </p>

      <!--
        The board renders from what this device stored when the node cannot be
        reached (technical spec 9.3), and says so. A volunteer with no signal
        still needs to know when they are due.
      -->
      <StaleReadNotice
        v-if="board"
        :freshness="board.freshness"
        :time-zone="timeZone"
        label="This board"
      />

      <p v-if="isOfflineBlocked" class="shift-board__notice" role="status">
        Signing up and withdrawing need a connection to the node. They are not
        held on this device for later.
      </p>

      <p v-if="status" class="shift-board__status" role="status">
        {{ status }}
      </p>

      <p
        v-if="!loadError && shifts.length === 0"
        class="shift-board__notice"
        role="status"
      >
        No shifts are scheduled for your departments at this event yet.
      </p>

      <article
        v-for="shift in shifts"
        :key="shift.id"
        class="shift-board__shift"
        :data-signed-up="shift.signedUp ? 'true' : 'false'"
      >
        <header class="shift-board__shift-header">
          <h2 class="shift-board__shift-title">{{ shift.title }}</h2>
          <span class="shift-board__state">{{ shiftBoardStateLabel(shift) }}</span>
        </header>

        <dl class="shift-board__facts">
          <div class="shift-board__fact">
            <dt>When</dt>
            <dd>{{ shiftBoardWindowLabel(shift) }}</dd>
          </div>
          <div class="shift-board__fact">
            <dt>Where</dt>
            <dd>
              {{ shift.departmentName ?? "Department" }} —
              {{ shift.eligibleTeamName ?? "Team" }}
            </dd>
          </div>
          <div class="shift-board__fact">
            <dt>Signed up</dt>
            <dd>{{ shiftBoardCapacityLabel(shift) }}</dd>
          </div>
          <div v-if="requirementsLabel(shift)" class="shift-board__fact">
            <dt>Requires</dt>
            <dd>{{ requirementsLabel(shift) }}</dd>
          </div>
        </dl>

        <!--
          SHIFT-018: the reason an unavailable shift is unavailable, in the words
          the node refused with.
        -->
        <p
          v-if="shift.unavailableReason"
          class="shift-board__reason"
          :data-reason-code="shift.unavailableReasonCode ?? ''"
        >
          {{ shift.unavailableReason }}
        </p>

        <!-- SHIFT-014: overlap warns, and the shift is still offered. -->
        <p
          v-for="warning in shift.overlapWarnings"
          :key="warning.message"
          class="shift-board__warning"
        >
          {{ warning.message }}
        </p>

        <!--
          Only off a live read (M18.50). "You cannot withdraw" is the node's
          answer online and this device's inability to ask offline, and the two
          have different remedies: one is a department lead, the other is a
          connection. Inferring the first from a stored copy would send somebody
          looking for the wrong person.
        -->
        <p
          v-if="shift.signedUp && !shift.canWithdraw && !stale"
          class="shift-board__reason"
        >
          The schedule is locked for this shift. Ask your department lead to
          change it.
        </p>

        <!--
          Absent, not disabled, when the shift will not take this person
          (CLIENT-005). The reason above says why, and a button that only ever
          refuses is not an offer.
        -->
        <div v-if="shift.canSignUp || shift.canWithdraw" class="shift-board__actions">
          <button
            v-if="shift.canSignUp"
            type="button"
            class="shift-board__action"
            data-variant="primary"
            :disabled="isOfflineBlocked || busyShiftId === shift.id"
            @click="signUp(shift)"
          >
            {{ busyShiftId === shift.id ? "Signing up…" : "Sign up" }}
          </button>

          <button
            v-if="shift.canWithdraw"
            type="button"
            class="shift-board__action"
            :disabled="isOfflineBlocked || busyShiftId === shift.id"
            @click="withdraw(shift)"
          >
            {{ busyShiftId === shift.id ? "Withdrawing…" : "Withdraw" }}
          </button>
        </div>
      </article>
    </template>
  </StaffPageShell>
</template>

<style scoped>
.shift-board__notice,
.shift-board__error,
.shift-board__status {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.shift-board__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.shift-board__shift {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.shift-board__shift[data-signed-up="true"] {
  border-color: var(--m-status-success, var(--m-border-strong, currentColor));
}

.shift-board__shift-header {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.shift-board__shift-title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.shift-board__state {
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.shift-board__facts {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.shift-board__fact {
  display: grid;
  grid-template-columns: minmax(5rem, 8rem) 1fr;
  gap: var(--m-space-3);
}

.shift-board__fact dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.shift-board__fact dd {
  margin: 0;
}

.shift-board__reason,
.shift-board__warning {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.shift-board__actions {
  display: grid;
  gap: var(--m-space-2);
}

.shift-board__action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  width: 100%;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
  cursor: pointer;
}

.shift-board__action[data-variant="primary"] {
  border-color: var(--m-action-primary-bg);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.shift-board__action:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.shift-board__action:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 44rem) {
  .shift-board__actions {
    display: flex;
    flex-wrap: wrap;
  }

  .shift-board__action {
    width: auto;
  }
}

@media (max-width: 40rem) {
  .shift-board__fact {
    grid-template-columns: 1fr;
    gap: var(--m-space-1);
  }
}
</style>
