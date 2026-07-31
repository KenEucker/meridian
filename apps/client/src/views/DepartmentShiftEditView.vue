<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import {
  resolveDepartmentSelfAdminSession,
} from "@/department-teams/fixtureDepartmentSession";
import {
  canManageShiftsForTeam,
  canViewShiftAdmin,
  createShift,
  getShift,
  listSchedulableTeams,
  listTrainingOptions,
  listWaiverOptions,
  shiftHasStarted,
  updateShift,
  type ShiftDraft,
} from "@/shift-admin/shiftAdminModel";

const route = useRoute();
const router = useRouter();
const session = computed(() => resolveDepartmentSelfAdminSession());
const canView = computed(() => canViewShiftAdmin(session.value));

const shiftId = computed(() =>
  typeof route.params.shiftId === "string" ? route.params.shiftId : "",
);
const isCreate = computed(
  () => route.name === "events.departments.shifts.create",
);

const existing = computed(() =>
  isCreate.value ? null : getShift(session.value, shiftId.value),
);
const started = computed(
  () => existing.value !== null && shiftHasStarted(existing.value),
);
const isCancelled = computed(() => existing.value?.cancelledAt != null);

const teams = computed(() => listSchedulableTeams(session.value));
const trainingOptions = computed(() => listTrainingOptions(session.value));
const waiverOptions = computed(() => listWaiverOptions(session.value));

const draft = reactive<ShiftDraft>({
  eligibleTeamId: "",
  title: "",
  startsAt: "",
  endsAt: "",
  capacity: null,
  signupOpensAt: null,
  signupClosesAt: null,
  scheduleLockAt: null,
  requiredTrainingIds: [],
  requiredWaiverIds: [],
});
const formError = ref<string | null>(null);
const busy = ref(false);

