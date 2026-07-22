<script setup lang="ts">
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";

withDefaults(
  defineProps<{
    title: string;
    eyebrow: string;
    headingId?: string;
    lede?: string;
    freshness?: string;
  }>(),
  {
    headingId: "dept-ops-heading",
    lede: "",
    freshness: "",
  },
);
</script>

<template>
  <WorkflowPageShell
    class="dept-ops"
    :heading-id="headingId"
    :title="title"
    :eyebrow="eyebrow"
    :lede="lede"
    :freshness="freshness"
  >
    <template #nav>
      <slot name="nav" />
    </template>
    <template v-if="$slots.actions" #actions>
      <slot name="actions" />
    </template>
    <template v-if="$slots.navigation" #navigation>
      <slot name="navigation" />
    </template>
    <template v-if="$slots['heading-cards']" #heading-cards>
      <slot name="heading-cards" />
    </template>
    <slot />
  </WorkflowPageShell>
</template>

<style scoped>
.dept-ops {
  width: min(100%, 76rem);
  min-width: 0;
}
</style>
