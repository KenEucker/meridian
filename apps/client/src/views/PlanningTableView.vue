<script setup lang="ts">
import ContentGrid from "@/components/ContentGrid.vue";
import ControlBar from "@/components/ControlBar.vue";
import { computed, ref, watch, type CSSProperties } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowHeadingCard from "@/components/WorkflowHeadingCard.vue";
import WorkflowHeadingCardGrid from "@/components/WorkflowHeadingCardGrid.vue";
import ShiftListSection from "@/components/sections/ShiftListSection.vue";
import StaleReadNotice from "@/components/StaleReadNotice.vue";
import TrainingListSection from "@/components/sections/TrainingListSection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { formatTimestamp, lifecycleLabel } from "@/department-ops/labels";
import {
  assertNoStaffIdentities,
  capacityLabel,
  dateKeyForTimestamp,
  getPlanningTable,
  planningSummary,
  signedVarianceLabel,
  type PlanningTableRead,
} from "@/department-ops/departmentOpsReadModel";
import { useDepartmentOpsModules } from "@/department-ops/departmentOpsModules";

/**
 * The Planning Table (SLB-019, SLB-020; bound to the node in M16.21).
 *
 * Every aggregate on this page is the node's arithmetic now: capacity against
 * assignments, planned hours against recorded hours, and the variance between
 * them. The client used to compute all of it over a fixture, which meant the
 * numbers a lead planned against were arithmetic on data no shift had produced.
 *
 * The team and date filters go to the node rather than narrowing rows already
 * here, so the counts on screen are that view's counts (SLB-020).
 *
 * The shift drill-down that used to list who was scheduled is gone. It named
 * individual staff on a surface SLB-019 says must not expose individual
 * identities, signup lists, or team-member lists — it read them out of the
 * fixture's workspaces, and the read that replaced that fixture deliberately
 * carries none. What a selected shift shows now is its own aggregates.
 */
const route = useRoute();
const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));
const modules = useDepartmentOpsModules(departmentId);

const table = ref<PlanningTableRead | null>(null);
const loadError = ref<string | null>(null);
const selectedTeamId = ref("");
const selectedDate = ref("");
const selectedShiftId = ref("");

const departmentLabel = computed(
  () => table.value?.context.departmentLabel ?? "Department",
);
const timeZone = computed(() => table.value?.context.timeZone ?? "UTC");
const availableTeams = computed(() => table.value?.teams ?? []);
const visibleRows = computed(() => table.value?.rows ?? []);
const summary = computed(() => planningSummary(visibleRows.value));
const availableDates = computed(() =>
  Array.from(
    new Set(
      visibleRows.value.map((row) =>
        dateKeyForTimestamp(row.startsAt, timeZone.value),
      ),
    ),
  ),
);

/**
 * Read the aggregates for the current filters.
 *
 * `assertNoStaffIdentities` runs on what came back rather than on a constant:
 * this is a surface a name must never reach, and the check belongs where the
 * data enters the page.
 */
async function loadPlanning(): Promise<void> {
  if (eventId.value === "" || departmentId.value === "") {
    table.value = null;

    return;
  }

  loadError.value = null;

  try {
    const read = await getPlanningTable(eventId.value, departmentId.value, {
      teamId: selectedTeamId.value === "" ? null : selectedTeamId.value,
      date: selectedDate.value === "" ? null : selectedDate.value,
    });

    assertNoStaffIdentities(read.rows);
    table.value = read;

    if (!read.rows.some((row) => row.shiftId === selectedShiftId.value)) {
      selectedShiftId.value =
        read.rows.find((row) => row.lifecycle === "active")?.shiftId ??
        read.rows[0]?.shiftId ??
        "";
    }
  } catch (error) {
    table.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load the planning table. Check the connection to this node and try again.",
    );
  }
}

watch([eventId, departmentId, selectedTeamId, selectedDate], () => {
  void loadPlanning();
});

