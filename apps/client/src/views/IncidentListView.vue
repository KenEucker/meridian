<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowHeadingCard from "@/components/WorkflowHeadingCard.vue";
import WorkflowHeadingCardGrid from "@/components/WorkflowHeadingCardGrid.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  DEFAULT_INCIDENT_LIST_PAGE_SIZE,
  deleteIncidentListPreset,
  formatIncidentDateTime,
  getEventIncidents,
  INCIDENT_LIST_PAGE_SIZES,
  INCIDENT_LIST_PRESET_NAME_MAX_LENGTH,
  incidentAccess,
  incidentListOpenModePreference,
  incidentSessionContext,
  saveIncidentListPreset,
  setIncidentListOpenMode,
  statusLabel,
  type ImsIncident,
  type IncidentList,
  type IncidentListFilterSelection,
  type IncidentListOpenMode,
  type IncidentListPreset,
} from "@/ims/incidentReadModel";

/**
 * `ims.incidents` — the restricted incident list (M11.5, M11.19; bound to the
 * node in M16.20; IMS surface specification 9; UI contract 15.1).
 *
 * The URL is the request. Search, filters, sort, and page live in the query
 * string, go to `GET /api/events/{event}/incidents` as they stand, and the node
 * answers with the page it decided on plus the selection it applied. Nothing
 * here narrows or reorders that answer: this list used to filter and sort a
 * compiled-in array in the browser, which meant the row count, the page count,
 * and the "matches the current filters" line could all describe a different
 * result than the one the server would give.
 *
 * One filter did not survive the move. "Current shift" matched an incident's
 * creation time against a shift table compiled into the client; there is no
 * shift on an incident and no shift window in the read, so a control that
 * quietly hid rows against fixture data is gone rather than reimplemented
 * against nothing.
 */
const DEFAULT_SELECTION: IncidentListFilterSelection = Object.freeze({
  search: "",
  state: "active",
  priority: "all",
  type: "all",
  responder: "all",
  startedFrom: null,
  startedTo: null,
  sort: "updated",
  direction: "desc",
});

/** The query parameters the list read takes (data/API 5.1). */
const LIST_QUERY_KEYS = [
  "search",
  "state",
  "priority",
  "type",
  "responder",
  "started_from",
  "started_to",
  "sort",
  "direction",
  "page",
  "per_page",
] as const;

const route = useRoute();
const router = useRouter();
const context = computed(() => incidentSessionContext.value);
const access = computed(() => incidentAccess.value);
const canView = computed(() => access.value.canView);
const canEdit = computed(() => access.value.canUpdate);

const list = ref<IncidentList | null>(null);
const loadError = ref<string | null>(null);
const loading = ref(false);
const presets = ref<readonly IncidentListPreset[]>([]);
const presetNameDraft = ref("");
const presetError = ref("");
const presetBusy = ref(false);
const listOpenMode = incidentListOpenModePreference;

/** The table's columns, and the sort key each one asks the node for. */
const columns: readonly { key: string; label: string }[] = Object.freeze([
  { key: "incident", label: "Incident" },
  { key: "state", label: "State" },
  { key: "priority", label: "Priority" },
  { key: "types", label: "Types" },
  { key: "location", label: "Location" },
  { key: "updated", label: "Last update" },
]);

/** The request, taken from the URL exactly as the node will read it. */
const requestQuery = computed(() => {
  const query: Record<string, string> = {};

  for (const key of LIST_QUERY_KEYS) {
    const value = route.query[key];

    if (typeof value === "string" && value !== "") {
      query[key] = value;
    }
  }

  return query;
});

/**
 * The selection the controls render from.
 *
 * The node's applied selection once a read has landed, and the URL's before
 * then. A control never shows a value the list was not actually narrowed by:
 * an unparseable filter is refused with a sentence rather than silently
 * dropped, and the refusal leaves the previous answer on screen.
 */
