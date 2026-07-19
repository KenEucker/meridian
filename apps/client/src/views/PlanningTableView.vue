<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import { LOCAL_PLANNING_TABLE } from "@/department-ops/fixtures";
import {
  formatTimestamp,
  lifecycleLabel,
} from "@/department-ops/labels";
import {
  assertNoStaffIdentities,
  capacityLabel,
  dateKeyForTimestamp,
  filterPlanningRows,
  planningSummary,
  signedVarianceLabel,
} from "@/department-ops/planning";

assertNoStaffIdentities(LOCAL_PLANNING_TABLE);

const table = LOCAL_PLANNING_TABLE;
const selectedTeamId = ref(table.selectedFilters.teamId ?? "");
const selectedDate = ref(table.selectedFilters.date ?? "");
const activeFilters = computed(() => ({
  teamId: selectedTeamId.value === "" ? null : selectedTeamId.value,
  date: selectedDate.value === "" ? null : selectedDate.value,
}));
const filteredTable = computed(() => ({
  ...table,
  selectedFilters: activeFilters.value,
}));
const visibleRows = computed(() => filterPlanningRows(table, activeFilters.value));
const summary = computed(() => planningSummary(filteredTable.value));
const availableDates = computed(() =>
  Array.from(
    new Set(
      table.rows.map((row) =>
        dateKeyForTimestamp(row.startsAt, table.context.timeZone),
      ),
    ),
  ),
);
const scopeLabel = computed(() => {
  const team = table.availableTeams.find(
    (candidate) => candidate.teamId === activeFilters.value.teamId,
  );
  const teamLabel = team ? team.teamLabel : "all teams";
  const dateLabel = activeFilters.value.date ?? "all dates";

  return `Department (${teamLabel}, ${dateLabel})`;
});
const syncStateLabel = computed(() => {
  const prefix =
    table.syncState === "offline"
      ? "Offline aggregate cache"
      : table.syncState === "stale"
        ? "Stale aggregate cache"
        : "Fresh aggregate cache";

  return `${prefix} · ${table.context.dataFreshnessLabel}`;
});
</script>

