<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

/**
 * One item in a staff-facing list, shaped as a card rather than a table row.
 *
 * A table row that scrolls sideways hides its own columns on a phone: the
 * reader has to remember which header they were under. A card keeps every field
 * labelled and on screen, and gives the whole item a thumb-sized tap target
 * when it links somewhere.
 */
withDefaults(
  defineProps<{
    title: string;
    to?: RouteLocationRaw | null;
    eyebrow?: string;
    subtitle?: string;
    /** Labelled fields shown under the title, e.g. schedule and status. */
    meta?: readonly { readonly label: string; readonly value: string }[];
    status?: string;
  }>(),
  {
    to: null,
    eyebrow: "",
    subtitle: "",
    meta: () => [],
    status: "",
  },
);
</script>

<template>
  <li class="staff-card">
    <component
      :is="to ? RouterLink : 'div'"
      v-bind="to ? { to } : {}"
      class="staff-card__main"
    >
      <span v-if="eyebrow" class="staff-card__eyebrow">{{ eyebrow }}</span>
      <strong class="staff-card__title">{{ title }}</strong>
      <span v-if="subtitle" class="staff-card__subtitle">{{ subtitle }}</span>
      <span v-if="status" class="staff-card__status">{{ status }}</span>
    </component>

    <dl v-if="meta.length > 0" class="staff-card__meta">
      <div v-for="field in meta" :key="field.label">
        <dt>{{ field.label }}</dt>
        <dd>{{ field.value }}</dd>
      </div>
    </dl>

    <slot />

    <div v-if="$slots.actions" class="staff-card__actions">
      <slot name="actions" />
    </div>
  </li>
</template>

<style scoped>
.staff-card {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.staff-card__main {
  display: grid;
  gap: var(--m-space-1);
  min-width: 0;
  color: var(--m-text-primary);
  text-decoration: none;
}

a.staff-card__main {
  min-height: 2.75rem;
  align-content: center;
}

a.staff-card__main:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.staff-card__eyebrow {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.staff-card__title {
  font-size: var(--m-text-base);
  font-weight: 800;
}

.staff-card__subtitle {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.staff-card__status {
  justify-self: start;
  margin-top: var(--m-space-1);
  padding: 0.2rem 0.55rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.staff-card__meta {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
}

.staff-card__meta dt {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.staff-card__meta dd {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-primary);
  overflow-wrap: anywhere;
}

.staff-card__actions {
  display: grid;
  gap: var(--m-space-2);
}

.staff-card__actions :deep(a),
.staff-card__actions :deep(button) {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  width: 100%;
  min-height: 2.75rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 800;
  line-height: 1;
  text-decoration: none;
  cursor: pointer;
}

.staff-card__actions :deep(a:focus-visible),
.staff-card__actions :deep(button:focus-visible) {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 44rem) {
  .staff-card__meta {
    grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  }

  .staff-card__actions {
    display: flex;
    flex-wrap: wrap;
  }

  .staff-card__actions :deep(a),
  .staff-card__actions :deep(button) {
    width: auto;
  }
}
</style>
