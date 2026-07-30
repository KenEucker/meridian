<script setup lang="ts">
import { computed } from "vue";

import { workstationSessionState } from "@/session/workstationSession";

/*
 * `kiosk.home` — trusted workstation dashboard (UI implementation contract 12.8).
 *
 * A shell in M16.9. The kiosk dashboard widgets — current tasks, staff-mediated
 * check-in, equipment returns, node status, event map (UI contract 12.10) — are
 * their own tasks, and building placeholders for them here would put unmet
 * promises on an operational screen.
 *
 * What it does carry now is the frame technical spec 13.3 requires: who is signed
 * in and which workstation this is. The workstation is stated because pinned
 * context frames the shell, and stated as context only — it grants the signed-in
 * user nothing.
 */

const user = computed(() => workstationSessionState.user);
const workstation = computed(() => workstationSessionState.workstation);
</script>

<template>
  <section class="kiosk-home" aria-labelledby="kiosk-home-heading">
    <h1 id="kiosk-home-heading" class="kiosk-home__heading">
      {{ user ? `Signed in as ${user.name}` : "Workstation" }}
    </h1>
    <p v-if="workstation" class="kiosk-home__workstation">
      {{ workstation.name }}
    </p>
    <p class="kiosk-home__empty">
      This workstation's operational surfaces are not built yet. Your session ends
      after 5 minutes without activity, or when you end it.
    </p>
  </section>
</template>

<style scoped>
.kiosk-home {
  width: var(--m-content-narrow);
}

.kiosk-home__heading {
  margin: 0 0 var(--m-space-1);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.kiosk-home__workstation {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-transform: uppercase;
}

.kiosk-home__empty {
  margin: 0;
  color: var(--m-text-muted);
}
</style>
