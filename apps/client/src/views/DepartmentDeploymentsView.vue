<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { useRoute } from "vue-router";

import { meridianErrorMessage } from "@/api/meridianApi";
import { connectionRequiredMessage } from "@/offline/connectionRequired";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  archiveDeployment,
  createDeployment,
  getDepartmentDeployments,
  restoreDeployment,
  updateDeployment,
  type DepartmentDeployments,
  type DeploymentOption,
} from "@/deployments/deploymentAdminModel";
import { selectedSessionDepartment } from "@/session/sessionAccess";

/**
 * `department.deployments` — maintaining the department's deployment options
 * (M18.30; UI contract 12.4; SLB-009, SLB-010).
 *
 * The Operations Center has been able to move staff between deployments since
 * M16.21, and until now nothing could put a deployment in the list to move them
 * between. This is that page: the places a department stands people, named,
 * described, and located well enough that the answer to "where is Gate 1" is on
 * the screen rather than in somebody's head.
 *
 * **Archived rather than deleted, and archived options stay on the page.** A
 * current assignment points at the row, and a location a department stopped
 * using is still where somebody was standing. Restoring last year's post before
 * an event is half of why anybody opens this page early.
 *
 * **A deployment holding people cannot be archived.** The node refuses it and
 * says how many are there; the page prints the count beside every row so the
 * refusal is not a surprise. Moving them is the Operations Center's job, and
 * this page does not duplicate it.
 */
const route = useRoute();
const eventId = computed(() => String(route.params.eventId ?? ""));
const departmentId = computed(() => String(route.params.departmentId ?? ""));

const page = ref<DepartmentDeployments | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const writeError = ref<string | null>(null);
const saving = ref(false);

/** The row being edited, or null while the form is creating a new one. */
const editingId = ref<string | null>(null);

const draft = reactive({
  name: "",
  description: "",
  locationDetails: "",
});

const eyebrow = computed(
  () =>
    page.value?.departmentLabel ||
    selectedSessionDepartment.value?.departmentLabel ||
    "Department",
);

const lede = computed(() =>
  page.value === null
    ? ""
    : `The places this department deploys to at ${page.value.eventLabel}.`,
);

const active = computed(() =>
  (page.value?.deployments ?? []).filter((row) => row.archivedAt === null),
);

const archived = computed(() =>
  (page.value?.deployments ?? []).filter((row) => row.archivedAt !== null),
);

function resetDraft(): void {
  editingId.value = null;
  draft.name = "";
  draft.description = "";
  draft.locationDetails = "";
}

function edit(deployment: DeploymentOption): void {
  editingId.value = deployment.id;
  draft.name = deployment.name;
  draft.description = deployment.description ?? "";
  draft.locationDetails = deployment.locationDetails ?? "";
  writeError.value = null;
}

async function load(): Promise<void> {
  if (eventId.value === "" || departmentId.value === "") {
    page.value = null;

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    page.value = await getDepartmentDeployments(eventId.value, departmentId.value);
  } catch (error) {
    page.value = null;
    loadError.value = meridianErrorMessage(
      error,
      connectionRequiredMessage(
        "The department's deployments",
        "a deployment reports who is standing at it now, which is a live count rather than a stored one",
      ),
    );
  } finally {
    loading.value = false;
  }
}

/**
 * Every write re-reads. The assigned counts and the refusals behind them are
 * the node's, and a page that patched its own list would be answering from a
 * copy that stopped being true the moment somebody was moved.
 */
async function run(write: () => Promise<void>): Promise<void> {
  saving.value = true;
  writeError.value = null;

  try {
    await write();
    await load();
    resetDraft();
  } catch (error) {
    writeError.value = meridianErrorMessage(error, "That change was not saved.");
  } finally {
    saving.value = false;
  }
}

async function submit(): Promise<void> {
  const id = editingId.value;

  await run(() =>
    id === null
      ? createDeployment(eventId.value, departmentId.value, { ...draft })
      : updateDeployment(id, { ...draft }),
  );
}

async function archive(deployment: DeploymentOption): Promise<void> {
  await run(() => archiveDeployment(deployment.id));
}

async function restore(deployment: DeploymentOption): Promise<void> {
  await run(() => restoreDeployment(deployment.id));
}

