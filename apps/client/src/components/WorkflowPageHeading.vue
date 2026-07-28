<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

import WorkflowPageNavigation from "@/components/WorkflowPageNavigation.vue";

/**
 * Workflow page heading.
 *
 * The heading is the same three strings, a few summary numbers, and a couple of
 * links at every width — but stacking them costs a phone nothing and costs a
 * wide display a third of the fold. So the band re-flows rather than re-sizes:
 * department, title, and description share a baseline row once there is width
 * for them, summary cards move up beside the title instead of below it, and
 * block padding tightens through the density tokens while inline padding grows.
 *
 * Nothing is hidden at any width. The same content is rearranged, so a wide
 * screen buys visible rows of data rather than a taller header.
 */
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
      <!--
        The mark slot carries the identity of whatever this page is about — a
        team's logo on Team Overview, for instance (BRAND-025). It sits beside
        the heading strings rather than above them so the band does not grow a
        row on a phone for a 2rem glyph.
      -->
      <div class="workflow-page-heading__identity">
        <div v-if="$slots.mark" class="workflow-page-heading__mark">
          <slot name="mark" />
        </div>
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
  gap: var(--m-stack-gap);
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
  gap: var(--m-stack-gap);
  min-width: 0;
  padding: var(--m-pad-block) var(--m-pad-inline);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

.workflow-page-heading__identity {
  display: flex;
  align-items: flex-start;
  gap: var(--m-space-3);
  min-width: 0;
}

.workflow-page-heading__mark {
  display: flex;
  flex: none;
  /* Optically aligned with the eyebrow rather than the top of the box, so the
     mark reads as part of the heading rather than as a floating badge. */
  margin-top: 0.1rem;
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
  max-width: var(--m-measure);
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
  }

  .workflow-page-heading__cards {
    grid-column: 1 / -1;
  }
}

/*
 * Wide: the three heading strings share one baseline row. Three stacked lines
 * of one-line text is the single most repeated piece of wasted height in the
 * product, because every workflow page has this band.
 */
@media (min-width: 90rem) {
  .workflow-page-heading__title {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: var(--m-space-2) var(--m-space-3);
  }

  .workflow-page-heading__title h1,
  .workflow-page-heading__description {
    margin-top: 0;
  }

  .workflow-page-heading__department::after {
    content: "";
  }

  .workflow-page-heading__description {
    flex: 1 1 20rem;
  }
}

/*
 * Wider still: summary cards move out of their own row and sit beside the
 * title, so the whole heading is one band rather than two.
 *
 * They are forced into a single row of content-sized columns to get there. A
 * card grid that wraps inside a side column is taller than the full-width row
 * it replaced, which would make this rule cost height rather than save it.
 */
@media (min-width: 120rem) {
  .workflow-page-heading__card {
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
  }

  .workflow-page-heading__cards {
    grid-column: 2;
    grid-row: 1;
  }

  .workflow-page-heading__cards :deep(.workflow-heading-card-grid) {
    grid-template-columns: none;
    grid-auto-flow: column;
    grid-auto-columns: minmax(7rem, max-content);
  }

  .workflow-page-heading__actions {
    grid-column: 3;
    grid-row: 1;
    align-items: center;
  }
}
</style>
