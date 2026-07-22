<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import {
  archiveOrganizerDepartment,
  canManageOrganizerDepartments,
  createOrganizerDepartment,
  getOrganizerDepartment,
  resolveOrganizerDepartmentSession,
  restoreOrganizerDepartment,
  updateOrganizerDepartment,
} from "@/organizer-departments/departmentAdminModel";

const route = useRoute();
const router = useRouter();
const session = computed(() => resolveOrganizerDepartmentSession());
const canManage = computed(() => canManageOrganizerDepartments(session.value));

const departmentId = computed(() =>
  typeof route.params.departmentId === "string" ? route.params.departmentId : "",
);
const isCreate = computed(() => route.name === "organizer.departments.create");

const existing = computed(() =>
  isCreate.value
    ? null
    : getOrganizerDepartment(session.value, departmentId.value),
);

const draft = reactive({
  name: "",
  code: "",
  description: "",
});
const formError = ref<string | null>(null);
const busy = ref(false);

watch(
  existing,
  (department) => {
    if (department === null) {
      draft.name = "";
      draft.code = "";
      draft.description = "";
      return;
    }

    draft.name = department.name;
    draft.code = department.code;
    draft.description = department.description ?? "";
  },
  { immediate: true },
);

const heading = computed(() =>
  isCreate.value ? "Create department" : "Edit department",
);

async function onSubmit(): Promise<void> {
  if (!canManage.value) {
    return;
  }

  formError.value = null;
  busy.value = true;

  try {
    if (isCreate.value) {
      const created = createOrganizerDepartment(session.value, { ...draft });
      await router.push({
        name: "organizer.departments.edit",
        params: { departmentId: created.id },
      });
      return;
    }

    updateOrganizerDepartment(session.value, departmentId.value, { ...draft });
  } catch (error) {
    formError.value =
      error instanceof Error ? error.message : "Unable to save department.";
  } finally {
    busy.value = false;
  }
}

async function onArchive(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    archiveOrganizerDepartment(session.value, departmentId.value);
  } catch (error) {
    formError.value =
      error instanceof Error ? error.message : "Unable to archive department.";
  } finally {
    busy.value = false;
  }
}

async function onRestore(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    restoreOrganizerDepartment(session.value, departmentId.value);
  } catch (error) {
    formError.value =
      error instanceof Error ? error.message : "Unable to restore department.";
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="org-dept-edit" aria-labelledby="org-dept-edit-heading">
    <p class="org-dept-edit__eyebrow">Organizer administration</p>
    <h1 id="org-dept-edit-heading" class="org-dept-edit__heading">
      {{ heading }}
    </h1>
    <p v-if="session" class="org-dept-edit__lede">
      {{ session.organizationLabel }} / {{ session.roleLabel }}
    </p>

    <p v-if="!canManage" class="org-dept-edit__restricted" role="status">
      Department administration requires organizer or lead organizer authority
      for this organization.
    </p>

    <p
      v-else-if="!isCreate && existing === null"
      class="org-dept-edit__restricted"
      role="status"
    >
      Department not found for this organization.
    </p>

    <form v-else class="org-dept-edit__form" @submit.prevent="onSubmit">
      <label class="org-dept-edit__field">
        Name
        <input v-model="draft.name" type="text" required maxlength="255" />
      </label>
      <label class="org-dept-edit__field">
        Code
        <input
          v-model="draft.code"
          type="text"
          required
          maxlength="64"
          pattern="[A-Za-z0-9_-]+"
          title="Letters, numbers, dashes, and underscores only"
        />
      </label>
      <label class="org-dept-edit__field">
        Description
        <textarea v-model="draft.description" rows="4" />
      </label>

      <p v-if="existing?.defaultTeamId" class="org-dept-edit__meta">
        Default team is created automatically when the department is created.
        Team administration remains with later department self-administration
        work.
      </p>

      <p v-if="formError" class="org-dept-edit__error" role="alert">
        {{ formError }}
      </p>

      <div class="org-dept-edit__actions">
        <button type="submit" :disabled="busy">
          {{ isCreate ? "Create department" : "Save changes" }}
        </button>
        <button
          v-if="existing && existing.archivedAt === null"
          type="button"
          class="org-dept-edit__archive"
          :disabled="busy"
          @click="onArchive"
        >
          Archive
        </button>
        <button
          v-else-if="existing && existing.archivedAt !== null"
          type="button"
          :disabled="busy"
          @click="onRestore"
        >
          Restore
        </button>
        <RouterLink :to="{ name: 'organizer.departments.index' }"
          >Back to list</RouterLink
        >
      </div>
    </form>
  </section>
</template>

<style scoped>
.org-dept-edit {
  width: min(100%, 36rem);
  display: grid;
  gap: var(--m-space-3);
}

.org-dept-edit__eyebrow {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.org-dept-edit__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.org-dept-edit__lede,
.org-dept-edit__meta {
  margin: 0;
  color: var(--m-text-muted);
}

.org-dept-edit__restricted,
.org-dept-edit__error {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.org-dept-edit__error {
  border-color: color-mix(in srgb, var(--m-status-danger, #cc792f) 40%, var(--m-border-default));
  color: var(--m-status-danger, #cc792f);
}

.org-dept-edit__form {
  display: grid;
  gap: var(--m-space-4);
}

.org-dept-edit__field {
  display: grid;
  gap: var(--m-space-1);
  font-weight: 600;
}

.org-dept-edit__field input,
.org-dept-edit__field textarea {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
}

.org-dept-edit__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
}

.org-dept-edit__actions button,
.org-dept-edit__actions a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  line-height: 1;
  text-decoration: none;
  cursor: pointer;
}

.org-dept-edit__actions button[type="submit"] {
  border-color: transparent;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.org-dept-edit__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.org-dept-edit__actions .org-dept-edit__archive {
  border-color: var(--m-action-destructive-bg);
  background: var(--m-action-destructive-bg);
  color: var(--m-action-destructive-text);
}
</style>
