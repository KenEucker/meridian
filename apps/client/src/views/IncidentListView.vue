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
  DEFAULT_INCIDENT_LIST_PAGE_SIZE,
  deleteIncidentPresetForSession,
  formatIncidentDateTime,
  hasIncidentCommandAccess,
  INCIDENT_LIST_PAGE_SIZES,
  INCIDENT_LIST_PRESET_NAME_MAX_LENGTH,
  incidentResponderOptionsForSession,
  incidentTypeOptionsForSession,
  listIncidentPresetsForSession,
  resolveIncidentListOpenMode,
  listIncidentsForSession,
  resolveIncidentSession,
  saveIncidentPresetForSession,
  setIncidentListOpenMode,
  statusLabel,
  type IncidentListFilterSelection,
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
const typeFilter = computed(() =>
  typeof route.query.type === "string" ? route.query.type : "all",
);
const responderFilter = computed(() =>
  typeof route.query.responder === "string" ? route.query.responder : "all",
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
const typeOptions = computed(() => incidentTypeOptionsForSession(session.value));
const responderOptions = computed(() =>
  incidentResponderOptionsForSession(session.value),
);
const incidents = computed(() => {
  return listIncidentsForSession(session.value, searchQuery.value)
    .filter((incident) => matchesStateFilter(incident, stateFilter.value))
    .filter((incident) => matchesPriorityFilter(incident, priorityFilter.value))
    .filter((incident) => matchesTypeFilter(incident, typeFilter.value))
    .filter((incident) =>
      matchesResponderFilter(incident, responderFilter.value),
    )
    .filter((incident) => matchesShiftFilter(incident, shiftFilter.value))
    .sort(compareIncidents);
});
const hasNarrowedList = computed(
  () =>
    searchQuery.value !== "" ||
    stateFilter.value !== "active" ||
    priorityFilter.value !== "all" ||
    typeFilter.value !== "all" ||
    responderFilter.value !== "all" ||
    shiftFilter.value !== "all",
);
const pageSize = computed(() => {
  const requested = Number.parseInt(String(route.query.per_page ?? ""), 10);

  return INCIDENT_LIST_PAGE_SIZES.includes(requested)
    ? requested
    : DEFAULT_INCIDENT_LIST_PAGE_SIZE;
});
const totalPages = computed(() =>
  Math.max(1, Math.ceil(incidents.value.length / pageSize.value)),
);
const currentPage = computed(() => {
  const requested = Number.parseInt(String(route.query.page ?? ""), 10);

  if (!Number.isFinite(requested) || requested < 1) {
    return 1;
  }

  return Math.min(requested, totalPages.value);
});
const pagedIncidents = computed(() => {
  const start = (currentPage.value - 1) * pageSize.value;

  return incidents.value.slice(start, start + pageSize.value);
});
const resultSummary = computed(() => {
  const total = incidents.value.length;
  const matched =
    total === 1
      ? "1 incident matches the current filters."
      : `${total} incidents match the current filters.`;

  return totalPages.value > 1
    ? `${matched} Showing ${pagedIncidents.value.length} on page ${currentPage.value} of ${totalPages.value}.`
    : matched;
});
const currentSelection = computed<IncidentListFilterSelection>(() => ({
  search: searchQuery.value,
  state: stateFilter.value,
  priority: priorityFilter.value,
  type: typeFilter.value,
  responder: responderFilter.value,
  shift: shiftFilter.value,
  sort: sortKey.value,
  direction: sortDirection.value,
}));
const presets = ref(listIncidentPresetsForSession(session.value));
const presetNameDraft = ref("");
const presetError = ref("");
const matchingPreset = computed(
  () =>
    presets.value.find(
      (preset) =>
        preset.name.toLowerCase() === presetNameDraft.value.trim().toLowerCase(),
    ) ?? null,
);

watch(searchQuery, (value) => {
  searchDraft.value = value;
});

watch(session, () => {
  refreshPresets();
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
    page: undefined,
  };
}

function onStateFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: { ...route.query, state: target.value, page: undefined },
  });
}

function onPriorityFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: { ...route.query, priority: target.value, page: undefined },
  });
}

function refreshPresets(): void {
  presets.value = listIncidentPresetsForSession(session.value);
}

function onPresetApply(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const preset = presets.value.find((candidate) => candidate.id === target.value);

  presetError.value = "";

  if (!preset) {
    return;
  }

  presetNameDraft.value = preset.name;

  // A preset replaces the whole selection rather than merging into it, and
  // always lands on the first page of its own result.
  void router.push({
    name: "ims.incidents.index",
    query: { ...preset.query },
  });
}

function onPresetSave(): void {
  presetError.value = "";

  try {
    const preset = saveIncidentPresetForSession(
      session.value,
      presetNameDraft.value,
      currentSelection.value,
    );

    presetNameDraft.value = preset.name;
    refreshPresets();
  } catch (error) {
    presetError.value =
      error instanceof Error ? error.message : "Unable to save this preset.";
  }
}

function onPresetDelete(): void {
  const preset = matchingPreset.value;
  presetError.value = "";

  if (!preset) {
    return;
  }

  try {
    deleteIncidentPresetForSession(session.value, preset.id);
    presetNameDraft.value = "";
    refreshPresets();
  } catch (error) {
    presetError.value =
      error instanceof Error ? error.message : "Unable to delete this preset.";
  }
}

function onPageSizeChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: {
      ...route.query,
      per_page:
        Number.parseInt(target.value, 10) === DEFAULT_INCIDENT_LIST_PAGE_SIZE
          ? undefined
          : target.value,
      page: undefined,
    },
  });
}

function pageQuery(page: number) {
  return {
    ...route.query,
    page: page <= 1 ? undefined : String(page),
  };
}

function onTypeFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: {
      ...route.query,
      type: target.value === "all" ? undefined : target.value,
      page: undefined,
    },
  });
}

function onResponderFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: {
      ...route.query,
      responder: target.value === "all" ? undefined : target.value,
      page: undefined,
    },
  });
}

