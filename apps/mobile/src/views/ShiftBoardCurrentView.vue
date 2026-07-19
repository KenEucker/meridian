<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import {
  LOCAL_CURRENT_SHIFT_BOARD,
  addUnscheduledRosterMember,
  attendanceStateLabel,
  checkedInRoster,
  eligibleUnscheduledCandidates,
  rosterSummary,
  type ShiftBoardRosterMember,
} from "@/shift-board/currentShiftBoard";

// Current Shift Board - UI contract 12.5 `shift-board.current` (M10.1, M10.7).
// Check-in/out, no-show, hours correction forms, deployment, equipment, and
// shortcuts arrive in their owning M10 tasks.
const board = ref(LOCAL_CURRENT_SHIFT_BOARD);
const checkedInMembers = computed(() => checkedInRoster(board.value));
const summary = computed(() => rosterSummary(board.value));
const candidates = computed(() => eligibleUnscheduledCandidates(board.value));
const selectedCandidateId = ref(candidates.value[0]?.staffId ?? "");
const addStatus = ref<string | null>(null);

const dateTimeFormatter = new Intl.DateTimeFormat("en-US", {
  month: "short",
  day: "numeric",
  hour: "numeric",
  minute: "2-digit",
  timeZone: board.value.timeZone,
  timeZoneName: "short",
});

function formatTimestamp(timestamp: string): string {
  return dateTimeFormatter.format(new Date(timestamp));
}

function checkedInText(member: ShiftBoardRosterMember): string {
  if (member.attendanceState !== "checked_in" || !member.checkedInAt) {
    return "Not checked in";
  }

  return `Checked in ${formatTimestamp(member.checkedInAt)}`;
}

function addSelectedCandidate(): void {
  const candidate = candidates.value.find(
    (item) => item.staffId === selectedCandidateId.value,
  );

  if (candidate === undefined) {
    return;
  }

  board.value = addUnscheduledRosterMember(
    board.value,
    candidate.staffId,
    `local-unscheduled-${candidate.staffId}`,
  );
  selectedCandidateId.value = candidates.value[0]?.staffId ?? "";
  addStatus.value = `${candidate.displayName} added to roster.`;
}
</script>

<template>
  <section class="shift-board" aria-labelledby="shift-board-heading">
    <p class="shift-board__nav">
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </p>

    <header class="shift-board__header">
      <div>
        <p class="shift-board__eyebrow">{{ board.departmentLabel }}</p>
        <h1 id="shift-board-heading" class="shift-board__heading">
          Current Shift Board
        </h1>
      </div>
      <p class="shift-board__window" aria-label="Current shift time">
        {{ formatTimestamp(board.startsAt) }} - {{ formatTimestamp(board.endsAt) }}
      </p>
    </header>

    <dl class="shift-board__context" aria-label="Shift context">
      <div>
        <dt>Event</dt>
        <dd>{{ board.eventLabel }}</dd>
      </div>
      <div>
        <dt>Team</dt>
        <dd>{{ board.teamLabel }}</dd>
      </div>
      <div>
        <dt>Shift</dt>
        <dd>{{ board.shiftTitle }}</dd>
      </div>
    </dl>

    <dl class="shift-board__summary" aria-label="Roster summary">
      <div>
        <dt>Roster</dt>
        <dd>{{ summary.rosterCount }}</dd>
      </div>
      <div>
        <dt>Checked in</dt>
        <dd>{{ summary.checkedInCount }}</dd>
      </div>
    </dl>

    <section
      class="shift-board__unscheduled"
      aria-labelledby="unscheduled-heading"
    >
      <h2 id="unscheduled-heading" class="shift-board__subheading">
        Add eligible staff
      </h2>
      <form
        class="shift-board__add-form"
        aria-label="Add eligible unscheduled staff"
        @submit.prevent="addSelectedCandidate"
      >
        <label class="shift-board__field">
          <span>Staff</span>
          <select
            v-model="selectedCandidateId"
            :disabled="candidates.length === 0"
          >
            <option
              v-for="candidate in candidates"
              :key="candidate.staffId"
              :value="candidate.staffId"
            >
              {{ candidate.displayName }} - {{ candidate.teamLabel }}
            </option>
          </select>
        </label>
        <button
          class="shift-board__button"
          type="submit"
          :disabled="selectedCandidateId === ''"
        >
          Add to roster
        </button>
      </form>
      <p class="shift-board__status" role="status">
        {{ addStatus ?? "No unscheduled staff added." }}
      </p>
    </section>

    <section
      class="shift-board__checked-in"
      aria-labelledby="checked-in-heading"
    >
      <h2 id="checked-in-heading" class="shift-board__subheading">
        Checked-in staff
      </h2>
      <p
        v-if="checkedInMembers.length === 0"
        class="shift-board__empty"
        role="status"
      >
        No staff are checked in.
      </p>
      <ul v-else class="shift-board__checked-list">
        <li
          v-for="member in checkedInMembers"
          :key="member.assignmentId"
          class="shift-board__checked-item"
        >
          <span>{{ member.displayName }}</span>
          <span>{{ checkedInText(member) }}</span>
        </li>
      </ul>
    </section>

    <section class="shift-board__roster" aria-labelledby="roster-heading">
      <h2 id="roster-heading" class="shift-board__subheading">
        Current shift roster
      </h2>

      <div class="shift-board__table-frame">
        <table class="shift-board__table">
          <thead>
            <tr>
              <th scope="col">Staff</th>
              <th scope="col">Team</th>
              <th scope="col">Attendance</th>
              <th scope="col">Checked-in time</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in board.roster" :key="member.assignmentId">
              <th scope="row">
                <span class="shift-board__name">{{ member.displayName }}</span>
                <span v-if="member.handle" class="shift-board__handle"
                  >@{{ member.handle }}</span
                >
              </th>
              <td>{{ member.teamLabel }}</td>
              <td>
                <span
                  class="shift-board__state"
                  :class="`shift-board__state--${member.attendanceState}`"
                >
                  {{ attendanceStateLabel(member.attendanceState) }}
                </span>
              </td>
              <td>{{ checkedInText(member) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </section>
</template>

<style scoped>
.shift-board {
  max-width: 64rem;
}

.shift-board__nav {
  margin: 0 0 var(--m-space-4);
}

.shift-board__nav a {
  color: var(--m-text-primary);
}

.shift-board__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.shift-board__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--m-space-4);
  margin-bottom: var(--m-space-4);
}

