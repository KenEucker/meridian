<script setup lang="ts">
/**
 * Wrapper for a feature section that appears both as its own page and as one
 * featureset inside a workflow hub.
 *
 * `variant="page"` renders bare, because the page shell already supplies the
 * heading. `variant="section"` renders its own heading and action row so the
 * hub page can stack several featuresets without duplicating markup.
 */
withDefaults(
  defineProps<{
    title: string;
    headingId: string;
    description?: string;
    variant?: "page" | "section";
  }>(),
  {
    description: "",
    variant: "page",
  },
);
</script>

<template>
  <div v-if="variant === 'page'" class="workflow-section workflow-section--page">
    <div v-if="$slots.actions" class="workflow-section__actions">
      <slot name="actions" />
    </div>
    <slot />
  </div>
  <section
    v-else
    class="workflow-section workflow-section--embedded"
    :aria-labelledby="headingId"
  >
    <div class="workflow-section__heading">
      <div>
        <h2 :id="headingId">{{ title }}</h2>
        <p v-if="description">{{ description }}</p>
      </div>
      <div v-if="$slots.actions" class="workflow-section__actions">
        <slot name="actions" />
      </div>
    </div>
    <slot />
  </section>
</template>

<style scoped>
.workflow-section {
  display: grid;
  gap: var(--m-space-3);
  min-width: 0;
}

.workflow-section--embedded {
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.workflow-section__heading {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--m-space-3);
}

.workflow-section__heading h2 {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
  letter-spacing: 0;
}

.workflow-section__heading p {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.workflow-section__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}
</style>