void loadPlanning();
const selectedShift = computed(
  () =>
    visibleRows.value.find((row) => row.shiftId === selectedShiftId.value) ??
    visibleRows.value[0] ??
    null,
);
const chartWindow = computed(() => {
  const starts = visibleRows.value
    .map((row) => Date.parse(row.startsAt))
    .filter((value) => !Number.isNaN(value));
  const ends = visibleRows.value
    .map((row) => Date.parse(row.endsAt))
    .filter((value) => !Number.isNaN(value));

  if (starts.length === 0 || ends.length === 0) {
    return null;
  }

  const startsAt = Math.min(...starts);
  const endsAt = Math.max(...ends);

  return {
    startsAt,
    endsAt,
    duration: Math.max(endsAt - startsAt, 1),
  };
});
const timelineMarkers = computed(() => {
  const window = chartWindow.value;

  if (!window) {
    return [];
  }

  return Array.from(
    { length: 5 },
    (_, index) => window.startsAt + (window.duration * index) / 4,
  );
});
const nowMarkerStyle = computed(() => {
  const window = chartWindow.value;
  const asOf = Date.parse(table.value?.context.asOf ?? "");

  if (
    !window ||
    Number.isNaN(asOf) ||
    asOf < window.startsAt ||
    asOf > window.endsAt
  ) {
    return null;
  }

  return {
    "--marker-left": `${(((asOf - window.startsAt) / window.duration) * 100).toFixed(3)}%`,
  } as CSSProperties;
});
/**
 * What the selected shift's bar is worth reading about, without naming anybody.
 *
 * Counts and hours, which is exactly what SLB-019 leaves on this surface. A lead
 * who needs to know who is on the shift opens the Department Overview, where
 * naming people is the point.
 */
const selectedShiftFigures = computed(() => {
  const shift = selectedShift.value;

  if (!shift) {
    return [];
  }

  return [
    { id: "capacity", label: "Capacity", value: capacityLabel(shift.capacity) },
    {
      id: "assigned",
      label: "Signed up / assigned",
      value: String(shift.signedUpOrAssignedCount),
    },
    { id: "checked-in", label: "Checked in", value: String(shift.checkedInCount) },
    { id: "no-show", label: "No-show", value: String(shift.noShowCount) },
    {
      id: "unscheduled",
      label: "Unscheduled additions",
      value: String(shift.unscheduledCount),
    },
    {
      id: "planned",
      label: "Planned hours",
      value: String(shift.plannedHours),
    },
    { id: "actual", label: "Actual hours", value: String(shift.actualHours) },
    {
      id: "variance",
      label: "Variance",
      value: signedVarianceLabel(shift.varianceHours),
    },
  ];
});

function selectShift(shiftId: string): void {
  selectedShiftId.value = shiftId;
}

function timelineStyle(row: {
  readonly startsAt: string;
  readonly endsAt: string;
}): CSSProperties {
  const window = chartWindow.value;
  const startsAt = Date.parse(row.startsAt);
  const endsAt = Date.parse(row.endsAt);

  if (!window || Number.isNaN(startsAt) || Number.isNaN(endsAt)) {
    return {
      "--bar-left": "0%",
      "--bar-width": "0%",
    } as CSSProperties;
  }

  return {
    "--bar-left": `${(((startsAt - window.startsAt) / window.duration) * 100).toFixed(3)}%`,
    "--bar-width": `${Math.max(
      ((endsAt - startsAt) / window.duration) * 100,
      1,
    ).toFixed(3)}%`,
  } as CSSProperties;
}

function timelineMarkerStyle(timestamp: number): CSSProperties {
  const window = chartWindow.value;

  if (!window) {
    return {
      "--marker-left": "0%",
    } as CSSProperties;
  }

  return {
    "--marker-left": `${(((timestamp - window.startsAt) / window.duration) * 100).toFixed(3)}%`,
  } as CSSProperties;
}

function formatTimelineMarker(timestamp: number): string {
  return new Intl.DateTimeFormat("en-US", {
    hour: "numeric",
    minute: "2-digit",
    timeZone: timeZone.value,
  }).format(new Date(timestamp));
}
</script>

