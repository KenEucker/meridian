<script setup lang="ts">
import { computed, reactive, ref, watch } from "vue";

import { meridianErrorMessage } from "@/api/meridianApi";
import ControlField from "@/components/ControlField.vue";
import WorkflowSection from "@/components/sections/WorkflowSection.vue";
import {
  getOrganizationConfiguration,
  updateOrganizationConfiguration,
  type OrganizationConfiguration,
} from "@/organizer-configuration/organizationConfigurationModel";

/**
 * The organization's operational settings (M18.14; ORG-017, ORG-018, ORG-020,
 * ORG-021).
 *
 * One featureset on the organization configuration page, carrying the values
 * ORG-018 lists: the Prospective and Active inactivity thresholds the M18.15
 * evaluator applies, the ORG-017 hours correction grace period, the calendar
 * year start, the default credit policy, and the Organizers, default Incident
 * Command, and default Placement department designations.
 *
 * The form is disabled — not hidden — while governance blocks edits, with the
 * node's reason on screen: configuration is frozen during an active event
 * window, and owned by central rather than an on-site node (ORG-021). An
 * organizer who cannot save should read why before typing, not after.
 */
const props = withDefaults(
  defineProps<{
    organizationId: string | null;
    variant?: "page" | "section";
  }>(),
  { variant: "section" },
);

const MONTH_NAMES = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
];

const configuration = ref<OrganizationConfiguration | null>(null);
const loadError = ref<string | null>(null);
const actionError = ref<string | null>(null);
const saved = ref(false);
const busy = ref(false);

/*
 * Form state as strings, because every control is a text/select input and ""
 * is how a cleared optional value reads. The save converts back to what the
 * command expects: numbers, ids, and null for "".
 */
const form = reactive({
  prospectiveYears: "",
  activeYears: "",
  gracePeriodDays: "14",
  eventHorizonLeadDays: "30",
  calendarMonth: "",
  calendarDay: "",
  defaultCreditPolicyId: "",
  organizersDepartmentId: "",
  defaultIcDepartmentId: "",
  defaultPlacementDepartmentId: "",
  handleChangePolicy: "organizer_only",
  profilePictureChangePolicy: "organizer_only",
  handleChangeLimit: "2",
});

const governance = computed(() => configuration.value?.governance ?? null);
const editable = computed(() => governance.value?.editable ?? false);
const departments = computed(() => configuration.value?.departments ?? []);
const creditPolicies = computed(() => configuration.value?.creditPolicies ?? []);
/*
 * The four staff profile approval policies (VOL-027), as the node named and
 * described them. An empty list means an older node that does not carry the
 * setting, and the two selects below are absent rather than offering choices
 * it would refuse.
 */
const changePolicies = computed(() => configuration.value?.changePolicies ?? []);

/**
 * What the selected policy means, in the node's own words, beside the choice
 * that produces it. Selecting is the moment somebody needs the explanation;
 * describing all four at once would be a wall of text describing three rules
 * nobody chose.
 */
const handlePolicyNote = computed(
  () =>
    changePolicies.value.find(
      (policy) => policy.value === form.handleChangePolicy,
    )?.handleDescription ?? "",
);
const picturePolicyNote = computed(
  () =>
    changePolicies.value.find(
      (policy) => policy.value === form.profilePictureChangePolicy,
    )?.pictureDescription ?? "",
);

/** The node's sentence for why the form is read-only right now, or null. */
const frozenReason = computed(() => {
  const state = governance.value;

  if (state === null || state.editable) {
    return null;
  }

  if (!state.holdsAuthority) {
    return "Organization configuration is maintained on the central node, and this node does not hold configuration authority.";
  }

  if (state.frozenByEvent !== null) {
    return `Configuration is frozen while ${state.frozenByEvent.name} is inside its active event window, under the same rules that freeze policies and branding.`;
  }

  return "Configuration cannot be edited right now.";
});

watch(() => props.organizationId, () => void loadConfiguration(), {
  immediate: true,
});

