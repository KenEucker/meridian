<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { selectedSessionDepartment } from "@/session/sessionAccess";
import {
  cancelTrainingSignup,
  getDepartmentTrainings,
  getTraining,
  importTrainingCompletions,
  recordTrainingCompletion,
  saveTraining,
  signUpForTraining,
  type ProductTrainingDetail,
  type TrainingDraft,
  type TrainingImportResult,
  type TrainingStaffOption,
  type TrainingWorkspace,
} from "@/trainings/trainingAdminModel";

const route = useRoute();
const router = useRouter();

const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));
const trainingId = computed(() =>
  typeof route.params.trainingId === "string" ? route.params.trainingId : null,
);
const isCreate = computed(() => trainingId.value === null);

/**
 * The two reads this page renders from (M16.16).
 *
 * The department read carries the authority to be here, the teams the scope
 * field offers, the department roster the staff fields offer, and the trainings
 * that may be prerequisites. The training read carries the record being edited
 * along with its roster and completion history. Both are read again after every
 * write rather than adjusted in place, so a table never shows a row the node did
 * not answer with.
 */
const workspace = ref<TrainingWorkspace | null>(null);
const training = ref<ProductTrainingDetail | null>(null);
const loadError = ref<string | null>(null);
const formError = ref<string | null>(null);
const formNotice = ref<string | null>(null);
const busy = ref(false);

/*
 * Authority is the node's answer on the response, not a role the client read
 * for itself (CLIENT-006). Recording completions is decided per training,
 * because a team lead is an authorized trainer for their own team's trainings
 * without managing the department's (TRAIN-005).
 */
const canManage = computed(() => workspace.value?.access.canManage ?? false);
const canRecord = computed(
  () => training.value?.viewer.canRecordCompletions ?? false,
);
const loaded = computed(
  () => workspace.value !== null && (isCreate.value || training.value !== null),
);

const departmentLabel = computed(
  () => selectedSessionDepartment.value?.departmentLabel ?? "Department",
);

const teams = computed(() => workspace.value?.teams ?? []);
const staff = computed<readonly TrainingStaffOption[]>(
  () => workspace.value?.departmentStaff ?? [],
);
const roster = computed(() => training.value?.roster ?? []);
const completions = computed(() => training.value?.completions ?? []);

/*
 * Any other unarchived training in the department may be a prerequisite. Whether
 * a particular choice would make a cycle, or reach outside the department, is
 * the node's rule to enforce (TRAIN-004); this list only stops the training from
 * offering itself.
 */
const prereqOptions = computed(() =>
  (workspace.value?.trainings ?? []).filter(
    (candidate) =>
      candidate.id !== trainingId.value && candidate.archivedAt === null,
  ),
);

const draft = ref<TrainingDraft>(emptyDraft());

const completionStaffId = ref("");
const completionDate = ref("");
const rosterAddStaffId = ref("");
const importCsv = ref("");
const importResult = ref<TrainingImportResult | null>(null);

/**
 * Whether this training collects signups.
 *
 * The node decides that from the delivery and schedule together, so the roster
 * panel follows its answer rather than re-deriving it from the start time.
 */
const takesSignups = computed(
  () => training.value?.requiresScheduledAttendance ?? false,
);

async function loadWorkspace(): Promise<void> {
  if (departmentId.value === "") {
    workspace.value = null;
    return;
  }

  try {
    workspace.value = await getDepartmentTrainings(departmentId.value);
  } catch (error) {
    workspace.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load trainings. Check the connection to this node and try again.",
    );
  }
}

async function loadTraining(): Promise<void> {
  const id = trainingId.value;
  if (id === null || departmentId.value === "") {
    training.value = null;
    return;
  }

  try {
    training.value = await getTraining(departmentId.value, id);
    draft.value = toDraft(training.value);
  } catch (error) {
    training.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this training. Check the connection to this node and try again.",
    );
  }
}

async function load(): Promise<void> {
  loadError.value = null;
  await Promise.all([loadWorkspace(), loadTraining()]);
}

watch([departmentId, trainingId], () => {
  formError.value = null;
  formNotice.value = null;
  importResult.value = null;
  draft.value = emptyDraft();
  void load();
});

void load();

