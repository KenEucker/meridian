<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  designateStaffCoordinatorTeam,
  getOrganizationDesignations,
  removeStaffCoordinatorTeam,
  type OrganizationDesignations,
} from "@/organizer-designations/organizationDesignationModel";

/**
 * The organization's team designations (M18.12; TEAM-014, TEAM-016).
 *
 * One featureset on the organization configuration page, carrying the one
 * organization-level designation that exists: which team within the configured
 * Organizers Department holds Staff Coordinator authority. Members of the
 * designated team review, approve, reject, and defer the organization's
 * applications (requirements 4.4) and nothing else — which is why choosing the
 * team is organizer work and sits here rather than on a department surface.
 *
 * The eligible team list is the node's answer, not a filter applied here: only
 * teams of the configured Organizers Department may carry the designation, and
 * offering anything else would be offering a refusal.
 */
const props = withDefaults(
  defineProps<{
    organizationId: string | null;
    variant?: "page" | "section";
  }>(),
  { variant: "section" },
);

const designations = ref<OrganizationDesignations | null>(null);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const busy = ref(false);
const selectedTeamId = ref("");

const staffCoordinator = computed(
  () => designations.value?.staffCoordinator ?? null,
);
const eligibleTeams = computed(() => designations.value?.eligibleTeams ?? []);
const organizersDepartment = computed(
  () => designations.value?.organizersDepartment ?? null,
);
/*
 * The already-designated team is not offered again: designating it once more
 * is the one selection the node would refuse.
 */
const selectableTeams = computed(() =>
  eligibleTeams.value.filter(
    (team) => team.id !== staffCoordinator.value?.teamId,
  ),
);

watch(() => props.organizationId, () => void loadDesignations(), {
  immediate: true,
});

async function loadDesignations(): Promise<void> {
  if (props.organizationId === null) {
    designations.value = null;

    return;
  }

  loadError.value = null;

  try {
    designations.value = await getOrganizationDesignations(props.organizationId);
  } catch (error) {
    designations.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load team designations. Check the connection to this node and try again.",
    );
  }
}

async function runCommand(
  command: () => Promise<OrganizationDesignations>,
  fallback: string,
): Promise<void> {
  actionError.value = null;
  busy.value = true;

  try {
    designations.value = await command();
    selectedTeamId.value = "";
  } catch (error) {
    actionError.value = meridianErrorMessage(error, fallback);
  } finally {
    busy.value = false;
  }
}

async function onDesignate(): Promise<void> {
  const organizationId = props.organizationId;
  const teamId = selectedTeamId.value;

  if (organizationId === null || teamId === "") {
    return;
  }

  await runCommand(
    () => designateStaffCoordinatorTeam(organizationId, teamId),
    "Unable to designate the Staff Coordinator team.",
  );
}

async function onRemove(): Promise<void> {
  const organizationId = props.organizationId;

  if (organizationId === null) {
    return;
  }

  await runCommand(
    () => removeStaffCoordinatorTeam(organizationId),
    "Unable to remove the Staff Coordinator designation.",
  );
}
</script>

<template>
  <WorkflowSection
    class="org-designations"
    title="Team designations"
    heading-id="org-designations-heading"
    description="Which team within the Organizers Department carries Staff Coordinator authority: reviewing, approving, rejecting, and deferring this organization's applications."
    :variant="props.variant"
  >
    <p v-if="loadError" class="org-designations__error" role="alert">
      {{ loadError }}
    </p>

    <p
      v-else-if="designations === null"
      class="org-designations__empty"
      role="status"
    >
      Loading team designations.
    </p>

    <template v-else>
      <p v-if="organizersDepartment === null" class="org-designations__empty">
        This organization has no configured Organizers Department, so no team
        can carry the Staff Coordinator designation yet.
      </p>

      <template v-else>
        <div class="org-designations__current">
          <span class="org-designations__function">Staff Coordinator</span>
          <span v-if="staffCoordinator" class="org-designations__team">
            {{ staffCoordinator.teamName ?? "Designated team" }}
          </span>
          <span v-else class="org-designations__team org-designations__team--none">
            No team designated
          </span>
          <button
            v-if="staffCoordinator"
            type="button"
            :disabled="busy"
            aria-label="Remove the Staff Coordinator designation"
            @click="onRemove"
          >
            Remove
          </button>
        </div>

        <ControlBar label="Designate the Staff Coordinator team">
          <form
            data-control-group="grow"
            aria-label="Designate the Staff Coordinator team"
            @submit.prevent="onDesignate"
          >
            <ControlField
              :label="`Team in ${organizersDepartment.name}`"
              control-id="staff-coordinator-team"
              width="grow"
            >
              <select id="staff-coordinator-team" v-model="selectedTeamId">
                <option value="" disabled>Select a team</option>
                <option
                  v-for="team in selectableTeams"
                  :key="team.id"
                  :value="team.id"
                >
                  {{ team.name }}
                </option>
              </select>
            </ControlField>
            <button type="submit" :disabled="busy || selectedTeamId === ''">
              {{ staffCoordinator ? "Change team" : "Designate team" }}
            </button>
          </form>
        </ControlBar>

        <p v-if="actionError" class="org-designations__error" role="alert">
          {{ actionError }}
        </p>

        <p class="org-designations__note">
          Members of the designated team hold the Staff Coordinator role for
          this organization, and their permission explanations name this
          designation.
        </p>
      </template>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.org-designations__current {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  align-items: center;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.org-designations__function {
  font-weight: 700;
}

.org-designations__team {
  flex: 1 1 10rem;
}

.org-designations__team--none {
  color: var(--m-text-muted);
}

.org-designations__empty,
.org-designations__note {
  margin: 0;
  color: var(--m-text-muted);
}

.org-designations__error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}
</style>