<template>
  <DeptOpsShell
    title="Planning Table"
    :eyebrow="departmentLabel"
    lede="Identity-free comparison of what was planned and how the department is tracking."
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #actions>
      <div class="planning__heading-actions">
        <WorkflowActionButton disabled>Add position</WorkflowActionButton>
      </div>
    </template>

    <template v-if="modules.scheduling.value" #heading-cards>
      <WorkflowHeadingCardGrid>
        <WorkflowHeadingCard
          label="Shift windows"
          :value="String(summary.shiftCount)"
        />
        <WorkflowHeadingCard
          label="Active now"
          :value="String(summary.activeCount)"
        />
        <WorkflowHeadingCard
          label="Under target"
          :value="String(summary.underTargetCount)"
        />
        <WorkflowHeadingCard
          label="Completed"
          :value="String(summary.completedCount)"
        />
        <WorkflowHeadingCard
          label="Planned hours"
          :value="String(summary.plannedHours)"
        />
        <WorkflowHeadingCard
          label="Actual hours"
          :value="String(summary.actualHours)"
        />
      </WorkflowHeadingCardGrid>
    </template>

    <p v-if="loadError" class="planning__error" role="alert">
      {{ loadError }}
      <button type="button" @click="loadPlanning">Try again</button>
    </p>

    <StaleReadNotice
      v-if="table"
      :freshness="table.freshness"
      :time-zone="timeZone"
      label="This table"
    />

    <!--
      Every row on this surface is a shift, so an organization that does not run
      Scheduling has nothing to plan against. The page opens and says so rather
      than rendering a chart, a filter bar, and a table with nothing in any of
      them, which would read as a department that has scheduled no work
      (MOD-019, CLIENT-005). This is a statement about the organization, not a
      refusal: the address resolves and no read is denied.
    -->
    <p
      v-if="!modules.scheduling.value"
      class="planning__empty"
      role="status"
    >
      This organization does not use Scheduling, so there are no shift windows
      to plan against. Attendance, hours, and credits are recorded without them.
    </p>

    <div
      v-if="modules.scheduling.value"
      class="planning__boardbar"
      aria-label="Board controls"
    >
      <span>{{ summary.shiftCount }} shift windows</span>
      <span v-if="table">
        Read {{ formatTimestamp(table.context.asOf, timeZone) }}
      </span>
      <div>
        <button type="button" disabled>Collapse all</button>
      </div>
    </div>

    <ControlBar v-if="modules.scheduling.value" label="Planning filters">
      <form data-control-group aria-label="Planning filters">
        <label>
          <span>Team filter</span>
          <select v-model="selectedTeamId">
            <option value="">All teams</option>
            <option
              v-for="team in availableTeams"
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
    </ControlBar>

    <!--
      The chart and the detail for the shift picked in it are peers, and the
      pairing is the point: choosing a bar to read its staffing should not push
      the answer below the fold. They sit side by side once each has room.
    -->
    <ContentGrid v-if="modules.scheduling.value" min="region" :stretch="false">
      <section class="planning__gantt" aria-labelledby="planning-gantt-heading">
        <header class="planning__section-header">
          <div>
            <h2 id="planning-gantt-heading">Scheduled shifts</h2>
            <p>Select a shift to inspect scheduled staff and timing.</p>
          </div>
          <span>{{ visibleRows.length }} visible</span>
        </header>

        <div v-if="visibleRows.length > 0" class="planning__gantt-frame">
          <div class="planning__time-scale" aria-hidden="true">
            <span></span>
            <ol>
              <li
                v-for="marker in timelineMarkers"
                :key="marker"
                :style="timelineMarkerStyle(marker)"
              >
                {{ formatTimelineMarker(marker) }}
              </li>
            </ol>
          </div>
          <div class="planning__gantt-body">
            <button
              v-for="row in visibleRows"
              :key="row.shiftId"
              type="button"
              class="planning__gantt-row"
              :class="`planning__gantt-row--${row.lifecycle}`"
              :data-selected="selectedShift?.shiftId === row.shiftId"
              @click="selectShift(row.shiftId)"
            >
              <span class="planning__gantt-label">
                <strong>{{ row.title }}</strong>
                <small>
                  {{ row.teamLabel }} / {{ lifecycleLabel(row.lifecycle) }}
                </small>
              </span>
              <span class="planning__gantt-track">
                <span
                  v-if="nowMarkerStyle"
                  class="planning__now-marker"
                  :style="nowMarkerStyle"
                ></span>
                <span class="planning__gantt-bar" :style="timelineStyle(row)">
                  <span>{{ row.title }}</span>
                </span>
              </span>
            </button>
          </div>
        </div>
        <p v-else class="planning__empty" role="status">
          No shift windows match the current filters.
        </p>
      </section>

      <section
        class="planning__drilldown"
        aria-labelledby="planning-shift-detail-heading"
      >
        <header class="planning__section-header">
          <div>
            <h2 id="planning-shift-detail-heading">Shift detail</h2>
            <p v-if="selectedShift">
              {{ selectedShift.title }} /
              {{ formatTimestamp(selectedShift.startsAt, timeZone) }}
              to
              {{ formatTimestamp(selectedShift.endsAt, timeZone) }}
            </p>
          </div>
          <span v-if="selectedShift">{{
            lifecycleLabel(selectedShift.lifecycle)
          }}</span>
        </header>

        <!--
          Counts, not people. SLB-019 keeps individual identities, signup lists,
          and team-member lists off this surface, and the read behind it carries
          none to put here.
        -->
        <dl v-if="selectedShift" class="planning__figures">
          <div v-for="figure in selectedShiftFigures" :key="figure.id">
            <dt>{{ figure.label }}</dt>
            <dd>{{ figure.value }}</dd>
          </div>
        </dl>
        <p v-else class="planning__empty" role="status">
          Select a shift window to read its coverage.
        </p>
      </section>
    </ContentGrid>

    <section
      v-if="modules.scheduling.value"
      class="planning__table-section"
      aria-labelledby="planning-table-heading"
    >
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
                  {{ formatTimestamp(row.startsAt, timeZone) }}
                  to
                  {{ formatTimestamp(row.endsAt, timeZone) }}
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
        Aggregate rows remain identity-free. Who is on a shift is the Department
        Overview's answer, not this one.
      </p>
    </section>

    <!--
      Planning is the forward-looking hub: the identity-free coverage table
      above, the shifts that coverage is built from, and the trainings that
      gate eligibility for them.
    -->
    <ContentGrid min="region" :stretch="false">
      <ShiftListSection variant="section" />
      <TrainingListSection variant="section" />
    </ContentGrid>
  </DeptOpsShell>