function assignedLabel(deployment: DeploymentOption): string {
  if (deployment.assignedStaffCount === 0) {
    return "Nobody here now";
  }

  return deployment.assignedStaffCount === 1
    ? "1 staff member here now"
    : `${deployment.assignedStaffCount} staff members here now`;
}

watch(
  [eventId, departmentId],
  () => {
    resetDraft();
    void load();
  },
  { immediate: true },
);
</script>

<template>
  <WorkflowPageShell
    heading-id="department-deployments-heading"
    title="Deployments"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <p v-if="loadError" class="deployments__notice" role="alert">
      {{ loadError }}
      <button type="button" @click="load()">Try again</button>
    </p>

    <p v-else-if="loading && page === null" class="deployments__notice" role="status">
      Reading the department's deployments…
    </p>

    <template v-else-if="page">
      <form class="deployments__form" @submit.prevent="submit()">
        <h2 class="deployments__subheading">
          {{ editingId === null ? "Add a deployment" : "Edit deployment" }}
        </h2>

        <label class="deployments__field">
          Name
          <input v-model="draft.name" type="text" required maxlength="100" />
        </label>

        <label class="deployments__field">
          Description
          <textarea v-model="draft.description" rows="2"></textarea>
        </label>

        <label class="deployments__field">
          Where it is
          <textarea v-model="draft.locationDetails" rows="2"></textarea>
        </label>

        <p v-if="writeError" class="deployments__notice" role="alert">
          {{ writeError }}
        </p>

        <div class="deployments__actions">
          <button type="submit" :disabled="saving || draft.name.trim() === ''">
            {{ editingId === null ? "Add deployment" : "Save changes" }}
          </button>
          <button v-if="editingId !== null" type="button" @click="resetDraft()">
            Cancel
          </button>
        </div>
      </form>

      <section aria-labelledby="deployments-active-heading">
        <h2 id="deployments-active-heading" class="deployments__subheading">
          In use
        </h2>

        <p v-if="active.length === 0" class="deployments__notice" role="status">
          This department has no deployments at this event yet. The Operations
          Center has nowhere to stand anybody until one is added here.
        </p>

        <ul v-else class="deployments__list">
          <li v-for="deployment in active" :key="deployment.id" class="deployments__row">
            <p class="deployments__name">{{ deployment.name }}</p>
            <p v-if="deployment.description" class="deployments__detail">
              {{ deployment.description }}
            </p>
            <p v-if="deployment.locationDetails" class="deployments__detail">
              {{ deployment.locationDetails }}
            </p>
            <p class="deployments__count">{{ assignedLabel(deployment) }}</p>
            <div class="deployments__actions">
              <button type="button" :disabled="saving" @click="edit(deployment)">
                Edit
              </button>
              <!--
                Offered even when somebody is standing there. The node refuses
                it and says how many have to be moved first, which is more use
                than a disabled control that explains nothing (CLIENT-006).
              -->
              <button type="button" :disabled="saving" @click="archive(deployment)">
                Archive
              </button>
            </div>
          </li>
        </ul>
      </section>

      <section v-if="archived.length > 0" aria-labelledby="deployments-archived-heading">
        <h2 id="deployments-archived-heading" class="deployments__subheading">
          Archived
        </h2>

        <ul class="deployments__list">
          <li
            v-for="deployment in archived"
            :key="deployment.id"
            class="deployments__row deployments__row--archived"
          >
            <p class="deployments__name">{{ deployment.name }}</p>
            <p v-if="deployment.description" class="deployments__detail">
              {{ deployment.description }}
            </p>
            <div class="deployments__actions">
              <button type="button" :disabled="saving" @click="restore(deployment)">
                Restore
              </button>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.deployments__notice {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.deployments__subheading {
  margin: 0 0 var(--m-space-2);
  font-size: 1.05rem;
}

.deployments__form {
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.deployments__field {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  font-size: 0.9rem;
}

.deployments__field input,
.deployments__field textarea {
  padding: 0.4rem;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
}

.deployments__list {
  margin: 0;
  padding: 0;
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--m-space-2);
}

.deployments__row {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.deployments__row--archived {
  opacity: 0.75;
}

.deployments__name {
  margin: 0;
  font-weight: 600;
}

.deployments__detail,
.deployments__count {
  margin: 0;
  font-size: 0.9rem;
}

.deployments__actions {
  display: flex;
  gap: var(--m-space-2);
  flex-wrap: wrap;
  margin-top: var(--m-space-2);
}
</style>
