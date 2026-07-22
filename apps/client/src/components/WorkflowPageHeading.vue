<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

import WorkflowPageNavigation from "@/components/WorkflowPageNavigation.vue";

withDefaults(
  defineProps<{
    headingId: string;
    title: string;
    departmentName?: string;
    description?: string;
    freshness?: string;
    backTo?: RouteLocationRaw;
  }>(),
  {
    departmentName: "",
    description: "",
    freshness: "",
    backTo: () => ({ name: "home" }),
  },
);
</script>

<template>
  <div class="workflow-page-heading">
    <p class="workflow-page-heading__back">
      <slot name="back">
        <RouterLink :to="backTo">Back To Home</RouterLink>
      </slot>
    </p>

    <header class="workflow-page-heading__card">
      <div class="workflow-page-heading__title">
        <p v-if="departmentName" class="workflow-page-heading__department">
          {{ departmentName }}
        </p>
        <h1 :id="headingId">{{ title }}</h1>
        <p v-if="description" class="workflow-page-heading__description">
          {{ description }}
        </p>
        <slot name="under-title" />
      </div>

      <div
        v-if="$slots.actions || freshness"
        class="workflow-page-heading__actions"
      >
        <slot name="actions" />
        <p
          v-if="freshness"
          class="workflow-page-heading__freshness"
          role="status"
        >
          {{ freshness }}
        </p>
      </div>

      <div v-if="$slots.cards" class="workflow-page-heading__cards">
        <slot name="cards" />
      </div>
    </header>

    <WorkflowPageNavigation v-if="$slots.navigation">
      <slot name="navigation" />
    </WorkflowPageNavigation>
  </div>
</template>

<style scoped>
.workflow-page-heading {
  display: grid;
  gap: var(--m-space-4);
  min-width: 0;
}

.workflow-page-heading__back {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.workflow-page-heading__back :deep(a) {
  color: var(--m-text-secondary);
  text-decoration: none;
}

.workflow-page-heading__back :deep(a:focus-visible) {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.workflow-page-heading__card {
  display: grid;
  gap: var(--m-space-4);
  min-width: 0;
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.workflow-page-heading__title {
  min-width: 0;
}

.workflow-page-heading__department,
.workflow-page-heading__title h1,
.workflow-page-heading__description,
.workflow-page-heading__freshness {
  margin: 0;
}

.workflow-page-heading__department {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.workflow-page-heading__title h1 {
  margin-top: var(--m-space-1);
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.workflow-page-heading__description {
  margin-top: var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.workflow-page-heading__actions {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  gap: var(--m-space-2);
}

.workflow-page-heading__freshness {
  display: inline-flex;
  align-items: center;
  min-height: 2.25rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  white-space: nowrap;
}

.workflow-page-heading__cards {
  min-width: 0;
}

@media (min-width: 48rem) {
  .workflow-page-heading__card {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
    padding: var(--m-space-5);
  }

  .workflow-page-heading__cards {
    grid-column: 1 / -1;
  }
}
</style>
