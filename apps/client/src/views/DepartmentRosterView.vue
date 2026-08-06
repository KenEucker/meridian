<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { useRoute } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  filterRoster,
  getDepartmentRoster,
  type DepartmentRoster,
} from "@/department-roster/departmentRosterModel";
import { selectedSessionDepartment } from "@/session/sessionAccess";

/**
 * `department.roster` — the department staff list (M18.30; UI contract 12.4;
 * VOL-011, VOL-012).
 *
 * The department has had a staff list on the Admin page since M11.13, but that
 * one exists to put people on teams: it shows a display name and a handle and
 * nothing anybody could reach somebody with. This is the list a lead opens to
 * reach their department — names, teams, phone numbers, and, for the leads
 * VOL-012 names, emergency contacts.
 *
 * **Emergency contacts are a column, not a badge.** They appear when the node
 * sent them and not otherwise, and the node sends them only to a reader who
 * holds `department.administer` over this department. A reader who does not is
 * shown a roster with no such column rather than one full of blanks: a blank
 * emergency contact has to keep meaning "none recorded" to the lead reading it.
 *
 * **Searching is local and works with the node unreachable once the page has
 * loaded.** A lead standing in a field looking for a phone number should not
 * need a round trip to find one, which is the same reasoning SLB-021 applies to
 * the Logistics Desk.
 */
const route = useRoute();
const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));

const roster = ref<DepartmentRoster | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);

const teamFilter = ref("");
const query = ref("");

const eyebrow = computed(
  () =>
    roster.value?.departmentLabel ||
    selectedSessionDepartment.value?.departmentLabel ||
    "Department",
);

const lede = computed(() => {
  const current = roster.value;

  if (current === null) {
    return "";
  }

  return current.wholeDepartment
    ? `Everyone associated with this department, for ${current.eventLabel}.`
    : `The teams you lead, for ${current.eventLabel}.`;
});

const members = computed(() =>
  filterRoster(roster.value?.members ?? [], {
    teamId: teamFilter.value,
    query: query.value,
  }),
);

/**
 * What a reader is being shown, stated rather than implied by the length of
 * the list. Somebody reading a short roster has to be able to tell "this
 * department is small" from "you are being shown part of it".
 */
const scopeStatement = computed(() => {
  const current = roster.value;

  if (current === null) {
    return "";
  }

  const contacts = current.emergencyContacts
    ? "Emergency contacts are shown because you lead this department."
    : "Emergency contacts are not shown: they belong to department leads for their own department.";

  const reach = current.wholeDepartment
    ? "This list covers the whole department."
    : "This list covers only the teams you lead.";

  return `${reach} ${contacts}`;
});

function formatTeams(teams: readonly { name: string; membershipRole: string | null }[]): string {
  if (teams.length === 0) {
    return "No team";
  }

  return teams
    .map((team) => (team.membershipRole === "lead" ? `${team.name} (lead)` : team.name))
    .join(", ");
}

async function load(): Promise<void> {
  if (eventId.value === "" || departmentId.value === "") {
    roster.value = null;

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    roster.value = await getDepartmentRoster(eventId.value, departmentId.value);
  } catch (error) {
    // A department whose roster could not be read must not look like a
    // department with nobody in it.
    roster.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "The department staff list could not be read.",
    );
  } finally {
    loading.value = false;
  }
}

watch(
  [eventId, departmentId],
  () => {
    teamFilter.value = "";
    void load();
  },
  { immediate: true },
);
</script>

<template>
  <WorkflowPageShell
    heading-id="department-roster-heading"
    title="Roster"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <p v-if="loadError" class="roster__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading && roster === null" class="roster__notice" role="status">
      Reading the department staff list…
    </p>

    <template v-else-if="roster">
      <p class="roster__scope" role="status">{{ scopeStatement }}</p>

      <!--
        Reported rather than enforced: department membership is an
        organization-level record, so the list is the same either way, and
        somebody who opened this from the wrong event should be told that
        instead of shown a page that looks right.
      -->
      <p v-if="!roster.participatesInEvent" class="roster__notice" role="status">
        This department is not participating in {{ roster.eventLabel }}. The
        people below are its members; none of them are working this event
        through it.
      </p>

      <div class="roster__filters">
        <label class="roster__filter">
          Search
          <input
            v-model="query"
            type="search"
            placeholder="Name, handle, email, or phone"
          />
        </label>

        <label v-if="roster.teams.length > 0" class="roster__filter">
          Team
          <select v-model="teamFilter">
            <option value="">All teams</option>
            <option v-for="team in roster.teams" :key="team.id" :value="team.id">
              {{ team.name }}
            </option>
          </select>
        </label>
      </div>

      <p v-if="roster.members.length === 0" class="roster__notice" role="status">
        Nobody is associated with this department yet.
      </p>

      <p v-else-if="members.length === 0" class="roster__notice" role="status">
        No one on this roster matches that search.
      </p>

      <div v-else class="roster__scroll">
        <table class="roster__table">
          <caption class="roster__caption">
            {{ members.length }} of {{ roster.members.length }} shown
          </caption>
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Handle</th>
              <th scope="col">Teams</th>
              <th scope="col">Phone</th>
              <th scope="col">Email</th>
              <th scope="col">Status</th>
              <th v-if="roster.emergencyContacts" scope="col">
                Emergency contact
              </th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="member in members" :key="member.membershipId">
              <th scope="row">
                {{ member.displayName }}
                <span
                  v-if="member.preferredName && member.legalName !== member.preferredName"
                  class="roster__aside"
                >
                  {{ member.legalName }}
                </span>
              </th>
              <td>{{ member.handle ?? "—" }}</td>
              <td>{{ formatTeams(member.teams) }}</td>
              <td>{{ member.phone ?? "—" }}</td>
              <td>{{ member.email ?? "—" }}</td>
              <td>
                {{ member.membershipStatus }}
                <span
                  v-if="
                    member.organizationStatus &&
                    member.organizationStatus !== member.membershipStatus
                  "
                  class="roster__aside"
                >
                  {{ member.organizationStatus }} in the organization
                </span>
              </td>
              <td v-if="roster.emergencyContacts">
                <template v-if="member.emergencyContactName || member.emergencyContactPhone">
                  {{ member.emergencyContactName ?? "Unnamed" }}
                  <span class="roster__aside">
                    {{ member.emergencyContactPhone ?? "No number recorded" }}
                  </span>
                </template>
                <template v-else>None recorded</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.roster__notice,
.roster__scope {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.roster__filters {
  display: flex;
  gap: var(--m-space-3);
  flex-wrap: wrap;
}

.roster__filter {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.9rem;
}

.roster__filter input,
.roster__filter select {
  padding: 0.4rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

/* A roster is wide and a phone is narrow; the table scrolls inside its own
   band rather than pushing the page sideways. */
.roster__scroll {
  overflow-x: auto;
}

.roster__table {
  width: 100%;
  border-collapse: collapse;
  text-align: left;
}

.roster__caption {
  text-align: left;
  padding-bottom: var(--m-space-2);
  font-size: 0.9rem;
}

.roster__table th,
.roster__table td {
  padding: var(--m-space-2);
  border-bottom: 1px solid var(--m-border-default);
  vertical-align: top;
}

.roster__aside {
  display: block;
  font-size: 0.85rem;
  font-weight: 400;
}
</style>
