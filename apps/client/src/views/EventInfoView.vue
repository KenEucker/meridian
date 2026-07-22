<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  LOCAL_DEPARTMENT_OPS_CONTEXT,
  LOCAL_PLANNING_TABLE,
} from "@/department-ops/fixtures";
import { formatTimestamp } from "@/department-ops/labels";

const eventWindow = computed(() => {
  const starts = LOCAL_PLANNING_TABLE.rows.map((row) => row.startsAt).sort();
  const ends = LOCAL_PLANNING_TABLE.rows.map((row) => row.endsAt).sort();

  return {
    startsAt: starts[0] ?? null,
    endsAt: ends.at(-1) ?? null,
  };
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
const eventInfoItems = [
  {
    id: "directions",
    title: "How to get to the event",
    body: "Placeholder: directions, access windows, gate instructions, and arrival checkpoints will be pulled from published event documents.",
  },
  {
    id: "packing",
    title: "What to bring",
    body: "Placeholder: staff packing guidance, credential requirements, weather notes, and role-specific supplies will be assembled from published documents.",
  },
  {
    id: "food",
    title: "Food and housing",
    body: "Placeholder: meal availability, housing status, camping notes, and reimbursement guidance will come from event-facing documents.",
  },
  {
    id: "documents",
    title: "Event documents",
    body: "Needs further development: this section should resolve the visible policy/procedure/event documents for the staff member and render the current published content.",
  },
];
</script>

<template>
  <section class="event-info" aria-labelledby="event-info-heading">
    <p class="event-info__nav">
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </p>

    <header class="event-info__header">
      <p class="event-info__eyebrow">Event info</p>
      <h1 id="event-info-heading">{{ LOCAL_DEPARTMENT_OPS_CONTEXT.eventLabel }}</h1>
      <p>{{ LOCAL_DEPARTMENT_OPS_CONTEXT.departmentLabel }}</p>
      <dl class="event-info__context" aria-label="Event information">
        <div>
          <dt>Operations</dt>
          <dd>{{ operationsWindowLabel }}</dd>
        </div>
        <div>
          <dt>Location</dt>
          <dd>Location document pending</dd>
        </div>
        <div>
          <dt>Description</dt>
          <dd>Event description document pending</dd>
        </div>
      </dl>
    </header>

    <div class="event-info__grid">
      <article v-for="item in eventInfoItems" :key="item.id">
        <h2>{{ item.title }}</h2>
        <p>{{ item.body }}</p>
      </article>
    </div>
  </section>
</template>

<style scoped>
.event-info {
  display: grid;
  gap: var(--m-space-4);
  width: min(100%, 72rem);
}

.event-info__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.event-info__nav a {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.event-info__header,
.event-info__grid article {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.event-info__eyebrow,
.event-info__header h1,
.event-info__header p,
.event-info__grid h2,
.event-info__grid p {
  margin: 0;
}

.event-info__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__header h1 {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.event-info__header p,
.event-info__grid p {
  color: var(--m-text-muted);
}

.event-info__context {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.event-info__context div {
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.event-info__context dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.event-info__context dd {
  margin: var(--m-space-1) 0 0;
}

.event-info__grid {
  display: grid;
  gap: var(--m-space-3);
}

.event-info__grid h2 {
  font-size: var(--m-text-base);
}

.event-info__nav a:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .event-info__context,
  .event-info__grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
