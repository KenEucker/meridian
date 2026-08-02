<script setup lang="ts">
import DocumentAcknowledgmentSection from "@/components/sections/DocumentAcknowledgmentSection.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";

/**
 * `staff.document-acknowledgments` — what this person has been asked to read,
 * and what they have said they read (M18.6; POL-023 through POL-027, POL-043
 * through POL-047; UI contract 12.3).
 *
 * A ledger rather than a task list. Every requirement is here whether or not it
 * has been answered, because the reason to open it after signup is to check what
 * you accepted and when — and the version you accepted is the part POL-043 makes
 * worth keeping.
 *
 * No capability gates it. Being asked to acknowledge something is a fact about
 * a person, not a permission somebody grants them, so the page renders for any
 * signed-in staff member and says plainly when nobody has asked them anything.
 */
</script>

<template>
  <StaffPageShell
    heading-id="staff-acknowledgments-heading"
    title="Acknowledgments"
    eyebrow="Your record"
    lede="Policies and procedures you have been asked to read, and the version you accepted."
  >
    <DocumentAcknowledgmentSection
      context="all"
      variant="page"
      heading-id="staff-acknowledgments-section"
    />
  </StaffPageShell>
</template>
