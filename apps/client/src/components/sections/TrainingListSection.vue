<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  activeSignupsFor,
  archiveTraining,
  canAccessTrainings,
  canManageTrainings,
  cancelTrainingSignup,
  deliveryLabel,
  expirationLabel,
  prerequisiteNames,
  resolveTrainingSession,
  restoreTraining,
  scheduleLabel,
  signUpForTraining,
  takesSignups,
  viewerStatus,
  visibleTrainings,
  type ProductTraining,
} from "@/trainings/trainingAdminModel";

/**
 * Training schedule, signup, and completion featureset (M11.16, M11.17).
 *
 * Rendered as its own page at `department.trainings` and embedded as a
 * featureset in the Planning workflow hub.
 */
const props = withDefaults(
  defineProps<{
    variant?: "page" | "section";
    departmentId?: string | null;
  }>(),
  {
    variant: "page",
    departmentId: null,
  },
);

const route = useRoute();
const session = computed(() =>
  resolveTrainingSession(
    props.departmentId ??
      (typeof route.params.departmentId === "string"
        ? route.params.departmentId
        : null),
  ),
);
const refreshKey = ref(0);
const trainings = computed(() => {
  void refreshKey.value;
  return visibleTrainings(session.value);
});
const canAccess = computed(() => canAccessTrainings(session.value));
const canManage = computed(() => canManageTrainings(session.value));
const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);

const routeParams = computed(() => ({
  eventId: session.value.eventId,
  departmentId: session.value.department.departmentId,
}));

const createRoute = computed(() => ({
  name: "events.departments.trainings.create",
  params: routeParams.value,
}));

defineExpose({ canAccess, canManage, trainings });

function signupSummary(training: ProductTraining): string {
  if (!takesSignups(training)) {
    return "Not required";
  }

  const count = activeSignupsFor(training).length;
  return training.capacity === null
    ? `${count} signed up`
    : `${count} of ${training.capacity} signed up`;
}

function statusLabel(training: ProductTraining): string {
  void refreshKey.value;
  const status = viewerStatus(session.value, training);

  if (status.completed) {
    return "Completed";
  }

  if (status.signedUp) {
    return "Signed up";
  }

  return "—";
}

function isSignedUp(training: ProductTraining): boolean {
  void refreshKey.value;
  return viewerStatus(session.value, training).signedUp;
}

function run(action: () => void, notice: string): void {
  actionError.value = null;
  actionNotice.value = null;

  try {
    action();
    actionNotice.value = notice;
    refreshKey.value++;
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to update training.";
  }
}

function onSignUp(training: ProductTraining): void {
  run(
    () => signUpForTraining(session.value, training.id),
    `Signed up for ${training.name}.`,
  );
}

function onCancelSignup(training: ProductTraining): void {
  run(
    () => cancelTrainingSignup(session.value, training.id),
    `Signup cancelled for ${training.name}.`,
  );
}

function onArchive(training: ProductTraining): void {
  run(
    () => archiveTraining(session.value, training.id),
    `${training.name} archived.`,
  );
}

function onRestore(training: ProductTraining): void {
  run(
    () => restoreTraining(session.value, training.id),
    `${training.name} restored.`,
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

    <p v-if="!canAccess" class="trainings__restricted" role="status">
      Trainings are available only to department members, trainers, and leads.
    </p>

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
                  v-if="canManage"
                  :to="{
                    name: 'events.departments.trainings.edit',
                    params: { ...routeParams, trainingId: training.id },
                  }"
                >
                  Edit
                </RouterLink>
                <button
                  v-if="
                    takesSignups(training) &&
                    training.archivedAt === null &&
                    !isSignedUp(training)
                  "
                  type="button"
                  @click="onSignUp(training)"
                >
                  Sign up
                </button>
                <button
                  v-if="isSignedUp(training)"
                  type="button"
                  @click="onCancelSignup(training)"
                >
                  Cancel signup
                </button>
                <button
                  v-if="canManage && training.archivedAt === null"
                  type="button"
                  @click="onArchive(training)"
                >
                  Archive
                </button>
                <button
                  v-if="canManage && training.archivedAt !== null"
                  type="button"
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