function emptyDraft(): TrainingDraft {
  return {
    name: "",
    description: "",
    teamId: null,
    expiresAfterDays: null,
    delivery: "in_person",
    onlineUrl: "",
    scheduledStartAt: null,
    scheduledEndAt: null,
    location: "",
    capacity: null,
    timeCommitment: "",
    afterTraining: "",
    provisions: "",
    prerequisiteIds: [],
  };
}

function toDraft(value: ProductTrainingDetail): TrainingDraft {
  return {
    name: value.name,
    description: value.description ?? "",
    teamId: value.teamId,
    expiresAfterDays: value.expiresAfterDays,
    delivery: value.delivery,
    onlineUrl: value.onlineUrl ?? "",
    scheduledStartAt: value.scheduledStartAt,
    scheduledEndAt: value.scheduledEndAt,
    location: value.location ?? "",
    capacity: value.capacity,
    timeCommitment: value.timeCommitment ?? "",
    afterTraining: value.afterTraining ?? "",
    provisions: value.provisions ?? "",
    prerequisiteIds: value.prerequisites.map((prerequisite) => prerequisite.id),
  };
}

function toDatetimeLocal(value: string | null): string {
  if (value === null) {
    return "";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return "";
  }

  const pad = (part: number): string => String(part).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(
    date.getDate(),
  )}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

function fromDatetimeLocal(value: string): string | null {
  if (value === "") {
    return null;
  }

  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? null : parsed.toISOString();
}

const scheduledStartLocal = computed({
  get: () => toDatetimeLocal(draft.value.scheduledStartAt),
  set: (value: string) => {
    draft.value.scheduledStartAt = fromDatetimeLocal(value);
  },
});
const scheduledEndLocal = computed({
  get: () => toDatetimeLocal(draft.value.scheduledEndAt),
  set: (value: string) => {
    draft.value.scheduledEndAt = fromDatetimeLocal(value);
  },
});

function togglePrerequisite(prerequisiteId: string, checked: boolean): void {
  const current = new Set(draft.value.prerequisiteIds);
  if (checked) {
    current.add(prerequisiteId);
  } else {
    current.delete(prerequisiteId);
  }
  draft.value.prerequisiteIds = [...current];
}

/**
 * Run one write, then read the node again.
 *
 * A refusal is reported in the node's words and nothing on the page moves, so a
 * rejected change is never shown as though it had been accepted.
 */
async function run(
  action: () => Promise<void>,
  notice: string,
  fallback: string,
): Promise<void> {
  formError.value = null;
  formNotice.value = null;
  busy.value = true;

  try {
    await action();
    formNotice.value = notice;
    await load();
  } catch (error) {
    formError.value = meridianErrorMessage(error, fallback);
  } finally {
    busy.value = false;
  }
}

async function onSubmit(): Promise<void> {
  formError.value = null;
  formNotice.value = null;
  busy.value = true;

  try {
    const saved = await saveTraining(
      departmentId.value,
      trainingId.value,
      draft.value,
    );

    if (isCreate.value) {
      /*
       * A created training becomes the edit route's subject, and the route watch
       * reads it. The notice is set after the navigation so that read does not
       * clear it on the way past.
       */
      await router.push({
        name: "events.departments.trainings.edit",
        params: {
          eventId: eventId.value,
          departmentId: departmentId.value,
          trainingId: saved.id,
        },
      });
      formNotice.value = `${saved.name} saved.`;
      return;
    }

    formNotice.value = `${saved.name} saved.`;
    await load();
  } catch (error) {
    formError.value = meridianErrorMessage(error, "Unable to save training.");
  } finally {
    busy.value = false;
  }
}

function onRecordCompletion(): void {
  if (training.value === null || completionStaffId.value === "") {
    formError.value = "Choose a staff member to record completion for.";
    return;
  }

  const id = training.value.id;
  const staffId = completionStaffId.value;
  const completedAt = completionDate.value;
  void run(
    () => recordTrainingCompletion(id, staffId, completedAt),
    `Completion recorded for ${staffLabel(staffId)}.`,
    "Unable to record this completion.",
  );
}

