<script setup lang="ts">
import { computed } from "vue";
import { useRoute } from "vue-router";

import WorkflowPageShell from "@/components/WorkflowPageShell.vue";
import DocumentLibrarySection from "@/components/sections/DocumentLibrarySection.vue";
import { resolveDocumentAuthoringSession } from "@/documents/documentAuthoringModel";

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
</script>

<template>
  <WorkflowPageShell
    heading-id="documents-heading"
    title="Documents"
    :eyebrow="
      surface === 'organizer'
        ? 'Organizer administration'
        : session.department.departmentLabel
    "
    :lede="
      surface === 'organizer'
        ? `${session.organizationLabel} policy, procedure, and fragment authoring.`
        : `${session.department.roleLabel} document library and maintainer workspace.`
    "
  >
    <DocumentLibrarySection variant="page" />
  </WorkflowPageShell>
</template>