function toLocalInput(iso: string | null): string {
  if (iso === null || iso === "") {
    return "";
  }

  const date = new Date(iso);
  const pad = (value: number): string => String(value).padStart(2, "0");

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromLocalInput(value: string): string | null {
  if (value === "") {
    return null;
  }

  return new Date(value).toISOString();
}

const schedule = reactive({
  startsAt: "",
  endsAt: "",
  signupOpensAt: "",
  signupClosesAt: "",
  scheduleLockAt: "",
});

watch(
  existing,
  (shift) => {
    if (shift === null) {
      draft.eligibleTeamId = teams.value[0]?.id ?? "";
      draft.title = "";
      draft.capacity = null;
      draft.requiredTrainingIds = [];
      draft.requiredWaiverIds = [];
      schedule.startsAt = "";
      schedule.endsAt = "";
      schedule.signupOpensAt = "";
      schedule.signupClosesAt = "";
      schedule.scheduleLockAt = "";
      return;
    }

    draft.eligibleTeamId = shift.eligibleTeamId;
    draft.title = shift.title;
    draft.capacity = shift.capacity;
    draft.requiredTrainingIds = [...shift.requiredTrainingIds];
    draft.requiredWaiverIds = [...shift.requiredWaiverIds];
    schedule.startsAt = toLocalInput(shift.startsAt);
    schedule.endsAt = toLocalInput(shift.endsAt);
    schedule.signupOpensAt = toLocalInput(shift.signupOpensAt);
    schedule.signupClosesAt = toLocalInput(shift.signupClosesAt);
    schedule.scheduleLockAt = toLocalInput(shift.scheduleLockAt);
  },
  { immediate: true },
);

const heading = computed(() =>
  isCreate.value ? "Create shift" : "Edit shift",
);

const shiftsIndexRoute = computed(() => ({
  name: "events.departments.shifts.index",
  params: {
    eventId: session.value?.eventId,
    departmentId: session.value?.departmentId,
  },
}));

const canManageSelectedTeam = computed(() =>
  draft.eligibleTeamId === ""
    ? teams.value.length > 0
    : canManageShiftsForTeam(session.value, draft.eligibleTeamId),
);

async function onSubmit(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    const payload: ShiftDraft = {
      ...draft,
      capacity:
        typeof draft.capacity === "number" && !Number.isNaN(draft.capacity)
          ? draft.capacity
          : null,
      requiredTrainingIds: [...draft.requiredTrainingIds],
      requiredWaiverIds: [...draft.requiredWaiverIds],
      startsAt:
        started.value && existing.value
          ? existing.value.startsAt
          : (fromLocalInput(schedule.startsAt) ?? ""),
      endsAt:
        started.value && existing.value
          ? existing.value.endsAt
          : (fromLocalInput(schedule.endsAt) ?? ""),
      signupOpensAt: fromLocalInput(schedule.signupOpensAt),
      signupClosesAt: fromLocalInput(schedule.signupClosesAt),
      scheduleLockAt: fromLocalInput(schedule.scheduleLockAt),
    };

    if (isCreate.value) {
      const created = createShift(session.value, payload);
      await router.push({
        name: "events.departments.shifts.edit",
        params: {
          eventId: session.value?.eventId,
          departmentId: session.value?.departmentId,
          shiftId: created.id,
        },
      });
      return;
    }

    updateShift(session.value, shiftId.value, payload);
  } catch (error) {
    formError.value =
      error instanceof Error ? error.message : "Unable to save shift.";
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="shift-edit" aria-labelledby="shift-edit-heading">
    <p class="shift-edit__eyebrow">Shift administration</p>
    <h1 id="shift-edit-heading" class="shift-edit__heading">{{ heading }}</h1>
    <p v-if="session" class="shift-edit__lede">
      {{ session.departmentLabel }} / {{ session.roleLabel }}
    </p>

    <p v-if="!canView" class="shift-edit__restricted" role="status">
      Shift administration requires department lead or team lead authority for
      this department.
    </p>

    <p
      v-else-if="!isCreate && existing === null"
      class="shift-edit__restricted"
      role="status"
    >
      Shift not found for your managed teams.
    </p>

    <template v-else>
      <p v-if="started" class="shift-edit__hint" role="status">
        This shift has started: scheduled times and the eligible team are
        locked to preserve worked history.
      </p>
      <p v-if="isCancelled" class="shift-edit__hint" role="status">
        This shift is cancelled. Restore it from the shift list before editing.
      </p>

      <p v-if="formError" class="shift-edit__error" role="alert">
        {{ formError }}
      </p>

      <form class="shift-edit__form" @submit.prevent="onSubmit">
        <label class="shift-edit__field">
          Title / function
          <input v-model="draft.title" type="text" required />
        </label>

        <label class="shift-edit__field">
          Eligible team
          <select v-model="draft.eligibleTeamId" required :disabled="started">
            <option value="" disabled>Select team</option>
            <option v-for="team in teams" :key="team.id" :value="team.id">
              {{ team.name }}
            </option>
          </select>
        </label>

        <div class="shift-edit__row">
          <label class="shift-edit__field">
            Starts at
            <input
              v-model="schedule.startsAt"
              type="datetime-local"
              required
              :disabled="started"
            />
          </label>
          <label class="shift-edit__field">
            Ends at
            <input
              v-model="schedule.endsAt"
              type="datetime-local"
              required
              :disabled="started"
            />
          </label>
        </div>

        <label class="shift-edit__field">
          Capacity (leave blank for no cap)
          <input v-model.number="draft.capacity" type="number" min="1" />
        </label>

        <div class="shift-edit__row">
          <label class="shift-edit__field">
            Signup opens
            <input v-model="schedule.signupOpensAt" type="datetime-local" />
          </label>
          <label class="shift-edit__field">
            Signup closes
            <input v-model="schedule.signupClosesAt" type="datetime-local" />
          </label>
        </div>

        <label class="shift-edit__field">
          Schedule lock / cutoff
          <input v-model="schedule.scheduleLockAt" type="datetime-local" />
        </label>

        <fieldset v-if="trainingOptions.length > 0" class="shift-edit__group">
          <legend>Required trainings</legend>
          <label
            v-for="option in trainingOptions"
            :key="option.id"
            class="shift-edit__check"
          >
            <input
              v-model="draft.requiredTrainingIds"
              type="checkbox"
              :value="option.id"
            />
            {{ option.name }}
          </label>
        </fieldset>

        <fieldset v-if="waiverOptions.length > 0" class="shift-edit__group">
          <legend>Required waivers</legend>
          <label
            v-for="option in waiverOptions"
            :key="option.id"
            class="shift-edit__check"
          >
            <input
              v-model="draft.requiredWaiverIds"
              type="checkbox"
              :value="option.id"
            />
            {{ option.name }}
          </label>
        </fieldset>

        <div class="shift-edit__actions">
          <button
            type="submit"
            :disabled="busy || isCancelled || !canManageSelectedTeam"
          >
            Save
          </button>
          <RouterLink :to="shiftsIndexRoute">Back to shifts</RouterLink>
        </div>
      </form>
    </template>
  </section>
</template>

<style scoped>
.shift-edit {
  width: min(100%, 42rem);
  display: grid;
  gap: var(--m-space-4);
}

.shift-edit__eyebrow {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.shift-edit__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.shift-edit__lede,
.shift-edit__hint {
  margin: 0;
  color: var(--m-text-muted);
}

.shift-edit__restricted,
.shift-edit__error {
  margin: 0;
  padding: var(--m-space-3);
  border-radius: var(--m-radius-sm);
  border: 1px solid var(--m-border-default);
}

.shift-edit__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.shift-edit__form {
  display: grid;
  gap: var(--m-space-3);
}

.shift-edit__row {
  display: grid;
  gap: var(--m-space-3);
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (max-width: 40rem) {
  .shift-edit__row {
    grid-template-columns: 1fr;
  }
}

.shift-edit__field {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.shift-edit__field input,
.shift-edit__field select {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-weight: 400;
}

.shift-edit__field input:disabled,
.shift-edit__field select:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.shift-edit__group {
  display: grid;
  gap: var(--m-space-1);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.shift-edit__group legend {
  padding: 0 var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.shift-edit__check {
  display: flex;
  align-items: center;
  gap: var(--m-space-2);
  font-weight: 400;
}

.shift-edit__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.shift-edit__actions button,
.shift-edit__actions a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}

.shift-edit__actions button[type="submit"] {
  border: 0;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.shift-edit__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}
</style>
