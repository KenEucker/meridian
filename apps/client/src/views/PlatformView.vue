<script setup lang="ts">
import { useRouter } from "vue-router";

import MarketingView from "@/views/MarketingView.vue";

/**
 * `/platform` — the marketing surface at an address of its own (M18.23,
 * M20; PUBLIC-001).
 *
 * The root route wraps `MarketingView` in `RootView`, which decides between
 * the marketing surface and the home directory and handles the node that
 * serves neither. This route mounted the view bare, which left its
 * `unavailable` emit unheard — and a visitor at `/platform` on a node that
 * does not serve the surface (PUBLIC-006) got a blank page, the one thing an
 * absent surface may never render as.
 *
 * Sent to the root rather than to sign-in directly, because the root already
 * knows what to do with every kind of visitor: a session holder gets their
 * home directory, and a visitor holding nothing on a node without the surface
 * goes to sign in.
 */
const router = useRouter();

function unavailable(): void {
  void router.replace({ name: "home" });
}
</script>

<template>
  <MarketingView @unavailable="unavailable" />
</template>
