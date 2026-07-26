<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";

import StaffPageShell from "@/components/StaffPageShell.vue";
import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import DocumentLibrarySection from "@/components/sections/DocumentLibrarySection.vue";
import {
  canMaintainDocuments,
  resolveDocumentAuthoringSession,
} from "@/documents/documentAuthoringModel";

const route = useRoute();
const surface = computed(() =>
  String(route.name ?? "").startsWith("organizer.")
    ? ("organizer" as const)
    : ("department" as const),
);
const session = computed(() =>
  resolveDocumentAuthoringSession(
    surface.value,
    typeof route.params.departmentId === "string"
      ? route.params.departmentId
      : null,
  ),
);
// Maintainers get the wide workflow shell because authoring needs the table and
// its lifecycle columns. Readers get the narrow, touch-first staff shell: they
// are here to read a policy, usually on a phone.
const isMaintainer = computed(() => canMaintainDocuments(session.value));
const eyebrow = computed(() =>
  surface.value === "organizer"
    ? "Organizer administration"
    : session.value.department.departmentLabel,
);
const lede = computed(() =>
  isMaintainer.value
    ? surface.value === "organizer"
      ? `${session.value.organizationLabel} policy, procedure, and fragment authoring.`
      : `${session.value.department.roleLabel} document library and maintainer workspace.`
    : "Policies and procedures published to you.",
);
</script>

<template>
  <StaffPageShell
    v-if="!isMaintainer"
    heading-id="documents-heading"
    title="Documents"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <DocumentLibrarySection variant="page" />
  </StaffPageShell>

  <WorkflowPageShell
    v-else
    heading-id="documents-heading"
    title="Documents"
    :eyebrow="eyebrow"
    :lede="lede"
  >
    <DocumentLibrarySection variant="page" />
  </WorkflowPageShell>
</template>
