<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { BRANDING_SLOTS } from "@/branding/brandingAdminModel";
import BrandingLogoField from "@/branding/BrandingLogoField.vue";
import { reloadSessionBranding } from "@/branding/brandingContext";
import { findDepartmentBranding } from "@/branding/brandingProfile";
import { meridianErrorMessage, MeridianApiError } from "@/api/meridianApi";
import {
  archiveOrganizerDepartment,
  createOrganizerDepartment,
  getOrganizerDepartment,
  restoreOrganizerDepartment,
  updateOrganizerDepartment,
  type OrganizerDepartment,
} from "@/organizer-departments/departmentAdminModel";
import { organizerDepartmentAdminSession } from "@/session/organizerAdminSession";

const route = useRoute();
const router = useRouter();
const session = organizerDepartmentAdminSession;
const canManage = computed(() => session.value !== null);

const departmentId = computed(() =>
  typeof route.params.departmentId === "string" ? route.params.departmentId : "",
);
const isCreate = computed(() => route.name === "organizer.departments.create");

const existing = ref<OrganizerDepartment | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const notFound = ref(false);

const draft = reactive({
  name: "",
  code: "",
  description: "",
});
const formError = ref<string | null>(null);
const busy = ref(false);

function fillDraft(department: OrganizerDepartment | null): void {
  draft.name = department?.name ?? "";
  draft.code = department?.code ?? "";
  draft.description = department?.description ?? "";
}

/**
 * Read the department this screen is editing.
 *
 * The form is filled from the response rather than from a row carried over
 * from the list, so an organizer who opens a department someone else has since
 * renamed edits the current values and not the ones their list was showing.
 *
 * A 404 is kept apart from every other failure. The server answers it both for
 * a department that does not exist and for one belonging to another
 * organization, and either way the screen has nothing to edit; anything else is
 * a request that may well succeed on a retry, and saying "not found" to a
 * dropped connection would be a lie.
 */
async function loadDepartment(): Promise<void> {
  const current = session.value;

  existing.value = null;
  notFound.value = false;
  loadError.value = null;

  if (current === null || isCreate.value) {
    fillDraft(null);

    return;
  }

  loading.value = true;

  try {
    const department = await getOrganizerDepartment(
      current.organizationId,
      departmentId.value,
    );

    existing.value = department;
    fillDraft(department);
  } catch (error) {
    fillDraft(null);

    if (error instanceof MeridianApiError && error.status === 404) {
      notFound.value = true;
    } else {
      loadError.value = meridianErrorMessage(
        error,
        "Unable to load this department. Check the connection to this node and try again.",
      );
    }
  } finally {
    loading.value = false;
  }
}

watch(
  [() => session.value?.organizationId ?? null, departmentId, isCreate],
  () => {
    formError.value = null;
    void loadDepartment();
  },
);

void loadDepartment();

const heading = computed(() =>
  isCreate.value ? "Create department" : "Edit department",
);

/**
 * The department's logo, reachable from department details as well as from the
 * department's own Branding surface (BRAND-010, BRAND-019).
 *
 * Absent while creating: the logo is an attachment against a department id,
 * and there is no department to attach it to until the first save. The same
 * reason the Orchid screen defers its branding block.
 */
const branding = computed(() =>
  isCreate.value ? null : findDepartmentBranding(departmentId.value),
);
const logoUrl = ref<string | null>(null);

watch(
  branding,
  (current) => {
    logoUrl.value = current?.logo_url ?? null;
  },
  { immediate: true },
);

async function onLogoChanged(url: string | null): Promise<void> {
  logoUrl.value = url;
  await reloadSessionBranding();
}

async function onSubmit(): Promise<void> {
  const current = session.value;

  if (current === null) {
    return;
  }

  formError.value = null;
  busy.value = true;

  try {
    if (isCreate.value) {
      const created = await createOrganizerDepartment(current.organizationId, {
        ...draft,
      });

      await router.push({
        name: "organizer.departments.edit",
        params: { departmentId: created.id },
      });

      return;
    }

    const updated = await updateOrganizerDepartment(departmentId.value, {
      ...draft,
    });

    existing.value = updated;
    fillDraft(updated);
  } catch (error) {
    formError.value = meridianErrorMessage(error, "Unable to save department.");
  } finally {
    busy.value = false;
  }
}

async function onArchive(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    existing.value = await archiveOrganizerDepartment(departmentId.value);
  } catch (error) {
    formError.value = meridianErrorMessage(
      error,
      "Unable to archive department.",
    );
  } finally {
    busy.value = false;
  }
}

async function onRestore(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    existing.value = await restoreOrganizerDepartment(departmentId.value);
  } catch (error) {
    formError.value = meridianErrorMessage(
      error,
      "Unable to restore department.",
    );
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

    <p v-else-if="loading" class="org-dept-edit__restricted" role="status">
      Loading department…
    </p>

    <p v-else-if="notFound" class="org-dept-edit__restricted" role="status">
      Department not found for this organization.
    </p>

    <p v-else-if="loadError" class="org-dept-edit__error" role="alert">
      {{ loadError }}
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

      <!--
        The logo saves on upload through the branding asset command, not with
        this form, so "Save changes" is never holding an unsaved logo.
      -->
      <BrandingLogoField
        v-if="!isCreate"
        label="Department logo"
        description="Shown in the application header beside the department name, on department badges, and on department-scoped surfaces. Saved as soon as you choose a file."
        :slot="BRANDING_SLOTS.departmentLogo"
        :department-id="departmentId"
        :url="logoUrl"
        :lettermark="branding?.lettermark ?? draft.code.slice(0, 2).toUpperCase()"
        @changed="onLogoChanged"
      />
      <p v-else class="org-dept-edit__meta">
        A department logo can be uploaded once the department has been created.
      </p>

      <p v-if="existing" class="org-dept-edit__meta">
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
