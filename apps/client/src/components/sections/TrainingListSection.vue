<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import StaffCardList from "@/components/StaffCardList.vue";
import StaffListCard from "@/components/StaffListCard.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  archiveTraining,
  cancelTrainingSignup,
  deliveryLabel,
  expirationLabel,
  getDepartmentTrainings,
  prerequisiteNames,
  restoreTraining,
  scheduleLabel,
  signUpForTraining,
  type ProductTraining,
  type TrainingWorkspace,
} from "@/trainings/trainingAdminModel";

/**
 * Training schedule, signup, and completion featureset (M11.16, M11.17; bound
 * to the node in M16.16).
 *
 * Rendered as its own page at `department.trainings` and embedded as a
 * featureset in the Planning workflow hub.
 *
 * The page above it already reads the department's trainings for its heading
 * cards, so it hands that response down through `workspace` and re-reads on
 * `reload`; embedded on its own the section makes the read itself. Either way
 * one read is behind what is shown rather than two that could disagree.
 */
const props = withDefaults(
  defineProps<{
    variant?: "page" | "section";
    departmentId?: string | null;
    workspace?: TrainingWorkspace | null;
  }>(),
  {
    variant: "page",
    departmentId: null,
    workspace: undefined,
  },
);

const emit = defineEmits<{ reload: [] }>();

const route = useRoute();
const departmentId = computed(
  () =>
    props.departmentId ??
    (typeof route.params.departmentId === "string"
      ? route.params.departmentId
      : ""),
);

/** Whether this section is responsible for its own read. */
const ownsRead = computed(() => props.workspace === undefined);

const ownWorkspace = ref<TrainingWorkspace | null>(null);
const workspace = computed<TrainingWorkspace | null>(() =>
  ownsRead.value ? ownWorkspace.value : (props.workspace ?? null),
);

const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);
const busyId = ref<string | null>(null);

/*
 * Authority is the node's answer, carried on the response, rather than a role
 * the client interpreted for itself. The commands behind these actions enforce
 * that same answer, so an action offered past it could only be refused
 * (CLIENT-006).
 */
const canAccess = computed(() => workspace.value !== null);
const canManage = computed(() => workspace.value?.access.canManage ?? false);
const trainings = computed<readonly ProductTraining[]>(
  () => workspace.value?.trainings ?? [],
);

const routeParams = computed(() => ({
  eventId: String(route.params.eventId ?? ""),
  departmentId: departmentId.value,
}));

const createRoute = computed(() => ({
  name: "events.departments.trainings.create",
  params: routeParams.value,
}));

async function loadOwnWorkspace(): Promise<void> {
  if (!ownsRead.value) {
    return;
  }

  if (departmentId.value === "") {
    ownWorkspace.value = null;

    return;
  }

  loadError.value = null;

  try {
    ownWorkspace.value = await getDepartmentTrainings(departmentId.value);
  } catch (error) {
    ownWorkspace.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load trainings. Check the connection to this node and try again.",
    );
  }
}

watch(departmentId, () => {
  actionError.value = null;
  actionNotice.value = null;
  void loadOwnWorkspace();
});

void loadOwnWorkspace();

defineExpose({ canAccess, canManage, trainings });

function signupSummary(training: ProductTraining): string {
  if (!training.requiresScheduledAttendance) {
    return "Not required";
  }

  return training.capacity === null
    ? `${training.activeSignupCount} signed up`
    : `${training.activeSignupCount} of ${training.capacity} signed up`;
}

function statusLabel(training: ProductTraining): string {
  if (training.viewer.completion !== null) {
    return "Completed";
  }

  if (training.viewer.isSignedUp) {
    return "Signed up";
  }

  return "—";
}

/**
 * Card status pill. Empty when the viewer has no standing on the training, so
 * the card omits the pill instead of showing an em dash inside one.
 */
function cardStatus(training: ProductTraining): string {
  const label = statusLabel(training);

  return label === "—" ? "" : label;
}

