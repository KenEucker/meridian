<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { BRANDING_SLOTS } from "@/branding/brandingAdminModel";
import BrandingLogoField from "@/branding/BrandingLogoField.vue";
import { reloadSessionBranding } from "@/branding/brandingContext";
import { findTeamBranding } from "@/branding/brandingProfile";
import { meridianErrorMessage } from "@/api/meridianApi";
import {
  archiveDepartmentTeam,
  createDepartmentTeam,
  getDepartmentTeam,
  restoreDepartmentTeam,
  suggestTeamCodeFromName,
  updateDepartmentTeam,
  type DepartmentTeamDetail,
} from "@/department-teams/teamAdminModel";

const route = useRoute();
const router = useRouter();

const departmentId = computed(() => String(route.params.departmentId ?? ""));
const teamId = computed(() =>
  typeof route.params.teamId === "string" ? route.params.teamId : "",
);
const isCreate = computed(() => route.name === "events.departments.teams.create");

/**
 * The team as the node holds it, with the node's answer about this caller
 * (M16.15).
 *
 * A create has nothing to read, so the form opens empty and the command decides
 * whether this caller may add a team to this department. On an edit the read
 * answers first, and its refusal — not this client's own guess at a role — is
 * what the page shows instead of the form.
 */
const detail = ref<DepartmentTeamDetail | null>(null);
const loadError = ref<string | null>(null);
const existing = computed(() => detail.value?.team ?? null);
const canAdminister = computed(
  () => isCreate.value || (detail.value?.access.canAdminister ?? false),
);

const draft = reactive({
  name: "",
  code: "",
  description: "",
});
const codeTouched = ref(false);
const formError = ref<string | null>(null);
const busy = ref(false);

async function loadTeam(): Promise<void> {
  loadError.value = null;

  if (isCreate.value || departmentId.value === "" || teamId.value === "") {
    detail.value = null;

    return;
  }

  try {
    detail.value = await getDepartmentTeam(departmentId.value, teamId.value);
  } catch (error) {
    detail.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this team. Check the connection to this node and try again.",
    );
  }
}

watch([departmentId, teamId, isCreate], () => {
  void loadTeam();
});

void loadTeam();

watch(
  existing,
  (team) => {
    codeTouched.value = false;
    if (team === null) {
      draft.name = "";
      draft.code = "";
      draft.description = "";
      return;
    }

    draft.name = team.name;
    draft.code = team.code;
    draft.description = team.description ?? "";
  },
  { immediate: true },
);

watch(
  () => draft.name,
  (name) => {
    if (!isCreate.value || codeTouched.value) {
      return;
    }

    draft.code = suggestTeamCodeFromName(name);
  },
);

const heading = computed(() => (isCreate.value ? "Create team" : "Edit team"));

/**
 * The team's mark (BRAND-025).
 *
 * A logo and nothing else: accent and surface background belong to the
 * department this team sits in, so a team never re-colors a surface the
 * department already colors.
 *
 * Absent while creating, for the reason the department screens defer theirs —
 * the asset attaches to a team id that does not exist until the first save.
 */
const branding = computed(() =>
  isCreate.value ? null : findTeamBranding(teamId.value),
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

  // Re-resolve so the team overview, rosters, and team pickers holding this
  // mark pick it up without a reload.
  await reloadSessionBranding();
}

const teamsIndexRoute = computed(() => ({
  name: "events.departments.teams.index",
  params: { eventId: route.params.eventId, departmentId: departmentId.value },
}));

async function onSubmit(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    if (isCreate.value) {
      const created = await createDepartmentTeam(departmentId.value, {
        ...draft,
      });
      await router.push({
        name: "events.departments.teams.edit",
        params: { ...route.params, teamId: created.id },
      });
      return;
    }

    await updateDepartmentTeam(teamId.value, { ...draft });
    await loadTeam();
  } catch (error) {
    formError.value = meridianErrorMessage(error, "Unable to save team.");
  } finally {
    busy.value = false;
  }
}

async function onArchive(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    await archiveDepartmentTeam(teamId.value);
    await loadTeam();
  } catch (error) {
    formError.value = meridianErrorMessage(error, "Unable to archive team.");
  } finally {
    busy.value = false;
  }
}

