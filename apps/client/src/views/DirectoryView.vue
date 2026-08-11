<script setup lang="ts">
import { computed, nextTick, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import DirectoryPersonEntry from "@/components/directory/DirectoryPersonEntry.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import StaleReadNotice from "@/components/StaleReadNotice.vue";
import {
  DirectoryAbsentError,
  fetchDirectoryChart,
  searchDirectory,
  type DirectoryChart,
  type DirectoryDepartment,
  type DirectoryLocation,
  type DirectoryPerson,
  type DirectorySearchRow,
} from "@/directory/directoryModel";
import { sessionEventContext } from "@/session/sessionAccess";

/**
 * `directory` — the organization as a chart: departments, teams, and the
 * people in them this viewer may see (M18.75; DIR-001 through DIR-016,
 * DIR-029; UI contract 12.3, 19D).
 *
 * The chart opens collapsed to departments (DIR-016) and expands by touch:
 * every disclosure is a real button, keyboard-reachable, meeting the section
 * 20 touch target size, and nothing anywhere requires hover (DIR-003). Depth
 * is expressed with indentation and disclosure rather than a box-and-line
 * diagram that must be panned sideways (19D.9) — the shape that fails on both
 * a phone and a wall display.
 *
 * What renders is only what the node's projection carries (DIR-028): a person
 * entry is a picture, a handle, the authorized locations, and years of
 * service, and there is no name, contact field, or action in the payload to
 * render by mistake. An empty department or team renders as itself, with no
 * count, no lock, and no "hidden" marker (DIR-015) — the branch existing is
 * the whole message.
 *
 * Where the organization has the Directory disabled the node answers 404, and
 * this page renders not-found copy identical in kind to an address that never
 * existed (DIR-005). No empty state explains that it would have been here.
 */
const chart = ref<DirectoryChart | null>(null);
const loadError = ref<string | null>(null);
const absent = ref(false);

const expandedDepartments = ref<Set<string>>(new Set());
const expandedTeams = ref<Set<string>>(new Set());
const chartRoot = ref<HTMLElement | null>(null);

/*
 * Search, beside the chart rather than on a page or tab of its own (M18.76;
 * DIR-031). Typing searches, selecting a row moves the chart, and the search
 * interface stays exactly where it was so the reader can search again without
 * reopening anything (DIR-035).
 */
const searchQuery = ref("");
const searchRows = ref<readonly DirectorySearchRow[]>([]);
const searchError = ref<string | null>(null);
const highlightedStaffId = ref<string | null>(null);

/*
 * Filtering (M18.76; DIR-036; UI contract 19D.7): chips, immediately visible
 * and touch-first — never a dropdown as the primary interaction. An empty set
 * filters nothing; the sets narrow what is presented and reveal nothing that
 * visibility withheld, because there is nothing beyond the authorized
 * projection here to reveal.
 */
const departmentFilter = ref<Set<string>>(new Set());
const teamFilter = ref<Set<string>>(new Set());
const roleFilter = ref<Set<string>>(new Set());
const statusFilter = ref<Set<string>>(new Set());

const ROLE_OPTIONS: readonly { value: DirectoryLocation["kind"]; label: string }[] = [
  { value: "department_lead", label: "Department Lead" },
  { value: "team_lead", label: "Team Lead" },
  { value: "team_member", label: "Member" },
  { value: "prospective", label: "Prospectives" },
];

const STATUS_OPTIONS: readonly { value: string; label: string }[] = [
  { value: "active", label: "Active" },
  { value: "prospective", label: "Prospective" },
  { value: "emeritus", label: "Emeritus" },
  { value: "retired", label: "Retired" },
];

const eventContext = computed(() => sessionEventContext.value);

const lede = computed(() => {
  if (chart.value === null) {
    return "The organization chart: departments, teams, and the people in them you may see.";
  }

  return chart.value.scope === "event"
    ? `Who is where at ${chart.value.eventLabel ?? "this event"}.`
    : `How ${chart.value.organizationLabel ?? "this organization"} is arranged, and who is where in it.`;
});

const peopleById = computed(() => {
  const map = new Map<string, DirectoryPerson>();

  for (const person of chart.value?.people ?? []) {
    map.set(person.id, person);
  }

  return map;
});

const departmentNames = computed(() => {
  const map = new Map<string, string>();

  for (const department of chart.value?.departments ?? []) {
    map.set(department.id, department.name);
  }

  return map;
});

const teamNames = computed(() => {
  const map = new Map<string, string>();

  for (const department of chart.value?.departments ?? []) {
    for (const team of department.teams) {
      map.set(team.id, team.name);
    }
  }

  return map;
});

async function load(): Promise<void> {
  loadError.value = null;
  absent.value = false;

  try {
    chart.value = await fetchDirectoryChart();
    // A fresh read reopens collapsed (DIR-016): expansion is the reader's
    // gesture, not a stored state.
    expandedDepartments.value = new Set();
    expandedTeams.value = new Set();
  } catch (error) {
    if (error instanceof DirectoryAbsentError) {
      absent.value = true;
      chart.value = null;

      return;
    }

    loadError.value = meridianErrorMessage(
      error,
      "Unable to load the Directory. Check the connection to this node and try again.",
    );
  }
}

function toggleDepartment(departmentId: string): void {
  const next = new Set(expandedDepartments.value);

  if (!next.delete(departmentId)) {
    next.add(departmentId);
  }

  expandedDepartments.value = next;
}

function toggleTeam(teamId: string): void {
  const next = new Set(expandedTeams.value);

  if (!next.delete(teamId)) {
    next.add(teamId);
  }

  expandedTeams.value = next;
}

function person(staffId: string): DirectoryPerson | null {
  return peopleById.value.get(staffId) ?? null;
}

function people(staffIds: readonly string[]): DirectoryPerson[] {
  return staffIds
    .map((staffId) => person(staffId))
    .filter((entry): entry is DirectoryPerson => entry !== null);
}

/*
 * The filter predicate, applied per placement rather than per person: a
 * person narrowed out of one location can still match in another, which is
 * how the same rule DIR-014 applies to presentation applies to filtering.
 */
function placementShown(
  staffId: string,
  departmentId: string,
  teamId: string | null,
  kind: DirectoryLocation["kind"],
): boolean {
  if (departmentFilter.value.size > 0 && !departmentFilter.value.has(departmentId)) {
    return false;
  }

  if (teamFilter.value.size > 0 && (teamId === null || !teamFilter.value.has(teamId))) {
    return false;
  }

  if (roleFilter.value.size > 0 && !roleFilter.value.has(kind)) {
    return false;
  }

  if (statusFilter.value.size > 0) {
    const status =
      person(staffId)?.locations.find(
        (location) =>
          location.departmentId === departmentId &&
          location.teamId === teamId &&
          location.kind === kind,
      )?.status ?? "";

    if (!statusFilter.value.has(status)) {
      return false;
    }
  }

  return true;
}

/** The chart with the filters applied to what is presented (DIR-036). */
const filteredDepartments = computed<readonly DirectoryDepartment[]>(() => {
  const departments = chart.value?.departments ?? [];

  return departments
    .filter(
      (department) =>
        departmentFilter.value.size === 0 ||
        departmentFilter.value.has(department.id),
    )
    .map((department) => ({
      ...department,
      leads: department.leads.filter((staffId) =>
        placementShown(staffId, department.id, null, "department_lead"),
      ),
      teams: department.teams
        .filter(
          (team) => teamFilter.value.size === 0 || teamFilter.value.has(team.id),
        )
        .map((team) => ({
          ...team,
          leads: team.leads.filter((staffId) =>
            placementShown(staffId, department.id, team.id, "team_lead"),
          ),
          members: team.members.filter((staffId) =>
            placementShown(staffId, department.id, team.id, "team_member"),
          ),
        })),
      prospectives: department.prospectives.filter((staffId) =>
        placementShown(staffId, department.id, null, "prospective"),
      ),
    }));
});

const anyFilterActive = computed(
  () =>
    departmentFilter.value.size > 0 ||
    teamFilter.value.size > 0 ||
    roleFilter.value.size > 0 ||
    statusFilter.value.size > 0,
);

/**
 * How many people the filters leave visible (DIR-036): unique people, and
 * only people this viewer can see — there is nothing else in the projection
 * to count.
 */
const filteredCount = computed(() => {
  const ids = new Set<string>();

  for (const department of filteredDepartments.value) {
    for (const staffId of department.leads) {
      ids.add(staffId);
    }

    for (const team of department.teams) {
      for (const staffId of [...team.leads, ...team.members]) {
        ids.add(staffId);
      }
    }

    for (const staffId of department.prospectives) {
      ids.add(staffId);
    }
  }

  return ids.size;
});

/** Team chips are offered once the department context is unambiguous. */
const teamFilterOptions = computed(() => {
  if (departmentFilter.value.size !== 1) {
    return [];
  }

  const [departmentId] = [...departmentFilter.value];

  return (
    chart.value?.departments.find((department) => department.id === departmentId)
      ?.teams ?? []
  );
});

function toggleFilter(set: Set<string>, value: string): Set<string> {
  const next = new Set(set);

  if (!next.delete(value)) {
    next.add(value);
  }

  return next;
}

function toggleDepartmentFilter(departmentId: string): void {
  departmentFilter.value = toggleFilter(departmentFilter.value, departmentId);
  // A team chip belongs to the one selected department; changing that
  // selection makes the held team chips meaningless.
  teamFilter.value = new Set();
}

async function runSearch(query: string): Promise<void> {
  // The previous highlight does not survive the next search (19D.6).
  highlightedStaffId.value = null;
  searchError.value = null;

  if (query.trim() === "") {
    searchRows.value = [];

    return;
  }

  try {
    const rows = await searchDirectory(query);

    // A slower answer to an earlier query must not replace the current one.
    if (query === searchQuery.value) {
      searchRows.value = rows;
    }
  } catch (error) {
    if (error instanceof DirectoryAbsentError) {
      absent.value = true;

      return;
    }

    searchError.value = meridianErrorMessage(
      error,
      "Unable to search the Directory right now.",
    );
  }
}

/**
 * Selecting a result moves the chart and nothing else (DIR-035): expand the
 * branches that hold the person's authorized occurrences, scroll to the
 * selected row's own node — open question 38, as settled: each row names one
 * location, so the row selected is the location scrolled to — highlight every
 * occurrence, and leave the search interface in place.
 */
async function selectResult(row: DirectorySearchRow): Promise<void> {
  highlightedStaffId.value = row.staffId;

  const departments = new Set(expandedDepartments.value);
  const teams = new Set(expandedTeams.value);

  departments.add(row.location.departmentId);

  if (row.location.teamId !== null) {
    teams.add(row.location.teamId);
  }

  for (const location of person(row.staffId)?.locations ?? []) {
    departments.add(location.departmentId);

    if (location.teamId !== null) {
      teams.add(location.teamId);
    }
  }

  expandedDepartments.value = departments;
  expandedTeams.value = teams;

  await nextTick();

  const selector =
    row.location.teamId !== null
      ? `[data-team-id="${row.location.teamId}"]`
      : `[data-department-id="${row.location.departmentId}"]`;
  const target = chartRoot.value?.querySelector(selector);

  target?.scrollIntoView?.({ block: "center" });
}

/**
 * A location in words, the same shape the search breadcrumb uses (DIR-034):
 * department, team where there is one, and what the person is there.
 */
function locationLabels(person: DirectoryPerson): string[] {
  const kindLabels: Record<DirectoryLocation["kind"], string> = {
    department_lead: "Department Lead",
    team_lead: "Team Lead",
    team_member: "Member",
    prospective: "Prospectives",
  };

  return person.locations.map((location) =>
    [
      departmentNames.value.get(location.departmentId) ?? "",
      location.teamId !== null
        ? (teamNames.value.get(location.teamId) ?? "")
        : "",
      kindLabels[location.kind],
    ]
      .filter((part) => part !== "")
      .join(" → "),
  );
}

watch(eventContext, () => {
  void load();
});

watch(searchQuery, (query) => {
  void runSearch(query);
});

void load();
</script>

<template>
  <StaffPageShell
    heading-id="directory-heading"
    title="Directory"
    eyebrow="The organization"
    :lede="absent ? '' : lede"
  >
    <!--
      DIR-005: absent, not refused. The same words a nonexistent address gets,
      with nothing explaining what would have been here.
    -->
    <section v-if="absent" class="directory__absent" aria-label="Page not found">
      <h2 class="directory__absent-heading">Page not found</h2>
      <p class="directory__absent-body">
        This screen does not exist.
        <RouterLink :to="{ name: 'home' }">Return home</RouterLink>.
      </p>
    </section>

    <template v-else>
      <p v-if="loadError" class="directory__error" role="alert">
        {{ loadError }}
        <button type="button" @click="load">Try again</button>
      </p>

      <template v-if="chart">
        <StaleReadNotice :freshness="chart.freshness" label="This chart" />

        <!--
          Search and filters, beside the chart rather than on a page or tab of
          their own (DIR-031, DIR-036; 19D.6, 19D.7). Every control is a
          visible touch target; nothing here is a dropdown.
        -->
        <section
          class="directory__tools"
          aria-label="Search and filter the Directory"
        >
          <div class="directory__search">
            <label class="directory__search-label" for="directory-search">
              Search handles
            </label>
            <input
              id="directory-search"
              v-model="searchQuery"
              type="search"
              inputmode="search"
              autocomplete="off"
              placeholder="Type a handle"
            />

            <p v-if="searchError" class="directory__error" role="alert">
              {{ searchError }}
            </p>

            <ul
              v-if="searchRows.length > 0"
              class="directory__results"
              aria-label="Search results"
            >
              <li v-for="row in searchRows" :key="`${row.staffId}-${row.breadcrumb}`">
                <button
                  type="button"
                  class="directory__result"
                  @click="selectResult(row)"
                >
                  <span class="directory__result-handle">{{ row.handle }}</span>
                  <span class="directory__result-breadcrumb">{{ row.breadcrumb }}</span>
                </button>
              </li>
            </ul>

            <p
              v-else-if="searchQuery.trim() !== '' && !searchError"
              class="directory__no-results"
              role="status"
            >
              No handles match.
            </p>
          </div>

          <fieldset class="directory__filter-group">
            <legend>Department</legend>
            <div class="directory__chips">
              <button
                v-for="department in chart.departments"
                :key="`filter-${department.id}`"
                type="button"
                class="directory__chip"
                :aria-pressed="departmentFilter.has(department.id)"
                @click="toggleDepartmentFilter(department.id)"
              >
                {{ department.name }}
              </button>
            </div>
          </fieldset>

          <fieldset v-if="teamFilterOptions.length > 0" class="directory__filter-group">
            <legend>Team</legend>
            <div class="directory__chips">
              <button
                v-for="team in teamFilterOptions"
                :key="`filter-${team.id}`"
                type="button"
                class="directory__chip"
                :aria-pressed="teamFilter.has(team.id)"
                @click="teamFilter = toggleFilter(teamFilter, team.id)"
              >
                {{ team.name }}
              </button>
            </div>
          </fieldset>

          <fieldset class="directory__filter-group">
            <legend>Role</legend>
            <div class="directory__chips">
              <button
                v-for="option in ROLE_OPTIONS"
                :key="`filter-role-${option.value}`"
                type="button"
                class="directory__chip"
                :aria-pressed="roleFilter.has(option.value)"
                @click="roleFilter = toggleFilter(roleFilter, option.value)"
              >
                {{ option.label }}
              </button>
            </div>
          </fieldset>

          <fieldset class="directory__filter-group">
            <legend>Status</legend>
            <div class="directory__chips">
              <button
                v-for="option in STATUS_OPTIONS"
                :key="`filter-status-${option.value}`"
                type="button"
                class="directory__chip"
                :aria-pressed="statusFilter.has(option.value)"
                @click="statusFilter = toggleFilter(statusFilter, option.value)"
              >
                {{ option.label }}
              </button>
            </div>
          </fieldset>

          <!--
            The count counts only people this viewer can see (DIR-036): the
            projection holds nothing else to count, and no wording implies
            there was.
          -->
          <p v-if="anyFilterActive" class="directory__filter-count" role="status">
            {{ filteredCount === 1 ? "1 person shown." : `${filteredCount} people shown.` }}
          </p>
        </section>

        <ul ref="chartRoot" class="directory__departments" data-testid="directory-chart">
          <li
            v-for="department in filteredDepartments"
            :key="department.id"
            class="directory__department"
          >
            <button
              type="button"
              class="directory__disclosure"
              :aria-expanded="expandedDepartments.has(department.id)"
              :data-department-id="department.id"
              @click="toggleDepartment(department.id)"
            >
              <span class="directory__disclosure-marker" aria-hidden="true">
                {{ expandedDepartments.has(department.id) ? "▾" : "▸" }}
              </span>
              <span class="directory__department-name">{{ department.name }}</span>
              <span v-if="department.isOrganizers" class="directory__department-role">
                Organizers Department
              </span>
            </button>

            <div
              v-if="expandedDepartments.has(department.id)"
              class="directory__department-body"
            >
              <section
                v-if="department.leads.length > 0"
                class="directory__section"
                :aria-label="`${department.name} department leads`"
              >
                <h3 class="directory__section-heading">Department leads</h3>
                <ul class="directory__people">
                  <li
                    v-for="entry in people(department.leads)"
                    :key="`${department.id}-lead-${entry.id}`"
                  >
                    <DirectoryPersonEntry
                      :person="entry"
                      :location-labels="locationLabels(entry)"
                      :highlighted="entry.id === highlightedStaffId"
                    />
                  </li>
                </ul>
              </section>

              <ul class="directory__teams">
                <li
                  v-for="team in department.teams"
                  :key="team.id"
                  class="directory__team"
                >
                  <button
                    type="button"
                    class="directory__disclosure directory__disclosure--team"
                    :aria-expanded="expandedTeams.has(team.id)"
                    :data-team-id="team.id"
                    @click="toggleTeam(team.id)"
                  >
                    <span class="directory__disclosure-marker" aria-hidden="true">
                      {{ expandedTeams.has(team.id) ? "▾" : "▸" }}
                    </span>
                    <span class="directory__team-name">{{ team.name }}</span>
                  </button>

                  <div v-if="expandedTeams.has(team.id)" class="directory__team-body">
                    <section
                      v-if="team.leads.length > 0"
                      class="directory__section"
                      :aria-label="`${team.name} team leads`"
                    >
                      <h4 class="directory__section-heading">Team leads</h4>
                      <ul class="directory__people">
                        <li
                          v-for="entry in people(team.leads)"
                          :key="`${team.id}-lead-${entry.id}`"
                        >
                          <DirectoryPersonEntry
                            :person="entry"
                            :location-labels="locationLabels(entry)"
                            :highlighted="entry.id === highlightedStaffId"
                          />
                        </li>
                      </ul>
                    </section>

                    <section
                      v-if="team.members.length > 0"
                      class="directory__section"
                      :aria-label="`${team.name} members`"
                    >
                      <h4 class="directory__section-heading">Members</h4>
                      <ul class="directory__people">
                        <li
                          v-for="entry in people(team.members)"
                          :key="`${team.id}-member-${entry.id}`"
                        >
                          <DirectoryPersonEntry
                            :person="entry"
                            :location-labels="locationLabels(entry)"
                            :highlighted="entry.id === highlightedStaffId"
                          />
                        </li>
                      </ul>
                    </section>
                  </div>
                </li>
              </ul>

              <section
                v-if="department.prospectives.length > 0"
                class="directory__section"
                :aria-label="`${department.name} prospectives`"
              >
                <!--
                  DIR-013: a Directory label, not the Prospective status, and
                  deliberately not styled with the status vocabulary.
                -->
                <h3 class="directory__section-heading">Prospectives</h3>
                <ul class="directory__people">
                  <li
                    v-for="entry in people(department.prospectives)"
                    :key="`${department.id}-prospective-${entry.id}`"
                  >
                    <DirectoryPersonEntry
                      :person="entry"
                      :location-labels="locationLabels(entry)"
                      :highlighted="entry.id === highlightedStaffId"
                    />
                  </li>
                </ul>
              </section>
            </div>
          </li>
        </ul>
      </template>

      <p v-else-if="!loadError" class="directory__loading" role="status">
        Loading the Directory.
      </p>
    </template>
  </StaffPageShell>
</template>

<style scoped>
.directory__departments,
.directory__teams,
.directory__people {
  margin: 0;
  padding: 0;
  list-style: none;
}

.directory__departments {
  display: grid;
  gap: var(--m-space-2);
}

/*
 * Disclosure, not diagram (19D.9): depth is indentation, the page scrolls
 * vertically only, and width is spent on legibility.
 */
.directory__disclosure {
  display: flex;
  gap: var(--m-space-2);
  align-items: center;
  width: 100%;
  min-height: 44px;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  font: inherit;
  font-weight: 700;
  text-align: left;
  cursor: pointer;
}

.directory__disclosure--team {
  font-weight: 600;
}

.directory__disclosure-marker {
  flex: none;
}

.directory__department-role {
  margin-left: auto;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 400;
}

.directory__department-body,
.directory__team-body {
  display: grid;
  gap: var(--m-space-2);
  margin-top: var(--m-space-2);
  padding-left: var(--m-space-3);
}

.directory__teams {
  display: grid;
  gap: var(--m-space-2);
}

.directory__section {
  display: grid;
  gap: var(--m-space-1);
}

.directory__section-heading {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.directory__people {
  display: grid;
  gap: var(--m-space-1);
}

.directory__tools {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.directory__search {
  display: grid;
  gap: var(--m-space-2);
}

.directory__search-label {
  font-weight: 700;
}

.directory__search input {
  min-height: 44px;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
}

.directory__results {
  display: grid;
  gap: var(--m-space-1);
  margin: 0;
  padding: 0;
  list-style: none;
}

.directory__result {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: baseline;
  width: 100%;
  min-height: 44px;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  font: inherit;
  text-align: left;
  cursor: pointer;
}

.directory__result-handle {
  font-weight: 700;
}

.directory__result-breadcrumb {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.directory__filter-group {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  border: 0;
}

.directory__filter-group legend {
  padding: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.directory__chips {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.directory__chip {
  min-height: 44px;
  padding: var(--m-space-1) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 999px;
  background: none;
  font: inherit;
  cursor: pointer;
}

.directory__chip[aria-pressed="true"] {
  border-color: var(--m-border-strong, currentColor);
  background: var(--m-surface-raised);
  font-weight: 700;
}

.directory__filter-count,
.directory__no-results {
  margin: 0;
  color: var(--m-text-muted);
}

.directory__loading,
.directory__absent-body {
  margin: 0;
  color: var(--m-text-muted);
}

.directory__absent-heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.directory__error {
  margin: 0;
  color: var(--m-attention-critical);
  font-weight: 700;
}
</style>
