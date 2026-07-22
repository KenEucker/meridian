<script setup lang="ts">
defineProps<{
  label: string;
  value: string | number;
  detail?: string;
}>();
</script>

<template>
  <article class="workflow-heading-card">
    <span>{{ label }}</span>
    <strong>{{ value }}</strong>
    <small v-if="detail">{{ detail }}</small>
  </article>
</template>

<style scoped>
.workflow-heading-card {
  display: grid;
  gap: var(--m-space-1);
  min-width: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: 8px;
  background: var(--m-surface-base);
}

.workflow-heading-card span {
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.workflow-heading-card strong {
  color: var(--m-text-primary);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  line-height: 1.05;
}

.workflow-heading-card small {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  line-height: 1.35;
}
</style>