<template>
  <DeptOpsShell
    title="Planning Table"
    :eyebrow="table.context.departmentLabel"
    lede="Identity-free comparison of what was planned and how the department is tracking."
    :freshness="syncStateLabel"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back to Home</RouterLink>
    </template>

    <dl class="planning__context" aria-label="Planning context">
      <div>
        <dt>Event</dt>
        <dd>{{ table.context.eventLabel }}</dd>
      </div>
      <div>
        <dt>Department</dt>
        <dd>{{ table.context.departmentLabel }}</dd>
      </div>
      <div>
        <dt>Scope</dt>
        <dd>{{ scopeLabel }}</dd>
      </div>
    </dl>

    <form class="planning__filters" aria-label="Planning filters">
      <label>
        <span>Team filter</span>
        <select v-model="selectedTeamId">
          <option value="">All teams</option>
          <option
            v-for="team in table.availableTeams"
            :key="team.teamId"
            :value="team.teamId"
          >
            {{ team.teamLabel }}
          </option>
        </select>
      </label>
      <label>
        <span>Date filter</span>
        <input
          v-model="selectedDate"
          type="date"
          :list="'planning-date-options'"
        />
        <datalist id="planning-date-options">
          <option v-for="date in availableDates" :key="date" :value="date" />
        </datalist>
      </label>
    </form>

    <dl class="planning__summary" aria-label="Planning summary">
      <div>
        <dt>Shift windows</dt>
        <dd>{{ summary.shiftCount }}</dd>
      </div>
      <div>
        <dt>Active now</dt>
        <dd>{{ summary.activeCount }}</dd>
      </div>
      <div>
        <dt>Under target</dt>
        <dd>{{ summary.underTargetCount }}</dd>
      </div>
      <div>
        <dt>Completed</dt>
        <dd>{{ summary.completedCount }}</dd>
      </div>
      <div>
        <dt>Planned hours</dt>
        <dd>{{ summary.plannedHours }}</dd>
      </div>
      <div>
        <dt>Actual hours</dt>
        <dd>{{ summary.actualHours }}</dd>
      </div>
    </dl>

    <section aria-labelledby="planning-table-heading">
      <h2 id="planning-table-heading">Plan versus actual</h2>
      <div class="planning__table-frame">
        <table>
          <thead>
            <tr>
              <th scope="col">Shift / team</th>
              <th scope="col">Lifecycle</th>
              <th scope="col">Capacity</th>
              <th scope="col">Signed up / assigned</th>
              <th scope="col">Checked in</th>
              <th scope="col">No-show</th>
              <th scope="col">Unscheduled</th>
              <th scope="col">Planned hours</th>
              <th scope="col">Actual hours</th>
              <th scope="col">Variance</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in visibleRows"
              :key="row.shiftId"
              :class="`planning__row--${row.lifecycle}`"
            >
              <th scope="row">
                <span>{{ row.title }}</span>
                <span class="planning__meta">
                  {{ row.teamLabel }} ·
                  {{ formatTimestamp(row.startsAt, table.context.timeZone) }}
                  to
                  {{ formatTimestamp(row.endsAt, table.context.timeZone) }}
                </span>
              </th>
              <td>{{ lifecycleLabel(row.lifecycle) }}</td>
              <td>{{ capacityLabel(row.capacity) }}</td>
              <td>{{ row.signedUpOrAssignedCount }}</td>
              <td>{{ row.checkedInCount }}</td>
              <td>{{ row.noShowCount }}</td>
              <td>{{ row.unscheduledCount }}</td>
              <td>{{ row.plannedHours }}</td>
              <td>{{ row.actualHours }}</td>
              <td>{{ signedVarianceLabel(row.varianceHours) }}</td>
              <td>
                <span class="planning__status">{{ row.statusLabel }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <p v-if="visibleRows.length === 0" class="planning__empty" role="status">
        No aggregate rows match the current department filters.
      </p>
      <p class="planning__note" role="status">
        This table does not show individual staff identities, signup lists, or
        team-member lists.
      </p>
    </section>
  </DeptOpsShell>
</template>

<style scoped>
.planning__context,
.planning__summary,
.planning__filters {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
}

.planning__context div,
.planning__summary div {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  padding: var(--m-space-3);
}

.planning__context dt,
.planning__summary dt {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.planning__context dd,
.planning__summary dd {
  margin: 0;
  font-weight: 700;
}

.planning__filters {
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
}

.planning__filters label {
  display: grid;
  gap: var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.planning__filters select,
.planning__filters input {
  min-height: 2.75rem;
  border: 1px solid var(--m-border-strong);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  padding: 0 var(--m-space-3);
}

.planning__filters select:focus-visible,
.planning__filters input:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.planning__table-frame {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

table {
  width: 100%;
  border-collapse: collapse;
  min-width: 68rem;
}

th,
td {
  padding: var(--m-space-3);
  text-align: left;
  border-bottom: 1px solid var(--m-border-default);
  vertical-align: top;
}

.planning__row--active {
  background: color-mix(
    in srgb,
    var(--m-action-primary-bg) 7%,
    var(--m-surface-raised)
  );
}

.planning__row--completed {
  background: color-mix(
    in srgb,
    var(--m-action-secondary-bg) 8%,
    var(--m-surface-raised)
  );
}

.planning__meta {
  display: block;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 400;
}

.planning__status {
  display: inline-block;
  font-weight: 700;
}

.planning__empty,
.planning__note {
  margin: var(--m-space-3) 0 0;
  color: var(--m-text-muted);
}

h2 {
  margin: 0 0 var(--m-space-3);
}

@media (min-width: 48rem) {
  .planning__context,
  .planning__filters {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }

  .planning__summary {
    grid-template-columns: repeat(6, minmax(0, 1fr));
  }
}
</style>
