<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from "vue";

import {
  MERIDIAN_DEFAULT_PALETTE,
  PALETTE_FIELDS,
  type BrandingPalette,
} from "@meridian/ui-tokens/branding";

import { meridianErrorMessage } from "@/api/meridianApi";
import { connectionRequiredMessage } from "@/offline/connectionRequired";
import {
  BRANDING_SLOTS,
  BrandingRejectedError,
  describeFailure,
  listEventBranding,
  previewOrganizationPalette,
  previewTokens,
  saveOrganizationBranding,
  type ContrastFailure,
  type EventBrandingSummary,
} from "@/branding/brandingAdminModel";
import BrandingLogoField from "@/branding/BrandingLogoField.vue";
import { brandingState, loadBrandingProfile } from "@/branding/brandingProfile";

/**
 * Organization branding administration (M15A.6; BRAND-018, BRAND-019).
 *
 * The screen is built around one rule: the organizer sees the resulting
 * appearance and the contrast verdict *before* the change is saved
 * (BRAND-018). The preview panel paints with the same resolved tokens the
 * product renders, and the verdict comes from the same server validator the
 * save runs, so neither is an approximation of the other.
 *
 * A failure is shown as a list of pairs with measured and required ratios
 * rather than a single "invalid palette" message (BRAND-015), because an
 * organizer told only that something is wrong has no way to find which of ten
 * colors to change.
 */

// `withDefaults` rather than an optional prop: Vue casts an absent Boolean
// prop to `false`, so an omitted `canManage` would silently render the screen
// read-only for everyone.
const props = withDefaults(
  defineProps<{
    readonly organizationId: string;
    /** False when the current user may view but not edit (BRAND-019). */
    readonly canManage?: boolean;
  }>(),
  { canManage: true },
);

const canManage = computed(() => props.canManage);

const FIELD_LABELS: Record<keyof BrandingPalette, string> = {
  primary: "Primary",
  secondary: "Secondary",
  tertiary: "Tertiary",
  accent: "Accent",
  canvas: "Canvas background",
  surface: "Surface background",
  foreground: "Foreground text",
  muted_foreground: "Muted foreground text",
  border: "Border",
  focus: "Focus indicator",
};

const draft = reactive<{
  displayName: string;
  palette: BrandingPalette;
  departmentBrandingEnabled: boolean;
}>({
  displayName: "",
  palette: { ...MERIDIAN_DEFAULT_PALETTE },
  departmentBrandingEnabled: true,
});

const logos = reactive<{ fullLockup: string | null; compactMark: string | null }>({
  fullLockup: null,
  compactMark: null,
});

/**
 * The organization's events and their marks (BRAND-028).
 *
 * Fetched here rather than read off the branding profile: that profile is
 * unauthenticated and publishes only the event an install is locked to, so the
 * full list lives behind a session.
 *
 * A failure is held rather than thrown. An organizer who cannot reach the event
 * list can still edit the palette and the organization's own logos, and taking
 * the whole screen down over a section would be the worse trade.
 */
const events = ref<readonly EventBrandingSummary[]>([]);
const eventsError = ref<string | null>(null);

async function refreshEvents(): Promise<void> {
  if (!canManage.value) {
    return;
  }

  eventsError.value = null;

  try {
    events.value = await listEventBranding(props.organizationId);
  } catch (error) {
    /*
     * The node's sentence where the node spoke, and this surface's where it did
     * not (M18.53). `error.message` on a request that never completed is
     * `Failed to fetch`, which is what a browser says to a developer: it reads
     * as a broken screen rather than as a missing connection, and it was being
     * rendered mid-page between the logo controls.
     */
    eventsError.value = meridianErrorMessage(
      error,
      connectionRequiredMessage(
        "This organization's events",
        "which events carry a logo of their own is the node's answer and is not held on this device",
      ),
    );
  }
}

onMounted(() => void refreshEvents());

