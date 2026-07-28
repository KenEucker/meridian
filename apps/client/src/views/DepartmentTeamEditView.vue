<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";
import { RouterLink, useRoute, useRouter } from "vue-router";

import { BRANDING_SLOTS } from "@/branding/brandingAdminModel";
import BrandingLogoField from "@/branding/BrandingLogoField.vue";
import {
  findTeamBranding,
  loadBrandingProfile,
} from "@/branding/brandingProfile";
import { FIXTURE_ORGANIZATION_ID } from "@/branding/brandingRouteProps";
import {
  archiveDepartmentTeam,
  canAdministerDepartment,
  createDepartmentTeam,
  getDepartmentTeam,
  resolveDepartmentSelfAdminSession,
  restoreDepartmentTeam,
  suggestTeamCodeFromName,
  updateDepartmentTeam,
} from "@/department-teams/teamAdminModel";

const route = useRoute();
const router = useRouter();
const session = computed(() => resolveDepartmentSelfAdminSession());
const canAdminister = computed(() => canAdministerDepartment(session.value));

const teamId = computed(() =>
  typeof route.params.teamId === "string" ? route.params.teamId : "",
);
const isCreate = computed(() => route.name === "events.departments.teams.create");

const existing = computed(() =>
  isCreate.value ? null : getDepartmentTeam(session.value, teamId.value),
);

const draft = reactive({
  name: "",
  code: "",
  description: "",
});
const codeTouched = ref(false);
const formError = ref<string | null>(null);
const busy = ref(false);

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
  await loadBrandingProfile(FIXTURE_ORGANIZATION_ID);
}

const teamsIndexRoute = computed(() => ({
  name: "events.departments.teams.index",
  params: {
    eventId: session.value?.eventId,
    departmentId: session.value?.departmentId,
  },
}));

async function onSubmit(): Promise<void> {
  if (!canAdminister.value) {
    return;
  }

  formError.value = null;
  busy.value = true;

  try {
    if (isCreate.value) {
      const created = createDepartmentTeam(session.value, { ...draft });
      await router.push({
        name: "events.departments.teams.edit",
        params: {
          eventId: session.value?.eventId,
          departmentId: session.value?.departmentId,
          teamId: created.id,
        },
      });
      return;
    }

    updateDepartmentTeam(session.value, teamId.value, { ...draft });
  } catch (error) {
    formError.value =
      error instanceof Error ? error.message : "Unable to save team.";
  } finally {
    busy.value = false;
  }
}

async function onArchive(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    archiveDepartmentTeam(session.value, teamId.value);
  } catch (error) {
    formError.value =
      error instanceof Error ? error.message : "Unable to archive team.";
  } finally {
    busy.value = false;
  }
}

async function onRestore(): Promise<void> {
  formError.value = null;
  busy.value = true;

  try {
    restoreDepartmentTeam(session.value, teamId.value);
  } catch (error) {
    formError.value =
      error instanceof Error ? error.message : "Unable to restore team.";
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
    <p v-if="session" class="dept-team-edit__lede">
      {{ session.departmentLabel }} / {{ session.roleLabel }}
    </p>

    <p v-if="!canAdminister" class="dept-team-edit__restricted" role="status">
      Department team administration requires department lead or department
      administration authority for this department.
    </p>

    <p
      v-else-if="!isCreate && existing === null"
      class="dept-team-edit__restricted"
      role="status"
    >
      Team not found for this department.
    </p>

    <template v-else-if="canAdminister">
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
