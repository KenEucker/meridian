<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  getOrganizationModules,
  moduleHistoryLabel,
  updateOrganizationModules,
  type OrganizationModuleSelection,
} from "@/organizer-configuration/organizationModuleModel";

/**
 * The modules this organization runs (M19.15; ORG-018, ORG-020, ORG-021;
 * MOD-008, MOD-010, MOD-011).
 *
 * One featureset on the organization configuration page, carrying the last of
 * the values ORG-018 lists: which parts of Meridian the organization uses. It
 * sits under `organization.configuration.manage` alongside the operational
 * settings, because enablement is one of that surface's values rather than a
 * separate authority (data/API 6.8).
 *
 * **Only entitled modules are offered.** Whether Meridian makes a module
 * available to an organization at all is a platform decision (MOD-006), so an
 * unentitled module is absent from this list rather than present and refused.
 * The list says what it is a list of, so a shorter one reads as a narrower
 * platform offering rather than as modules gone missing.
 *
 * **Turning one off is a real absence, not a hidden menu.** The copy says so,
 * because an organizer who thinks they are tidying navigation and is actually
 * taking records off devices has been told the wrong thing (MOD-012).
 *
 * The form is disabled — not hidden — while governance blocks changes, with the
 * node's reason on screen: module state is frozen during an active event window
 * and owned by central, on the same rule as the rest of the configuration
 * surface (MOD-010). The disabled control is a courtesy; the node refuses the
 * save regardless.
 */
const props = withDefaults(
  defineProps<{
    organizationId: string | null;
    variant?: "page" | "section";
  }>(),
  { variant: "section" },
);

const selection = ref<OrganizationModuleSelection | null>(null);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const saved = ref(false);
const busy = ref(false);

/** The checkbox state per module key, filled from the node's answer. */
const enabled = reactive<Record<string, boolean>>({});
const reason = ref("");

const governance = computed(() => selection.value?.governance ?? null);
const editable = computed(() => governance.value?.editable ?? false);
const modules = computed(() => selection.value?.modules ?? []);

/** The node's sentence for why the form is read-only right now, or null. */
const frozenReason = computed(() => {
  const state = governance.value;

  if (state === null || state.editable) {
    return null;
  }

  if (!state.holdsAuthority) {
    return "Module state is maintained on the central node, and this node does not hold module authority.";
  }

  if (state.frozenByEvent !== null) {
    return `Modules are frozen while ${state.frozenByEvent.name} is inside its active event window. Turning one off mid-event would take its records off devices that are already holding them.`;
  }

  return "Modules cannot be changed right now.";
});

watch(() => props.organizationId, () => void loadModules(), {
  immediate: true,
});

async function loadModules(): Promise<void> {
  if (props.organizationId === null) {
    selection.value = null;

    return;
  }

  loadError.value = null;

  try {
    selection.value = await getOrganizationModules(props.organizationId);
    fillForm(selection.value);
  } catch (error) {
    selection.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load this organization's modules. Check the connection to this node and try again.",
    );
  }
}

function fillForm(current: OrganizationModuleSelection): void {
  for (const key of Object.keys(enabled)) {
    delete enabled[key];
  }

  for (const module of current.modules) {
    enabled[module.key] = module.enabled;
  }
}

function historyLabel(key: string): string {
  const module = modules.value.find((candidate) => candidate.key === key);

  return module === undefined ? "" : moduleHistoryLabel(module);
}