const selection = computed<IncidentListFilterSelection>(
  () => list.value?.filters ?? { ...DEFAULT_SELECTION, ...urlSelection() },
);
const searchQuery = computed(() => selection.value.search);
const searchDraft = ref(searchQuery.value);
const incidents = computed(() => list.value?.incidents ?? []);
const typeOptions = computed(() => list.value?.filterOptions.types ?? []);
const responderOptions = computed(
  () => list.value?.filterOptions.responders ?? [],
);
const stateOptions = computed(() =>
  list.value === null ? [selection.value.state] : list.value.filterOptions.states,
);
const priorityOptions = computed(() =>
  list.value === null
    ? [selection.value.priority]
    : list.value.filterOptions.priorities,
);
const pagination = computed(
  () =>
    list.value?.pagination ?? {
      page: 1,
      perPage: DEFAULT_INCIDENT_LIST_PAGE_SIZE,
      total: 0,
      totalPages: 1,
      hasMore: false,
    },
);
const pageSize = computed(() => pagination.value.perPage);
const currentPage = computed(() => pagination.value.page);
const totalPages = computed(() => pagination.value.totalPages);
const hasNarrowedList = computed(
  () =>
    selection.value.search !== "" ||
    selection.value.state !== DEFAULT_SELECTION.state ||
    selection.value.priority !== DEFAULT_SELECTION.priority ||
    selection.value.type !== DEFAULT_SELECTION.type ||
    selection.value.responder !== DEFAULT_SELECTION.responder ||
    selection.value.startedFrom !== null ||
    selection.value.startedTo !== null,
);

/**
 * How many list filters are currently narrowing the list.
 *
 * Shown on the collapsed accordion so a closed panel still says whether it is
 * hiding anything — a filter the reader cannot see and cannot count is how a
 * list ends up looking empty for no visible reason.
 */
const activeFilterCount = computed(
  () =>
    [
      selection.value.state !== DEFAULT_SELECTION.state,
      selection.value.priority !== DEFAULT_SELECTION.priority,
      selection.value.type !== DEFAULT_SELECTION.type,
      selection.value.responder !== DEFAULT_SELECTION.responder,
      selection.value.startedFrom !== null || selection.value.startedTo !== null,
    ].filter(Boolean).length,
);

/**
 * The panel starts closed and opens itself when a filter is already applied,
 * which happens whenever someone follows a filtered link or a saved preset.
 */
const filtersOpen = ref(activeFilterCount.value > 0);

const resultSummary = computed(() => {
  const total = pagination.value.total;
  const matched =
    total === 1
      ? "1 incident matches the current filters."
      : `${total} incidents match the current filters.`;

  return totalPages.value > 1
    ? `${matched} Showing ${incidents.value.length} on page ${currentPage.value} of ${totalPages.value}.`
    : matched;
});
const matchingPreset = computed(
  () =>
    presets.value.find(
      (preset) =>
        preset.name.toLowerCase() === presetNameDraft.value.trim().toLowerCase(),
    ) ?? null,
);

watch(activeFilterCount, (count) => {
  if (count > 0) {
    filtersOpen.value = true;
  }
});

watch(searchQuery, (value) => {
  searchDraft.value = value;
});

watch(
  () => [context.value?.eventId ?? null, requestQuery.value] as const,
  () => {
    void loadIncidents();
  },
  { deep: true, immediate: true },
);

function urlSelection(): Partial<IncidentListFilterSelection> {
  const query = requestQuery.value;

  return {
    ...(query.search === undefined ? {} : { search: query.search }),
    ...(query.state === undefined ? {} : { state: query.state }),
    ...(query.priority === undefined ? {} : { priority: query.priority }),
    ...(query.type === undefined ? {} : { type: query.type }),
    ...(query.responder === undefined ? {} : { responder: query.responder }),
    ...(query.sort === undefined ? {} : { sort: query.sort }),
    ...(query.direction === undefined ? {} : { direction: query.direction }),
  };
}

