<script setup lang="ts">
import ContentGrid from "@/components/ContentGrid.vue";

/**
 * Stack of StaffListCard items, with the empty state built in so every staff
 * list says the same thing when it has nothing to show.
 *
 * One column on a phone; a column per tile width beyond that, so a roster that
 * needed four screens of scrolling on a laptop needs one on a large display.
 */
withDefaults(
  defineProps<{
    label: string;
    empty?: boolean;
    emptyMessage?: string;
    /** Widen the tile when cards carry long labelled fields. */
    min?: "tile" | "wide";
  }>(),
  {
    empty: false,
    emptyMessage: "Nothing to show yet.",
    min: "tile",
  },
);
</script>

<template>
  <p v-if="empty" class="staff-card-list__empty" role="status">
    {{ emptyMessage }}
  </p>
  <ContentGrid v-else as="ul" :label="label" :min="min" class="staff-card-list">
    <slot />
  </ContentGrid>
</template>

<style scoped>
.staff-card-list__empty {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px dashed var(--m-border-default);
  border-radius: var(--m-radius-sm);
  color: var(--m-text-muted);
}
</style>