async function loadConfiguration(): Promise<void> {
  if (props.organizationId === null) {
    configuration.value = null;

    return;
  }

  loadError.value = null;

  try {
    configuration.value = await getOrganizationConfiguration(
      props.organizationId,
    );
    fillForm(configuration.value);
  } catch (error) {
    configuration.value = null;
    loadError.value = meridianErrorMessage(
      error,
      "Unable to load organization configuration. Check the connection to this node and try again.",
    );
  }
}

function fillForm(current: OrganizationConfiguration): void {
  const values = current.values;

  form.prospectiveYears = values.prospectiveInactiveThresholdYears?.toString() ?? "";
  form.activeYears = values.activeInactiveThresholdYears?.toString() ?? "";
  form.gracePeriodDays = values.hoursCorrectionGracePeriodDays.toString();
  form.eventHorizonLeadDays = values.eventHorizonLeadDays.toString();
  form.calendarMonth = values.calendarYearStartMonth?.toString() ?? "";
  form.calendarDay = values.calendarYearStartDay?.toString() ?? "";
  form.defaultCreditPolicyId = values.defaultCreditPolicyId ?? "";
  form.organizersDepartmentId = values.organizersDepartmentId ?? "";
  form.defaultIcDepartmentId = values.defaultIcDepartmentId ?? "";
  form.defaultPlacementDepartmentId = values.defaultPlacementDepartmentId ?? "";
  form.handleChangePolicy = values.handleChangePolicy;
  form.profilePictureChangePolicy = values.profilePictureChangePolicy;
  form.handleChangeLimit = values.handleSelfServiceChangeLimit.toString();
}

/*
 * A number input's v-model hands back a number once the browser has parsed
 * one, and a string while the field is empty or being typed in, so both
 * arrive here.
 */
function numberOrNull(value: string | number): number | null {
  if (typeof value === "number") {
    return Number.isNaN(value) ? null : value;
  }

  return value.trim() === "" ? null : Number(value);
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

  try {
    configuration.value = await updateOrganizationConfiguration(organizationId, {
      prospective_inactive_threshold_years: numberOrNull(form.prospectiveYears),
      active_inactive_threshold_years: numberOrNull(form.activeYears),
      hours_correction_grace_period_days: Number(form.gracePeriodDays),
      event_horizon_lead_days: Number(form.eventHorizonLeadDays),
      calendar_year_start_month: numberOrNull(form.calendarMonth),
      calendar_year_start_day: numberOrNull(form.calendarDay),
      default_credit_policy_id: form.defaultCreditPolicyId || null,
      organizers_department_id: form.organizersDepartmentId || null,
      default_ic_department_id: form.defaultIcDepartmentId || null,
      default_placement_department_id: form.defaultPlacementDepartmentId || null,
      handle_change_policy: form.handleChangePolicy,
      profile_picture_change_policy: form.profilePictureChangePolicy,
      handle_self_service_change_limit: numberOrNull(form.handleChangeLimit),
    });
    fillForm(configuration.value);
    saved.value = true;
  } catch (error) {
    actionError.value = meridianErrorMessage(
      error,
      "Unable to save organization configuration.",
    );
  } finally {
    busy.value = false;
  }
}
</script>

