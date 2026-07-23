<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import {
  activeSignupsFor,
  canAccessTrainings,
  canManageTrainings,
  cancelTrainingSignup,
  deliveryLabel,
  expirationLabel,
  getTraining,
  hasCurrentCompletion,
  prerequisiteNames,
  resolveTrainingSession,
  scheduleLabel,
  signUpForTraining,
  takesSignups,
  unlockedShiftsFor,
  viewerStatus,
} from "@/trainings/trainingAdminModel";

const route = useRoute();
const session = computed(() =>
  resolveTrainingSession(
    typeof route.params.departmentId === "string"
      ? route.params.departmentId
      : null,
  ),
);
const refreshKey = ref(0);
const training = computed(() => {
  void refreshKey.value;
  return typeof route.params.trainingId === "string"
    ? getTraining(session.value, route.params.trainingId)
    : null;
});
const canAccess = computed(() => canAccessTrainings(session.value));
const canManage = computed(() => canManageTrainings(session.value));
const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);

const routeParams = computed(() => ({
  eventId: session.value.eventId,
  departmentId: session.value.department.departmentId,
}));

const status = computed(() => {
  void refreshKey.value;
  return training.value === null
    ? { signedUp: false, completed: false }
    : viewerStatus(session.value, training.value);
});

const signupSummary = computed(() => {
  if (training.value === null || !takesSignups(training.value)) {
    return null;
  }

  const count = activeSignupsFor(training.value).length;
  return training.value.capacity === null
    ? `${count} signed up`
    : `${count} of ${training.value.capacity} spots taken`;
});

const teamLabel = computed(() => {
  if (training.value === null || training.value.teamId === null) {
    return null;
  }

  return (
    session.value.department.teams.find(
      (team) => team.teamId === training.value?.teamId,
    )?.teamLabel ?? null
  );
});

const prerequisiteStates = computed(() => {
  void refreshKey.value;
  if (training.value === null) {
    return [];
  }

  return training.value.prerequisiteIds.map((prerequisiteId, index) => ({
    id: prerequisiteId,
    name: prerequisiteNames(training.value!)[index] ?? "Unknown training",
    complete: hasCurrentCompletion(prerequisiteId, session.value.viewerStaffId),
  }));
});

const unlockedShifts = computed(() =>
  training.value === null ? [] : unlockedShiftsFor(training.value),
);

function run(action: () => void, notice: string): void {
  actionError.value = null;
  actionNotice.value = null;

  try {
    action();
    actionNotice.value = notice;
    refreshKey.value++;
  } catch (error) {
    actionError.value =
      error instanceof Error ? error.message : "Unable to update signup.";
  }
}

function onSignUp(): void {
  if (training.value === null) {
    return;
  }

  const name = training.value.name;
  run(() => signUpForTraining(session.value, training.value!.id), `Signed up for ${name}.`);
}

function onCancelSignup(): void {
  if (training.value === null) {
    return;
  }

  const name = training.value.name;
  run(
    () => cancelTrainingSignup(session.value, training.value!.id),
    `Signup cancelled for ${name}.`,
  );
}

function formatTimestamp(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime())
    ? value
    : date.toLocaleString(undefined, { dateStyle: "medium", timeStyle: "short" });
}
</script>

