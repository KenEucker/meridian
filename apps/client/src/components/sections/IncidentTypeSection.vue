<script setup lang="ts">
import { computed, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  archiveIncidentType,
  createIncidentType,
  getOrganizationIncidentTypes,
  renameIncidentType,
  restoreIncidentType,
  type OrganizationIncidentType,
} from "@/ims/incidentTypeAdminModel";

/**
 * The organization's incident type list (M18.14A; ORG-018, ORG-020).
 *
 * One featureset on the organization configuration page. Incident types have
 * been per-organization configuration since M11.7A, and with nowhere to
 * configure them the incident form created one whenever somebody typed a name
 * it did not recognize — which is how an organization's vocabulary came to be
 * whatever had been entered into an incident. Now the form chooses from this
 * list and this is where the list is maintained.
 *
 * Archived types are shown rather than hidden, which is the opposite of every
 * other list in this client. Restoring one is half of why a maintainer is here,
 * and a row nobody can see is a row nobody can restore.
 */
const props = withDefaults(
  defineProps<{
    organizationId: string | null;
    variant?: "page" | "section";
  }>(),
  { variant: "section" },
);

const types = ref<readonly OrganizationIncidentType[]>([]);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const busyId = ref<string | null>(null);
const creating = ref(false);
const newName = ref("");
const renamingId = ref<string | null>(null);
const renameDraft = ref("");

const activeTypes = computed(() => types.value.filter((type) => !type.archived));
const archivedTypes = computed(() => types.value.filter((type) => type.archived));

watch(() => props.organizationId, () => void loadTypes(), { immediate: true });

/**
 * Read the list the node holds.
 *
 * Re-read after every write rather than patched in place: a rename moves a row
 * in the node's sort, and an archive changes what the incident form will offer.
 */
async function loadTypes(): Promise<void> {
  if (props.organizationId === null) {
    types.value = [];

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    const list = await getOrganizationIncidentTypes(props.organizationId);

    types.value = list.incidentTypes;
  } catch (error) {
    types.value = [];
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load incident types. Check the connection to this node and try again.",
    );
  } finally {
    loading.value = false;
  }
}

async function runCommand(
  key: string,
  command: () => Promise<void>,
  fallback: string,
): Promise<void> {
  actionError.value = null;
  busyId.value = key;

  try {
    await command();
    await loadTypes();
  } catch (error) {
    actionError.value = meridianErrorMessage(error, fallback);
  } finally {
    busyId.value = null;
  }
}

async function onCreate(): Promise<void> {
  const organizationId = props.organizationId;
  const name = newName.value.trim();

  if (organizationId === null || name === "") {
    return;
  }

  creating.value = true;

  await runCommand(
    "create",
    async () => {
      await createIncidentType(organizationId, name);
      newName.value = "";
    },
    "Unable to add this incident type.",
  );

  creating.value = false;
}

function startRename(type: OrganizationIncidentType): void {
  renamingId.value = type.id;
  renameDraft.value = type.name;
  actionError.value = null;
}

function cancelRename(): void {
  renamingId.value = null;
  renameDraft.value = "";
}

async function onRename(type: OrganizationIncidentType): Promise<void> {
  const name = renameDraft.value.trim();

  if (name === "" || name === type.name) {
    cancelRename();

    return;
  }

  await runCommand(
    type.id,
    async () => {
      await renameIncidentType(type.id, name);
      cancelRename();
    },
    "Unable to rename this incident type.",
  );
}

async function onArchive(type: OrganizationIncidentType): Promise<void> {
  await runCommand(
    type.id,
    () => archiveIncidentType(type.id),
    "Unable to archive this incident type.",
  );
}

async function onRestore(type: OrganizationIncidentType): Promise<void> {
  await runCommand(
    type.id,
    () => restoreIncidentType(type.id),
    "Unable to restore this incident type.",
  );
}

/** How many incidents a type is on, said in words rather than a bare number. */
function usageText(type: OrganizationIncidentType): string {
  if (type.incidentCount === 0) {
    return "Not used yet";
  }

  return type.incidentCount === 1
    ? "On 1 incident"
    : `On ${type.incidentCount} incidents`;
}
</script>

