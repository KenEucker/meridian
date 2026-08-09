<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import { connectionRequiredMessage } from "@/offline/connectionRequired";
import {
  BRANDING_SLOTS,
  BrandingRejectedError,
  describeFailure,
  previewDepartmentBranding,
  saveDepartmentBranding,
  type ContrastFailure,
} from "@/branding/brandingAdminModel";
import BrandingLogoField from "@/branding/BrandingLogoField.vue";
import {
  brandingState,
  findDepartmentBranding,
  loadBrandingProfile,
} from "@/branding/brandingProfile";
import DepartmentBadge from "@/components/DepartmentBadge.vue";

/**
 * Department branding administration (M15A.7; BRAND-009, BRAND-011, BRAND-013,
 * BRAND-018, BRAND-019).
 *
 * Two controls, and no more. A department sets a logo, an accent, and a
 * surface background; foreground, border, focus, status, severity, attention,
 * and chart colors resolve from the organization palette and are not offered
 * here (BRAND-011). The screen says so rather than leaving a department lead
 * hunting for the missing controls.
 *
 * When the organization has switched department overrides off, the form is
 * disabled and the reason is stated (BRAND-013). The stored values are still
 * shown, because they are not deleted and the department will get them back if
 * the organization switches overrides on again.
 */

// `withDefaults` rather than an optional prop: Vue casts an absent Boolean
// prop to `false`, so an omitted `canManage` would silently render the screen
// read-only for everyone.
const props = withDefaults(
  defineProps<{
    readonly organizationId: string;
    readonly departmentId: string;
    readonly departmentName: string;
    /** False when the current user may view but not edit (BRAND-019). */
    readonly canManage?: boolean;
  }>(),
  { canManage: true },
);

const canManage = computed(() => props.canManage);
const overridesEnabled = computed(
  () => brandingState.profile.department_branding_enabled,
);
const editable = computed(() => canManage.value && overridesEnabled.value);

const stored = computed(() => findDepartmentBranding(props.departmentId));

const draft = reactive({
  accent: "",
  surface: "",
});

// The logo slot is not gated on the organization override switch. BRAND-013
// disables accent and background overrides; a department keeps its logo
// identity either way.
const logoUrl = ref<string | null>(null);

const failures = ref<readonly ContrastFailure[]>([]);
const previewChecked = ref(false);
const previewValid = ref(false);
const blockedReason = ref<string | null>(null);
const saved = ref(false);
const busy = ref(false);

watch(
  stored,
  (branding) => {
    draft.accent = branding?.accent ?? "";
    draft.surface = branding?.surface ?? "";
    logoUrl.value = branding?.logo_url ?? null;
  },
  { immediate: true },
);

watch(
  () => [draft.accent, draft.surface],
  () => {
    previewChecked.value = false;
    previewValid.value = false;
    saved.value = false;
  },
);

const previewDepartment = computed(() => ({
  id: props.departmentId,
  name: props.departmentName,
  accentColor: draft.accent === "" ? null : draft.accent,
  logoUrl: logoUrl.value,
  lettermark: stored.value?.lettermark ?? null,
}));

const previewStyle = computed(() =>
  draft.surface === ""
    ? {}
    : { "--m-department-surface": draft.surface, background: draft.surface },
);

function handleRejection(error: unknown): void {
  if (error instanceof BrandingRejectedError) {
    failures.value = error.failures;

    // A refusal with no per-pair failures is a permission, authority, or
    // freeze refusal rather than a colour problem, and only the server's
    // sentence explains it. The contrast block stays hidden so an empty list
    // never appears under "these pairs need changing".
    const hasPairs = error.failures.length > 0;

    previewChecked.value = hasPairs;
    previewValid.value = false;
    blockedReason.value = hasPairs && !error.blocked ? null : error.message;
    return;
  }

  blockedReason.value = meridianErrorMessage(
    error,
    connectionRequiredMessage(
      "The contrast check",
      "it is run against the node so that a palette and the rules it is checked by cannot drift apart",
    ),
  );
}

async function onPreview(): Promise<void> {
  busy.value = true;
  blockedReason.value = null;

  try {
    const result = await previewDepartmentBranding(
      props.organizationId,
      props.departmentId,
      draft.accent === "" ? null : draft.accent,
      draft.surface === "" ? null : draft.surface,
    );

    failures.value = result.failures;
    previewValid.value = result.valid;
    previewChecked.value = true;
  } catch (error) {
    handleRejection(error);
  } finally {
    busy.value = false;
  }
}

async function onSave(): Promise<void> {
  if (!editable.value) {
    return;
  }

  busy.value = true;
  blockedReason.value = null;
  saved.value = false;

  try {
    await saveDepartmentBranding(props.departmentId, {
      accent: draft.accent === "" ? null : draft.accent,
      surface: draft.surface === "" ? null : draft.surface,
    });

    failures.value = [];
    previewChecked.value = true;
    previewValid.value = true;
    saved.value = true;

    // Re-resolve so the department badge and any scoped surface pick the new
    // values up without a reload.
    await loadBrandingProfile(props.organizationId);
  } catch (error) {
    handleRejection(error);
  } finally {
    busy.value = false;
  }
}

function clearSurface(): void {
  draft.surface = "";
}
</script>

