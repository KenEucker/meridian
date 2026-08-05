<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import StatusPill, { type StatusPillTone } from "@/components/StatusPill.vue";
import {
  widgetDestination,
  type DashboardContext,
  type DashboardWidget,
} from "@/dashboard/dashboardModel";

/**
 * One dashboard widget (M18.28; UI contract 13; dashboard widget spec 4, 5, 11,
 * 12, 14).
 *
 * The required anatomy on one card: the title, the attention level, what the
 * widget found or the reassurance that it found nothing, the capped list behind
 * it, and the primary action. Everything on it arrives from the node — this
 * component decides nothing about what is true, only how it reads.
 *
 * Three rules the widget spec is specific about, kept here:
 *
 *  1. **Attention is never color alone** (spec 5). The level is rendered as a
 *     word beside the title. The tint and the pill repeat it; a reader who sees
 *     neither reads the same thing.
 *  2. **A quiet widget is calm, not empty** (spec 14). It prints the contract's
 *     own sentence rather than a dash, an empty list, or a zero, and it never
 *     borrows the reporting card's emphasis.
 *  3. **An unavailable action is absent** (CLIENT-005). A widget whose
 *     destination this client has not built renders no link at all rather than a
 *     disabled control — the reading is still worth having.
 */
const props = defineProps<{
  readonly widget: DashboardWidget;
  readonly context: DashboardContext;
}>();

const destination = computed(() =>
  widgetDestination(props.widget.actionSurface, props.context),
);

/**
 * The attention scale mapped onto the status pill's consequence scale.
 *
 * Two vocabularies deliberately kept apart: attention says how hard to pull,
 * the pill's tone says what a state costs. Restricted takes `info` rather than
 * a louder tone because it is about who may see a thing rather than about
 * anything going wrong.
 */
const tone = computed<StatusPillTone>(() => {
  switch (props.widget.attention) {
    case "critical":
      return "critical";
    case "warning":
      return "caution";
    case "attention":
      return "caution";
    case "restricted":
      return "info";
    default:
      return "neutral";
  }
});
</script>

<template>
  <article
    class="widget"
    :class="widget.quiet ? 'widget--quiet' : ''"
    :data-widget="widget.id"
    :data-attention="widget.attention"
    :aria-labelledby="`widget-${widget.id}-title`"
  >
    <header class="widget__header">
      <h3 :id="`widget-${widget.id}-title`" class="widget__title">
        {{ widget.title }}
      </h3>
      <!--
        Reporting widgets name their attention level; a quiet one does not,
        because "Routine" beside "No current shift" is a label for a state the
        sentence already carries.
      -->
      <StatusPill
        v-if="!widget.quiet"
        :label="widget.attentionLabel"
        :tone="tone"
        sr-prefix="Attention"
      />
    </header>

    <p v-if="widget.quiet" class="widget__quiet">{{ widget.quietState }}</p>

    <template v-else>
      <p class="widget__summary">{{ widget.summary }}</p>

      <p v-if="widget.metric" class="widget__metric">
        <span class="widget__metric-value">{{ widget.metric.value }}</span>
        <span class="widget__metric-label">{{ widget.metric.label }}</span>
      </p>

      <ul v-if="widget.items.length > 0" class="widget__items">
        <li v-for="(item, index) in widget.items" :key="`${widget.id}:${index}`">
          <span class="widget__item-label">{{ item.label }}</span>
          <span v-if="item.detail" class="widget__item-detail">{{
            item.detail
          }}</span>
          <span v-if="item.status" class="widget__item-status">{{
            item.status
          }}</span>
        </li>
      </ul>
    </template>

    <RouterLink
      v-if="destination && widget.actionLabel"
      class="widget__action"
      :to="destination"
    >
      {{ widget.actionLabel }}
    </RouterLink>
  </article>
</template>

<style scoped>
.widget {
  display: grid;
  align-content: start;
  gap: var(--m-space-2);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  box-shadow: var(--m-shadow-sm);
}

/*
 * A quiet card sits back. It keeps its border and its title so the reader can
 * see the widget is present and answered, and it drops the raised surface so a
 * screen of quiet cards does not read as a screen of things to do.
 */
.widget--quiet {
  background: var(--m-surface-base);
  box-shadow: none;
}

/*
 * The attention level again as a rule down the edge, for the scan rather than
 * the read. The word in the pill is the primary signal; this repeats it.
 */
.widget[data-attention="critical"] {
  border-inline-start: 3px solid var(--m-status-danger);
}

.widget[data-attention="warning"] {
  border-inline-start: 3px solid var(--m-status-warning);
}

.widget[data-attention="attention"] {
  border-inline-start: 3px solid var(--m-status-neutral);
}

.widget__header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--m-space-2);
}

.widget__title {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-md);
  letter-spacing: 0;
}

.widget__quiet {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.widget__summary {
  margin: 0;
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.widget__metric {
  display: flex;
  align-items: baseline;
  gap: var(--m-space-2);
  margin: 0;
}

.widget__metric-value {
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  line-height: 1;
}

.widget__metric-label {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.widget__items {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.widget__items li {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: var(--m-space-2);
  padding-block: 0.15rem;
  border-top: 1px solid var(--m-border-subtle, var(--m-border-default));
  font-size: var(--m-text-sm);
}

.widget__item-label {
  font-weight: 700;
}

.widget__item-detail {
  color: var(--m-text-muted);
}

.widget__item-status {
  margin-inline-start: auto;
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.widget__action {
  justify-self: start;
  margin-top: var(--m-space-1);
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.widget__action:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