async function onRestore(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    await restoreDepartmentTeam(teamId.value);
    await loadTeam();
  } catch (error) {
    formError.value = meridianErrorMessage(error, "Unable to restore team.");
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <section class="dept-team-edit" aria-labelledby="dept-team-edit-heading">
    <p class="dept-team-edit__eyebrow">Department administration</p>
    <h1 id="dept-team-edit-heading" class="dept-team-edit__heading">
      {{ heading }}
    </h1>
    <!--
      Both refusals this page can meet — no authority over the department, and
      no such team in it — are already sentences the node writes, so they are
      shown as written rather than restated here (CLIENT-006).
    -->
    <p v-if="loadError" class="dept-team-edit__restricted" role="alert">
      {{ loadError }}
    </p>

    <p
      v-else-if="!isCreate && existing === null"
      class="dept-team-edit__restricted"
      role="status"
    >
      Loading team…
    </p>

    <p
      v-else-if="!canAdminister"
      class="dept-team-edit__restricted"
      role="status"
    >
      You lead this team but do not administer this department, so its details
      are not editable here.
    </p>

    <template v-else>
      <p v-if="formError" class="dept-team-edit__error" role="alert">
        {{ formError }}
      </p>

      <form class="dept-team-edit__form" @submit.prevent="onSubmit">
        <label class="dept-team-edit__field">
          Name
          <input v-model="draft.name" type="text" required />
        </label>
        <label class="dept-team-edit__field">
          Code
          <input
            v-model="draft.code"
            type="text"
            required
            @input="codeTouched = true"
          />
        </label>
        <label class="dept-team-edit__field">
          Description
          <textarea v-model="draft.description" rows="4" />
        </label>

        <!--
          The logo saves on upload through the branding asset command rather
          than with this form, so "Save" is never holding an unsaved logo.
        -->
        <BrandingLogoField
          v-if="!isCreate"
          label="Team logo"
          description="Shown wherever this team is named on its own: the team overview, team rosters and pickers, and team shift rows. Saved as soon as you choose a file."
          :slot="BRANDING_SLOTS.teamLogo"
          :team-id="teamId"
          :url="logoUrl"
          :lettermark="branding?.lettermark ?? draft.code.slice(0, 2).toUpperCase()"
          @changed="onLogoChanged"
        />
        <p v-else class="dept-team-edit__hint">
          A team logo can be uploaded once the team has been created.
        </p>

        <p v-if="existing?.isDefault" class="dept-team-edit__hint">
          This is the department default team. It can be renamed but cannot be
          archived.
        </p>

        <div class="dept-team-edit__actions">
          <button type="submit" :disabled="busy">Save</button>
          <RouterLink :to="teamsIndexRoute">Cancel</RouterLink>
          <button
            v-if="existing && !existing.isDefault && existing.archivedAt === null"
            type="button"
            class="dept-team-edit__archive"
            :disabled="busy"
            @click="onArchive"
          >
            Archive
          </button>
          <button
            v-if="existing && !existing.isDefault && existing.archivedAt !== null"
            type="button"
            :disabled="busy"
            @click="onRestore"
          >
            Restore
          </button>
        </div>
      </form>
    </template>
  </section>
</template>

<style scoped>
.dept-team-edit {
  width: min(100%, 36rem);
  display: grid;
  gap: var(--m-space-4);
}

.dept-team-edit__eyebrow {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.dept-team-edit__heading {
  margin: 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.dept-team-edit__lede,
.dept-team-edit__hint {
  margin: 0;
  color: var(--m-text-muted);
}

.dept-team-edit__restricted,
.dept-team-edit__error {
  margin: 0;
  padding: var(--m-space-3);
  border-radius: var(--m-radius-sm);
  border: 1px solid var(--m-border-default);
}

.dept-team-edit__error {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger, #cc792f) 40%,
    var(--m-border-default)
  );
  color: var(--m-status-danger, #cc792f);
}

.dept-team-edit__form {
  display: grid;
  gap: var(--m-space-3);
}

.dept-team-edit__field {
  display: grid;
  gap: var(--m-space-1);
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.dept-team-edit__field input,
.dept-team-edit__field textarea {
  min-height: 2.5rem;
  padding: var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-weight: 400;
}

.dept-team-edit__actions {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
}

.dept-team-edit__actions button,
.dept-team-edit__actions a {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-height: 2.75rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
}

.dept-team-edit__actions button[type="submit"] {
  border: 0;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.dept-team-edit__actions button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.dept-team-edit__actions .dept-team-edit__archive {
  border-color: var(--m-action-destructive-bg);
  background: var(--m-action-destructive-bg);
  color: var(--m-action-destructive-text);
}
</style>