/**
 * Run one command and then take the surface's state from the node again.
 *
 * Nothing is patched in place: a signup that moves a capacity count, an archive
 * that changes which actions a row offers, and a refusal that changes nothing
 * at all are all read back rather than guessed at.
 */
async function run(
  training: ProductTraining,
  action: () => Promise<void>,
  notice: string,
  fallback: string,
): Promise<void> {
  actionError.value = null;
  actionNotice.value = null;
  busyId.value = training.id;

  try {
    await action();
    actionNotice.value = notice;

    if (ownsRead.value) {
      await loadOwnWorkspace();
    } else {
      emit("reload");
    }
  } catch (error) {
    actionError.value = meridianErrorMessage(error, fallback);
  } finally {
    busyId.value = null;
  }
}

async function onSignUp(training: ProductTraining): Promise<void> {
  await run(
    training,
    () => signUpForTraining(training.id),
    `Signed up for ${training.name}.`,
    "Unable to sign up for this training.",
  );
}

async function onCancelSignup(training: ProductTraining): Promise<void> {
  await run(
    training,
    () => cancelTrainingSignup(training.id),
    `Signup cancelled for ${training.name}.`,
    "Unable to cancel this signup.",
  );
}

async function onArchive(training: ProductTraining): Promise<void> {
  await run(
    training,
    () => archiveTraining(training.id),
    `${training.name} archived.`,
    "Unable to archive this training.",
  );
}

async function onRestore(training: ProductTraining): Promise<void> {
  await run(
    training,
    () => restoreTraining(training.id),
    `${training.name} restored.`,
    "Unable to restore this training.",
  );
}
</script>

