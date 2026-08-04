<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import ControlBar from "@/components/ControlBar.vue";
import ControlField from "@/components/ControlField.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  archiveCreditPolicy,
  calculateEventCredits,
  createCreditPolicy,
  getOrganizationCreditPolicies,
  restoreCreditPolicy,
  updateCreditPolicy,
  type CreditRunEvent,
  type OrganizationCreditPolicy,
  type OrganizationCreditPolicyList,
} from "@/organizer-configuration/creditPolicyAdminModel";

/**
 * The organization's credit policies and calculation runs (M18.16; ORG-009,
 * ORG-020; CREDIT-001 through CREDIT-003).
 *
 * One featureset on the organization configuration page. A policy is a rate —
 * credits per hour worked — and the featureset carries the whole credit story
 * in one place: the rates, which one is the organization default, and the
 * events whose settled hours are waiting to be priced.
 *
 * Like the operational settings and unlike the incident type list, edits here
 * freeze during the active event window and belong to central (ORG-021): a
 * credit rate is a rule of the event being played. The form is disabled — not
 * hidden — while governance blocks edits, with the node's reason on screen.
 *
 * Archived policies are shown rather than hidden. Restoring one is half of why
 * a maintainer is here, and a shift may still name one (CREDIT-002).
 */
const props = withDefaults(
  defineProps<{
    organizationId: string | null;
    variant?: "page" | "section";
  }>(),
  { variant: "section" },
);

const list = ref<OrganizationCreditPolicyList | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const busyId = ref<string | null>(null);
const creating = ref(false);
/*
 * The multiplier fields hold `string | number`: a `type="number"` input's
 * v-model hands back a number once the browser has parsed one, and a string
 * while the field is empty or being typed in.
 */
const createForm = reactive<{ name: string; multiplier: string | number }>({
  name: "",
  multiplier: "",
});
const editingId = ref<string | null>(null);
const editForm = reactive<{ name: string; multiplier: string | number }>({
  name: "",
  multiplier: "",
});
const runNotice = ref<string | null>(null);

const policies = computed(() => list.value?.creditPolicies ?? []);
const activePolicies = computed(() =>
  policies.value.filter((policy) => !policy.archived),
);
const archivedPolicies = computed(() =>
  policies.value.filter((policy) => policy.archived),
);
const events = computed(() => list.value?.events ?? []);
const governance = computed(() => list.value?.governance ?? null);
const editable = computed(() => governance.value?.editable ?? false);

/** The node's sentence for why the featureset is read-only right now, or null. */
const frozenReason = computed(() => {
  const state = governance.value;

  if (state === null || state.editable) {
    return null;
  }

  if (!state.holdsAuthority) {
    return "Credit policies are maintained on the central node, and this node does not hold configuration authority.";
  }

  if (state.frozenByEvent !== null) {
    return `Credit policies are frozen while ${state.frozenByEvent.name} is inside its active event window: a credit rate is a rule of the event being played.`;
  }

  return "Credit policies cannot be edited right now.";
});

watch(() => props.organizationId, () => void loadPolicies(), { immediate: true });

/**
 * Read the list the node holds.
 *
 * Re-read after every write rather than patched in place: a rename moves a
 * row in the node's sort, an archive changes what the shift edit form offers,
 * and a calculation run changes the event counts.
 */
