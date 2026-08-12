<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import CreditPolicySection from "@/components/sections/CreditPolicySection.vue";
import IncidentTypeSection from "@/components/sections/IncidentTypeSection.vue";
import OrganizationConfigurationSection from "@/components/sections/OrganizationConfigurationSection.vue";
import OrganizationDesignationSection from "@/components/sections/OrganizationDesignationSection.vue";
import OrganizationModuleSection from "@/components/sections/OrganizationModuleSection.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import {
  organizerConfigurationAdminSession,
  organizerCreditPolicyAdminSession,
  organizerDesignationAdminSession,
  organizerIncidentTypeAdminSession,
} from "@/session/organizerAdminSession";

/**
 * `organizer.configuration` — the organization configuration surface (M18.14A;
 * ORG-018, ORG-020).
 *
 * ORG-018 asks for one place where an organization sets the values that govern
 * how it operates, and says that place may not be reachable only through God
 * Mode. This is that page. It carries five featuresets: the operational
 * settings (M18.14) — the Prospective and Active inactivity thresholds, the
 * hours correction grace period, the calendar year start, the default credit
 * policy, and the Organizers, default Incident Command, and default Placement
 * department designations — the modules the organization runs (M19.15;
 * MOD-008), and the credit policies and their calculation runs (M18.16), the
 * incident type list (M18.14A), and the organization's team designations
 * (M18.12; TEAM-014, TEAM-016).
 *
 * Each featureset carries its own authority rather than inheriting one from the
 * page. `organization.configuration.manage` admits somebody to the settings and
 * to the modules — ORG-018 names both as that surface's values and data/API 6.8
 * gives them the same capability — `organization.credit_policies.manage` to the
 * credit policies, `organization.incident_types.manage` to the incident type
 * list, and `organization.designations.manage` to the designations; a person who
 * may set one of them is not thereby entitled to the rest.
 */
const settings = organizerConfigurationAdminSession;
const creditPolicies = organizerCreditPolicyAdminSession;
const incidentTypes = organizerIncidentTypeAdminSession;
const designations = organizerDesignationAdminSession;
const organizationLabel = computed(
  () =>
    settings.value?.organizationLabel ??
    creditPolicies.value?.organizationLabel ??
    incidentTypes.value?.organizationLabel ??
    designations.value?.organizationLabel ??
    null,
);

/** Whether any featureset on this page admits this user. */
const canConfigureSomething = computed(
  () =>
    settings.value !== null ||
    creditPolicies.value !== null ||
    incidentTypes.value !== null ||
    designations.value !== null,
);
</script>

<template>
  <WorkflowPageShell
    class="org-configuration"
    heading-id="org-configuration-heading"
    title="Configuration"
    eyebrow="Organizer administration"
    :lede="
      organizationLabel
        ? `Operational settings for ${organizationLabel}.`
        : 'Operational settings for this organization.'
    "
  >
    <div
      v-if="!canConfigureSomething"
      class="org-configuration__restricted"
      role="status"
    >
      Organizer access is required to configure this organization.
      <RouterLink :to="{ name: 'home' }">Return home</RouterLink>
    </div>

    <template v-else>
      <OrganizationConfigurationSection
        v-if="settings"
        variant="section"
        :organization-id="settings.organizationId"
      />

      <OrganizationModuleSection
        v-if="settings"
        variant="section"
        :organization-id="settings.organizationId"
      />

      <CreditPolicySection
        v-if="creditPolicies"
        variant="section"
        :organization-id="creditPolicies.organizationId"
      />

      <IncidentTypeSection
        v-if="incidentTypes"
        variant="section"
        :organization-id="incidentTypes.organizationId"
      />

      <OrganizationDesignationSection
        v-if="designations"
        variant="section"
        :organization-id="designations.organizationId"
      />
    </template>
  </WorkflowPageShell>
</template>

<style scoped>
.org-configuration {
  width: 100%;
  display: grid;
  gap: var(--m-space-5);
}

.org-configuration__restricted {
  display: grid;
  gap: var(--m-space-2);
  justify-items: start;
  color: var(--m-text-muted);
}

.org-configuration__restricted a {
  color: var(--m-action-secondary-bg);
  font-weight: 700;
}
</style>
