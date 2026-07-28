<script setup lang="ts">
import { computed, ref } from "vue";

import {
  BRANDING_ASSET_ACCEPT,
  BRANDING_ASSET_MAX_BYTES,
  BrandingRejectedError,
  removeBrandingAsset,
  uploadBrandingAsset,
  type BrandingSlot,
} from "@/branding/brandingAdminModel";

/**
 * Upload, replace, and remove one branding logo slot (M15A.5, M15A.6, M15A.7;
 * BRAND-004, BRAND-005, BRAND-010, BRAND-023).
 *
 * One component for all three slots — organization full lockup, organization
 * compact mark, and department logo — because the rules are identical and the
 * only differences are the label and which owner id is sent. Three near-copies
 * would be three places to forget the size check.
 *
 * The current state is always visible: either the stored logo, or the
 * generated lettermark that renders in its place (BRAND-005, BRAND-010). An
 * empty slot showing nothing would leave an organizer unsure whether the slot
 * was empty or the image was broken.
 */

const props = defineProps<{
  readonly label: string;
  readonly description: string;
  readonly slot: BrandingSlot;
  readonly organizationId?: string;
  readonly departmentId?: string;
  /** Current asset URL, or null when the slot is empty. */
  readonly url: string | null;
  /** Letters rendered when the slot is empty. */
  readonly lettermark: string;
  readonly disabled?: boolean;
}>();

const emit = defineEmits<{
  (event: "changed", url: string | null): void;
}>();

const input = ref<HTMLInputElement | null>(null);
const busy = ref(false);
const error = ref<string | null>(null);
const localUrl = ref<string | null>(null);
const cleared = ref(false);

const currentUrl = computed(() =>
  cleared.value ? null : (localUrl.value ?? props.url),
);

const owner = computed(() => ({
  organizationId: props.organizationId,
  departmentId: props.departmentId,
}));

const maxKilobytes = Math.floor(BRANDING_ASSET_MAX_BYTES / 1024);

function describe(candidate: unknown): string {
  if (candidate instanceof BrandingRejectedError) {
    return candidate.message;
  }

  return candidate instanceof Error
    ? candidate.message
    : "Unable to reach the server.";
}

async function onSelected(event: Event): Promise<void> {
  const file = (event.target as HTMLInputElement).files?.[0];

  if (!file) {
    return;
  }

  error.value = null;

  // Checked here as well as on the server so an organizer on a field
  // connection is told immediately rather than after uploading two megabytes
  // and being refused. The server check is the one that counts.
  if (file.size > BRANDING_ASSET_MAX_BYTES) {
    error.value = `A branding logo may be at most ${maxKilobytes} KB. That file is ${Math.ceil(file.size / 1024)} KB.`;
    resetInput();
    return;
  }

  busy.value = true;

  try {
    const result = await uploadBrandingAsset(owner.value, props.slot, file);

    localUrl.value = result.url;
    cleared.value = false;
    emit("changed", result.url);
  } catch (candidate) {
    error.value = describe(candidate);
  } finally {
    busy.value = false;
    resetInput();
  }
}

async function onRemove(): Promise<void> {
  error.value = null;
  busy.value = true;

  try {
    await removeBrandingAsset(owner.value, props.slot);

    localUrl.value = null;
    cleared.value = true;
    emit("changed", null);
  } catch (candidate) {
    error.value = describe(candidate);
  } finally {
    busy.value = false;
  }
}

function resetInput(): void {
  if (input.value) {
    input.value.value = "";
  }
}
</script>

<template>
  <div class="branding-logo" :data-slot="slot">
    <div class="branding-logo__current">
      <img
        v-if="currentUrl"
        class="branding-logo__image"
        :src="currentUrl"
        :alt="`${label} preview`"
      />
      <span v-else class="branding-logo__lettermark" role="img" :aria-label="`${label}: no logo, showing generated lettermark ${lettermark}`">
        {{ lettermark }}
      </span>
    </div>

    <div class="branding-logo__controls">
      <label class="branding-logo__label" :for="`branding-logo-${slot}`">
        {{ label }}
      </label>
      <p class="branding-logo__description">{{ description }}</p>

      <input
        :id="`branding-logo-${slot}`"
        ref="input"
        type="file"
        :name="`logo_${slot}`"
        :accept="BRANDING_ASSET_ACCEPT"
        :disabled="disabled || busy"
        @change="onSelected"
      />

      <p class="branding-logo__limits">
        PNG, WebP, or JPEG, up to {{ maxKilobytes }} KB. SVG is not accepted.
        {{ currentUrl ? "Uploading replaces the current logo." : "" }}
      </p>

      <button
        v-if="currentUrl"
        type="button"
        data-action="remove-logo"
        :disabled="disabled || busy"
        @click="onRemove"
      >
        Remove logo
      </button>

      <p v-if="error" class="branding-logo__error" role="alert">{{ error }}</p>
    </div>
  </div>
</template>

<style scoped>
.branding-logo {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: var(--m-space-3);
  align-items: start;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-sm);
}

.branding-logo__current {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 4.5rem;
  height: 4.5rem;
  overflow: hidden;
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-base);
}

.branding-logo__image {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.branding-logo__lettermark {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
  font-weight: 800;
}

.branding-logo__controls {
  display: grid;
  gap: var(--m-space-1);
  justify-items: start;
  min-width: 0;
}

.branding-logo__label {
  font-weight: 800;
}

.branding-logo__description,
.branding-logo__limits {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.branding-logo__error {
  margin: var(--m-space-1) 0 0;
  padding: var(--m-space-2);
  border-left: 4px solid var(--m-status-danger);
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
}
</style>