async function loadPolicies(): Promise<void> {
  if (props.organizationId === null) {
    list.value = null;

    return;
  }

  loading.value = true;
  loadError.value = null;

  try {
    list.value = await getOrganizationCreditPolicies(props.organizationId);
  } catch (error) {
    list.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load credit policies. Check the connection to this node and try again.",
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
  runNotice.value = null;
  busyId.value = key;

  try {
    await command();
    await loadPolicies();
  } catch (error) {
    actionError.value = meridianErrorMessage(error, fallback);
  } finally {
    busyId.value = null;
  }
}

/** The multiplier as the command sends it, whatever the input handed back. */
function trimmedMultiplier(value: string | number): string {
  return String(value).trim();
}

async function onCreate(): Promise<void> {
  const organizationId = props.organizationId;
  const name = createForm.name.trim();
  const multiplier = trimmedMultiplier(createForm.multiplier);

  if (organizationId === null || name === "" || multiplier === "") {
    return;
  }

  creating.value = true;

  await runCommand(
    "create",
    async () => {
      await createCreditPolicy(organizationId, name, multiplier);
      createForm.name = "";
      createForm.multiplier = "";
    },
    "Unable to add this credit policy.",
  );

  creating.value = false;
}

function startEdit(policy: OrganizationCreditPolicy): void {
  editingId.value = policy.id;
  editForm.name = policy.name;
  editForm.multiplier = policy.creditMultiplier;
  actionError.value = null;
}

function cancelEdit(): void {
  editingId.value = null;
  editForm.name = "";
  editForm.multiplier = "";
}

async function onSaveEdit(policy: OrganizationCreditPolicy): Promise<void> {
  const name = editForm.name.trim();
  const multiplier = trimmedMultiplier(editForm.multiplier);

  if (name === "" || multiplier === "") {
    return;
  }

  if (name === policy.name && multiplier === policy.creditMultiplier) {
    cancelEdit();

    return;
  }

  await runCommand(
    policy.id,
    async () => {
      await updateCreditPolicy(policy.id, name, multiplier);
      cancelEdit();
    },
    "Unable to save this credit policy.",
  );
}

async function onArchive(policy: OrganizationCreditPolicy): Promise<void> {
  await runCommand(
    policy.id,
    () => archiveCreditPolicy(policy.id),
    "Unable to archive this credit policy.",
  );
}

async function onRestore(policy: OrganizationCreditPolicy): Promise<void> {
  await runCommand(
    policy.id,
    () => restoreCreditPolicy(policy.id),
    "Unable to restore this credit policy.",
  );
}

async function onCalculate(event: CreditRunEvent): Promise<void> {
  await runCommand(
    event.id,
    async () => {
      const result = await calculateEventCredits(event.id);

      runNotice.value =
        result.entriesCreated === 0
          ? `${event.name}: nothing new to credit.`
          : `${event.name}: ${result.entriesCreated} ledger entr${
              result.entriesCreated === 1 ? "y" : "ies"
            } written for ${result.totalCredits} credits.` +
            (result.hoursWithoutCreditPolicy > 0
              ? ` ${result.hoursWithoutCreditPolicy} hours record(s) have no credit policy and were not credited.`
              : "");
    },
    "Unable to calculate credits for this event.",
  );
}

/** The rate said in words, so the number on screen explains itself. */
function rateText(policy: OrganizationCreditPolicy): string {
  return `${policy.creditMultiplier} credits per hour`;
}

/** How many shifts name a policy, said in words rather than a bare number. */
function usageText(policy: OrganizationCreditPolicy): string {
  const parts: string[] = [];

  if (policy.isDefault) {
    parts.push("Organization default");
  }

  if (policy.shiftCount === 0) {
    parts.push("No shifts name it");
  } else {
    parts.push(
      policy.shiftCount === 1
        ? "Named by 1 shift"
        : `Named by ${policy.shiftCount} shifts`,
    );
  }

  return parts.join(" · ");
}

/** The node's answer on where an event stands, in one sentence. */
function eventStatusText(event: CreditRunEvent): string {
  if (!event.graceClosed) {
    return event.graceClosesAt === null
      ? "The event has no end date to count the grace period from."
      : `Correction grace period open until ${formatDate(event.graceClosesAt)}.`;
  }

  if (event.openHoursCount > 0) {
    return `${event.openHoursCount} hours record(s) are still open for correction.`;
  }

  if (event.uncreditedHoursCount > 0) {
    return `${event.uncreditedHoursCount} settled hours record(s) waiting to be credited.`;
  }

  if (event.creditedHoursCount > 0) {
    return `All ${event.creditedHoursCount} hours record(s) credited.`;
  }

  return "No hours were recorded for this event.";
}

function formatDate(iso: string): string {
  const parsed = new Date(iso);

  return Number.isNaN(parsed.getTime()) ? iso : parsed.toLocaleDateString();
}
</script>

<template>
  <WorkflowSection
    class="credit-policies"
    title="Credit policies"
    heading-id="credit-policies-heading"
    description="The rates worked hours are credited at, and the events waiting to be credited."
    :variant="props.variant"
  >
    <p v-if="loadError" class="credit-policies__error" role="alert">
      {{ loadError }}
    </p>

    <p
      v-else-if="loading && list === null"
      class="credit-policies__empty"
      role="status"
    >
      Loading credit policies.
    </p>

    <template v-else-if="list !== null">
      <p v-if="frozenReason" class="credit-policies__frozen" role="status">
        {{ frozenReason }}
      </p>

      <ControlBar label="Add a credit policy">
        <form
          data-control-group="grow"
          aria-label="Add a credit policy"
          @submit.prevent="onCreate"
        >
          <ControlField
            label="New policy name"
            control-id="credit-policy-name"
            width="grow"
          >
            <input
              id="credit-policy-name"
              v-model="createForm.name"
              type="text"
              maxlength="100"
              autocomplete="off"
              :disabled="!editable"
            />
          </ControlField>
          <ControlField
            label="Credits per hour"
            control-id="credit-policy-multiplier"
          >
            <input
              id="credit-policy-multiplier"
              v-model="createForm.multiplier"
              type="number"
              min="0.001"
              max="1000"
              step="0.001"
              autocomplete="off"
              :disabled="!editable"
            />
          </ControlField>
          <button
            type="submit"
            :disabled="
              !editable ||
              creating ||
              createForm.name.trim() === '' ||
              String(createForm.multiplier).trim() === ''
            "
          >
            Add policy
          </button>
        </form>
      </ControlBar>

      <p v-if="actionError" class="credit-policies__error" role="alert">
        {{ actionError }}
      </p>

      <p v-else-if="runNotice" class="credit-policies__notice" role="status">
        {{ runNotice }}
      </p>

      <p v-if="activePolicies.length === 0" class="credit-policies__empty">
        This organization has no credit policies. Credits cannot be calculated
        until one exists and is set as the organization default or named by a
        shift.
      </p>

      <ul v-else class="credit-policies__list">
        <li v-for="policy in activePolicies" :key="policy.id">
          <form
            v-if="editingId === policy.id"
            class="credit-policies__edit"
            :aria-label="`Edit ${policy.name}`"
            @submit.prevent="onSaveEdit(policy)"
          >
            <input
              v-model="editForm.name"
              type="text"
              maxlength="100"
              autocomplete="off"
              :aria-label="`New name for ${policy.name}`"
            />
            <input
              v-model="editForm.multiplier"
              type="number"
              min="0.001"
              max="1000"
              step="0.001"
              autocomplete="off"
              :aria-label="`Credits per hour for ${policy.name}`"
            />
            <button type="submit" :disabled="busyId === policy.id">Save</button>
            <button type="button" @click="cancelEdit">Cancel</button>
          </form>

          <template v-else>
            <span class="credit-policies__name">{{ policy.name }}</span>
            <span class="credit-policies__rate">{{ rateText(policy) }}</span>
            <span class="credit-policies__usage">{{ usageText(policy) }}</span>
            <button
              type="button"
              :disabled="!editable || busyId === policy.id"
              @click="startEdit(policy)"
            >
              Edit
            </button>
            <button
              type="button"
              :disabled="!editable || busyId === policy.id"
              :aria-label="`Archive ${policy.name}`"
              @click="onArchive(policy)"
            >
              Archive
            </button>
          </template>
        </li>
      </ul>

      <!--
        Archiving withdraws a policy from future selection without repricing
        anything: a shift that names it keeps it, and frozen ledger entries
        carry their own copy of the rate.
      -->
      <div v-if="archivedPolicies.length > 0" class="credit-policies__archived">
        <h3>Archived</h3>
        <p class="credit-policies__note">
          Archived policies keep governing the shifts that already name them
          and are no longer offered for new choices.
        </p>

        <ul class="credit-policies__list">
          <li v-for="policy in archivedPolicies" :key="policy.id">
            <span class="credit-policies__name">{{ policy.name }}</span>
            <span class="credit-policies__rate">{{ rateText(policy) }}</span>
            <span class="credit-policies__usage">{{ usageText(policy) }}</span>
            <button
              type="button"
              :disabled="!editable || busyId === policy.id"
              :aria-label="`Restore ${policy.name}`"
              @click="onRestore(policy)"
            >
              Restore
            </button>
          </li>
        </ul>
      </div>

      <!--
        The calculation runs (CREDIT-001). The scheduler performs the same run
        daily once an event settles; the button is for the organizer who wants
        the ledger now and by name.
      -->
      <div v-if="events.length > 0" class="credit-policies__runs">
        <h3>Event credit runs</h3>
        <p class="credit-policies__note">
          Credits are calculated from finalized hours once the correction grace
          period closes, and freeze as they are written. The nightly schedule
          performs the same run.
        </p>

        <ul class="credit-policies__list">
          <li v-for="event in events" :key="event.id">
            <span class="credit-policies__name">{{ event.name }}</span>
            <span class="credit-policies__usage">{{
              eventStatusText(event)
            }}</span>
            <button
              v-if="event.canCalculate && event.uncreditedHoursCount > 0"
              type="button"
              :disabled="busyId === event.id"
              :aria-label="`Calculate credits for ${event.name}`"
              @click="onCalculate(event)"
            >
              Calculate credits
            </button>
          </li>
        </ul>
      </div>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.credit-policies__archived h3,
.credit-policies__runs h3 {
  margin: var(--m-space-4) 0 var(--m-space-2);
  font-size: var(--m-text-sm);
  text-transform: uppercase;
  color: var(--m-text-secondary);
}

.credit-policies__list {
  display: grid;
  gap: var(--m-space-2);
  margin: 0;
  padding: 0;
  list-style: none;
}

.credit-policies__list li {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  align-items: center;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
}

.credit-policies__name {
  flex: 1 1 12rem;
  font-weight: 700;
}

.credit-policies__rate {
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
}

.credit-policies__usage {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.credit-policies__edit {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-2);
  align-items: center;
  width: 100%;
}

.credit-policies__edit input[type="text"] {
  flex: 1 1 12rem;
}

.credit-policies__edit input[type="number"] {
  flex: 0 1 8rem;
}

.credit-policies__frozen {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-muted);
}

.credit-policies__empty,
.credit-policies__note {
  margin: 0;
  color: var(--m-text-muted);
}

.credit-policies__notice {
  margin: 0;
  color: var(--m-text-muted);
  font-weight: 700;
}

.credit-policies__error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}
</style>
