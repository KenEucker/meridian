<script setup lang="ts">
import { RouterLink, type RouteLocationRaw } from "vue-router";

withDefaults(
  defineProps<{
    to?: RouteLocationRaw;
    disabled?: boolean;
    variant?: "primary" | "secondary";
  }>(),
  {
    to: undefined,
    disabled: false,
    variant: "primary",
  },
);

defineEmits<{
  click: [event: MouseEvent];
}>();
</script>

<template>
  <RouterLink
    v-if="to"
    class="workflow-action"
    :class="`workflow-action--${variant}`"
    :to="to"
  >
    <slot />
  </RouterLink>
  <button
    v-else
    type="button"
    class="workflow-action"
    :class="`workflow-action--${variant}`"
    :disabled="disabled"
    @click="$emit('click', $event)"
  >
    <slot />
  </button>
</template>

<style scoped>
.workflow-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-weight: 800;
  line-height: 1;
  text-align: center;
  text-decoration: none;
  cursor: pointer;
}

.workflow-action--primary {
  border: 0;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.workflow-action--secondary {
  border: 1px solid var(--m-border-default);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
}

.workflow-action:disabled {
  cursor: default;
  opacity: 0.62;
}

.workflow-action:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