function onEventLogoChanged(eventId: string, url: string | null): void {
  events.value = events.value.map((event) =>
    event.event_id === eventId ? { ...event, logo_url: url } : event,
  );

  // The header, tab icon, and window icon of an install locked to this event
  // read the mark from the branding profile, so re-resolve rather than leave
  // them on the previous one until a reload.
  void loadBrandingProfile(props.organizationId);
}

const failures = ref<readonly ContrastFailure[]>([]);
const previewChecked = ref(false);
const previewValid = ref(false);
const blockedReason = ref<string | null>(null);
const saved = ref(false);
const busy = ref(false);

watch(
  () => brandingState.profile,
  (profile) => {
    if (profile.organization_id !== props.organizationId) {
      return;
    }

    draft.displayName = profile.is_branded ? profile.display_name : "";
    draft.palette = { ...profile.palette };
    draft.departmentBrandingEnabled = profile.department_branding_enabled;
    logos.fullLockup = profile.full_lockup_url;
    logos.compactMark = profile.compact_mark_url;
  },
  { immediate: true, deep: true },
);

// The lettermark shown in an empty logo slot follows what the organizer is
// typing, so they can see what the fallback would be before saving a name
// (BRAND-005).
const lettermark = computed(() => {
  const source = draft.displayName.trim() || "Meridian";
  const words = source
    .replace(/[^\p{L}\p{N}]+/gu, " ")
    .trim()
    .split(/\s+/u)
    .filter((word) => word.length > 0);

  if (words.length === 0) {
    return "?";
  }

  return words.length === 1
    ? words[0].slice(0, 2).toUpperCase()
    : words
        .slice(0, 3)
        .map((word) => word.slice(0, 1))
        .join("")
        .toUpperCase();
});

// Editing any value invalidates the previous verdict. Leaving a stale "passes"
// on screen after a change would be worse than showing nothing.
watch(
  () => ({ ...draft.palette }),
  () => {
    previewChecked.value = false;
    previewValid.value = false;
    saved.value = false;
  },
  { deep: true },
);

const previewStyle = computed(() => previewTokens(draft.palette));

function setColor(field: keyof BrandingPalette, value: string): void {
  draft.palette = { ...draft.palette, [field]: value };
}