function onShiftFilterChange(event: Event): void {
  const target = event.target as HTMLSelectElement;

  void router.push({
    name: "ims.incidents.index",
    query: {
      ...route.query,
      shift: target.value === "current" ? "current" : undefined,
      page: undefined,
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

function matchesTypeFilter(incident: ImsIncident, filter: string): boolean {
  return (
    filter === "all" ||
    incident.incidentTypeNames.some(
      (name) => name.toLowerCase() === filter.toLowerCase(),
    )
  );
}

function matchesResponderFilter(incident: ImsIncident, filter: string): boolean {
  return (
    filter === "all" ||
    incident.responders.some((responder) => responder.staffId === filter)
  );
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
      page: undefined,
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

        <label for="ims-list-type">Type</label>
        <select
          id="ims-list-type"
          :value="typeFilter"
          @change="onTypeFilterChange"
        >
          <option value="all">All types</option>
          <option v-for="name in typeOptions" :key="name" :value="name">
            {{ name }}
          </option>
        </select>

        <label for="ims-list-responder">Responder</label>
        <select
          id="ims-list-responder"
          :value="responderFilter"
          @change="onResponderFilterChange"
        >
          <option value="all">All responders</option>
          <option
            v-for="responder in responderOptions"
            :key="responder.staffId"
            :value="responder.staffId"
          >
            {{ responder.displayName }}
          </option>
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

        <label for="ims-list-page-size">Per page</label>
        <select
          id="ims-list-page-size"
          :value="String(pageSize)"
          @change="onPageSizeChange"
        >
          <option v-for="size in INCIDENT_LIST_PAGE_SIZES" :key="size" :value="String(size)">
            {{ size }}
          </option>
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

      <form
        class="ims-list__presets"
        aria-label="Saved incident list presets"
        @submit.prevent="onPresetSave"
      >
        <label for="ims-list-preset">Saved presets</label>
        <select
          id="ims-list-preset"
          :value="matchingPreset?.id ?? ''"
          @change="onPresetApply"
        >
          <option value="">
            {{
              presets.length === 0
                ? "No saved presets"
                : "Apply a saved preset"
            }}
          </option>
          <option v-for="preset in presets" :key="preset.id" :value="preset.id">
            {{ preset.name }}
          </option>
        </select>

        <label for="ims-list-preset-name">Preset name</label>
        <input
          id="ims-list-preset-name"
          v-model="presetNameDraft"
          type="text"
          autocomplete="off"
          :maxlength="INCIDENT_LIST_PRESET_NAME_MAX_LENGTH"
        />

        <button type="submit">
          {{ matchingPreset ? "Update preset" : "Save preset" }}
        </button>
        <button v-if="matchingPreset" type="button" @click="onPresetDelete">
          Delete preset
        </button>
      </form>

      <p v-if="presetError" class="ims-list__preset-error" role="alert">
        {{ presetError }}
      </p>

      <p class="ims-list__summary" role="status">
        <span>{{ resultSummary }}</span>
        <RouterLink
          v-if="hasNarrowedList"
          class="ims-list__reset-filters"
          :to="{ name: 'ims.incidents.index' }"
        >
          Reset filters
        </RouterLink>
      </p>

      <p v-if="incidents.length === 0" class="ims-list__empty">
        {{
          hasNarrowedList
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
            <tr v-for="incident in pagedIncidents" :key="incident.id">
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

        <nav
          v-if="totalPages > 1"
          class="ims-list__pagination"
          aria-label="Incident list pages"
        >
          <RouterLink
            v-if="currentPage > 1"
            class="ims-list__page-link"
            :to="{ name: 'ims.incidents.index', query: pageQuery(currentPage - 1) }"
            rel="prev"
          >
            Previous
          </RouterLink>
          <span v-else class="ims-list__page-link ims-list__page-link--disabled">
            Previous
          </span>

          <span class="ims-list__page-position">
            Page {{ currentPage }} of {{ totalPages }}
          </span>

          <RouterLink
            v-if="currentPage < totalPages"
            class="ims-list__page-link"
            :to="{ name: 'ims.incidents.index', query: pageQuery(currentPage + 1) }"
            rel="next"
          >
            Next
          </RouterLink>
          <span v-else class="ims-list__page-link ims-list__page-link--disabled">
            Next
          </span>
        </nav>
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

.ims-list__summary {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__restricted a,
.ims-list__incident-link,
.ims-list__search-context a,
.ims-list__reset-filters,
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
.ims-list__presets select:focus-visible,
.ims-list__presets input:focus-visible,
.ims-list__presets button:focus-visible,
.ims-list__page-link:focus-visible,
.ims-list__reset-filters:focus-visible,
.ims-list__clear-search:focus-visible,
.ims-list__table thead a:focus-visible {
  outline: 3px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-list__pagination {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  align-items: center;
  justify-content: center;
  padding: var(--m-space-2);
}

.ims-list__page-position {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__page-link {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  color: var(--m-action-secondary-bg);
  font-weight: 800;
  text-decoration: none;
}

.ims-list__page-link--disabled {
  border-color: var(--m-border-default);
  color: var(--m-text-muted);
}

.ims-list__preset-error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.ims-list__presets button {
  border: 1px solid var(--m-action-secondary-bg);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-4);
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
  font-weight: 800;
}

.ims-list__presets button[type="button"] {
  background: var(--m-surface-primary);
  color: var(--m-action-secondary-bg);
}

.ims-list__search-form,
.ims-list__filters,
.ims-list__presets {
  display: grid;
  grid-template-columns: minmax(12rem, 1fr) auto auto;
  gap: var(--m-space-2);
  align-items: end;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__filters,
.ims-list__presets {
  grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr));
}

.ims-list__search-form label,
.ims-list__filters label,
.ims-list__presets label {
  grid-column: 1 / -1;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.ims-list__filters label,
.ims-list__presets label {
  grid-column: auto;
  align-self: center;
}

.ims-list__search-form input,
.ims-list__filters select,
.ims-list__presets select,
.ims-list__presets input {
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
  .ims-list__filters,
  .ims-list__presets {
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
