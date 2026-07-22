<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowHeadingCard from "@/components/WorkflowHeadingCard.vue";
import WorkflowHeadingCardGrid from "@/components/WorkflowHeadingCardGrid.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import { LOCAL_PLANNING_TABLE } from "@/department-ops/fixtures";
import { selectedFixtureDepartment } from "@/department-teams/fixtureDepartmentAccess";
import {
  canEditIncident,
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  resolveIncidentListOpenMode,
  listIncidentsForSession,
  resolveIncidentSession,
  setIncidentListOpenMode,
  statusLabel,
  type IncidentListOpenMode,
  type IncidentPriorityLabel,
  type ImsIncident,
} from "@/ims/incidentReadModel";

const session = computed(() => resolveIncidentSession());
const canView = computed(
  () =>
    selectedFixtureDepartment.value.capabilities.hasIncidentCommand &&
    hasIncidentCommandAccess(session.value),
);
const canEdit = computed(
  () =>
    selectedFixtureDepartment.value.capabilities.hasIncidentCommand &&
    canEditIncident(session.value),
);
const route = useRoute();
const router = useRouter();
const searchQuery = computed(() =>
  typeof route.query.search === "string" ? route.query.search : "",
);
const stateFilter = computed(() =>
  typeof route.query.state === "string" ? route.query.state : "active",
);
const priorityFilter = computed(() =>
  typeof route.query.priority === "string" ? route.query.priority : "all",
);
const shiftFilter = computed(() =>
  route.query.shift === "current" ? "current" : "all",
);
const sortKey = computed(() =>
  typeof route.query.sort === "string" ? route.query.sort : "updated",
);
const sortDirection = computed(() =>
  route.query.direction === "asc" ? "asc" : "desc",
);
const searchDraft = ref(searchQuery.value);
const listOpenMode = ref<IncidentListOpenMode>(resolveIncidentListOpenMode());
const incidents = computed(() => {
  return listIncidentsForSession(session.value, searchQuery.value)
    .filter((incident) => matchesStateFilter(incident, stateFilter.value))
    .filter((incident) => matchesPriorityFilter(incident, priorityFilter.value))
    .filter((incident) => matchesShiftFilter(incident, shiftFilter.value))
    .sort(compareIncidents);
});

watch(searchQuery, (value) => {
  searchDraft.value = value;
});

function priorityText(priorityLabel: string | null): string {
  return priorityLabel ?? "Priority not set";
}

function priorityClass(priorityLabel: IncidentPriorityLabel): string {
  return `ims-list__priority--${priorityLabel.toLowerCase()}`;
}

function typeText(typeNames: readonly string[]): string {
  return typeNames.length > 0 ? typeNames.join(", ") : "Types not set";
}

function activeSortDirection(key: string): "ascending" | "descending" | "none" {
  if (sortKey.value !== key) {
    return "none";
  }

  return sortDirection.value === "asc" ? "ascending" : "descending";
}

function nextSortQuery(key: string) {
  const nextDirection =
    sortKey.value === key && sortDirection.value === "asc" ? "desc" : "asc";

  return {
    ...route.query,
    sort: key,
    direction: nextDirection,
  };
}

function onStateFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: { ...route.query, state: target.value },
  });
}

function onPriorityFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: { ...route.query, priority: target.value },
  });
}

function onShiftFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: {
      ...route.query,
      shift: target.value === "current" ? "current" : undefined,
    },
  });
}

function onListOpenModeChange(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const mode: IncidentListOpenMode = target.value === "edit" ? "edit" : "view";

  listOpenMode.value = mode;
  setIncidentListOpenMode(mode);
}

function incidentOpenTarget(incident: ImsIncident) {
  if (canEdit.value && listOpenMode.value === "edit") {
    return {
      name: "ims.incidents.edit",
      params: { incidentId: incident.id },
    };
  }

  return {
    name: "ims.incidents.show",
    params: { incidentId: incident.id },
  };
}

function matchesStateFilter(incident: ImsIncident, filter: string): boolean {
  if (filter === "all") {
    return true;
  }

  if (filter === "active") {
    return incident.status !== "closed";
  }

  return incident.status === filter;
}

function matchesPriorityFilter(incident: ImsIncident, filter: string): boolean {
  return filter === "all" || incident.priorityLabel === filter;
}

