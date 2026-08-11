<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import DirectoryPersonEntry from "@/components/directory/DirectoryPersonEntry.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import StaleReadNotice from "@/components/StaleReadNotice.vue";
import {
  DirectoryAbsentError,
  fetchDirectoryChart,
  type DirectoryChart,
  type DirectoryLocation,
  type DirectoryPerson,
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

        <ul class="directory__departments" data-testid="directory-chart">
          <li
            v-for="department in chart.departments"
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