function handleRejection(error: unknown): void {
  if (error instanceof BrandingRejectedError) {
    failures.value = error.failures;

    // A refusal with no per-pair failures is not a colour problem: it is a
    // permission, authority, or freeze refusal, and the server's sentence is
    // the only thing that explains it. Rendering the "these pairs need
    // changing" block with an empty list under it — which is what happened
    // before — tells the reader nothing at all.
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
    const result = await previewOrganizationPalette(
      props.organizationId,
      draft.palette,
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
  if (!canManage.value) {
    return;
  }

  busy.value = true;
  blockedReason.value = null;
  saved.value = false;

  try {
    await saveOrganizationBranding(props.organizationId, {
      display_name: draft.displayName.trim() === "" ? null : draft.displayName,
      palette: draft.palette,
      department_branding_enabled: draft.departmentBrandingEnabled,
    });

    failures.value = [];
    previewChecked.value = true;
    previewValid.value = true;
    saved.value = true;

    // Re-resolve so the header, the mark, and the document title show the new
    // identity now rather than after a reload.
    await loadBrandingProfile(props.organizationId);
  } catch (error) {
    handleRejection(error);
  } finally {
    busy.value = false;
  }
}

/** A logo change saves on its own, so the shell is re-resolved for it too. */
async function onLogoChanged(): Promise<void> {
  await loadBrandingProfile(props.organizationId);
}

function resetToDefaults(): void {
  draft.palette = { ...MERIDIAN_DEFAULT_PALETTE };
}
</script>

<template>
  <section class="branding-admin" aria-labelledby="organization-branding-heading">
    <h1 id="organization-branding-heading">Organization branding</h1>

    <p v-if="!canManage" class="branding-admin__denied" role="status">
      Only organizers and Lead Organizers can edit organization branding. You
      can see the current profile here.
    </p>

    <p v-if="blockedReason" class="branding-admin__blocked" role="alert">
      {{ blockedReason }}
    </p>

    <form class="branding-admin__form" @submit.prevent="onSave">
      <fieldset data-section="identity" :disabled="!canManage || busy">
        <legend>Identity</legend>

        <label class="branding-admin__field">
          <span>Display name</span>
          <input
            v-model="draft.displayName"
            type="text"
            name="display_name"
            placeholder="Meridian"
            maxlength="255"
          />
          <small>
            Replaces the Meridian name on the app header, document title,
            generated PDF exports, and system email. Login and the
            administrative console keep Meridian's identity.
          </small>
        </label>

        <label class="branding-admin__switch">
          <input
            v-model="draft.departmentBrandingEnabled"
            type="checkbox"
            name="department_branding_enabled"
          />
          <span>Allow departments to set their own accent and background</span>
          <small>
            Turning this off keeps department logos and accents and removes
            department background colors organization-wide. Nothing a
            department has already set is deleted.
          </small>
        </label>
      </fieldset>

      <fieldset data-section="logos" :disabled="!canManage || busy">
        <legend>Logos</legend>

        <BrandingLogoField
          label="Full lockup"
          description="The wide logo used where there is room for it, such as generated exports."
          :slot="BRANDING_SLOTS.fullLockup"
          :organization-id="organizationId"
          :url="logos.fullLockup"
          :lettermark="lettermark"
          :disabled="!canManage"
          @changed="(url) => { logos.fullLockup = url; void onLogoChanged(); }"
        />

        <BrandingLogoField
          label="Compact mark"
          description="The square mark used in the application header, where the Meridian mark otherwise appears."
          :slot="BRANDING_SLOTS.compactMark"
          :organization-id="organizationId"
          :url="logos.compactMark"
          :lettermark="lettermark"
          :disabled="!canManage"
          @changed="(url) => { logos.compactMark = url; void onLogoChanged(); }"
        />

        <p class="branding-admin__note">
          A logo change saves immediately and is audited. With no logo, Meridian
          renders the generated lettermark shown above.
        </p>
      </fieldset>

      <!--
        Event marks (BRAND-028). Separate from the organization's own logos
        because they answer a different question: the logos above are who
        produces this, and these are what an install locked to one event shows
        the staff working it.
      -->
      <fieldset
        v-if="canManage"
        data-section="event-logos"
        :disabled="busy"
      >
        <legend>Event logos</legend>

        <p class="branding-admin__note">
          On a node locked to one of these events, the event's logo replaces the
          organization mark in the application header, the browser tab icon, and
          the desktop window icon. Staff who know the event but not the company
          producing it can still tell which app they are in. An event with no
          logo shows the organization mark, and nodes not locked to an event are
          never affected.
        </p>

        <p v-if="eventsError" class="branding-admin__blocked" role="alert">
          {{ eventsError }}
        </p>

        <p v-else-if="events.length === 0" role="status">
          This organization has no events yet. An event can carry a logo once it
          has been created.
        </p>

        <BrandingLogoField
          v-for="event in events"
          :key="event.event_id"
          :label="event.archived ? `${event.name} (archived)` : event.name"
          description="Shown as the application mark, tab icon, and desktop window icon on any node locked to this event."
          :slot="BRANDING_SLOTS.eventLogo"
          :event-id="event.event_id"
          :url="event.logo_url"
          :lettermark="event.lettermark"
          @changed="(url) => onEventLogoChanged(event.event_id, url)"
        />
      </fieldset>

      <fieldset data-section="palette" :disabled="!canManage || busy">
        <legend>Palette</legend>

        <div class="branding-admin__palette">
          <label
            v-for="field in PALETTE_FIELDS"
            :key="field"
            class="branding-admin__color"
          >
            <span>{{ FIELD_LABELS[field] }}</span>
            <input
              type="color"
              :name="field"
              :value="draft.palette[field]"
              @input="
                setColor(
                  field,
                  ($event.target as HTMLInputElement).value,
                )
              "
            />
            <code>{{ draft.palette[field] }}</code>
          </label>
        </div>

        <p class="branding-admin__note">
          Action, status, severity, priority, and chart colors are derived from
          this palette and cannot be set separately, so state stays readable
          under every branding profile.
        </p>

        <button type="button" data-action="reset" @click="resetToDefaults">
          Reset to Meridian defaults
        </button>
      </fieldset>

      <section class="branding-admin__preview" aria-labelledby="branding-preview-heading">
        <h2 id="branding-preview-heading">Preview</h2>

        <div class="branding-admin__swatches" :style="previewStyle">
          <p class="branding-admin__sample-surface">
            <strong>{{ draft.displayName || "Meridian" }}</strong>
            <span>Body text on the surface color.</span>
            <small>Muted supporting text.</small>
          </p>
          <span class="branding-admin__sample-action">Primary action</span>
          <span class="branding-admin__sample-destructive">Destructive action</span>
        </div>
      </section>

      <section class="branding-admin__verdict" aria-live="polite">
        <h2>Contrast check</h2>

        <p v-if="!previewChecked">
          Run the contrast check to see whether this palette meets WCAG 2.1 AA.
        </p>
        <p v-else-if="previewValid" class="branding-admin__pass" data-result="pass">
          <span aria-hidden="true">&#10003;</span>
          This palette meets WCAG 2.1 AA.
          <template v-if="saved">Saved.</template>
        </p>
        <div v-else class="branding-admin__fail" data-result="fail">
          <p>
            <span aria-hidden="true">&#10007;</span>
            This palette was not saved. Meridian does not adjust submitted
            colors, so these pairs need changing:
          </p>
          <ul>
            <li v-for="failure in failures" :key="failure.pair">
              {{ describeFailure(failure) }}
            </li>
          </ul>
        </div>
      </section>

      <div class="branding-admin__actions">
        <button
          type="button"
          data-action="preview"
          :disabled="busy"
          @click="onPreview"
        >
          Check contrast
        </button>
        <button type="submit" data-action="save" :disabled="!canManage || busy">
          Save branding
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.branding-admin {
  display: grid;
  gap: var(--m-space-4);
  width: 100%;
  max-width: var(--m-content-workflow, 76rem);
}

.branding-admin__form {
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

.branding-admin__field,
.branding-admin__switch {
  display: grid;
  gap: var(--m-space-1);
}

.branding-admin__field small,
.branding-admin__switch small,
.branding-admin__note {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.branding-admin__palette {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr));
  gap: var(--m-space-3);
}

.branding-admin__color {
  display: grid;
  gap: var(--m-space-1);
}

.branding-admin__swatches {
  display: grid;
  gap: var(--m-space-3);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-app);
}

.branding-admin__sample-surface {
  display: grid;
  gap: var(--m-space-1);
  margin: 0;
  padding: var(--m-space-3);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
}

.branding-admin__sample-surface small {
  color: var(--m-text-muted);
}

.branding-admin__sample-action,
.branding-admin__sample-destructive {
  display: inline-flex;
  align-items: center;
  justify-self: start;
  padding: var(--m-space-2) var(--m-space-4);
  border-radius: var(--m-radius-sm);
  font-weight: 700;
}

.branding-admin__sample-action {
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.branding-admin__sample-destructive {
  background: var(--m-action-destructive-bg);
  color: var(--m-action-destructive-text);
}

.branding-admin__verdict ul {
  margin: var(--m-space-2) 0 0;
  padding-left: var(--m-space-5);
}

.branding-admin__pass,
.branding-admin__fail,
.branding-admin__blocked,
.branding-admin__denied {
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-left-width: 4px;
  border-radius: var(--m-radius-sm);
}

.branding-admin__pass {
  border-left-color: var(--m-status-success);
}

.branding-admin__fail,
.branding-admin__blocked {
  border-left-color: var(--m-status-danger);
}

.branding-admin__denied {
  border-left-color: var(--m-status-neutral);
}

.branding-admin__actions {
  display: flex;
  gap: var(--m-space-3);
}
</style>