function activeShiftWindow():
  | { readonly startsAt: number; readonly endsAt: number }
  | null {
  const activeShift =
    LOCAL_PLANNING_TABLE.rows.find((row) => row.lifecycle === "active") ?? null;

  if (!activeShift) {
    return null;
  }

  const startsAt = Date.parse(activeShift.startsAt);
  const endsAt = Date.parse(activeShift.endsAt);

  if (Number.isNaN(startsAt) || Number.isNaN(endsAt)) {
    return null;
  }

  return { startsAt, endsAt };
}

function matchesShiftFilter(incident: ImsIncident, filter: string): boolean {
  if (filter !== "current") {
    return true;
  }

  const shiftWindow = activeShiftWindow();
  const createdAt = Date.parse(incident.createdAt);

  if (!shiftWindow || Number.isNaN(createdAt)) {
    return false;
  }

  return createdAt >= shiftWindow.startsAt && createdAt <= shiftWindow.endsAt;
}

function statusSortValue(status: ImsIncident["status"]): number {
  return {
    open: 0,
    on_scene: 1,
    monitoring: 2,
    on_hold: 3,
    closed: 4,
  }[status];
}

function prioritySortValue(priority: IncidentPriorityLabel): number {
  return {
    Critical: 0,
    Serious: 1,
    Important: 2,
    Routine: 3,
  }[priority];
}

function compareText(left: string, right: string): number {
  return left.localeCompare(right, undefined, { sensitivity: "base" });
}

function compareIncidents(left: ImsIncident, right: ImsIncident): number {
  const direction = sortDirection.value === "asc" ? 1 : -1;
  let result = 0;

  switch (sortKey.value) {
    case "incident":
      result =
        compareText(left.incidentNumber, right.incidentNumber) ||
        compareText(left.title, right.title);
      break;
    case "state":
      result = statusSortValue(left.status) - statusSortValue(right.status);
      break;
    case "priority":
      result =
        prioritySortValue(left.priorityLabel) -
        prioritySortValue(right.priorityLabel);
      break;
    case "types":
      result = compareText(
        typeText(left.incidentTypeNames),
        typeText(right.incidentTypeNames),
      );
      break;
    case "location":
      result = compareText(left.locationName ?? "", right.locationName ?? "");
      break;
    case "updated":
    default:
      result = left.updatedAt.localeCompare(right.updatedAt);
      break;
  }

  return result === 0
    ? right.updatedAt.localeCompare(left.updatedAt)
    : result * direction;
}

async function onSearchSubmit(): Promise<void> {
  const search = searchDraft.value.trim();

  await router.push({
    name: "ims.incidents.index",
    query: {
      ...route.query,
      ...(search ? { search } : {}),
      ...(!search ? { search: undefined } : {}),
    },
  });
}
</script>

