<script setup lang="ts">
/**
 * Wrapper for a stack of StaffListCard items, with the empty state built in so
 * every staff list says the same thing when it has nothing to show.
 */
withDefaults(
  defineProps<{
    label: string;
    empty?: boolean;
    emptyMessage?: string;
  }>(),
  {
    empty: false,
    emptyMessage: "Nothing to show yet.",
  },
);
</script>

<template>
  <p v-if="empty" class="staff-card-list__empty" role="status">
    {{ emptyMessage }}
  </p>
  <ul v-else class="staff-card-list" :aria-label="label">
    <slot />
  </ul>
</template>

<style scoped>
.staff-card-list {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: 0;
  list-style: none;
  min-width: 0;
}

.staff-card-list__empty {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px dashed var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-text-muted);
}
</style>