async function onSave(): Promise<void> {
  const organizationId = props.organizationId;

  // Not editable is not "the button was disabled": a submit can still fire,
  // and the node would refuse it. Refusing here keeps the explanation on
  // screen instead of replacing it with a rejection.
  if (organizationId === null || busy.value || !editable.value) {
    return;
  }

  actionError.value = null;
  saved.value = false;
  busy.value = true;

  const submitted: Record<string, boolean> = {};

  for (const module of modules.value) {
    submitted[module.key] = enabled[module.key] ?? module.enabled;
  }

  const note = reason.value.trim();

  try {
    selection.value = await updateOrganizationModules(
      organizationId,
      submitted,
      note === "" ? null : note,
    );
    fillForm(selection.value);
    reason.value = "";
    saved.value = true;
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to save this organization's modules.",
    );
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <WorkflowSection
    class="org-modules"
    title="Modules"
    heading-id="org-modules-heading"
    description="The parts of Meridian this organization runs. A module that is switched off is absent rather than hidden: no navigation entry, no page, no API route, and nothing synchronized to devices. Switching it back on returns the records it holds exactly as they were left."
    :variant="props.variant"
  >
    <p v-if="loadError" class="org-modules__error" role="alert">
      {{ loadError }}
    </p>

    <p
      v-else-if="selection === null"
      class="org-modules__empty"
      role="status"
    >
      Loading this organization's modules.
    </p>

    <template v-else>
      <p v-if="frozenReason" class="org-modules__frozen" role="status">
        {{ frozenReason }}
      </p>

      <!--
        MOD-008: only entitled modules are a choice. An organization the
        platform offers nothing optional to is still a working organization —
        staff, status, attendance, hours, and credits are core (MOD-004) — so
        the empty state says that rather than reading as a fault.
      -->
      <p v-if="modules.length === 0" class="org-modules__empty" role="status">
        Meridian makes no optional modules available to this organization. Staff,
        departments, teams, status, attendance, hours, and credits do not belong
        to a module and are always available.
      </p>

      <form
        v-else
        class="org-modules__form"
        aria-label="Organization modules"
        @submit.prevent="onSave"
      >
        <ul class="org-modules__list">
          <li v-for="module in modules" :key="module.key" class="org-modules__item">
            <label class="org-modules__toggle" :for="`module-${module.key}`">
              <input
                :id="`module-${module.key}`"
                v-model="enabled[module.key]"
                type="checkbox"
                :disabled="!editable"
              />
              {{ module.name }}
            </label>
            <p class="org-modules__note">{{ module.summary }}</p>
            <p class="org-modules__history">{{ historyLabel(module.key) }}</p>
          </li>
        </ul>

        <p class="org-modules__note">
          Meridian offers this organization the modules listed here. Anything not
          on the list is not part of what this organization has been given, and
          is set by the platform rather than here.
        </p>

        <div class="org-modules__reason">
          <label for="module-change-reason">Reason (optional)</label>
          <textarea
            id="module-change-reason"
            v-model="reason"
            rows="2"
            maxlength="1000"
            :disabled="!editable"
          ></textarea>
          <p class="org-modules__note">
            Recorded with the change. Every module change is audited with its
            previous and new state and who made it whether or not a reason is
            given; this is the part only a person can supply.
          </p>
        </div>

        <div class="org-modules__actions">
          <button type="submit" :disabled="busy || !editable">
            Save modules
          </button>
          <span v-if="saved" class="org-modules__saved" role="status">
            Modules saved.
          </span>
        </div>

        <p v-if="actionError" class="org-modules__error" role="alert">
          {{ actionError }}
        </p>
      </form>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.org-modules__form {
  display: grid;
  gap: var(--m-space-4);
}

.org-modules__list {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: 0;
  list-style: none;
}

.org-modules__item {
  display: grid;
  gap: var(--m-space-1);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.org-modules__toggle {
  display: inline-flex;
  gap: var(--m-space-2);
  align-items: center;
  min-height: 44px;
  font-weight: 700;
}

.org-modules__toggle input {
  width: 24px;
  height: 24px;
}

.org-modules__reason {
  display: grid;
  gap: var(--m-space-2);
  justify-items: start;
}

.org-modules__reason label {
  font-weight: 700;
}

.org-modules__reason textarea {
  width: 100%;
  max-width: 48rem;
}

.org-modules__actions {
  display: flex;
  gap: var(--m-space-3);
  align-items: center;
}

.org-modules__frozen {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-muted);
}

.org-modules__saved,
.org-modules__empty,
.org-modules__history,
.org-modules__note {
  margin: 0;
  color: var(--m-text-muted);
}

.org-modules__history {
  font-size: var(--m-text-sm);
}

.org-modules__error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}
</style>
