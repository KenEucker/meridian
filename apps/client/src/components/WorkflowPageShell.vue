<script setup lang="ts">
import WorkflowPageHeading from "@/components/WorkflowPageHeading.vue";

withDefaults(
  defineProps<{
    title: string;
    headingId: string;
    eyebrow?: string;
    lede?: string;
    freshness?: string;
  }>(),
  {
    eyebrow: "",
    lede: "",
    freshness: "",
  },
);
</script>

<template>
  <section class="workflow-page" :aria-labelledby="headingId">
    <WorkflowPageHeading
      :heading-id="headingId"
      :title="title"
      :department-name="eyebrow"
      :description="lede"
      :freshness="freshness"
    >
      <template v-if="$slots.nav" #back>
        <slot name="nav" />
      </template>
      <template v-if="$slots.actions" #actions>
        <slot name="actions" />
      </template>
      <template v-if="$slots.mark" #mark>
        <slot name="mark" />
      </template>
      <template v-if="$slots['under-title']" #under-title>
        <slot name="under-title" />
      </template>
      <template v-if="$slots['heading-cards'] || $slots['header-body']" #cards>
        <slot name="heading-cards">
          <slot name="header-body" />
        </slot>
      </template>
      <template v-if="$slots.navigation" #navigation>
        <slot name="navigation" />
      </template>
    </WorkflowPageHeading>

    <slot />
  </section>
</template>

<style scoped>
.workflow-page {
  display: grid;
  /* Rows keep their content height; slack collects at the end of the page
     rather than being shared out as gaps between unrelated bands. */
  align-content: start;
  gap: var(--m-stack-gap);
  width: var(--m-content-workflow);
  min-width: 0;
}
</style>