</template>

<style scoped>
.planning__filters {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
  max-width: 100%;
  margin: 0 0 var(--m-space-5);
}

.planning__boardbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-3);
  min-width: 0;
  max-width: 100%;
  margin: 0 0 var(--m-space-5);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.planning__boardbar > span {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.planning__heading-actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.planning__boardbar div {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.planning__boardbar button {
  min-height: 2.25rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 6px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.planning__boardbar button:disabled {
  opacity: 0.58;
}

.planning__filters {
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
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
  border-radius: 6px;
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

.planning__gantt,
.planning__drilldown {
  min-width: 0;
  max-width: 100%;
  margin: 0 0 var(--m-space-5);
}

.planning__section-header {
  display: flex;
  flex-wrap: wrap;
  align-items: end;
  justify-content: space-between;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-3);
}

.planning__section-header h2,
.planning__section-header p {
  margin: 0;
}

.planning__section-header p {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.planning__section-header > span {
  display: inline-flex;
  align-items: center;
  min-height: 1.8rem;
  padding: 0 var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.planning__gantt-frame {
  max-width: 100%;
  min-width: 0;
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.planning__time-scale,
.planning__gantt-row {
  display: grid;
  grid-template-columns: minmax(11rem, 15rem) minmax(32rem, 1fr);
  min-width: 48rem;
}

.planning__time-scale {
  min-height: 2.4rem;
  border-bottom: 1px solid var(--m-border-default);
  background: var(--m-surface-base);
}

.planning__time-scale > span {
  border-right: 1px solid var(--m-border-default);
}

.planning__time-scale ol {
  position: relative;
  min-height: 2.4rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.planning__time-scale li {
  position: absolute;
  left: var(--marker-left);
  top: 50%;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  transform: translate(-50%, -50%);
  white-space: nowrap;
}

.planning__gantt-body {
  display: grid;
}

.planning__gantt-row {
  min-height: 3.05rem;
  padding: 0;
  border: 0;
  border-bottom: 1px solid var(--m-border-default);
  background: transparent;
  color: var(--m-text-primary);
  cursor: pointer;
  font: inherit;
  text-align: left;
}

.planning__gantt-row:last-child {
  border-bottom: 0;
}

.planning__gantt-row[data-selected="true"] {
  background: color-mix(
    in srgb,
    var(--m-action-secondary-bg) 10%,
    transparent
  );
}

.planning__gantt-row:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: -2px;
}

.planning__gantt-label {
  display: grid;
  align-content: center;
  gap: 0.2rem;
  min-width: 0;
  padding: var(--m-space-2) var(--m-space-3);
  border-right: 1px solid var(--m-border-default);
}

.planning__gantt-label strong,
.planning__gantt-label small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.planning__gantt-label strong {
  font-size: var(--m-text-sm);
}

.planning__gantt-label small {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
  text-transform: uppercase;
}

.planning__gantt-track {
  position: relative;
  min-height: 3.05rem;
  background:
    repeating-linear-gradient(
      90deg,
      transparent 0,
      transparent calc(25% - 1px),
      var(--m-border-subtle) calc(25% - 1px),
      var(--m-border-subtle) 25%
    ),
    color-mix(in srgb, var(--m-surface-base) 56%, transparent);
}

.planning__gantt-bar {
  position: absolute;
  left: var(--bar-left);
  top: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  width: var(--bar-width);
  min-width: 4rem;
  min-height: 1.5rem;
  padding: 0 var(--m-space-2);
  border: 1px solid color-mix(in srgb, var(--m-status-neutral) 58%, transparent);
  border-radius: 5px;
  background: color-mix(
    in srgb,
    var(--m-status-neutral) 40%,
    var(--m-surface-raised)
  );
  color: var(--m-text-primary);
  font-size: var(--m-text-xs);
  font-weight: 800;
  transform: translateY(-50%);
}

.planning__gantt-bar span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.planning__gantt-row--active .planning__gantt-bar {
  border-color: color-mix(in srgb, var(--m-status-success) 58%, transparent);
  background: color-mix(
    in srgb,
    var(--m-status-success) 34%,
    var(--m-surface-raised)
  );
}

.planning__gantt-row--upcoming .planning__gantt-bar {
  border-color: color-mix(in srgb, var(--m-status-warning) 54%, transparent);
  background: color-mix(
    in srgb,
    var(--m-status-warning) 28%,
    var(--m-surface-raised)
  );
}

.planning__gantt-row--completed .planning__gantt-bar {
  border-color: color-mix(in srgb, var(--m-status-neutral) 50%, transparent);
  background: color-mix(
    in srgb,
    var(--m-status-neutral) 24%,
    var(--m-surface-raised)
  );
}

.planning__now-marker {
  position: absolute;
  left: var(--marker-left);
  top: 0;
  bottom: 0;
  z-index: 2;
  width: 2px;
  background: color-mix(
    in srgb,
    var(--m-action-primary-bg) 58%,
    var(--m-text-muted)
  );
}

.planning__figures {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  gap: var(--m-space-2);
  margin: 0;
}

.planning__figures div {
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.planning__figures dt {
  margin: 0 0 var(--m-space-1);
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.planning__figures dd {
  margin: 0;
  color: var(--m-text-primary);
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.planning__error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
  padding: var(--m-space-3);
  border: 1px solid var(--m-status-danger, #cc792f);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-status-danger, #cc792f);
}

.planning__table-section {
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
}

.planning__table-frame {
  width: 100%;
  max-width: 100%;
  min-width: 0;
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

table {
  width: 100%;
  min-width: 72rem;
  border-collapse: collapse;
}

th,
td {
  padding: var(--m-space-2) var(--m-space-3);
  text-align: left;
  border-bottom: 1px solid var(--m-border-default);
  vertical-align: top;
  overflow-wrap: anywhere;
}

thead th {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  text-transform: uppercase;
  white-space: nowrap;
}

tbody th {
  color: var(--m-text-primary);
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
  display: inline-flex;
  align-items: center;
  padding: 0.15rem var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-base);
  font-weight: 700;
  white-space: nowrap;
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
  .planning__filters {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 47.99rem) {
  .planning__table-frame {
    border: 0;
    background: transparent;
  }

  table,
  tbody,
  tr,
  th,
  td {
    display: block;
    width: 100%;
  }

  table {
    min-width: 0;
  }

  thead {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }

  tbody tr {
    display: grid;
    gap: var(--m-space-2);
    margin-bottom: var(--m-space-3);
    padding: var(--m-space-3);
    border: 1px solid var(--m-border-default);
    border-radius: 8px;
    background: var(--m-surface-raised);
  }

  tbody th,
  tbody td {
    padding: 0;
    border-bottom: 0;
  }

  tbody td {
    display: grid;
    grid-template-columns: minmax(7rem, auto) minmax(0, 1fr);
    gap: var(--m-space-2);
  }

  tbody td::before {
    color: var(--m-text-secondary);
    font-size: var(--m-text-xs);
    font-weight: 900;
    text-transform: uppercase;
  }

  tbody td:nth-of-type(1)::before {
    content: "Lifecycle";
  }

  tbody td:nth-of-type(2)::before {
    content: "Capacity";
  }

  tbody td:nth-of-type(3)::before {
    content: "Signed up";
  }

  tbody td:nth-of-type(4)::before {
    content: "Checked in";
  }

  tbody td:nth-of-type(5)::before {
    content: "No-show";
  }

  tbody td:nth-of-type(6)::before {
    content: "Unscheduled";
  }

  tbody td:nth-of-type(7)::before {
    content: "Planned";
  }

  tbody td:nth-of-type(8)::before {
    content: "Actual";
  }

  tbody td:nth-of-type(9)::before {
    content: "Variance";
  }

  tbody td:nth-of-type(10)::before {
    content: "Status";
  }
}
</style>
