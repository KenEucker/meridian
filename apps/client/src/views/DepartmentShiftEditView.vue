<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";

/** The select value that means "this shift carries its own custom rate". */
const CUSTOM_RATE = "__custom__";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import {
  selectedSessionDepartment,
  sessionEventWindow,
} from "@/session/sessionAccess";
import {
  createShift,
  getDepartmentShifts,
  getShift,
  shiftCreditPolicyOptions,
  shiftTeamOptions,
  updateShift,
  type ProductShift,
  type ShiftDraft,
  type ShiftWorkspace,
} from "@/shift-admin/shiftAdminModel";

/**
 * `department.shift-create` and `department.shift-edit` (M11.17; bound to the
 * node in M16.18; UI contract 12.4).
 *
 * Two reads fill this page. The department read carries the authority to be
 * here, the teams the eligible-team field may offer, and the trainings and
 * waivers a shift may require. The shift read carries the record being edited,
 * including the node's own answer on whether it has started — which is what
 * locks the schedule and the team, so the lock and the clock behind it stay on
 * the same side (SHIFT-002, SHIFT-004; TEAM-007).
 *
 * Every rule the form used to enforce for itself now belongs to the node, and
 * its refusals are shown as it worded them.
 */
const route = useRoute();
const router = useRouter();

const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));
const shiftId = computed(() =>
  typeof route.params.shiftId === "string" ? route.params.shiftId : null,
);
const isCreate = computed(() => shiftId.value === null);

const workspace = ref<ShiftWorkspace | null>(null);
const existing = ref<ProductShift | null>(null);
const loadError = ref<string | null>(null);
const formError = ref<string | null>(null);
const formNotice = ref<string | null>(null);
const busy = ref(false);

/*
 * Authority is the node's answer on the response, not a role the client read for
 * itself (CLIENT-006). A member reaches this endpoint and is answered with
 * `can_manage: false`; the commands would refuse them, so the form is not shown.
 */
const canManage = computed(() => workspace.value?.access.canManage ?? false);
const loaded = computed(
  () => workspace.value !== null && (isCreate.value || existing.value !== null),
);
const started = computed(() => existing.value?.hasStarted ?? false);
const isCancelled = computed(() => existing.value?.cancelledAt != null);

const teams = computed(() =>
  shiftTeamOptions(workspace.value?.teams ?? [], existing.value),
);
const trainingOptions = computed(() => workspace.value?.trainingOptions ?? []);
const waiverOptions = computed(() => workspace.value?.waiverOptions ?? []);
const creditPolicyOptions = computed(() =>
  shiftCreditPolicyOptions(
    workspace.value?.creditPolicyOptions ?? [],
    existing.value,
  ),
);

const departmentLabel = computed(
  () =>
    workspace.value?.departmentName ||
    selectedSessionDepartment.value?.departmentLabel ||
    "Department",
);

const heading = computed(() => (isCreate.value ? "Create shift" : "Edit shift"));

const shiftsIndexRoute = computed(() => ({
  name: "events.departments.shifts.index",
  params: { eventId: eventId.value, departmentId: departmentId.value },
}));

const draft = reactive<ShiftDraft>(emptyDraft());

/**
 * The schedule fields as `datetime-local` inputs hold them.
 *
 * The control speaks local wall-clock time and the node speaks ISO instants, so
 * the two are kept apart rather than round-tripped through one string.
 */
const schedule = reactive({
  startsAt: "",
  endsAt: "",
  signupOpensAt: "",
  signupClosesAt: "",
  scheduleLockAt: "",
});

function emptyDraft(): ShiftDraft {
  return {
    eligibleTeamId: "",
    title: "",
    startsAt: "",
    endsAt: "",
    capacity: null,
    signupOpensAt: null,
    signupClosesAt: null,
    scheduleLockAt: null,
    creditPolicyId: null,
    customCreditMultiplier: null,
    requiredTrainingIds: [],
    requiredWaiverIds: [],
  };
}

/*
 * The credit choice as the select holds it: "" for the organization default, a
 * policy id for a named policy, or the custom sentinel with its rate in the
 * number input beside it. Folded back into the draft's two exclusive fields on
 * submit.
 */
const creditSelection = ref("");
const customRate = ref<string | number>("");

