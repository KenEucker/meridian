<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  LOCAL_DEPARTMENT_OPS_CONTEXT,
  LOCAL_LOGISTICS_DESK,
  LOCAL_PLANNING_TABLE,
} from "@/department-ops/fixtures";
import {
  attendanceStateLabel,
  formatTimestamp,
  lifecycleLabel,
  presenceStateLabel,
} from "@/department-ops/labels";
import {
  selectedFixtureDepartment,
  selectedFixtureDepartmentRouteParams,
} from "@/department-teams/fixtureDepartmentAccess";
import { LOCAL_FIELD_FIXTURE } from "@/field-reports/localFieldFixture";
import { resolveFieldSession } from "@/field-reports/fieldSession";

const session = computed(() => resolveFieldSession());
const staffId = computed(() => session.value?.staffId ?? LOCAL_FIELD_FIXTURE.staffId);
const workspace = computed(
  () => LOCAL_LOGISTICS_DESK.staffWorkspaces[staffId.value] ?? null,
);
const displayName = computed(() => workspace.value?.displayName ?? "Local Field Author");
const initials = computed(() =>
  displayName.value
    .split(/\s+/u)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase() ?? "")
    .join(""),
);
const eventWindow = computed(() => {
  const starts = LOCAL_PLANNING_TABLE.rows.map((row) => row.startsAt).sort();
  const ends = LOCAL_PLANNING_TABLE.rows.map((row) => row.endsAt).sort();

  return {
    startsAt: starts[0] ?? null,
    endsAt: ends.at(-1) ?? null,
  };
});
const eventStatus = computed<"ongoing" | "upcoming" | "ended">(() => {
  const startsAt = eventWindow.value.startsAt;
  const endsAt = eventWindow.value.endsAt;
  const asOf = Date.parse(LOCAL_DEPARTMENT_OPS_CONTEXT.asOf);

  if (!startsAt || !endsAt || Number.isNaN(asOf)) {
    return "upcoming";
  }

  if (asOf < Date.parse(startsAt)) {
    return "upcoming";
  }

  if (asOf > Date.parse(endsAt)) {
    return "ended";
  }

  return "ongoing";
});
const eventCards = computed(() => [
  {
    id: selectedFixtureDepartment.value.eventId,
    label: LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel,
    department: selectedFixtureDepartment.value.departmentLabel,
    status: eventStatus.value,
    startsAt: eventWindow.value.startsAt,
    endsAt: eventWindow.value.endsAt,
  },
]);
const selectedMemberTeams = computed(() =>
  selectedFixtureDepartment.value.teams.filter((team) => team.isMember),
);
const selectedTeamLabels = computed(() =>
  selectedMemberTeams.value.map((team) => team.teamLabel),
);
const isSelectedTeamLead = computed(() =>
  selectedFixtureDepartment.value.teams.some((team) => team.isTeamLead),
);
const leadTeamId = computed(
  () =>
    selectedFixtureDepartment.value.teams.find((team) => team.isTeamLead)
      ?.teamId ?? null,
);
const scheduleRows = computed(() =>
  (workspace.value?.shiftCards ?? [])
    .filter(
      (card) =>
        card.lifecycle === "active" ||
        card.lifecycle === "upcoming" ||
        card.attendanceState === "checked_in",
    )
    .slice()
    .sort((left, right) => left.startsAt.localeCompare(right.startsAt)),
);
const firstServiceYear = computed(() => {
  const years = (workspace.value?.shiftCards ?? [])
    .map((card) => new Date(card.startsAt).getUTCFullYear())
    .filter((year) => Number.isFinite(year));

  return years.length > 0
    ? Math.min(...years)
    : new Date(LOCAL_DEPARTMENT_OPS_CONTEXT.asOf).getUTCFullYear();
});
const yearsOfService = computed(() => {
  const asOfYear = new Date(LOCAL_DEPARTMENT_OPS_CONTEXT.asOf).getUTCFullYear();

  return Math.max(1, asOfYear - firstServiceYear.value + 1);
});
const personalDetails = computed(() => [
  {
    label: "Department",
    value: selectedFixtureDepartment.value.departmentLabel,
  },
  {
    label: "Team",
    value:
      selectedTeamLabels.value.length > 0
        ? selectedTeamLabels.value.join(", ")
        : "Not set",
  },
  {
    label: "Role",
    value: selectedFixtureDepartment.value.roleLabel,
  },
  {
    label: "Handle",
    value: workspace.value?.handle ? `@${workspace.value.handle}` : "Not set",
  },
  {
    label: "Presence",
    value: workspace.value
      ? presenceStateLabel(workspace.value.presenceState)
      : "Not available",
  },
  {
    label: "Years of service",
    value: `${yearsOfService.value}`,
  },
  {
    label: "Events worked",
    value: `${eventCards.value.length}`,
  },
]);
/**
 * Role-aware event routing (M11.20, UI contract 12.3). Department leads land on
 * Department Overview, team leads on the team overview for a team they lead,
 * and everyone else on Event Info. The team-lead branch previously fell through
 * to the Admin page as an interim, which answered who is on the team but not
 * what the team is doing.
 */
