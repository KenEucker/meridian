<script setup lang="ts">
import { RouterLink } from "vue-router";

import DocumentAcknowledgmentSection from "@/components/sections/DocumentAcknowledgmentSection.vue";
import StaffPageShell from "@/components/StaffPageShell.vue";

/**
 * `signup.policy-acknowledgment` — the acknowledgments staff signup asks for
 * (M18.6; POL-024, POL-046; UI contract 12.1).
 *
 * The same read as the staff ledger, narrowed to the signup context and to what
 * is still outstanding, and the documents are open rather than collapsed. It is
 * a step somebody is walking through: a list that also recites what they already
 * accepted makes the remaining work harder to find, and text behind a disclosure
 * control is text somebody can accept without opening.
 *
 * Connected, which is what UI contract 12.1 says of this screen. Acceptance
 * records the version that was read (POL-043), so there is nothing honest to
 * hold on a device — and the section refuses rather than queues.
 *
 * Not a gate on anything else. POL-024 puts acknowledgment *in* signup;
 * POL-026 and POL-027 keep it out of shift signup and credential eligibility,
 * and the section prints that in the node's own words.
 */
</script>

<template>
  <StaffPageShell
    heading-id="signup-acknowledgment-heading"
    title="Before you start"
    eyebrow="Staff signup"
    lede="Read each document and confirm you have. Your acknowledgment records the version you read."
  >
    <DocumentAcknowledgmentSection
      context="signup"
      variant="page"
      heading-id="signup-acknowledgment-section"
    />

    <p class="signup-ack__next">
      <RouterLink :to="{ name: 'staff.documents.acknowledgments' }">
        See everything you have acknowledged
      </RouterLink>
    </p>
  </StaffPageShell>
</template>

<style scoped>
.signup-ack__next {
  margin: 0;
}
</style>
