<script setup lang="ts">
import type {
  ReadinessChecklistItem,
  ReadinessItemStatus,
} from "@/readiness/checklist";

defineProps<{
  items: ReadinessChecklistItem[];
}>();

// Status wording is textual so state is never conveyed by color alone
// (accessibility checklist: "state is not conveyed by color alone"). The glyph
// is decorative and hidden from assistive tech; the visible word carries state.
const STATUS_LABEL: Record<ReadinessItemStatus, string> = {
  ready: "Ready",
  "not-ready": "Not ready",
  pending: "Pending",
};

const STATUS_GLYPH: Record<ReadinessItemStatus, string> = {
  ready: "\u2713",
  "not-ready": "\u2715",
  pending: "\u2026",
};
</script>

<template>
  <ul class="readiness-checklist" aria-label="Device readiness checklist">
    <li
      v-for="item in items"
      :key="item.key"
      class="readiness-checklist__item"
      :data-status="item.status"
    >
      <span
        class="readiness-checklist__glyph"
        :class="`readiness-checklist__glyph--${item.status}`"
        aria-hidden="true"
        >{{ STATUS_GLYPH[item.status] }}</span
      >
      <span class="readiness-checklist__body">
        <span class="readiness-checklist__label">{{ item.label }}</span>
        <span
          class="readiness-checklist__status"
          :class="`readiness-checklist__status--${item.status}`"
        >
          {{ STATUS_LABEL[item.status] }}
        </span>
        <span v-if="item.detail" class="readiness-checklist__detail">
          {{ item.detail }}
        </span>
      </span>
    </li>
  </ul>
</template>

<style scoped>
.readiness-checklist {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2);
}

.readiness-checklist__item {
  display: flex;
  align-items: flex-start;
  gap: var(--m-space-3);
  padding: var(--m-space-3);
  background: var(--m-surface-raised);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
}

.readiness-checklist__item[data-status="ready"] {
  border-color: color-mix(
    in srgb,
    var(--m-status-success) 38%,
    var(--m-border-default)
  );
  background: color-mix(
    in srgb,
    var(--m-status-success) 18%,
    var(--m-surface-raised)
  );
}

.readiness-checklist__glyph {
  flex: none;
  width: 1.5rem;
  height: 1.5rem;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--m-radius-pill);
  font-weight: 700;
  line-height: 1;
  color: var(--m-text-inverse);
}

.readiness-checklist__glyph--ready {
  background: var(--m-status-success);
}

.readiness-checklist__glyph--not-ready {
  background: var(--m-status-danger);
}

.readiness-checklist__glyph--pending {
  background: var(--m-status-neutral);
}

.readiness-checklist__body {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-1);
}

.readiness-checklist__label {
  font-weight: 600;
  color: var(--m-text-primary);
}

.readiness-checklist__status {
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.readiness-checklist__status--ready {
  color: var(--m-status-success);
}

.readiness-checklist__status--not-ready {
  color: var(--m-status-danger);
}

.readiness-checklist__status--pending {
  color: var(--m-text-secondary);
}

.readiness-checklist__detail {
  font-size: var(--m-text-sm);
  color: var(--m-text-muted);
}
</style>