const currentEventTarget = computed(() => {
  if (selectedFixtureDepartment.value.isDepartmentLead) {
    return {
      name: "events.departments.overview",
      params: selectedFixtureDepartmentRouteParams.value,
    };
  }

  if (isSelectedTeamLead.value && leadTeamId.value !== null) {
    return {
      name: "events.departments.teams.show",
      params: {
        ...selectedFixtureDepartmentRouteParams.value,
        teamId: leadTeamId.value,
      },
    };
  }

  return {
    name: "events.info",
    params: {
      eventId: selectedFixtureDepartment.value.eventId,
    },
  };
});
const currentEventTargetLabel = computed(() => {
  if (selectedFixtureDepartment.value.isDepartmentLead) {
    return "Opens Department Overview";
  }

  return isSelectedTeamLead.value && leadTeamId.value !== null
    ? "Opens Team Overview"
    : "Opens Event Info";
});
</script>

<template>
  <section class="me" aria-labelledby="me-heading">
    <p class="me__nav">
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </p>

    <header class="me__hero">
      <div
        class="me__photo"
        role="img"
        :aria-label="`${displayName} profile photo`"
      >
        <span>{{ initials }}</span>
      </div>
      <div class="me__identity">
        <p class="me__eyebrow">Staff profile</p>
        <h1 id="me-heading">{{ displayName }}</h1>
        <p>
          {{ LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel }} -
          {{ selectedFixtureDepartment.departmentLabel }}
        </p>
      </div>
      <dl class="me__details" aria-label="Personal details">
        <div v-for="detail in personalDetails" :key="detail.label">
          <dt>{{ detail.label }}</dt>
          <dd>{{ detail.value }}</dd>
        </div>
      </dl>
    </header>

    <nav class="me__links" aria-label="Me links">
      <RouterLink
        :to="{
          name: 'events.info',
          params: { eventId: selectedFixtureDepartment.eventId },
        }"
      >
        Event Info
      </RouterLink>
      <RouterLink :to="{ name: 'staff.field-reports.index' }">
        My Field Reports
      </RouterLink>
      <RouterLink :to="{ name: 'readiness' }">Device Readiness</RouterLink>
      <RouterLink :to="{ name: 'settings.about' }">Account and Device</RouterLink>
    </nav>

    <section class="me__section" aria-labelledby="me-assignments-heading">
      <div class="me__section-heading">
        <p class="me__eyebrow">Assignments</p>
        <h2 id="me-assignments-heading">Upcoming assignments and schedule</h2>
      </div>

      <div class="me__event-list" aria-label="Events">
        <RouterLink
          v-for="event in eventCards"
          :key="event.id"
          class="me__event"
          :to="currentEventTarget"
        >
          <span class="me__event-status" :data-status="event.status">
            {{ event.status }}
          </span>
          <strong>{{ event.label }}</strong>
          <span>{{ event.department }}</span>
          <small v-if="event.startsAt && event.endsAt">
            {{ formatTimestamp(event.startsAt, LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone) }}
            to
            {{ formatTimestamp(event.endsAt, LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone) }}
          </small>
          <small>{{ currentEventTargetLabel }}</small>
        </RouterLink>
      </div>

      <section
        class="me__schedule"
        aria-labelledby="me-current-schedule-heading"
      >
        <h3 id="me-current-schedule-heading">
          {{
            eventStatus === "ongoing"
              ? "Schedule for ongoing event"
              : "Schedule for next event"
          }}
        </h3>
        <p v-if="scheduleRows.length === 0" role="status">
          No upcoming assignments are available in the local fixture.
        </p>
        <ul v-else class="me__schedule-list">
          <li v-for="shift in scheduleRows" :key="shift.shiftId">
            <div>
              <strong>{{ shift.title }}</strong>
              <span>
                {{ shift.teamLabel }} -
                {{ lifecycleLabel(shift.lifecycle) }}
              </span>
            </div>
            <dl>
              <div>
                <dt>When</dt>
                <dd>
                  {{ formatTimestamp(shift.startsAt, LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone) }}
                  to
                  {{ formatTimestamp(shift.endsAt, LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone) }}
                </dd>
              </div>
              <div>
                <dt>Status</dt>
                <dd>
                  {{
                    shift.attendanceState
                      ? attendanceStateLabel(shift.attendanceState)
                      : "Not assigned"
                  }}
                </dd>
              </div>
            </dl>
          </li>
        </ul>
      </section>
    </section>
  </section>
