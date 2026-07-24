<script setup lang="ts">
import { computed, ref } from "vue";
import { RouterLink, useRoute } from "vue-router";

import DeptOpsShell from "@/components/department-ops/DeptOpsShell.vue";
import WorkflowActionButton from "@/components/WorkflowActionButton.vue";
import WorkflowHeadingCard from "@/components/WorkflowHeadingCard.vue";
import WorkflowHeadingCardGrid from "@/components/WorkflowHeadingCardGrid.vue";
import TrainingListSection from "@/components/sections/TrainingListSection.vue";
import {
  canAccessTrainings,
  canManageTrainings,
  resolveTrainingSession,
  viewerStatus,
  visibleTrainings,
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
const trainings = computed(() => {
  void refreshKey.value;
  return visibleTrainings(session.value);
});
const canAccess = computed(() => canAccessTrainings(session.value));
const canManage = computed(() => canManageTrainings(session.value));

const routeParams = computed(() => ({
  eventId: session.value.eventId,
  departmentId: session.value.department.departmentId,
}));

const scheduledCount = computed(
  () =>
    trainings.value.filter((training) => training.scheduledStartAt !== null)
      .length,
);
const viewerSignupCount = computed(
  () =>
    trainings.value.filter(
      (training) => viewerStatus(session.value, training).signedUp,
    ).length,
);
const viewerCompletedCount = computed(
  () =>
    trainings.value.filter(
      (training) => viewerStatus(session.value, training).completed,
    ).length,
);
</script>

<template>
  <DeptOpsShell
    heading-id="trainings-heading"
    title="Trainings"
    :eyebrow="session.department.departmentLabel"
    :lede="`${session.department.roleLabel} training schedule, signup, and completion workspace.`"
  >
    <template #nav>
      <RouterLink :to="{ name: 'home' }">Back To Home</RouterLink>
    </template>

    <template #actions>
      <WorkflowActionButton
        v-if="canManage"
        :to="{ name: 'events.departments.trainings.create', params: routeParams }"
      >
        New training
      </WorkflowActionButton>
    </template>

    <template v-if="canAccess" #heading-cards>
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

    <TrainingListSection variant="page" />
  </DeptOpsShell>
</template>