<template>
  <DeptOpsShell
    heading-id="training-detail-heading"
    :title="training?.name ?? 'Training'"
    :eyebrow="session.department.departmentLabel"
    :lede="training?.description ?? 'Training information page.'"
  >
    <template #nav>
      <RouterLink
        :to="{ name: 'events.departments.trainings.index', params: routeParams }"
      >
        Back to Trainings
      </RouterLink>
    </template>

    <template #actions>
      <WorkflowActionButton
        v-if="canManage && training !== null"
        variant="secondary"
        :to="{
          name: 'events.departments.trainings.edit',
          params: { ...routeParams, trainingId: training.id },
        }"
      >
        Edit training
      </WorkflowActionButton>
      <WorkflowActionButton
        v-if="training !== null && takesSignups(training) && !status.signedUp && training.archivedAt === null"
        @click="onSignUp"
      >
        Sign up
      </WorkflowActionButton>
      <WorkflowActionButton
        v-if="training !== null && status.signedUp"
        variant="secondary"
        @click="onCancelSignup"
      >
        Cancel signup
      </WorkflowActionButton>
    </template>

    <p v-if="!canAccess" class="training-detail__restricted" role="status">
      Trainings are available only to department members, trainers, and leads.
    </p>
    <p
      v-else-if="training === null"
      class="training-detail__restricted"
      role="status"
    >
      Training not found.
    </p>

    <template v-else>
      <p v-if="actionError" class="training-detail__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="actionNotice" class="training-detail__notice" role="status">
        {{ actionNotice }}
      </p>

      <p v-if="status.completed" class="training-detail__notice" role="status">
        You have a current completion for this training.
      </p>
      <p v-else-if="status.signedUp" class="training-detail__notice" role="status">
        You are signed up for this training.
      </p>

      <section
        class="training-detail__section"
        aria-labelledby="training-when-heading"
      >
        <h2 id="training-when-heading">When and where</h2>
        <dl class="training-detail__facts">
          <div>
            <dt>Delivery</dt>
            <dd>{{ deliveryLabel(training) }}</dd>
          </div>
          <div v-if="training.delivery === 'online' && training.onlineUrl">
            <dt>Training page</dt>
            <dd>
              <a :href="training.onlineUrl" target="_blank" rel="noreferrer">
                {{ training.onlineUrl }}
              </a>
            </dd>
          </div>
          <div>
            <dt>Schedule</dt>
            <dd>{{ scheduleLabel(training) }}</dd>
          </div>
          <div v-if="training.timeCommitment">
            <dt>Time commitment</dt>
            <dd>{{ training.timeCommitment }}</dd>
          </div>
          <div>
            <dt>Expiration</dt>
            <dd>{{ expirationLabel(training) }}</dd>
          </div>
          <div v-if="teamLabel">
            <dt>Team</dt>
            <dd>For members of {{ teamLabel }}</dd>
          </div>
          <div v-if="signupSummary">
            <dt>Signups</dt>
            <dd>{{ signupSummary }}</dd>
          </div>
        </dl>
        <p v-if="takesSignups(training)" class="training-detail__muted">
          This session is listed as a shift signup for
          {{ teamLabel ?? session.department.departmentLabel }} and works like
          signing up for any other shift.
        </p>
        <p v-else-if="training.delivery === 'online'" class="training-detail__muted">
          No signup is needed for this online training. Visit the training page
          at your own pace.
        </p>
      </section>

      <section
        class="training-detail__section"
        aria-labelledby="training-before-heading"
      >
        <h2 id="training-before-heading">Before you start</h2>
        <p v-if="prerequisiteStates.length === 0" class="training-detail__muted">
          This training has no prerequisites.
        </p>
        <ul v-else class="training-detail__list">
          <li v-for="prerequisite in prerequisiteStates" :key="prerequisite.id">
            {{ prerequisite.name }} —
            {{ prerequisite.complete ? "you have completed this" : "not completed yet" }}
          </li>
        </ul>
      </section>

      <section
        class="training-detail__section"
        aria-labelledby="training-after-heading"
      >
        <h2 id="training-after-heading">After this training</h2>
        <p v-if="training.afterTraining">{{ training.afterTraining }}</p>
        <p v-if="training.provisions">
          <strong>Provisions:</strong> {{ training.provisions }}
        </p>
        <template v-if="unlockedShifts.length > 0">
          <h3>Shifts this training unlocks</h3>
          <ul class="training-detail__list">
            <li v-for="shift in unlockedShifts" :key="shift.shiftTitle">
              {{ shift.shiftTitle }} — {{ formatTimestamp(shift.startsAt) }}
            </li>
          </ul>
        </template>
        <p
          v-if="!training.afterTraining && !training.provisions && unlockedShifts.length === 0"
          class="training-detail__muted"
        >
          Your department lead will share next steps after completion.
        </p>
      </section>
    </template>
  </DeptOpsShell>
</template>

<style scoped>
.training-detail__section {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
}

.training-detail__section h2,
.training-detail__section h3,
.training-detail__section p {
  margin: 0;
}

.training-detail__facts {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
}

.training-detail__facts div {
  display: grid;
  gap: 0.2rem;
}

.training-detail__facts dt {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.training-detail__facts dd {
  margin: 0;
  color: var(--m-text-primary);
}

.training-detail__facts a {
  color: var(--m-text-primary);
}

.training-detail__list {
  margin: 0;
  padding-left: var(--m-space-4);
  color: var(--m-text-primary);
}

.training-detail__muted {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.training-detail__error,
.training-detail__restricted {
  margin: 0;
  color: var(--m-status-danger);
  font-weight: 800;
}

.training-detail__notice {
  margin: 0;
  color: var(--m-status-success);
  font-weight: 800;
}

@media (min-width: 44rem) {
  .training-detail__facts {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