</template>

<style scoped>
.me {
  display: grid;
  gap: var(--m-space-5);
  width: min(100%, 72rem);
}

.me__hero,
.me__section {
  display: grid;
  gap: var(--m-space-4);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.me__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.me__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.me__photo {
  display: grid;
  place-items: center;
  width: clamp(7rem, 42vw, 10rem);
  aspect-ratio: 1;
  border: 1px solid var(--m-border-default);
  border-radius: 50%;
  background:
    radial-gradient(
      circle at 34% 26%,
      color-mix(in srgb, var(--m-action-secondary-bg) 24%, transparent),
      transparent 32%
    ),
    linear-gradient(
      145deg,
      color-mix(in srgb, var(--m-surface-base) 88%, transparent),
      color-mix(in srgb, var(--m-action-primary-bg) 18%, var(--m-surface-raised))
    );
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  font-weight: 900;
}

.me__photo span {
  display: grid;
  place-items: center;
  width: 58%;
  aspect-ratio: 1;
  border-radius: 50%;
  background: color-mix(in srgb, var(--m-surface-overlay) 72%, transparent);
}

.me__identity {
  display: grid;
  gap: var(--m-space-1);
}

.me__identity h1,
.me__section-heading h2,
.me__schedule h3 {
  margin: 0;
  font-family: var(--m-font-heading);
}

.me__identity h1 {
  font-size: var(--m-text-xl);
}

.me__identity p,
.me__section-heading,
.me__schedule p {
  margin: 0;
  color: var(--m-text-muted);
}

.me__eyebrow {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.me__details {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(8rem, 1fr));
  gap: var(--m-space-2);
  margin: 0;
}

.me__details div {
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.me__details dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.me__details dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  font-weight: 800;
}

.me__links,
.me__event-list {
  display: grid;
  gap: var(--m-space-3);
}

.me__links a {
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

.me__event,
.me__schedule-list li {
  display: grid;
  gap: var(--m-space-4);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  text-decoration: none;
}

.me__event strong,
.me__schedule-list strong {
  font-size: var(--m-text-base);
}

.me__event span,
.me__event small,
.me__schedule-list span,
.me__schedule-list dd {
  color: var(--m-text-muted);
}

.me__event-status {
  justify-self: start;
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.me__event-status[data-status="ongoing"] {
  border-color: var(--m-status-success);
  color: var(--m-text-primary);
}

.me__schedule {
  display: grid;
  gap: var(--m-space-3);
}

.me__schedule-list {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.me__schedule-list dl {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.me__schedule-list li > div:first-child {
  display: grid;
  gap: var(--m-space-2);
}

.me__schedule-list dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.me__schedule-list dd {
  margin: var(--m-space-1) 0 0;
}

.me__links a:focus-visible,
.me__nav a:focus-visible,
.me__event:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .me__hero {
    grid-template-columns: auto minmax(12rem, 1fr);
    align-items: center;
  }

  .me__details {
    grid-column: 1 / -1;
  }

  .me__links {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }

  .me__schedule-list dl {
    grid-template-columns: 1fr minmax(8rem, auto);
  }
}
</style>
