<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

/**
 * Page frame for staff-facing informational pages.
 *
 * These are the pages most people read on a phone between shifts — Event Info,
 * Documents, Shifts, Trainings, My Field Reports — so the frame is mobile-first:
 * one column, thumb-sized full-width actions, cards rather than a table that
 * scrolls sideways.
 *
 * Mobile-first is where it starts, not where it stops. The container keeps
 * growing with the viewport, the header stops stacking once there is room
 * beside the title, and card lists inside tile into columns. What stays bounded
 * is reading measure, not layout width: prose is capped per block so a wall
 * display shows more records rather than wider sentences.
 *
 * Lead surfaces keep WorkflowPageShell: a lead comparing coverage across teams
 * needs the table, and a table is the right shape for that work.
 */
withDefaults(
  defineProps<{
    headingId: string;
    title: string;
    eyebrow?: string;
    lede?: string;
    context?: string;
    backTo?: RouteLocationRaw;
    backLabel?: string;
  }>(),
  {
    eyebrow: "",
    lede: "",
    context: "",
    backTo: () => ({ name: "home" }),
    backLabel: "Back To Home",
  },
);
</script>

<template>
  <section class="staff-page" :aria-labelledby="headingId">
    <p class="staff-page__nav">
      <slot name="nav">
        <RouterLink :to="backTo">{{ backLabel }}</RouterLink>
      </slot>
    </p>

    <header class="staff-page__header">
      <div class="staff-page__title">
        <p v-if="eyebrow" class="staff-page__eyebrow">{{ eyebrow }}</p>
        <h1 :id="headingId" class="staff-page__heading">{{ title }}</h1>
        <p v-if="lede" class="staff-page__lede">{{ lede }}</p>
        <p v-if="context" class="staff-page__context">{{ context }}</p>
        <slot name="under-title" />
      </div>

      <div v-if="$slots.actions" class="staff-page__actions">
        <slot name="actions" />
      </div>
    </header>

    <div class="staff-page__body">
      <slot />
    </div>
  </section>
</template>

<style scoped>
.staff-page {
  display: grid;
  align-content: start;
  gap: var(--m-stack-gap);
  width: var(--m-content-staff);
  min-width: 0;
}

.staff-page__nav {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.staff-page__nav :deep(a) {
  display: inline-flex;
  align-items: center;
  min-height: 2.75rem;
  color: var(--m-text-secondary);
  text-decoration: none;
}

.staff-page__nav :deep(a:focus-visible) {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.staff-page__header {
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
}

.staff-page__title {
  display: grid;
  gap: var(--m-space-2);
  min-width: 0;
  max-width: var(--m-measure);
}

.staff-page__eyebrow,
.staff-page__heading,
.staff-page__lede,
.staff-page__context {
  margin: 0;
}

.staff-page__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-transform: uppercase;
}

.staff-page__heading {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.staff-page__lede,
.staff-page__context {
  color: var(--m-text-muted);
}

.staff-page__context {
  font-size: var(--m-text-sm);
}

.staff-page__actions {
  display: grid;
  gap: var(--m-space-2);
  margin-top: var(--m-space-2);
}

/* Touch-first: primary actions are full-width thumb targets on a phone. */
.staff-page__actions :deep(a),
.staff-page__actions :deep(button) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  width: 100%;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
  line-height: 1;
  text-align: center;
  text-decoration: none;
  cursor: pointer;
}

/* Consumers mark the one action that starts the page's main task. */
.staff-page__actions :deep([data-variant="primary"]) {
  border-color: var(--m-action-primary-bg);
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.staff-page__actions :deep(a:focus-visible),
.staff-page__actions :deep(button:focus-visible) {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.staff-page__body {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
}

@media (min-width: 44rem) {
  .staff-page__actions {
    display: flex;
    flex-wrap: wrap;
  }

  .staff-page__actions :deep(a),
  .staff-page__actions :deep(button) {
    width: auto;
  }
}

/*
 * Past this width the actions have somewhere to sit other than under the title,
 * which is a row of vertical space back on every staff page.
 */
@media (min-width: 64rem) {
  .staff-page__header {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
    gap: var(--m-space-4);
  }

  .staff-page__actions {
    margin-top: 0;
    justify-content: flex-end;
  }
}
</style>
