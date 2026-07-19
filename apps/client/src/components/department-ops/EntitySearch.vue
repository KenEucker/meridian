<script setup lang="ts">
import { computed, ref, watch } from "vue";

import type { LogisticsSearchHit } from "@/department-ops/types";

const props = defineProps<{
  hits: readonly LogisticsSearchHit[];
  placeholder?: string;
}>();

const emit = defineEmits<{
  search: [query: string];
  select: [hit: LogisticsSearchHit];
}>();

const query = ref("");

watch(query, (value) => {
  emit("search", value);
});

const grouped = computed(() => {
  const groups: Record<LogisticsSearchHit["kind"], LogisticsSearchHit[]> = {
    staff: [],
    equipment: [],
    shift: [],
  };

  for (const hit of props.hits) {
    groups[hit.kind].push(hit);
  }

  return groups;
});

const kindLabel: Record<LogisticsSearchHit["kind"], string> = {
  staff: "Staff",
  equipment: "Equipment",
  shift: "Shifts",
};
</script>

<template>
  <div class="entity-search">
    <label class="entity-search__field">
      <span>Search staff, equipment, or shifts</span>
      <input
        v-model="query"
        type="search"
        :placeholder="placeholder ?? 'Type a name, asset tag, or shift title'"
        autocomplete="off"
      />
    </label>

    <div
      v-if="query.trim().length > 0"
      class="entity-search__results"
      role="listbox"
      aria-label="Search results"
    >
      <p v-if="hits.length === 0" class="entity-search__empty" role="status">
        No matches in this department.
      </p>
      <template v-for="kind in (['staff', 'equipment', 'shift'] as const)" :key="kind">
        <section v-if="grouped[kind].length > 0" class="entity-search__group">
          <h3 class="entity-search__group-title">{{ kindLabel[kind] }}</h3>
          <ul>
            <li v-for="hit in grouped[kind]" :key="`${hit.kind}-${hit.id}`">
              <button type="button" @click="emit('select', hit)">
                <span class="entity-search__label">{{ hit.label }}</span>
                <span class="entity-search__detail">{{ hit.detail }}</span>
              </button>
            </li>
          </ul>
        </section>
      </template>
    </div>
  </div>
</template>

<style scoped>
.entity-search {
  display: grid;
  gap: var(--m-space-3);
  margin: 0 0 var(--m-space-5);
}

.entity-search__field {
  display: grid;
  gap: var(--m-space-2);
  font-weight: 700;
}

.entity-search__field input {
  min-height: 3rem;
  padding: var(--m-space-3);
  border: 2px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
}

.entity-search__field input:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.entity-search__results {
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  padding: var(--m-space-3);
}

.entity-search__empty {
  margin: 0;
  color: var(--m-text-muted);
}

.entity-search__group + .entity-search__group {
  margin-top: var(--m-space-3);
}

.entity-search__group-title {
  margin: 0 0 var(--m-space-2);
  font-size: var(--m-text-sm);
  text-transform: uppercase;
}

.entity-search__group ul {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: var(--m-space-2);
}

.entity-search__group button {
  width: 100%;
  min-height: 2.75rem;
  display: grid;
  gap: 0.15rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-app);
  color: inherit;
  text-align: left;
  font: inherit;
  cursor: pointer;
}

.entity-search__group button:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.entity-search__label {
  font-weight: 700;
}

.entity-search__detail {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}
</style>