function onRecordRosterCompletion(staffId: string): void {
  if (training.value === null) {
    return;
  }

  const id = training.value.id;
  void run(
    () => recordTrainingCompletion(id, staffId),
    `Completion recorded for ${staffLabel(staffId)}.`,
    "Unable to record this completion.",
  );
}

function onAddToRoster(staffId: string): void {
  if (training.value === null || staffId === "") {
    return;
  }

  const id = training.value.id;
  void run(
    () => signUpForTraining(id, staffId),
    `${staffLabel(staffId)} added to the roster.`,
    "Unable to add this staff member to the roster.",
  );
}

function onRemoveFromRoster(staffId: string): void {
  if (training.value === null) {
    return;
  }

  const id = training.value.id;
  void run(
    () => cancelTrainingSignup(id, staffId),
    `${staffLabel(staffId)} removed from the roster.`,
    "Unable to remove this staff member from the roster.",
  );
}

async function onImport(): Promise<void> {
  if (training.value === null) {
    return;
  }

  formError.value = null;
  formNotice.value = null;
  importResult.value = null;
  busy.value = true;

  try {
    const result = await importTrainingCompletions(
      training.value.id,
      importCsv.value,
    );
    importResult.value = result;
    formNotice.value = `Imported ${result.imported} completion(s); skipped ${result.skipped}.`;
    await load();
  } catch (error) {
    formError.value = meridianErrorMessage(
      error,
      "Unable to import completions.",
    );
  } finally {
    busy.value = false;
  }
}

/**
 * How a staff member is named in a notice.
 *
 * Roster and completion rows carry their own display name from the node; this
 * covers the select fields, where only an id has been chosen so far.
 */
function staffLabel(staffId: string): string {
  const member = staff.value.find((candidate) => candidate.staffId === staffId);
  return member?.displayName ?? member?.email ?? "This staff member";
}

function formatTimestamp(value: string | null): string {
  if (value === null) {
    return "—";
  }

  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? "—"
    : date.toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}
</script>

