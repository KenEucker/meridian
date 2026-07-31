<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import { selectedSessionDepartment } from "@/session/sessionAccess";
import {
  cancelTrainingSignup,
  deliveryLabel,
  expirationLabel,
  getTraining,
  scheduleLabel,
  signUpForTraining,
  type ProductTrainingDetail,
} from "@/trainings/trainingAdminModel";

const route = useRoute();
const departmentId = computed(() => String(route.params.departmentId ?? ""));
const trainingId = computed(() => String(route.params.trainingId ?? ""));

/**
 * The one read this page renders from (M16.16).
 *
 * Every fact on it — the schedule, the prerequisite list with what the reader
 * has already completed, the shifts completion unlocks, and where the reader
 * stands — arrives on this response, and it is read again after each signup
 * change rather than adjusted in place.
 */
const training = ref<ProductTrainingDetail | null>(null);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const actionNotice = ref<string | null>(null);
const busy = ref(false);

/*
 * Authority is the node's answer, carried on the response, rather than a role
 * the client interpreted for itself (CLIENT-006).
 */
const canManage = computed(() => training.value?.viewer.canManage ?? false);
const isSignedUp = computed(() => training.value?.viewer.isSignedUp ?? false);
const hasCompletion = computed(
  () => (training.value?.viewer.completion ?? null) !== null,
);

const departmentLabel = computed(
  () => selectedSessionDepartment.value?.departmentLabel ?? "Department",
);

const routeParams = computed(() => ({
  eventId: String(route.params.eventId ?? ""),
  departmentId: departmentId.value,
}));

const takesSignups = computed(
  () => training.value?.requiresScheduledAttendance ?? false,
);

const signupSummary = computed(() => {
  if (training.value === null || !takesSignups.value) {
    return null;
  }

  return training.value.capacity === null
    ? `${training.value.activeSignupCount} signed up`
    : `${training.value.activeSignupCount} of ${training.value.capacity} spots taken`;
});

const prerequisites = computed(() => training.value?.prerequisites ?? []);
const unlockedShifts = computed(() => training.value?.unlockedShifts ?? []);

async function loadTraining(): Promise<void> {
  if (departmentId.value === "" || trainingId.value === "") {
    training.value = null;

    return;
  }

  loadError.value = null;

  try {
    training.value = await getTraining(departmentId.value, trainingId.value);
  } catch (error) {
    training.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this training. Check the connection to this node and try again.",
    );
  }
}

watch([departmentId, trainingId], () => {
  actionError.value = null;
  actionNotice.value = null;
  void loadTraining();
});

void loadTraining();

/**
 * Run one signup command and then take the page's state from the node again.
 *
 * Capacity, prerequisite completion, and whether the training takes signups at
 * all are the node's to decide, so a refusal is shown as it worded it and
 * nothing on the page moves.
 */
async function run(
  action: () => Promise<void>,
  notice: string,
  fallback: string,
): Promise<void> {
  actionError.value = null;
  actionNotice.value = null;
  busy.value = true;

  try {
    await action();
    actionNotice.value = notice;
    await loadTraining();
  } catch (error) {
    actionError.value = meridianErrorMessage(error, fallback);
  } finally {
    busy.value = false;
  }
}

async function onSignUp(): Promise<void> {
  const current = training.value;

  if (current === null) {
    return;
  }

  await run(
    () => signUpForTraining(current.id),
    `Signed up for ${current.name}.`,
    "Unable to sign up for this training.",
  );
}

async function onCancelSignup(): Promise<void> {
  const current = training.value;

  if (current === null) {
    return;
  }

  await run(
    () => cancelTrainingSignup(current.id),
    `Signup cancelled for ${current.name}.`,
    "Unable to cancel this signup.",
  );
}

function formatTimestamp(value: string | null): string {
  if (value === null) {
    return "Not scheduled";
  }

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
    :eyebrow="departmentLabel"
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
        v-if="
          training !== null &&
          takesSignups &&
          !isSignedUp &&
          training.archivedAt === null
        "
        :disabled="busy"
        @click="onSignUp"
      >
        Sign up
      </WorkflowActionButton>
      <WorkflowActionButton
        v-if="training !== null && isSignedUp"
        variant="secondary"
        :disabled="busy"
        @click="onCancelSignup"
      >
        Cancel signup
      </WorkflowActionButton>
    </template>

    <!--
      A refusal is the node's own sentence. A training in another department, one
      that does not exist, and one this caller may not see are all answered by
      the endpoint, and saying what it answered keeps one account of who may read
      this page (CLIENT-006).
    -->
    <p v-if="loadError" class="training-detail__error" role="alert">
      {{ loadError }}
    </p>
    <p
      v-else-if="training === null"
      class="training-detail__restricted"
      role="status"
    >
      Loading training…
    </p>

    <template v-else>
      <p v-if="actionError" class="training-detail__error" role="alert">
        {{ actionError }}
      </p>
      <p v-if="actionNotice" class="training-detail__notice" role="status">
        {{ actionNotice }}
      </p>

      <p v-if="hasCompletion" class="training-detail__notice" role="status">
        You have a current completion for this training.
      </p>
      <p v-else-if="isSignedUp" class="training-detail__notice" role="status">
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
          <div v-if="training.teamName">
            <dt>Team</dt>
            <dd>For members of {{ training.teamName }}</dd>
          </div>
          <div v-if="signupSummary">
            <dt>Signups</dt>
            <dd>{{ signupSummary }}</dd>
          </div>
        </dl>
        <p v-if="takesSignups" class="training-detail__muted">
          This session is listed as a shift signup for
          {{ training.teamName ?? departmentLabel }} and works like signing up
          for any other shift.
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
        <p v-if="prerequisites.length === 0" class="training-detail__muted">
          This training has no prerequisites.
        </p>
        <ul v-else class="training-detail__list">
          <li v-for="prerequisite in prerequisites" :key="prerequisite.id">
            {{ prerequisite.name }} —
            {{
              prerequisite.viewerCompleted
                ? "you have completed this"
                : "not completed yet"
            }}
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
            <li v-for="shift in unlockedShifts" :key="shift.id">
              {{ shift.title }} — {{ formatTimestamp(shift.startsAt) }}
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