function toLocalInput(iso: string | null): string {
  if (iso === null || iso === "") {
    return "";
  }

  const date = new Date(iso);

  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const pad = (value: number): string => String(value).padStart(2, "0");

  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromLocalInput(value: string): string | null {
  if (value === "") {
    return null;
  }

  const parsed = new Date(value);

  return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString();
}

/**
 * What a brand-new shift's schedule starts out as.
 *
 * Every one of these five fields was blank, and every one of them was being
 * typed to the same handful of values: a shift covers some part of the event,
 * signups open now because the schedule is being built now, and both the signup
 * close and the schedule cutoff land on the event starting because that is when
 * the roster stops being something anybody may still change (SHIFT-002).
 *
 * Defaults, not rules. The node decides what it will accept and every field is
 * still editable; what this removes is the typing, not the choice. And they are
 * offered only where they can be known — an event with no recorded window leaves
 * the schedule blank rather than inventing one, because a shift defaulted to a
 * window nobody set is worse than an empty field.
 */
function defaultSchedule(): void {
  const window = sessionEventWindow.value;

  schedule.startsAt = toLocalInput(window?.startsAt ?? null);
  schedule.endsAt = toLocalInput(window?.endsAt ?? null);
  // Now, because a schedule being built is a schedule people may sign up for.
  schedule.signupOpensAt = toLocalInput(new Date().toISOString());
  schedule.signupClosesAt = toLocalInput(window?.startsAt ?? null);
  schedule.scheduleLockAt = toLocalInput(window?.startsAt ?? null);
}

function applyShift(shift: ProductShift | null): void {
  if (shift === null) {
    Object.assign(draft, emptyDraft(), {
      eligibleTeamId: workspace.value?.teams[0]?.id ?? "",
    });
    defaultSchedule();
    creditSelection.value = "";
    customRate.value = "";

    return;
  }

  Object.assign(draft, {
    eligibleTeamId: shift.eligibleTeamId,
    title: shift.title,
    startsAt: shift.startsAt ?? "",
    endsAt: shift.endsAt ?? "",
    capacity: shift.capacity,
    signupOpensAt: shift.signupOpensAt,
    signupClosesAt: shift.signupClosesAt,
    scheduleLockAt: shift.scheduleLockAt,
    creditPolicyId: shift.creditPolicyId,
    customCreditMultiplier: shift.customCreditMultiplier,
    requiredTrainingIds: [...shift.requiredTrainingIds],
    requiredWaiverIds: [...shift.requiredWaiverIds],
  });
  if (shift.customCreditMultiplier !== null) {
    creditSelection.value = CUSTOM_RATE;
    customRate.value = shift.customCreditMultiplier;
  } else {
    creditSelection.value = shift.creditPolicyId ?? "";
    customRate.value = "";
  }
  schedule.startsAt = toLocalInput(shift.startsAt);
  schedule.endsAt = toLocalInput(shift.endsAt);
  schedule.signupOpensAt = toLocalInput(shift.signupOpensAt);
  schedule.signupClosesAt = toLocalInput(shift.signupClosesAt);
  schedule.scheduleLockAt = toLocalInput(shift.scheduleLockAt);
}

async function loadWorkspace(): Promise<void> {
  if (departmentId.value === "") {
    workspace.value = null;

    return;
  }

  try {
    workspace.value = await getDepartmentShifts(departmentId.value);
  } catch (error) {
    workspace.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load shifts. Check the connection to this node and try again.",
    );
  }
}

async function loadShift(): Promise<void> {
  const id = shiftId.value;

  if (id === null || departmentId.value === "") {
    existing.value = null;

    return;
  }

  try {
    existing.value = await getShift(departmentId.value, id);
  } catch (error) {
    existing.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this shift. Check the connection to this node and try again.",
    );
  }
}

async function load(): Promise<void> {
  loadError.value = null;
  await Promise.all([loadWorkspace(), loadShift()]);
  applyShift(existing.value);
}

watch([departmentId, shiftId], () => {
  formError.value = null;
  formNotice.value = null;
  void load();
});

void load();

/**
 * Submit the form as one command, then read the result back.
 *
 * A started shift sends the schedule and team it already has: those inputs are
 * disabled, and the node compares what arrives against what it holds, so sending
 * the current values is how an unchanged field is expressed.
 */