<template>
  <WorkflowSection
    :variant="props.variant"
    title="Trainings"
    heading-id="trainings-section-heading"
    description="Department training schedule, signup, and completion."
  >
    <template v-if="canManage" #actions>
      <WorkflowActionButton :to="createRoute">
        New training
      </WorkflowActionButton>
    </template>

    <!--
      A refusal is the node's own sentence, not a second copy of its rules. The
      403 this endpoint answers with when the caller may not view the
      department's trainings already says so, and saying it here keeps one
      account of who may see this page (CLIENT-006). An unreachable node reads
      the same way rather than as a department with nothing in it.
    -->
    <p v-if="loadError" class="trainings__restricted" role="alert">
      {{ loadError }}
    </p>

    <p v-else-if="!canAccess" class="trainings__restricted" role="status">
      Loading trainings…
    </p>

    <!--
      A member's question is "what do I need, and am I signed up" — answered on
      a phone. The seven-column management table answers a different question,
      so members get a card per training with the signup action in reach.
    -->
    <template v-else-if="!canManage">
      <p v-if="actionError" class="trainings__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="actionNotice" class="trainings__notice" role="status">
        {{ actionNotice }}
      </p>

      <StaffCardList
        min="wide"
        label="Trainings"
        :empty="trainings.length === 0"
        empty-message="No trainings are available for your department yet."
      >
        <StaffListCard
          v-for="training in trainings"
          :key="training.id"
          :title="training.name"
          :eyebrow="deliveryLabel(training)"
          :subtitle="training.description ?? ''"
          :status="cardStatus(training)"
          :meta="[
            { label: 'Schedule', value: scheduleLabel(training) },
            { label: 'Expiration', value: expirationLabel(training) },
            {
              label: 'Prerequisites',
              value:
                prerequisiteNames(training).length === 0
                  ? 'None'
                  : prerequisiteNames(training).join(', '),
            },
            { label: 'Signups', value: signupSummary(training) },
          ]"
        >
          <template #actions>
            <RouterLink
              :to="{
                name: 'events.departments.trainings.show',
                params: { ...routeParams, trainingId: training.id },
              }"
            >
              Details
            </RouterLink>
            <button
              v-if="
                training.requiresScheduledAttendance &&
                training.archivedAt === null &&
                !training.viewer.isSignedUp
              "
              type="button"
              :disabled="busyId === training.id"
              @click="onSignUp(training)"
            >
              Sign up
            </button>
            <button
              v-if="training.viewer.isSignedUp"
              type="button"
              :disabled="busyId === training.id"
              @click="onCancelSignup(training)"
            >
              Cancel signup
            </button>
          </template>
        </StaffListCard>
      </StaffCardList>
    </template>

    <template v-else>
      <p v-if="actionError" class="trainings__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="actionNotice" class="trainings__notice" role="status">
        {{ actionNotice }}
      </p>

      <div class="trainings__table-wrap" role="region" aria-label="Trainings">
        <table class="trainings__table">
          <thead>
            <tr>
              <th scope="col">Training</th>
              <th scope="col">Schedule</th>
              <th scope="col">Expiration</th>
              <th scope="col">Prerequisites</th>
              <th scope="col">Signups</th>
              <th scope="col">Your status</th>
              <th scope="col">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="trainings.length === 0">
              <td colspan="7">No trainings yet.</td>
            </tr>
            <tr v-for="training in trainings" :key="training.id">
              <td>
                <RouterLink
                  :to="{
                    name: 'events.departments.trainings.show',
                    params: { ...routeParams, trainingId: training.id },
                  }"
                >
                  {{ training.name }}
                </RouterLink>
                <p class="trainings__muted">{{ deliveryLabel(training) }}</p>
                <p v-if="training.description" class="trainings__muted">
                  {{ training.description }}
                </p>
                <p v-if="training.archivedAt" class="trainings__muted">
                  Archived
                </p>
              </td>
              <td>{{ scheduleLabel(training) }}</td>
              <td>{{ expirationLabel(training) }}</td>
              <td>
                <span v-if="prerequisiteNames(training).length === 0">None</span>
                <span v-else>{{ prerequisiteNames(training).join(", ") }}</span>
              </td>
              <td>{{ signupSummary(training) }}</td>
              <td>{{ statusLabel(training) }}</td>
              <td class="trainings__actions">
                <RouterLink
                  :to="{
                    name: 'events.departments.trainings.show',
                    params: { ...routeParams, trainingId: training.id },
                  }"
                >
                  Details
                </RouterLink>
                <RouterLink
                  :to="{
                    name: 'events.departments.trainings.edit',
                    params: { ...routeParams, trainingId: training.id },
                  }"
                >
                  Edit
                </RouterLink>
                <button
                  v-if="
                    training.requiresScheduledAttendance &&
                    training.archivedAt === null &&
                    !training.viewer.isSignedUp
                  "
                  type="button"
                  :disabled="busyId === training.id"
                  @click="onSignUp(training)"
                >
                  Sign up
                </button>
                <button
                  v-if="training.viewer.isSignedUp"
                  type="button"
                  :disabled="busyId === training.id"
                  @click="onCancelSignup(training)"
                >
                  Cancel signup
                </button>
                <button
                  v-if="training.archivedAt === null"
                  type="button"
                  :disabled="busyId === training.id"
                  @click="onArchive(training)"
                >
                  Archive
                </button>
                <button
                  v-if="training.archivedAt !== null"
                  type="button"
                  :disabled="busyId === training.id"
                  @click="onRestore(training)"
                >
                  Restore
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.trainings__actions a,
.trainings__actions button {
  min-height: 2.25rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 900;
  text-decoration: none;
}

.trainings__table-wrap {
  overflow-x: auto;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.trainings__table {
  width: 100%;
  border-collapse: collapse;
  min-width: 62rem;
}

.trainings__table th,
.trainings__table td {
  padding: var(--m-space-3);
  border-bottom: 1px solid var(--m-border-subtle);
  text-align: left;
  vertical-align: top;
}

.trainings__table th {
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

.trainings__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.trainings__muted {
  margin: var(--m-space-1) 0 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.trainings__restricted,
.trainings__error,
.trainings__notice {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.trainings__error {
  border-color: var(--m-status-danger, #cc792f);
  color: var(--m-status-danger, #cc792f);
}
</style>