<template>
  <WorkflowPageShell
    class="ims-list"
    heading-id="ims-incidents-heading"
    title="Incidents"
    :eyebrow="session?.icDepartmentLabel ?? 'Incident Command'"
    lede="Restricted Incident Command workspace for event incident records."
  >
    <template #actions>
      <div class="ims-list__links">
        <WorkflowActionButton
          v-if="canEdit"
          :to="{ name: 'ims.incidents.create' }"
        >
          Create incident
        </WorkflowActionButton>
      </div>
    </template>

    <template #navigation>
      <RouterLink
        v-if="canView"
        class="ims-list__secondary-link"
        :to="{ name: 'ims.field-reports.index' }"
      >
        Field Reports
      </RouterLink>
    </template>

    <template #heading-cards>
      <WorkflowHeadingCardGrid v-if="session">
        <WorkflowHeadingCard
          label="Organization"
          :value="session.organizationLabel"
        />
        <WorkflowHeadingCard label="Event" :value="session.eventLabel" />
        <WorkflowHeadingCard
          label="IC department"
          :value="session.icDepartmentLabel"
        />
        <WorkflowHeadingCard label="Role" :value="session.roleLabel" />
      </WorkflowHeadingCardGrid>
    </template>

    <div v-if="!canView" class="ims-list__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <template v-else>
      <form
        class="ims-list__search-form"
        aria-label="Search incidents"
        @submit.prevent="onSearchSubmit"
      >
        <label for="ims-list-search">Search</label>
        <input
          id="ims-list-search"
          v-model="searchDraft"
          type="search"
          autocomplete="off"
        />
        <button type="submit">Search</button>
        <RouterLink
          v-if="searchQuery"
          class="ims-list__clear-search"
          :to="{ name: 'ims.incidents.index' }"
        >
          Clear
        </RouterLink>
      </form>

      <form class="ims-list__filters" aria-label="Filter incidents">
        <label for="ims-list-state">State</label>
        <select
          id="ims-list-state"
          :value="stateFilter"
          @change="onStateFilterChange"
        >
          <option value="active">Active states</option>
          <option value="open">Open</option>
          <option value="on_scene">On Scene</option>
          <option value="monitoring">Monitoring</option>
          <option value="on_hold">On Hold</option>
          <option value="closed">Closed</option>
          <option value="all">All states</option>
        </select>

        <label for="ims-list-priority">Priority</label>
        <select
          id="ims-list-priority"
          :value="priorityFilter"
          @change="onPriorityFilterChange"
        >
          <option value="all">All priorities</option>
          <option value="Critical">Critical</option>
          <option value="Serious">Serious</option>
          <option value="Important">Important</option>
          <option value="Routine">Routine</option>
        </select>

        <label for="ims-list-shift">Shift</label>
        <select
          id="ims-list-shift"
          :value="shiftFilter"
          @change="onShiftFilterChange"
        >
          <option value="all">All shifts</option>
          <option value="current">Current shift</option>
        </select>

        <label v-if="canEdit" for="ims-list-open-mode">Open incidents as</label>
        <select
          v-if="canEdit"
          id="ims-list-open-mode"
          :value="listOpenMode"
          @change="onListOpenModeChange"
        >
          <option value="view">View</option>
          <option value="edit">Edit</option>
        </select>
      </form>

      <p
        v-if="incidents.length === 0"
        class="ims-list__empty"
        role="status"
      >
        {{
          searchQuery
            ? "No incidents match this search."
            : "No incidents are recorded for this event."
        }}
      </p>

      <div v-else class="ims-list__results">
        <div
          v-if="searchQuery"
          class="ims-list__search-context"
          role="status"
        >
          <span>Search: {{ searchQuery }}</span>
          <RouterLink :to="{ name: 'ims.incidents.index' }">Clear</RouterLink>
        </div>

        <div class="ims-list__table-wrap">
        <table class="ims-list__table">
          <caption>
            Restricted incident list for the configured Incident Command
            department.
          </caption>
          <thead>
            <tr>
              <th scope="col" :aria-sort="activeSortDirection('incident')">
                <RouterLink :to="{ name: 'ims.incidents.index', query: nextSortQuery('incident') }">
                  Incident
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('state')">
                <RouterLink :to="{ name: 'ims.incidents.index', query: nextSortQuery('state') }">
                  State
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('priority')">
                <RouterLink :to="{ name: 'ims.incidents.index', query: nextSortQuery('priority') }">
                  Priority
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('types')">
                <RouterLink :to="{ name: 'ims.incidents.index', query: nextSortQuery('types') }">
                  Types
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('location')">
                <RouterLink :to="{ name: 'ims.incidents.index', query: nextSortQuery('location') }">
                  Location
                </RouterLink>
              </th>
              <th scope="col" :aria-sort="activeSortDirection('updated')">
                <RouterLink :to="{ name: 'ims.incidents.index', query: nextSortQuery('updated') }">
                  Last update
                </RouterLink>
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="incident in incidents" :key="incident.id">
              <th scope="row">
                <RouterLink
                  class="ims-list__incident-link"
                  :to="incidentOpenTarget(incident)"
                >
                  <span>{{ incident.incidentNumber }}</span>
                  <span>{{ incident.title }}</span>
                </RouterLink>
              </th>
              <td>
                <span class="ims-list__status">{{
                  statusLabel(incident.status)
                }}</span>
              </td>
              <td>
                <span
                  class="ims-list__priority"
                  :class="priorityClass(incident.priorityLabel)"
                >
                  {{ priorityText(incident.priorityLabel) }}
                </span>
              </td>
              <td>{{ typeText(incident.incidentTypeNames) }}</td>
              <td>{{ incident.locationName ?? "Location not set" }}</td>
              <td>{{ formatIncidentDateTime(incident.updatedAt) }}</td>
            </tr>
          </tbody>
        </table>
        </div>
      </div>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.ims-list {
  width: 100%;
  display: grid;
  gap: var(--m-space-5);
}

