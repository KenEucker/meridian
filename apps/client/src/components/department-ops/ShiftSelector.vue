<script setup lang="ts">
import type { ShiftOption } from "@/department-ops/types";
import { formatTimestamp, lifecycleLabel } from "@/department-ops/labels";

defineProps<{
  shifts: readonly ShiftOption[];
  modelValue: string;
  timeZone: string;
}>();

defineEmits<{
  "update:modelValue": [value: string];
}>();
</script>

<template>
  <label class="shift-selector">
    <span>Selected shift</span>
    <select
      :value="modelValue"
      @change="
        $emit(
          'update:modelValue',
          ($event.target as HTMLSelectElement).value,
        )
      "
    >
      <option
        v-for="shift in shifts"
        :key="shift.shiftId"
        :value="shift.shiftId"
      >
        {{ shift.title }} · {{ lifecycleLabel(shift.lifecycle) }} ·
        {{ formatTimestamp(shift.startsAt, timeZone) }}
      </option>
    </select>
  </label>
</template>

<style scoped>
.shift-selector {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-5);
  font-weight: 700;
}

.shift-selector select {
  min-height: 2.75rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
}

.shift-selector select:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
