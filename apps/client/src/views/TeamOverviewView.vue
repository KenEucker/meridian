<script setup lang="ts">
import { computed } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  attendanceStateLabel,
  formatTimestamp,
  lifecycleLabel,
} from "@/department-ops/labels";
import {
  resolveTeamOverview,
  type TeamOverviewModel,
} from "@/department-teams/teamOverviewModel";

const route = useRoute();
const router = useRouter();

const overview = computed<TeamOverviewModel | null>(() =>
  resolveTeamOverview(
    typeof route.params.teamId === "string" ? route.params.teamId : null,
  ),
);
const routeParams = computed(() => ({
  eventId: String(route.params.eventId ?? ""),
  departmentId: String(route.params.departmentId ?? ""),
}));

function onTeamChange(event: Event): void {
  const teamId = (event.target as HTMLSelectElement).value;

  void router.replace({
    name: "events.departments.teams.show",
    params: { ...routeParams.value, teamId },
  });
}
</script>

<template>
  <WorkflowPageShell
    heading-id="team-overview-heading"
    :title="overview ? overview.team.name : 'Team Overview'"
    :eyebrow="overview ? overview.departmentLabel : 'Team overview'"
    :lede="
      overview
        ? overview.team.description ?? 'Team situational awareness for this event.'
        : ''
    "
  >
    <template #nav>
      <RouterLink :to="{ name: 'staff.me' }">Back To Me</RouterLink>
    </template>

    <p v-if="!overview" class="team-overview__restricted" role="status">
      Team Overview requires department lead or team lead authority for a team in
      this department.
    </p>

    <template v-else>
      <section
        class="team-overview__panel"
        aria-labelledby="team-overview-summary-heading"
      >
        <div class="team-overview__panel-heading">
          <h2 id="team-overview-summary-heading">Right now</h2>
          <label v-if="overview.availableTeams.length > 1">
            Team
            <select :value="overview.team.id" @change="onTeamChange">
              <option
                v-for="team in overview.availableTeams"
                :key="team.id"
                :value="team.id"
              >
                {{ team.name }}
              </option>
            </select>
          </label>
        </div>

        <dl class="team-overview__summary" aria-label="Team summary">
          <div>
            <dt>Your role</dt>
            <dd>{{ overview.roleLabel }}</dd>
          </div>
          <div>
            <dt>Team members</dt>
            <dd>{{ overview.summary.memberCount }}</dd>
          </div>
          <div>
            <dt>Checked in</dt>
            <dd>{{ overview.summary.checkedInCount }}</dd>
          </div>
          <div>
            <dt>Active shifts</dt>
            <dd>{{ overview.summary.activeShiftCount }}</dd>
          </div>
          <div>
            <dt>Upcoming shifts</dt>
            <dd>{{ overview.summary.upcomingShiftCount }}</dd>
          </div>
        </dl>
      </section>

      <section
        class="team-overview__panel"
        aria-labelledby="team-overview-shifts-heading"
      >
        <div class="team-overview__panel-heading">
          <h2 id="team-overview-shifts-heading">Team shifts</h2>
        </div>
        <p v-if="overview.shifts.length === 0" role="status">
          No shifts are scheduled for this team in the current event window.
        </p>
        <ul v-else class="team-overview__list">
          <li v-for="shift in overview.shifts" :key="shift.shiftId">
            <div>
              <strong>{{ shift.title }}</strong>
              <span>{{ lifecycleLabel(shift.lifecycle) }}</span>
            </div>
            <dl>
              <div>
                <dt>When</dt>
                <dd>
                  {{ formatTimestamp(shift.startsAt, overview.timeZone) }} to
                  {{ formatTimestamp(shift.endsAt, overview.timeZone) }}
                </dd>
              </div>
              <div>
                <dt>Staffing</dt>
                <dd>
                  {{ shift.checkedInCount }} checked in of
                  {{ shift.signedUpOrAssignedCount }} assigned
                  <template v-if="shift.capacity !== null">
                    / {{ shift.capacity }} target
                  </template>
                </dd>
              </div>
              <div>
                <dt>Status</dt>
                <dd>{{ shift.statusLabel }}</dd>
              </div>
            </dl>
          </li>
        </ul>
      </section>

      <section
        class="team-overview__panel"
        aria-labelledby="team-overview-roster-heading"
      >
        <div class="team-overview__panel-heading">
          <h2 id="team-overview-roster-heading">Team roster</h2>
        </div>
        <p v-if="overview.members.length === 0" role="status">
          No staff are assigned to this team yet.
        </p>
        <ul v-else class="team-overview__list">
          <li v-for="member in overview.members" :key="member.staffId">
            <div>
              <strong>{{ member.displayName }}</strong>
              <span>
                {{ member.roleLabel
                }}<template v-if="member.handle"> / @{{ member.handle }}</template>
              </span>
            </div>
            <dl>
              <div>
                <dt>Current shift</dt>
                <dd>
                  {{
                    member.attendanceState
                      ? attendanceStateLabel(member.attendanceState)
                      : "Not on an active shift"
                  }}
                </dd>
              </div>
            </dl>
          </li>
        </ul>
      </section>

      <nav class="team-overview__links" aria-label="Team workflows">
        <RouterLink
          :to="{ name: 'events.departments.teams.index', params: routeParams }"
        >
          Team And Staff Admin
        </RouterLink>
        <RouterLink
          :to="{ name: 'events.departments.shifts.index', params: routeParams }"
        >
          Shifts
        </RouterLink>
        <RouterLink
          :to="{
            name: 'events.departments.documents.index',
            params: routeParams,
          }"
        >
          Documents
        </RouterLink>
        <RouterLink
          :to="{ name: 'events.info', params: { eventId: routeParams.eventId } }"
        >
          Event Info
        </RouterLink>
      </nav>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.team-overview__panel {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.team-overview__panel-heading {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  align-items: center;
  justify-content: space-between;
}

.team-overview__panel h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-base);
}

.team-overview__panel p {
  margin: 0;
  color: var(--m-text-muted);
}

.team-overview__panel-heading label {
  display: grid;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.team-overview__panel-heading select {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.team-overview__summary {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
  gap: var(--m-space-2);
  margin: 0;
}

.team-overview__summary div,
.team-overview__list li {
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.team-overview__summary dt,
.team-overview__list dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.team-overview__summary dd,
.team-overview__list dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  font-weight: 800;
}

.team-overview__list {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.team-overview__list dl {
  display: grid;
  gap: var(--m-space-2);
  margin: var(--m-space-2) 0 0;
}

.team-overview__list li > div:first-child {
  display: grid;
  gap: var(--m-space-1);
}

.team-overview__list span {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.team-overview__links {
  display: grid;
  gap: var(--m-space-3);
}

.team-overview__links a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-weight: 800;
  text-decoration: none;
}

.team-overview__restricted {
  margin: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
}

.team-overview__links a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .team-overview__list dl {
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  }

  .team-overview__links {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}
</style>