/**
 * Read the page the URL asks for.
 *
 * A failed read clears the list rather than leaving the last page on screen: an
 * event whose incidents could not be read must not look like an event with no
 * incidents.
 */
async function loadIncidents(): Promise<void> {
  const eventId = context.value?.eventId;

  if (eventId === undefined || !canView.value) {
    list.value = null;
    presets.value = [];

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    const page = await getEventIncidents(eventId, requestQuery.value);

    list.value = page;
    presets.value = page.presets;
  } catch (error) {
    list.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load incidents. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

function priorityText(priorityLabel: string): string {
  return priorityLabel === "" ? "Priority not set" : priorityLabel;
}

function priorityClass(priorityLabel: string): string {
  return `ims-list__priority--${priorityLabel.toLowerCase()}`;
}

function typeText(typeNames: readonly string[]): string {
  return typeNames.length > 0 ? typeNames.join(", ") : "Types not set";
}

/**
 * What one entry of the node's state vocabulary is called in the control.
 *
 * `active` and `all` are questions rather than states, so they read as
 * questions; everything else is a status and gets the label the rest of the
 * IMS gives it.
 */
function stateFilterLabel(state: string): string {
  if (state === "active") {
    return "Active states";
  }

  return state === "all" ? "All states" : statusLabel(state);
}

/** Whether the node offers this sort at all; unlisted headings stay plain. */
function isSortable(key: string): boolean {
  return (list.value?.filterOptions.sorts ?? []).includes(key);
}

function activeSortDirection(key: string): "ascending" | "descending" | "none" {
  if (selection.value.sort !== key) {
    return "none";
  }

  return selection.value.direction === "asc" ? "ascending" : "descending";
}

function nextSortQuery(key: string) {
  const nextDirection =
    selection.value.sort === key && selection.value.direction === "asc"
      ? "desc"
      : "asc";

  return {
    ...route.query,
    sort: key,
    direction: nextDirection,
    page: undefined,
  };
}

/** Every filter change lands on the first page of its own result. */
function pushFilter(key: string, value: string | undefined): void {
  void router.push({
    name: "ims.incidents.index",
    query: { ...route.query, [key]: value, page: undefined },
  });
}

function onStateFilterChange(event: Event): void {
  pushFilter("state", (event.target as HTMLSelectElement).value);
}

function onPriorityFilterChange(event: Event): void {
  pushFilter("priority", (event.target as HTMLSelectElement).value);
}

function onTypeFilterChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;

  pushFilter("type", value === "all" ? undefined : value);
}

function onResponderFilterChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;

  pushFilter("responder", value === "all" ? undefined : value);
}

function onPageSizeChange(event: Event): void {
  const value = (event.target as HTMLSelectElement).value;

  pushFilter(
    "per_page",
    Number.parseInt(value, 10) === DEFAULT_INCIDENT_LIST_PAGE_SIZE
      ? undefined
      : value,
  );
}

function onPresetApply(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const preset = presets.value.find((candidate) => candidate.id === target.value);

  presetError.value = "";

  if (!preset) {
    return;
  }

  presetNameDraft.value = preset.name;

  // A preset replaces the whole selection rather than merging into it, and the
  // node hands its own query parameters back ready to be used as one.
  void router.push({
    name: "ims.incidents.index",
    query: { ...preset.query },
  });
}

async function onPresetSave(): Promise<void> {
  const eventId = context.value?.eventId;

  presetError.value = "";

  if (eventId === undefined) {
    return;
  }

  presetBusy.value = true;

  try {
    presets.value = await saveIncidentListPreset(
      eventId,
      presetNameDraft.value,
      selection.value,
    );
    presetNameDraft.value = presetNameDraft.value.trim();
  } catch (error) {
    presetError.value = meridianErrorMessage(
      error,
      "Unable to save this preset.",
    );
  } finally {
    presetBusy.value = false;
  }
}

