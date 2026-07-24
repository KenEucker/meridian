<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  LOCAL_DEPARTMENT_OPS_CONTEXT,
  LOCAL_PLANNING_TABLE,
} from "@/department-ops/fixtures";
import { formatTimestamp } from "@/department-ops/labels";
import { useNavigationSections } from "@/components/workflowLinks";
import { selectedFixtureDepartment } from "@/department-teams/fixtureDepartmentAccess";

// Home lists every page the current user can reach, grouped so workflows stay
// distinguishable from the individual pages they contain.
const navigationSections = useNavigationSections();

const eventWindow = computed(() => {
  const sortedStarts = LOCAL_PLANNING_TABLE.rows
    .map((row) => row.startsAt)
    .sort();
  const sortedEnds = LOCAL_PLANNING_TABLE.rows.map((row) => row.endsAt).sort();

  return {
    startsAt: sortedStarts[0] ?? null,
    endsAt: sortedEnds.at(-1) ?? null,
  };
});
const eventStatus = computed(() => {
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
const operationsWindowLabel = computed(() => {
  const startsAt = eventWindow.value.startsAt;
  const endsAt = eventWindow.value.endsAt;

  if (!startsAt || !endsAt) {
    return "Operations window not set";
  }

  return `${formatTimestamp(
    startsAt,
    LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone,
  )} to ${formatTimestamp(endsAt, LOCAL_DEPARTMENT_OPS_CONTEXT.timeZone)}`;
});
</script>

<template>
  <section class="home" aria-labelledby="home-heading">
    <header class="home__event-card">
      <div>
        <h1 id="home-heading" class="home__heading">
          {{ LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel }}
        </h1>
        <p class="home__lede">
          {{ selectedFixtureDepartment.departmentLabel }}
          operations workspace.
        </p>
        <dl class="home__event-details" aria-label="Event information">
          <div>
            <dt>Operations</dt>
            <dd>{{ operationsWindowLabel }}</dd>
          </div>
          <div>
            <dt>Location</dt>
            <dd>Location not set</dd>
          </div>
          <div>
            <dt>Description</dt>
            <dd>Description not set</dd>
          </div>
        </dl>
      </div>
      <span class="home__status" :data-status="eventStatus">
        {{ eventStatus }}
      </span>
    </header>

    <section
      v-for="group in navigationSections"
      :key="group.title"
      class="home__section"
      :aria-label="group.title"
    >
      <div class="home__section-heading">
        <h2>{{ group.title }}</h2>
        <p>{{ group.description }}</p>
      </div>
      <div class="home__grid">
        <RouterLink
          v-for="link in group.links"
          :key="`${group.title}:${link.label}`"
          :to="link.to"
          class="home__card"
        >
          <h3>{{ link.pageLabel ?? link.label }}</h3>
          <p>{{ link.description }}</p>
        </RouterLink>
      </div>
    </section>
  </section>
</template>

<style scoped>
.home {
  width: min(100%, 76rem);
}

.home__event-card {
  display: grid;
  gap: var(--m-space-4);
  align-items: start;
  margin-bottom: var(--m-space-4);
  padding: var(--m-space-5);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.home__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.home__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.home__event-details {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.home__event-details div {
  display: grid;
  gap: 0.2rem;
}

.home__event-details dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.home__event-details dd {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.home__status {
  display: inline-flex;
  align-items: center;
  justify-self: start;
  min-height: 2rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-status-success);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.home__status[data-status="ongoing"] {
  border-color: var(--m-status-success);
}

.home__status[data-status="upcoming"] {
  border-color: var(--m-status-neutral);
}

.home__status[data-status="ended"] {
  border-color: var(--m-text-muted);
}

.home__section {
  display: grid;
  gap: var(--m-space-3);
  margin-bottom: var(--m-space-5);
}

.home__section-heading h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
  letter-spacing: 0;
}

.home__section-heading p {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.home__grid {
  display: grid;
  gap: var(--m-space-3);
  grid-template-columns: minmax(0, 1fr);
}

.home__card {
  display: grid;
  align-content: start;
  gap: var(--m-space-2);
  min-height: 6.25rem;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  text-decoration: none;
  box-shadow: var(--m-shadow-sm);
}

.home__card h3 {
  margin: 0;
  font-size: var(--m-text-md);
  letter-spacing: 0;
}

.home__card p {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.home__card:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 44rem) {
  .home__event-card {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .home__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