<template>
  <section class="department-branding" aria-labelledby="department-branding-heading">
    <h1 id="department-branding-heading">{{ departmentName }} branding</h1>

    <p v-if="!canManage" class="department-branding__denied" role="status">
      Department leads and department administration can edit this department's
      branding. You can see the current profile here.
    </p>

    <p
      v-else-if="!overridesEnabled"
      class="department-branding__disabled"
      role="status"
      data-state="overrides-disabled"
    >
      Your organization has switched department branding overrides off.
      Department logos and accents still show; accent and background cannot be
      changed until an organizer re-enables them. Nothing you have already set
      has been deleted.
    </p>

    <p v-if="blockedReason" class="department-branding__blocked" role="alert">
      {{ blockedReason }}
    </p>

    <form class="department-branding__form" @submit.prevent="onSave">
      <fieldset data-section="logo" :disabled="!canManage || busy">
        <legend>Department logo</legend>

        <BrandingLogoField
          label="Department logo"
          description="Shown in the application header while you are in this department's context, on the department badge, and on department-scoped surfaces."
          :slot="BRANDING_SLOTS.departmentLogo"
          :department-id="departmentId"
          :url="logoUrl"
          :lettermark="stored?.lettermark ?? departmentName.slice(0, 2).toUpperCase()"
          :disabled="!canManage"
          @changed="(url) => { logoUrl = url; void loadBrandingProfile(organizationId); }"
        />

        <p class="department-branding__note">
          The logo is department identity and stays available even when your
          organization has switched accent and background overrides off.
        </p>
      </fieldset>

      <fieldset data-section="colors" :disabled="!editable || busy">
        <legend>Department colors</legend>

        <label class="department-branding__field">
          <span>Accent color</span>
          <input v-model="draft.accent" type="color" name="accent" />
          <code>{{ draft.accent || "not set" }}</code>
          <small>
            A small identifier on badges and department context. It shows
            wherever the department appears, including surfaces that do not take
            a department background.
          </small>
        </label>

        <label class="department-branding__field">
          <span>Surface background</span>
          <input v-model="draft.surface" type="color" name="surface" />
          <code>{{ draft.surface || "not set" }}</code>
          <small>
            Applies only to this department's own operations surfaces. Incident
            management, The Briefing, and organization-level screens are never
            tinted.
          </small>
          <button type="button" data-action="clear-surface" @click="clearSurface">
            Clear background
          </button>
        </label>

        <p class="department-branding__note">
          Text, border, focus, status, severity, priority, and chart colors come
          from the organization palette and are not set per department, so state
          reads the same everywhere.
        </p>
      </fieldset>

      <section
        class="department-branding__preview"
        aria-labelledby="department-branding-preview-heading"
      >
        <h2 id="department-branding-preview-heading">Preview</h2>

        <div class="department-branding__surface" :style="previewStyle">
          <DepartmentBadge :department="previewDepartment" size="lg" />
          <p>Body text on this department's surface.</p>
          <small>Muted supporting text.</small>
        </div>
      </section>

      <section class="department-branding__verdict" aria-live="polite">
        <h2>Contrast check</h2>

        <p v-if="!previewChecked">
          Run the contrast check to see whether these colors meet WCAG 2.1 AA
          against your organization's palette.
        </p>
        <p
          v-else-if="previewValid"
          class="department-branding__pass"
          data-result="pass"
        >
          <span aria-hidden="true">&#10003;</span>
          These colors meet WCAG 2.1 AA.
          <template v-if="saved">Saved.</template>
        </p>
        <div v-else class="department-branding__fail" data-result="fail">
          <p>
            <span aria-hidden="true">&#10007;</span>
            These colors were not saved. Meridian does not adjust submitted
            colors, so these pairs need changing:
          </p>
          <ul>
            <li v-for="failure in failures" :key="failure.pair">
              {{ describeFailure(failure) }}
            </li>
          </ul>
        </div>
      </section>

      <div class="department-branding__actions">
        <button
          type="button"
          data-action="preview"
          :disabled="!editable || busy"
          @click="onPreview"
        >
          Check contrast
        </button>
        <button type="submit" data-action="save" :disabled="!editable || busy">
          Save branding
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.department-branding {
  display: grid;
  gap: var(--m-space-4);
  width: 100%;
  max-width: var(--m-content-workflow, 76rem);
}

.department-branding__form {
  display: grid;
  gap: var(--m-space-4);
}

fieldset {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
}

legend {
  padding: 0 var(--m-space-2);
  font-weight: 800;
}

.department-branding__field {
  display: grid;
  gap: var(--m-space-1);
  justify-items: start;
}

.department-branding__field small,
.department-branding__note {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.department-branding__surface {
  display: grid;
  gap: var(--m-space-2);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
}

.department-branding__surface small {
  color: var(--m-text-muted);
}

.department-branding__verdict ul {
  margin: var(--m-space-2) 0 0;
  padding-left: var(--m-space-5);
}

.department-branding__pass,
.department-branding__fail,
.department-branding__blocked,
.department-branding__denied,
.department-branding__disabled {
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-left-width: 4px;
  border-radius: var(--m-radius-sm);
}

.department-branding__pass {
  border-left-color: var(--m-status-success);
}

.department-branding__fail,
.department-branding__blocked {
  border-left-color: var(--m-status-danger);
}

.department-branding__denied,
.department-branding__disabled {
  border-left-color: var(--m-status-neutral);
}

.department-branding__actions {
  display: flex;
  gap: var(--m-space-3);
}
</style>
