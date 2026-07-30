<script setup lang="ts">
import { computed } from "vue";
import { RouterLink } from "vue-router";

import {
  fieldReportCatalogRevision,
  pendingFieldReportQueue,
} from "@/field-reports/fieldReportRuntime";

/*
 * `kiosk.safe-timeout` — the surface a timed-out session lands on (UI
 * implementation contract 12.8; M16.9; technical spec 13.3; kiosk guide 4.4).
 *
 * "Safe" is the whole requirement: a workstation left alone must not be showing
 * anybody's records when somebody else walks up. So this screen holds no name, no
 * event, no record, and no fragment of what was on screen when the timeout
 * landed. It is where the shell goes when the session data is wiped.
 *
 * What it does say is what happened and what survived it. A workstation that
 * silently returns to a login screen leaves the person who queued a check-in two
 * minutes ago with no way to know whether the machine kept it, and the honest
 * answer — it did — is worth stating (kiosk guide 4.4).
 */

const queuedCount = computed(() => {
  void fieldReportCatalogRevision.value;

  return pendingFieldReportQueue.size;
});
</script>

<template>
  <section class="safe-timeout" aria-labelledby="safe-timeout-heading">
    <h1 id="safe-timeout-heading" class="safe-timeout__heading">Session timed out</h1>
    <p class="safe-timeout__lede">
      This workstation signed out after 5 minutes without activity. Session data
      has been cleared and anything not yet saved was abandoned.
    </p>

    <p v-if="queuedCount > 0" class="safe-timeout__queued" role="status">
      {{ queuedCount === 1 ? "1 queued report is" : `${queuedCount} queued reports are` }}
      still on this workstation and will sync when the node is reachable.
    </p>

    <RouterLink class="safe-timeout__sign-in" :to="{ name: 'kiosk.workstation-login' }">
      Sign in
    </RouterLink>
  </section>
</template>

<style scoped>
.safe-timeout {
  width: var(--m-content-narrow);
}

.safe-timeout__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.safe-timeout__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.safe-timeout__queued {
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-3) var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-neutral);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
}

.safe-timeout__sign-in {
  display: inline-flex;
  align-items: center;
  min-height: 3.5rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-action-primary-bg, var(--m-surface-raised));
  color: var(--m-action-primary-fg, var(--m-text-primary));
  font-size: var(--m-text-lg);
  font-weight: 900;
  text-decoration: none;
}

.safe-timeout__sign-in:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