async function onSubmit(): Promise<void> {
  formError.value = null;
  formNotice.value = null;
  busy.value = true;

  const lockedStart = started.value ? existing.value?.startsAt : null;
  const lockedEnd = started.value ? existing.value?.endsAt : null;

  const isCustomRate = creditSelection.value === CUSTOM_RATE;

  const payload: ShiftDraft = {
    ...draft,
    requiredTrainingIds: [...draft.requiredTrainingIds],
    requiredWaiverIds: [...draft.requiredWaiverIds],
    startsAt: lockedStart ?? fromLocalInput(schedule.startsAt) ?? "",
    endsAt: lockedEnd ?? fromLocalInput(schedule.endsAt) ?? "",
    signupOpensAt: fromLocalInput(schedule.signupOpensAt),
    signupClosesAt: fromLocalInput(schedule.signupClosesAt),
    scheduleLockAt: fromLocalInput(schedule.scheduleLockAt),
    creditPolicyId: isCustomRate ? null : creditSelection.value || null,
    customCreditMultiplier: isCustomRate
      ? String(customRate.value).trim() || null
      : null,
  };

  const id = shiftId.value;

  try {
    if (isCreate.value || id === null) {
      const createdId = await createShift(
        departmentId.value,
        eventId.value,
        payload,
      );

      await router.push({
        name: "events.departments.shifts.edit",
        params: {
          eventId: eventId.value,
          departmentId: departmentId.value,
          shiftId: createdId,
        },
      });

      return;
    }

    await updateShift(id, payload);
    formNotice.value = "Shift saved.";
    await load();
  } catch (error) {
    formError.value = meridianErrorMessage(error, "Unable to save shift.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="shift-edit" aria-labelledby="shift-edit-heading">
    <p class="shift-edit__eyebrow">Shift administration</p>
    <h1 id="shift-edit-heading" class="shift-edit__heading">{{ heading }}</h1>
    <p class="shift-edit__lede">{{ departmentLabel }}</p>

    <!--
      A refusal is the node's own sentence: a shift in another department, or one
      whose team this caller does not manage, is refused by the read, and an
      unreachable node is stated rather than shown as a shift that is not there.
    -->
    <p v-if="loadError" class="shift-edit__restricted" role="alert">
      {{ loadError }}
    </p>

    <p v-else-if="!loaded" class="shift-edit__restricted" role="status">
      Loading shift…
    </p>

    <p v-else-if="!canManage" class="shift-edit__restricted" role="status">
      Creating and maintaining shifts requires department lead or team lead
      authority for this department.
    </p>

    <template v-else>
      <p v-if="started" class="shift-edit__hint" role="status">
        This shift has started: scheduled times and the eligible team are locked
        to preserve worked history.
      </p>
      <p v-if="isCancelled" class="shift-edit__hint" role="status">
        This shift is cancelled. Restore it from the shift list before editing.
      </p>

      <p v-if="formError" class="shift-edit__error" role="alert">
        {{ formError }}
      </p>
      <p v-if="formNotice" class="shift-edit__notice" role="status">
        {{ formNotice }}
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

        <!--
          The shift override the credit resolver prefers over the organization
          default (SHIFT-010, CREDIT-002; M18.16). Blank means the shift is
          credited at whatever the organization default is when credits run;
          a custom rate gives the shift its own price between 0 and 2 credits
          per hour — the usual shape of pre- and post-event work.
        -->
        <label class="shift-edit__field">
          Credit policy
          <select v-model="creditSelection">
            <option value="">Organization default</option>
            <option
              v-for="option in creditPolicyOptions"
              :key="option.id"
              :value="option.id"
            >
              {{ option.name
              }}{{
                option.creditMultiplier
                  ? ` — ${option.creditMultiplier} credits/hour`
                  : ""
              }}{{ option.archived ? " (archived)" : "" }}
            </option>
            <option :value="CUSTOM_RATE">Custom rate for this shift…</option>
          </select>
        </label>

        <label
          v-if="creditSelection === CUSTOM_RATE"
          class="shift-edit__field"
        >
          Custom credits per hour (0–2)
          <input
            v-model="customRate"
            type="number"
            min="0"
            max="2"
            step="0.001"
            required
            aria-label="Custom credits per hour"
          />
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
          <button type="submit" :disabled="busy || isCancelled">Save</button>
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
.shift-edit__error,
.shift-edit__notice {
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