<template>
  <DeptOpsShell
    heading-id="training-edit-heading"
    :title="isCreate ? 'New training' : (training?.name ?? 'Training')"
    :eyebrow="departmentLabel"
    :lede="
      isCreate
        ? 'Create a department training with prerequisites and expiration.'
        : 'Training setup, roster, and completion recording.'
    "
  >
    <template #nav>
      <RouterLink
        :to="{
          name: 'events.departments.trainings.index',
          params: { eventId, departmentId },
        }"
      >
        Back to Trainings
      </RouterLink>
    </template>

    <p v-if="loadError" class="training-edit__error" role="alert">
      {{ loadError }}
    </p>
    <p v-else-if="!loaded" class="training-edit__muted" role="status">
      Loading training…
    </p>
    <p
      v-else-if="!canManage && isCreate"
      class="training-edit__restricted"
      role="status"
    >
      Only department leads and organizers may create trainings.
    </p>

    <template v-else>
      <p v-if="formError" class="training-edit__error" role="alert">
        {{ formError }}
      </p>
      <p v-if="formNotice" class="training-edit__notice" role="status">
        {{ formNotice }}
      </p>

      <p v-if="!canManage" class="training-edit__restricted" role="status">
        This training's details are not editable without department authority.
      </p>

      <form
        v-if="canManage"
        class="training-edit__form"
        aria-label="Training details"
        @submit.prevent="onSubmit"
      >
        <label>
          Name
          <input v-model="draft.name" type="text" required />
        </label>

        <label>
          Description
          <textarea v-model="draft.description" rows="3"></textarea>
        </label>

        <label>
          Team scope
          <select v-model="draft.teamId">
            <option :value="null">Whole department</option>
            <option v-for="team in teams" :key="team.id" :value="team.id">
              {{ team.name }}{{ team.archivedAt === null ? "" : " (archived)" }}
            </option>
          </select>
        </label>

        <label>
          Expires after days (blank for no expiration)
          <input
            v-model.number="draft.expiresAfterDays"
            type="number"
            min="1"
            max="3650"
            placeholder="e.g. 365 for annual"
          />
        </label>

        <label>
          Delivery
          <select v-model="draft.delivery">
            <option value="in_person">In person</option>
            <option value="online">Online</option>
          </select>
        </label>

        <label v-if="draft.delivery === 'online'">
          Training URL
          <input
            v-model="draft.onlineUrl"
            type="url"
            placeholder="https://example.org/training"
          />
        </label>

        <label>
          Time commitment (shown on the training page)
          <input
            v-model="draft.timeCommitment"
            type="text"
            placeholder="e.g. One three-hour session, renewed annually"
          />
        </label>

        <label>
          After this training (shown on the training page)
          <textarea
            v-model="draft.afterTraining"
            rows="3"
            placeholder="Does it unlock shifts? Add staff to a team? What happens next?"
          ></textarea>
        </label>

        <label>
          Provisions (shown on the training page)
          <textarea
            v-model="draft.provisions"
            rows="2"
            placeholder="Equipment or provisions that come with this training"
          ></textarea>
        </label>

        <label>
          Scheduled session start (blank when attendance is not scheduled)
          <input v-model="scheduledStartLocal" type="datetime-local" />
        </label>

        <label>
          Scheduled session end
          <input v-model="scheduledEndLocal" type="datetime-local" />
        </label>

        <label>
          Location
          <input v-model="draft.location" type="text" />
        </label>

        <label>
          Capacity (blank for unlimited)
          <input v-model.number="draft.capacity" type="number" min="1" />
        </label>

        <fieldset class="training-edit__prereqs">
          <legend>Prerequisites</legend>
          <p v-if="prereqOptions.length === 0" class="training-edit__muted">
            No other trainings available as prerequisites.
          </p>
          <label
            v-for="option in prereqOptions"
            :key="option.id"
            class="training-edit__prereq-option"
          >
            <input
              type="checkbox"
              :checked="draft.prerequisiteIds.includes(option.id)"
              @change="
                togglePrerequisite(
                  option.id,
                  ($event.target as HTMLInputElement).checked,
                )
              "
            />
            {{ option.name }}
          </label>
        </fieldset>

        <button class="training-edit__submit" type="submit" :disabled="busy">
          {{ isCreate ? "Create training" : "Save training" }}
        </button>
      </form>

      <template v-if="!isCreate && training !== null && canRecord">
        <section class="training-edit__section" aria-labelledby="roster-heading">
          <h2 id="roster-heading">Roster</h2>
          <p v-if="!takesSignups" class="training-edit__muted">
            This training has no scheduled session, so there is no signup roster.
            Record completions directly below.
          </p>
          <template v-else>
            <table class="training-edit__table">
              <thead>
                <tr>
                  <th scope="col">Staff</th>
                  <th scope="col">Signed up</th>
                  <th scope="col">Completed</th>
                  <th scope="col">Actions</th>
                </tr>
              </thead>
              <tbody>
                <tr v-if="roster.length === 0">
                  <td colspan="4">No signups yet.</td>
                </tr>
                <tr v-for="signup in roster" :key="signup.staffId">
                  <td>{{ signup.displayName ?? signup.email ?? "Staff" }}</td>
                  <td>{{ formatTimestamp(signup.signedUpAt) }}</td>
                  <td>{{ signup.completed ? "Yes" : "No" }}</td>
                  <td class="training-edit__actions">
                    <button
                      type="button"
                      :disabled="busy"
                      @click="onRecordRosterCompletion(signup.staffId)"
                    >
                      Record completion
                    </button>
                    <button
                      type="button"
                      :disabled="busy"
                      @click="onRemoveFromRoster(signup.staffId)"
                    >
                      Remove
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>

            <div class="training-edit__inline-form">
              <label>
                Add staff to roster
                <select v-model="rosterAddStaffId">
                  <option value="">Choose staff</option>
                  <option
                    v-for="member in staff"
                    :key="member.staffId"
                    :value="member.staffId"
                  >
                    {{ member.displayName ?? member.email }}
                  </option>
                </select>
              </label>
              <button
                type="button"
                :disabled="busy"
                @click="onAddToRoster(rosterAddStaffId)"
              >
                Add to roster
              </button>
            </div>
          </template>
        </section>

        <section
          class="training-edit__section"
          aria-labelledby="completions-heading"
        >
          <h2 id="completions-heading">Completions</h2>

          <div class="training-edit__inline-form">
            <label>
              Staff member
              <select v-model="completionStaffId">
                <option value="">Choose staff</option>
                <option
                  v-for="member in staff"
                  :key="member.staffId"
                  :value="member.staffId"
                >
                  {{ member.displayName ?? member.email }}
                </option>
              </select>
            </label>
            <label>
              Completion date (blank for today)
              <input v-model="completionDate" type="date" />
            </label>
            <button type="button" :disabled="busy" @click="onRecordCompletion">
              Record completion
            </button>
          </div>

          <table class="training-edit__table">
            <thead>
              <tr>
                <th scope="col">Staff</th>
                <th scope="col">Completed</th>
                <th scope="col">Expires</th>
                <th scope="col">Recorded by</th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="completions.length === 0">
                <td colspan="4">No completions recorded yet.</td>
              </tr>
              <tr v-for="completion in completions" :key="completion.id">
                <td>
                  {{ completion.displayName ?? completion.email ?? "Staff" }}
                </td>
                <td>{{ formatTimestamp(completion.completedAt) }}</td>
                <td>
                  {{
                    completion.expiresAt === null
                      ? "Does not expire"
                      : formatTimestamp(completion.expiresAt)
                  }}
                  <span v-if="completion.expired"> (expired)</span>
                </td>
                <td>{{ completion.recordedBy ?? "—" }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section
          v-if="canManage"
          class="training-edit__section"
          aria-labelledby="import-heading"
        >
          <h2 id="import-heading">Import completions from spreadsheet</h2>
          <p class="training-edit__muted">
            Paste CSV rows with an <code>email</code> column and an optional
            <code>completed_at</code> column.
          </p>
          <textarea
            v-model="importCsv"
            rows="5"
            aria-label="Completion CSV"
            placeholder="email,completed_at&#10;vera@signalcamp.dev,2026-07-01"
          ></textarea>
          <button type="button" :disabled="busy" @click="onImport">
            Import completions
          </button>

          <table v-if="importResult" class="training-edit__table">
            <caption>
              Imported {{ importResult.imported }}, skipped
              {{ importResult.skipped }}
            </caption>
            <thead>
              <tr>
                <th scope="col">Line</th>
                <th scope="col">Email</th>
                <th scope="col">Result</th>
                <th scope="col">Reason</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in importResult.rows" :key="row.line">
                <td>{{ row.line }}</td>
                <td>{{ row.email }}</td>
                <td>{{ row.status }}</td>
                <td>{{ row.reason ?? "—" }}</td>
              </tr>
            </tbody>
          </table>
        </section>
      </template>
    </template>
  </DeptOpsShell>
</template>

<style scoped>
.training-edit__form,
.training-edit__section {
  display: grid;
  gap: var(--m-space-3);
  margin-top: var(--m-space-4);
}

.training-edit__form label,
.training-edit__inline-form label {
  display: grid;
  gap: var(--m-space-1);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.training-edit__form input,
.training-edit__form textarea,
.training-edit__form select,
.training-edit__inline-form input,
.training-edit__inline-form select,
.training-edit__section textarea {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
}

.training-edit__prereqs {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
}

.training-edit__prereq-option {
  display: flex;
  align-items: center;
  gap: var(--m-space-2);
  color: var(--m-text-primary);
  font-weight: 600;
}

.training-edit__submit,
.training-edit__section button,
.training-edit__actions button {
  justify-self: start;
  min-height: 2.5rem;
  padding: var(--m-space-2) var(--m-space-4);
  border: 1px solid var(--m-platform-accent);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
}

.training-edit__inline-form {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  align-items: end;
}

.training-edit__table {
  width: 100%;
  border-collapse: collapse;
}

.training-edit__table th,
.training-edit__table td {
  padding: var(--m-space-2) var(--m-space-3);
  border-bottom: 1px solid var(--m-border-subtle);
  color: var(--m-text-primary);
  text-align: left;
}

.training-edit__table th {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.training-edit__table caption {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-align: left;
}

.training-edit__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.training-edit__section h2 {
  margin: 0;
}

.training-edit__muted {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.training-edit__error,
.training-edit__restricted {
  margin: 0;
  color: var(--m-status-danger);
  font-weight: 800;
}

.training-edit__notice {
  margin: 0;
  color: var(--m-status-success);
  font-weight: 800;
}
</style>