.shift-board__eyebrow,
.shift-board__window,
.shift-board__empty {
  margin: 0;
  color: var(--m-text-muted);
}

.shift-board__eyebrow {
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0;
}

.shift-board__heading {
  margin: var(--m-space-1) 0 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.shift-board__window {
  text-align: right;
  font-size: var(--m-text-sm);
}

.shift-board__context,
.shift-board__summary {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
}

.shift-board__context {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.shift-board__summary {
  grid-template-columns: repeat(2, minmax(8rem, 12rem));
}

.shift-board__context div,
.shift-board__summary div {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  padding: var(--m-space-3);
}

.shift-board__context dt,
.shift-board__summary dt {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.shift-board__context dd,
.shift-board__summary dd {
  margin: 0;
  font-weight: 700;
}

.shift-board__summary dd {
  font-size: var(--m-text-xl);
}

.shift-board__checked-in,
.shift-board__unscheduled,
.shift-board__roster {
  margin-top: var(--m-space-6);
}

.shift-board__subheading {
  margin: 0 0 var(--m-space-3);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.shift-board__checked-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-2);
}

.shift-board__checked-item {
  display: flex;
  justify-content: space-between;
  gap: var(--m-space-3);
  border-left: 4px solid var(--m-status-success);
  padding: var(--m-space-2) var(--m-space-3);
  background: var(--m-surface-raised);
}

.shift-board__checked-item span:last-child {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.shift-board__add-form {
  display: flex;
  align-items: end;
  gap: var(--m-space-3);
  max-width: 34rem;
}

.shift-board__field {
  display: grid;
  flex: 1;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.shift-board__field select {
  min-height: 2.75rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  padding: 0 var(--m-space-3);
}

.shift-board__button {
  min-height: 2.75rem;
  border: 1px solid var(--m-text-primary);
  border-radius: var(--m-radius-sm);
  background: var(--m-text-primary);
  color: var(--m-surface-app);
  font: inherit;
  font-weight: 700;
  padding: 0 var(--m-space-4);
}

.shift-board__button:disabled,
.shift-board__field select:disabled {
  cursor: not-allowed;
  opacity: 0.55;
}

.shift-board__button:focus-visible,
.shift-board__field select:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.shift-board__status {
  margin: var(--m-space-2) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.shift-board__table-frame {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.shift-board__table {
  width: 100%;
  min-width: 42rem;
  border-collapse: collapse;
}

.shift-board__table th,
.shift-board__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-subtle);
  text-align: left;
  vertical-align: top;
}

.shift-board__table tbody tr:last-child th,
.shift-board__table tbody tr:last-child td {
  border-bottom: 0;
}

.shift-board__table thead th {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.shift-board__name,
.shift-board__handle {
  display: block;
}

.shift-board__handle {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 400;
}

.shift-board__state {
  display: inline-flex;
  align-items: center;
  min-height: 1.75rem;
  padding: 0 var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-app);
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.shift-board__state--checked_in {
  border-color: var(--m-status-success);
  color: var(--m-status-success);
}

.shift-board__state--no_show {
  border-color: var(--m-status-danger);
  color: var(--m-status-danger);
}

@media (max-width: 44rem) {
  .shift-board__header,
  .shift-board__checked-item {
    display: block;
  }

  .shift-board__window {
    margin-top: var(--m-space-2);
    text-align: left;
  }

  .shift-board__context,
  .shift-board__summary {
    grid-template-columns: 1fr;
  }

  .shift-board__add-form {
    align-items: stretch;
    display: grid;
  }

  .shift-board__checked-item span {
    display: block;
  }
}
</style>