<template>
  <WorkflowSection
    class="incident-types"
    title="Incident types"
    heading-id="incident-types-heading"
    description="What Incident Command may choose from when filing an incident."
    :variant="props.variant"
  >
    <ControlBar label="Add an incident type">
      <form
        data-control-group="grow"
        aria-label="Add an incident type"
        @submit.prevent="onCreate"
      >
        <ControlField
          label="New incident type"
          control-id="incident-type-name"
          width="grow"
        >
          <input
            id="incident-type-name"
            v-model="newName"
            type="text"
            maxlength="100"
            autocomplete="off"
          />
        </ControlField>
        <button type="submit" :disabled="creating || newName.trim() === ''">
          Add type
        </button>
      </form>
    </ControlBar>

    <p v-if="loadError" class="incident-types__error" role="alert">
      {{ loadError }}
    </p>
    <p v-if="actionError" class="incident-types__error" role="alert">
      {{ actionError }}
    </p>

    <p
      v-if="loading && types.length === 0"
      class="incident-types__empty"
      role="status"
    >
      Loading incident types.
    </p>

    <template v-else>
      <!--
        A failed read must not also claim the organization configured nothing.
        The two states look identical on screen and are opposite problems: one
        is an organizer's to fix here, the other is a node that could not be
        reached.
      -->
      <p v-if="loadError" class="incident-types__empty">
        This organization's incident types could not be read.
      </p>

      <p v-else-if="activeTypes.length === 0" class="incident-types__empty">
        This organization has no incident types. Incident Command cannot
        categorize an incident until one is added.
      </p>

      <ul v-else class="incident-types__list">
        <li v-for="type in activeTypes" :key="type.id">
          <form
            v-if="renamingId === type.id"
            class="incident-types__rename"
            :aria-label="`Rename ${type.name}`"
            @submit.prevent="onRename(type)"
          >
            <input
              v-model="renameDraft"
              type="text"
              maxlength="100"
              autocomplete="off"
              :aria-label="`New name for ${type.name}`"
            />
            <button type="submit" :disabled="busyId === type.id">Save</button>
            <button type="button" @click="cancelRename">Cancel</button>
          </form>

          <template v-else>
            <span class="incident-types__name">{{ type.name }}</span>
            <span class="incident-types__usage">{{ usageText(type) }}</span>
            <button
              type="button"
              :disabled="busyId === type.id"
              @click="startRename(type)"
            >
              Rename
            </button>
            <button
              type="button"
              :disabled="busyId === type.id"
              :aria-label="`Archive ${type.name}`"
              @click="onArchive(type)"
            >
              Archive
            </button>
          </template>
        </li>
      </ul>

      <!--
        Archiving retires a type without touching the incidents that carry it,
        so this is the record of what the organization used to categorize by,
        and the only place a retired type can be brought back.
      -->
      <div v-if="archivedTypes.length > 0" class="incident-types__archived">
        <h3>Archived</h3>
        <p class="incident-types__note">
          Archived types stay on the incidents that already carry them and are no
          longer offered when an incident is filed.
        </p>

        <ul class="incident-types__list">
          <li v-for="type in archivedTypes" :key="type.id">
            <span class="incident-types__name">{{ type.name }}</span>
            <span class="incident-types__usage">{{ usageText(type) }}</span>
            <button
              type="button"
              :disabled="busyId === type.id"
              :aria-label="`Restore ${type.name}`"
              @click="onRestore(type)"
            >
              Restore
            </button>
          </li>
        </ul>
      </div>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.incident-types__archived h3 {
  margin: var(--m-space-4) 0 var(--m-space-2);
  font-size: var(--m-text-sm);
  text-transform: uppercase;
  color: var(--m-text-secondary);
}

.incident-types__list {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.incident-types__list li {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  align-items: center;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.incident-types__name {
  flex: 1 1 12rem;
  font-weight: 700;
}

.incident-types__usage {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.incident-types__rename {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
  width: 100%;
}

.incident-types__rename input {
  flex: 1 1 12rem;
}

.incident-types__empty,
.incident-types__note {
  margin: 0;
  color: var(--m-text-muted);
}

.incident-types__error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}
</style>