async function onPresetDelete(): Promise<void> {
  const eventId = context.value?.eventId;
  const preset = matchingPreset.value;

  presetError.value = "";

  if (eventId === undefined || !preset) {
    return;
  }

  presetBusy.value = true;

  try {
    presets.value = await deleteIncidentListPreset(eventId, preset.id);
    presetNameDraft.value = "";
  } catch (error) {
    presetError.value = meridianErrorMessage(
      error,
      "Unable to delete this preset.",
    );
  } finally {
    presetBusy.value = false;
  }
}

function pageQuery(page: number) {
  return {
    ...route.query,
    page: page <= 1 ? undefined : String(page),
  };
}

function onListOpenModeChange(event: Event): void {
  const target = event.target as HTMLSelectElement;
  const mode: IncidentListOpenMode = target.value === "edit" ? "edit" : "view";

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

async function onSearchSubmit(): Promise<void> {
  const search = searchDraft.value.trim();

  await router.push({
    name: "ims.incidents.index",
    query: {
      ...route.query,
      search: search === "" ? undefined : search,
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
    :eyebrow="context?.icDepartmentLabel ?? 'Incident Command'"
    lede="Restricted Incident Command workspace for event incident records."
  >
    <template #actions>
      <div class="ims-list__links">
        <WorkflowActionButton
          v-if="access.canCreate"
          :to="{ name: 'ims.incidents.create' }"
        >
          Create incident
        </WorkflowActionButton>
        <!--
          Taking a report by dictation is gated on the same permission as
          creating an incident: it records an account on another staff member's
          behalf, which is Incident Command work.
        -->
        <WorkflowActionButton
          v-if="access.canCreate"
          variant="secondary"
          :to="{ name: 'ims.field-reports.create' }"
        >
          Take Field Report
        </WorkflowActionButton>
      </div>
    </template>

    <template #navigation>
      <RouterLink
        v-if="access.canViewFieldReports"
        class="ims-list__secondary-link"
        :to="{ name: 'ims.field-reports.index' }"
      >
        Field Reports
      </RouterLink>
    </template>

    <template #heading-cards>
      <WorkflowHeadingCardGrid v-if="context">
        <WorkflowHeadingCard
          label="Organization"
          :value="context.organizationLabel"
        />
        <WorkflowHeadingCard label="Event" :value="context.eventLabel" />
        <WorkflowHeadingCard
          label="IC department"
          :value="context.icDepartmentLabel"
        />
        <WorkflowHeadingCard label="Role" :value="context.roleLabel" />
      </WorkflowHeadingCardGrid>
    </template>

    <div v-if="!canView" class="ims-list__restricted" role="status">
      <RouterLink :to="{ name: 'ims.restricted' }">
        Incident Command access required
      </RouterLink>
    </div>

    <template v-else>
      <!--
        One control band. Search, filters, and presets stay separate forms
        because they submit separately, but they share a surface and pack into
        the width available instead of stacking three bordered rows of
        near-empty controls above the data.
      -->
      <ControlBar label="Incident list controls">
        <form
          data-control-group="grow"
          aria-label="Search incidents"
          @submit.prevent="onSearchSubmit"
        >
          <ControlField label="Search" control-id="ims-list-search" width="grow">
            <input
              id="ims-list-search"
              v-model="searchDraft"
              type="search"
              autocomplete="off"
            />
          </ControlField>
          <button type="submit">Search</button>
          <RouterLink
            v-if="searchQuery"
            class="ims-list__clear-search"
            :to="{ name: 'ims.incidents.index' }"
          >
            Clear
          </RouterLink>
        </form>

        <!--
          Not a filter, and not behind the filter panel's disclosure. It changes
          what every row in the table does when it is clicked, which an operator
          who wants to go straight to the form has to be able to find without
          opening something first — and a control nobody finds is a control that
          does not exist. Offered only where the edit route is, because sending
          a reader who holds no `incidents.update` to it would land them on a
          refusal.
        -->
        <ControlField
          v-if="canEdit"
          label="Clicking an incident"
          control-id="ims-list-open-mode"
          width="md"
        >
          <select
            id="ims-list-open-mode"
            :value="listOpenMode"
            @change="onListOpenModeChange"
          >
            <option value="view">Opens it</option>
            <option value="edit">Edits it</option>
          </select>
        </ControlField>
      </ControlBar>

      <p v-if="loadError" class="ims-list__load-error" role="alert">
        {{ loadError }}
      </p>

      <!--
        Filters collapse by default. Six selects above a list is more chrome
        than data on a phone, and the reader arrives wanting the incidents, not
        the controls. The summary carries the active count so a closed panel
        never hides a filter silently.
      -->
      <details class="ims-list__filter-panel" :open="filtersOpen">
        <summary>
          <span>Filters and presets</span>
          <span v-if="activeFilterCount > 0" class="ims-list__filter-count">
            {{ activeFilterCount }} active
          </span>
        </summary>
        <div class="ims-list__filter-body">
        <form class="ims-list__filters" aria-label="Filter incidents">
          <!--
            The state and priority vocabularies are the node's, off the read's
            own `filter_options`, so a state Meridian adds appears here without
            this template learning about it. `active` and `all` are ways of
            asking rather than states, which is why they get their own wording.
          -->
          <label for="ims-list-state"><span>State</span>
            <select
              id="ims-list-state"
              :value="selection.state"
              @change="onStateFilterChange"
            >
              <option
                v-for="state in stateOptions"
                :key="state"
                :value="state"
              >
                {{ stateFilterLabel(state) }}
              </option>
            </select>
          </label>

          <label for="ims-list-priority"><span>Priority</span>
            <select
              id="ims-list-priority"
              :value="selection.priority"
              @change="onPriorityFilterChange"
            >
              <option
                v-for="priority in priorityOptions"
                :key="priority"
                :value="priority"
              >
                {{ priority === "all" ? "All priorities" : priority }}
              </option>
            </select>
          </label>

          <label for="ims-list-type"><span>Type</span>
            <select
              id="ims-list-type"
              :value="selection.type"
              @change="onTypeFilterChange"
            >
              <option value="all">All types</option>
              <option v-for="name in typeOptions" :key="name" :value="name">
                {{ name }}
              </option>
            </select>
          </label>

          <label for="ims-list-responder"><span>Responder</span>
            <select
              id="ims-list-responder"
              :value="selection.responder"
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
          </label>

          <label for="ims-list-page-size"><span>Per page</span>
            <select
              id="ims-list-page-size"
              :value="String(pageSize)"
              @change="onPageSizeChange"
            >
              <option
                v-for="size in INCIDENT_LIST_PAGE_SIZES"
                :key="size"
                :value="String(size)"
              >
                {{ size }}
              </option>
            </select>
          </label>

        </form>

        <form
          class="ims-list__filters ims-list__presets"
          aria-label="Saved incident list presets"
          @submit.prevent="onPresetSave"
        >
          <label for="ims-list-preset"><span>Saved presets</span>
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
          </label>

          <label for="ims-list-preset-name"><span>Preset name</span>
            <input
              id="ims-list-preset-name"
              v-model="presetNameDraft"
              type="text"
              autocomplete="off"
              :maxlength="INCIDENT_LIST_PRESET_NAME_MAX_LENGTH"
            />
          </label>

          <button type="submit" :disabled="presetBusy">
            {{ matchingPreset ? "Update preset" : "Save preset" }}
          </button>
          <button
            v-if="matchingPreset"
            type="button"
            :disabled="presetBusy"
            @click="onPresetDelete"
          >
            Delete preset
          </button>
        </form>
        </div>
      </details>

      <p v-if="presetError" class="ims-list__preset-error" role="alert">
        {{ presetError }}
      </p>

      <!--
        Counts and empty states stay off the screen while the read is unknown.
        "No incidents are recorded for this event" under a failed read is the
        client answering a question the node did not.
      -->
      <p v-if="!loadError" class="ims-list__summary" role="status">
        <span>{{ resultSummary }}</span>
        <RouterLink
          v-if="hasNarrowedList"
          class="ims-list__reset-filters"
          :to="{ name: 'ims.incidents.index' }"
        >
          Reset filters
        </RouterLink>
      </p>

      <p
        v-if="loading && list === null"
        class="ims-list__empty"
        role="status"
      >
        Loading incidents.
      </p>

      <p v-else-if="loadError" class="ims-list__empty">
        The incidents for this event could not be read.
      </p>

      <p v-else-if="incidents.length === 0" class="ims-list__empty">
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
              <!--
                A heading is a sort control only where the node offers that
                sort. Types is the one that never was: it was sorted here over
                a compiled-in array, and `filter_options.sorts` does not list
                it, so asking for it would be refused.
              -->
              <th
                v-for="column in columns"
                :key="column.key"
                scope="col"
                :aria-sort="activeSortDirection(column.key)"
              >
                <RouterLink
                  v-if="isSortable(column.key)"
                  :to="{
                    name: 'ims.incidents.index',
                    query: nextSortQuery(column.key),
                  }"
                >
                  {{ column.label }}
                </RouterLink>
                <span v-else>{{ column.label }}</span>
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

.ims-list__preset-error,
.ims-list__load-error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

/*
 * Filters and presets accordion. The panel matches the Field Reports list
 * filters: label above control, one comfortable column per filter, sized by the
 * grid rather than packed inline. Roomier than the old inline band, which is the
 * point — these are read and changed, not scanned past.
 */
.ims-list__filter-panel {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.ims-list__filter-panel > summary {
  display: flex;
  align-items: center;
  gap: var(--m-space-2);
  min-height: 2.75rem;
  padding: var(--m-space-2) var(--m-pad-inline);
  color: var(--m-text-primary);
  font-weight: 800;
  cursor: pointer;
  list-style-position: inside;
}

.ims-list__filter-panel > summary:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: -2px;
}

.ims-list__filter-count {
  padding: 0.1rem var(--m-space-2);
  border-radius: 999px;
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
  font-size: var(--m-text-xs);
  font-weight: 800;
}

.ims-list__filter-body {
  display: grid;
  gap: var(--m-space-4);
  padding: 0 var(--m-pad-inline) var(--m-pad-block);
  border-top: 1px solid var(--m-border-subtle);
  padding-top: var(--m-pad-block);
}

.ims-list__filters {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--m-space-3);
}

.ims-list__filters label {
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
}

.ims-list__filters label span {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.ims-list__filters select,
.ims-list__filters input {
  width: 100%;
  min-width: 0;
  min-height: 2.75rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.ims-list__filters select:focus-visible,
.ims-list__filters input:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.ims-list__presets {
  align-items: end;
  padding-top: var(--m-space-4);
  border-top: 1px solid var(--m-border-subtle);
}

.ims-list__presets button {
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

.ims-list__presets button[type="submit"] {
  border-color: var(--m-action-secondary-bg);
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
}

.ims-list__presets button:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .ims-list__filters {
    grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
  }
}

.ims-list :deep(.control-bar button[type="submit"]) {
  border-color: var(--m-action-secondary-bg);
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
}

.ims-list :deep(.control-bar input),
.ims-list :deep(.control-bar select) {
  min-width: 0;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  padding: var(--m-space-2) var(--m-space-3);
  background: var(--m-surface-primary);
  color: var(--m-text-primary);
  font: inherit;
}

.ims-list__clear-search {
  display: inline-flex;
  align-items: center;
  min-height: 2.75rem;
  color: var(--m-action-secondary-bg);
  font-weight: 800;
  text-decoration: none;
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
