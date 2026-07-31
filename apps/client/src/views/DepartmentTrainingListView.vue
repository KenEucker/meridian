<script setup lang="ts">
import { computed, ref, watch } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowHeadingCard from "@/components/WorkflowHeadingCard.vue";
import WorkflowHeadingCardGrid from "@/components/WorkflowHeadingCardGrid.vue";
import TrainingListSection from "@/components/sections/TrainingListSection.vue";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  selectedSessionDepartment,
  sessionDepartmentRoleSummary,
} from "@/session/sessionAccess";
import {
  getDepartmentTrainings,
  type TrainingWorkspace,
} from "@/trainings/trainingAdminModel";

const route = useRoute();
const departmentId = computed(() => String(route.params.departmentId ?? ""));

/**
 * The one read this surface renders from (M16.16).
 *
 * The heading cards and the training list are two views of one response rather
 * than two requests that could disagree about which trainings exist, so the
 * page reads and the featureset below is handed the answer. It is re-read after
 * every write the featureset makes.
 */
const workspace = ref<TrainingWorkspace | null>(null);
const loadError = ref<string | null>(null);

/*
 * Authority is the node's answer, carried on the response, rather than a role
 * the client interpreted for itself (CLIENT-006). Until the read lands the page
 * shows the member shell: an unanswered question about authority is not a
 * manager.
 */
const canManage = computed(() => workspace.value?.access.canManage ?? false);
const trainings = computed(() => workspace.value?.trainings ?? []);

const department = computed(() => selectedSessionDepartment.value);
const departmentLabel = computed(
  () => department.value?.departmentLabel ?? "Department",
);
const roleLabel = computed(() =>
  department.value === null
    ? "Department"
    : sessionDepartmentRoleSummary(department.value),
);

const routeParams = computed(() => ({
  eventId: String(route.params.eventId ?? ""),
  departmentId: departmentId.value,
}));

const scheduledCount = computed(
  () =>
    trainings.value.filter(
      (training) => training.requiresScheduledAttendance,
    ).length,
);
const viewerSignupCount = computed(
  () => trainings.value.filter((training) => training.viewer.isSignedUp).length,
);
const viewerCompletedCount = computed(
  () =>
    trainings.value.filter((training) => training.viewer.completion !== null)
      .length,
);

async function loadWorkspace(): Promise<void> {
  if (departmentId.value === "") {
    workspace.value = null;

    return;
  }

  loadError.value = null;

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

watch(departmentId, () => {
  void loadWorkspace();
});

void loadWorkspace();
</script>

<template>
  <!--
    Members come here to find a training and sign up for it, so they get the
    narrow touch-first staff shell with their own counts. Managers keep the
    wide workflow shell and its roster-level heading cards.
  -->
  <StaffPageShell
    v-if="!canManage"
    heading-id="trainings-heading"
    title="Trainings"
    :eyebrow="departmentLabel"
    lede="Department training schedule, signup, and completion."
    :context="
      workspace === null
        ? ''
        : `${viewerSignupCount} signed up / ${viewerCompletedCount} completed`
    "
  >
    <!--
      A refusal is the node's own sentence, and an unreachable node is stated
      rather than shown as a department with no trainings in it (data/API 7.2).
    -->
    <p v-if="loadError" class="trainings-page__error" role="alert">
      {{ loadError }}
    </p>

    <TrainingListSection
      v-else
      variant="page"
      :workspace="workspace"
      @reload="loadWorkspace"
    />
  </StaffPageShell>

  <DeptOpsShell
    v-else
    heading-id="trainings-heading"
    title="Trainings"
    :eyebrow="departmentLabel"
    :lede="`${roleLabel} training schedule, signup, and completion workspace.`"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #actions>
      <WorkflowActionButton
        :to="{ name: 'events.departments.trainings.create', params: routeParams }"
      >
        New training
      </WorkflowActionButton>
    </template>

    <template #heading-cards>
      <WorkflowHeadingCardGrid>
        <WorkflowHeadingCard
          label="Trainings"
          :value="String(trainings.length)"
        />
        <WorkflowHeadingCard
          label="Scheduled sessions"
          :value="String(scheduledCount)"
        />
        <WorkflowHeadingCard
          label="Your signups"
          :value="String(viewerSignupCount)"
        />
        <WorkflowHeadingCard
          label="Your completions"
          :value="String(viewerCompletedCount)"
        />
      </WorkflowHeadingCardGrid>
    </template>

    <TrainingListSection
      variant="page"
      :workspace="workspace"
      @reload="loadWorkspace"
    />
  </DeptOpsShell>
</template>

<style scoped>
.trainings-page__error {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-status-danger, #cc792f);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-status-danger, #cc792f);
}
</style>
