<script setup lang="ts">
import { computed } from "vue";
import { useRouter } from "vue-router";

import { holdsMeridianCredential } from "@/api/meridianApi";
import { clientSessionState } from "@/session/clientSession";
import HomeView from "@/views/HomeView.vue";
import MarketingView from "@/views/MarketingView.vue";

/**
 * The deployment root (M18.23; PUBLIC-001).
 *
 * One address, two answers. A client holding nothing gets the marketing
 * surface — the page PUBLIC-001 puts at the deployment root for "organizations
 * that do not yet use it" — and a client holding a session gets the home
 * directory it came for.
 *
 * That split is the requirement read literally. The person who signed in this
 * morning to run a shift is not the audience PUBLIC-001 describes, and sending
 * them to a page about what Meridian is would be answering a question they
 * stopped asking when their organization adopted it.
 *
 * A client still resolving its first session is shown neither. At that moment
 * "holds nothing" is not a fact about the client, it is a fact about how far the
 * boot has got, and rendering a marketing page at somebody mid-boot would be
 * acting on it. This mirrors the same care in `requiresSignIn`.
 *
 * A node that does not serve the marketing surface at all — on-site, or locked
 * to an event (PUBLIC-006) — has nothing to show a client holding nothing, so
 * the old behaviour stands: it goes to sign in.
 */
const router = useRouter();

const resolving = computed(() => clientSessionState.refreshing);

const holdsNothing = computed(
  () => !holdsMeridianCredential() && clientSessionState.document === null,
);

function signIn(): void {
  void router.replace({ name: "login" });
}
</script>

<template>
  <template v-if="resolving" />
  <MarketingView v-else-if="holdsNothing" @unavailable="signIn" />
  <HomeView v-else />
</template>