.ims-list__header {
  display: grid;
  gap: var(--m-space-4);
}

.ims-list__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.ims-list__create {
  justify-self: start;
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-weight: 800;
  text-decoration: none;
}

.ims-list__links {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
}

.ims-list__secondary-link {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  color: var(--m-action-secondary-bg);
  font-weight: 800;
  text-decoration: none;
}

.ims-list__eyebrow,
.ims-list__heading,
.ims-list__empty {
  margin: 0;
}

.ims-list__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
}

.ims-list__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.ims-list__context {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
  gap: var(--m-space-3);
  margin: 0;
}

.ims-list__context div {
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__context dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__context dd {
  margin: var(--m-space-1) 0 0;
}

.ims-list__restricted,
.ims-list__empty {
  color: var(--m-text-muted);
}

.ims-list__restricted a,
.ims-list__incident-link,
.ims-list__search-context a,
.ims-list__clear-search,
.ims-list__table thead a {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}

.ims-list__create:focus-visible,
.ims-list__nav a:focus-visible,
.ims-list__secondary-link:focus-visible,
.ims-list__search-form input:focus-visible,
.ims-list__search-form button:focus-visible,
.ims-list__filters select:focus-visible,
.ims-list__clear-search:focus-visible,
.ims-list__table thead a:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-list__search-form,
.ims-list__filters {
  display: grid;
  grid-template-columns: minmax(12rem, 1fr) auto auto;
  gap: var(--m-space-2);
  align-items: end;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__filters {
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}

.ims-list__search-form label,
.ims-list__filters label {
  grid-column: 1 / -1;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-list__filters label {
  grid-column: auto;
  align-self: center;
}

.ims-list__search-form input,
.ims-list__filters select {
  min-width: 0;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  background: var(--m-surface-primary);
  color: var(--m-text-primary);
  font: inherit;
}

.ims-list__search-form button {
  border: 1px solid var(--m-action-secondary-bg);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
  font-weight: 800;
}

.ims-list__clear-search {
  align-self: center;
}

.ims-list__results {
  display: grid;
  gap: var(--m-space-3);
}

.ims-list__search-context {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__table {
  width: 100%;
  min-width: 48rem;
  border-collapse: collapse;
}

.ims-list__table caption {
  padding: var(--m-space-3);
  color: var(--m-text-secondary);
  text-align: left;
}

.ims-list__table th,
.ims-list__table td {
  padding: var(--m-space-3);
  border-top: 1px solid var(--m-border-default);
  text-align: left;
  vertical-align: top;
}

.ims-list__table thead th {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.ims-list__table thead a {
  text-decoration: none;
}

.ims-list__incident-link {
  display: grid;
  gap: var(--m-space-1);
  color: var(--m-text-primary);
  text-decoration: none;
}

.ims-list__incident-link span:first-child,
.ims-list__status {
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__incident-link span:first-child {
  color: var(--m-text-secondary);
}

.ims-list__incident-link span:last-child {
  color: var(--m-text-primary);
  font-weight: 800;
}

.ims-list__priority {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-list__priority--routine {
  border-color: var(--m-status-neutral);
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
}

.ims-list__priority--important {
  border-color: var(--m-attention-attention);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
}

.ims-list__priority--serious {
  border-color: var(--m-attention-warning);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
}

.ims-list__priority--critical {
  border-color: var(--m-attention-critical);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
}

.ims-list__status {
  display: inline-block;
  padding: 0.15rem 0.45rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-text-primary);
}

@media (max-width: 43.99rem) {
  .ims-list__search-form,
  .ims-list__filters {
    grid-template-columns: 1fr;
  }

  .ims-list__clear-search {
    justify-self: start;
  }

  .ims-list__table-wrap {
    border: 0;
    background: transparent;
  }

  .ims-list__table {
    min-width: 0;
    border-collapse: separate;
    border-spacing: 0 var(--m-space-3);
  }

  .ims-list__table caption,
  .ims-list__table thead {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
  }

  .ims-list__table tr {
    display: grid;
    gap: var(--m-space-2);
    padding: var(--m-space-3);
    border: 1px solid var(--m-border-default);
    border-radius: var(--m-radius-sm);
    background: var(--m-surface-raised);
  }

  .ims-list__table th,
  .ims-list__table td {
    padding: 0;
    border-top: 0;
  }
}
</style>