<template>
  <WorkflowSection
    class="org-configuration-settings"
    title="Operational settings"
    heading-id="org-configuration-settings-heading"
    description="The values that govern staff lifecycle and operational timing: inactivity thresholds, the hours correction grace period, the calendar year, the default credit policy, and the organization's department designations."
    :variant="props.variant"
  >
    <p v-if="loadError" class="org-configuration-settings__error" role="alert">
      {{ loadError }}
    </p>

    <p
      v-else-if="configuration === null"
      class="org-configuration-settings__empty"
      role="status"
    >
      Loading organization configuration.
    </p>

    <template v-else>
      <p
        v-if="frozenReason"
        class="org-configuration-settings__frozen"
        role="status"
      >
        {{ frozenReason }}
      </p>

      <form
        class="org-configuration-settings__form"
        aria-label="Organization operational settings"
        @submit.prevent="onSave"
      >
        <fieldset class="org-configuration-settings__group">
          <legend>Staff lifecycle</legend>
          <div class="org-configuration-settings__fields">
            <ControlField
              label="Prospective inactivity threshold (years)"
              control-id="config-prospective-years"
            >
              <input
                id="config-prospective-years"
                v-model="form.prospectiveYears"
                type="number"
                min="1"
                max="100"
                :disabled="!editable"
                placeholder="Not set"
              />
            </ControlField>
            <ControlField
              label="Active inactivity threshold (years)"
              control-id="config-active-years"
            >
              <input
                id="config-active-years"
                v-model="form.activeYears"
                type="number"
                min="1"
                max="100"
                :disabled="!editable"
                placeholder="Not set"
              />
            </ControlField>
          </div>
          <p class="org-configuration-settings__note">
            Prospective staff past the threshold become Inactive and must
            reapply; Active staff become Inactive after that long without
            recorded work. Leave a threshold blank and it is not applied.
          </p>
        </fieldset>

        <fieldset class="org-configuration-settings__group">
          <legend>Operational timing</legend>
          <div class="org-configuration-settings__fields">
            <ControlField
              label="Hours correction grace period (days after event end)"
              control-id="config-grace-days"
            >
              <input
                id="config-grace-days"
                v-model="form.gracePeriodDays"
                type="number"
                min="0"
                max="365"
                required
                :disabled="!editable"
              />
            </ControlField>
            <ControlField
              label="Event Horizon lead-up window (days before the event)"
              control-id="config-horizon-lead-days"
            >
              <input
                id="config-horizon-lead-days"
                v-model="form.eventHorizonLeadDays"
                type="number"
                min="0"
                max="365"
                required
                :disabled="!editable"
              />
            </ControlField>
            <ControlField
              label="Calendar year start month"
              control-id="config-calendar-month"
            >
              <select
                id="config-calendar-month"
                v-model="form.calendarMonth"
                :disabled="!editable"
              >
                <option value="">Not set</option>
                <option
                  v-for="(name, index) in MONTH_NAMES"
                  :key="name"
                  :value="(index + 1).toString()"
                >
                  {{ name }}
                </option>
              </select>
            </ControlField>
            <ControlField
              label="Calendar year start day"
              control-id="config-calendar-day"
            >
              <input
                id="config-calendar-day"
                v-model="form.calendarDay"
                type="number"
                min="1"
                max="31"
                :disabled="!editable"
                placeholder="Not set"
              />
            </ControlField>
          </div>
          <p class="org-configuration-settings__note">
            Hours can be corrected until the grace period closes, and freeze
            after it. The Event Horizon opens for staff that many days before
            an event's active window starts, and 30 is the documented default.
            The calendar year start takes a month and a day together, or
            neither.
          </p>
        </fieldset>

        <fieldset class="org-configuration-settings__group">
          <legend>Credits</legend>
          <div class="org-configuration-settings__fields">
            <ControlField
              label="Default credit policy"
              control-id="config-credit-policy"
            >
              <select
                id="config-credit-policy"
                v-model="form.defaultCreditPolicyId"
                :disabled="!editable"
              >
                <option value="">No default</option>
                <option
                  v-for="policy in creditPolicies"
                  :key="policy.id"
                  :value="policy.id"
                >
                  {{ policy.name }}
                </option>
              </select>
            </ControlField>
          </div>
          <p class="org-configuration-settings__note">
            Applied to any shift that does not name its own credit policy.
          </p>
        </fieldset>

        <fieldset class="org-configuration-settings__group">
          <legend>Department designations</legend>
          <div class="org-configuration-settings__fields">
            <ControlField
              label="Organizers Department"
              control-id="config-organizers-department"
            >
              <select
                id="config-organizers-department"
                v-model="form.organizersDepartmentId"
                :disabled="!editable"
              >
                <option value="">Not designated</option>
                <option
                  v-for="department in departments"
                  :key="department.id"
                  :value="department.id"
                >
                  {{ department.name }}
                </option>
              </select>
            </ControlField>
            <ControlField
              label="Default Incident Command department"
              control-id="config-ic-department"
            >
              <select
                id="config-ic-department"
                v-model="form.defaultIcDepartmentId"
                :disabled="!editable"
              >
                <option value="">Not designated</option>
                <option
                  v-for="department in departments"
                  :key="department.id"
                  :value="department.id"
                >
                  {{ department.name }}
                </option>
              </select>
            </ControlField>
            <ControlField
              label="Default Placement department"
              control-id="config-placement-department"
            >
              <select
                id="config-placement-department"
                v-model="form.defaultPlacementDepartmentId"
                :disabled="!editable"
              >
                <option value="">Not designated</option>
                <option
                  v-for="department in departments"
                  :key="department.id"
                  :value="department.id"
                >
                  {{ department.name }}
                </option>
              </select>
            </ControlField>
          </div>
          <p class="org-configuration-settings__note">
            The Organizers Department is where organizer authority resolves;
            the default Incident Command and Placement departments seed new
            events with those functions.
          </p>
        </fieldset>

        <!--
          Staff profile approval (VOL-027, VOL-028). Handles and pictures are
          set separately because they are different kinds of fact about a
          person: a handle is spoken on a radio and has to be unambiguous, a
          picture is how a desk recognises somebody.

          The choices and the words describing them come from the node, so the
          option an organizer reads is the rule the server will apply. A node
          that carries no policies is an older one, and this whole featureset
          is absent rather than offering settings it would refuse.
        -->
        <fieldset
          v-if="changePolicies.length > 0"
          class="org-configuration-settings__group"
        >
          <legend>Staff profile approval</legend>
          <div class="org-configuration-settings__fields">
            <ControlField
              label="Handle changes"
              control-id="config-handle-policy"
            >
              <select
                id="config-handle-policy"
                v-model="form.handleChangePolicy"
                :disabled="!editable"
              >
                <option
                  v-for="policy in changePolicies"
                  :key="policy.value"
                  :value="policy.value"
                >
                  {{ policy.label }}
                </option>
              </select>
            </ControlField>
            <ControlField
              label="Handle changes applied without review"
              control-id="config-handle-limit"
            >
              <input
                id="config-handle-limit"
                v-model="form.handleChangeLimit"
                type="number"
                min="0"
                max="50"
                :disabled="!editable"
              />
            </ControlField>
            <ControlField
              label="Profile pictures"
              control-id="config-picture-policy"
            >
              <select
                id="config-picture-policy"
                v-model="form.profilePictureChangePolicy"
                :disabled="!editable"
              >
                <option
                  v-for="policy in changePolicies"
                  :key="policy.value"
                  :value="policy.value"
                >
                  {{ policy.label }}
                </option>
              </select>
            </ControlField>
          </div>
          <p class="org-configuration-settings__note">
            {{ handlePolicyNote }}
          </p>
          <p class="org-configuration-settings__note">
            {{ picturePolicyNote }}
          </p>
          <p class="org-configuration-settings__note">
            The allowance applies only while handle changes are applied without
            review, and 0 switches it off without changing the policy. Pictures
            applied without review are not rationed.
          </p>
        </fieldset>

        <div class="org-configuration-settings__actions">
          <button type="submit" :disabled="busy || !editable">
            Save configuration
          </button>
          <span
            v-if="saved"
            class="org-configuration-settings__saved"
            role="status"
          >
            Configuration saved.
          </span>
        </div>

        <p
          v-if="actionError"
          class="org-configuration-settings__error"
          role="alert"
        >
          {{ actionError }}
        </p>
      </form>
    </template>
  </WorkflowSection>
</template>

<style scoped>
.org-configuration-settings__form {
  display: grid;
  gap: var(--m-space-4);
}

.org-configuration-settings__group {
  display: grid;
  gap: var(--m-space-3);
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
}

.org-configuration-settings__group legend {
  padding: 0 var(--m-space-1);
  font-weight: 700;
}

.org-configuration-settings__fields {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  align-items: flex-end;
}

.org-configuration-settings__actions {
  display: flex;
  gap: var(--m-space-3);
  align-items: center;
}

.org-configuration-settings__frozen {
  margin: 0;
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  color: var(--m-text-muted);
}

.org-configuration-settings__saved {
  color: var(--m-text-muted);
}

.org-configuration-settings__empty,
.org-configuration-settings__note {
  margin: 0;
  color: var(--m-text-muted);
}

.org-configuration-settings__error {
  margin: 0;
  color: var(--m-attention-critical);
  font-size: var(--m-text-sm);
  font-weight: 700;
}
</style>
