<script setup lang="ts">
import { onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";

import { appConfigForUiMode } from "@/app/appConfig";
import AppShell from "@/components/AppShell.vue";
import KioskSessionBar from "@/components/KioskSessionBar.vue";
import { kioskContextPinned, resolveKioskContext } from "@/session/kioskContext";

/*
 * The session bar sits here rather than inside `AppShell`, above the routed
 * surface and outside anything a surface can collapse or scroll away. "The active
 * user is shown prominently at all times" (technical spec 13.3) is not a property
 * a screen can be trusted to preserve, and only Kiosk has a shared-workstation
 * session to show.
 *
 * The shell is also where the Kiosk asks the node what this machine is pinned to
 * (M18.32; UI-019, UI-020). It happens here because it is a property of the
 * application rather than of any screen, and because the answer decides which
 * screens are reachable at all: a machine with no pinned organization and event
 * is in setup, and the router sends every other Kiosk route there until the node
 * says otherwise.
 *
 * The move off setup is a watch rather than a redirect inside the guard. Nothing
 * is resolved at the first navigation — the node has not answered yet — so a
 * guard that waited would be a guard that blocked the boot, and one that guessed
 * would be the inference UI-020 forbids. A machine that was pinned last time is
 * already pinned at boot from what the node last said about it, so this only
 * moves the machine that was genuinely in setup and has just left it.
 */

const config = appConfigForUiMode("kiosk");

const router = useRouter();
const route = useRoute();

onMounted(() => {
  void resolveKioskContext();
});

watch(kioskContextPinned, async (pinned) => {
  if (pinned && route.name === "kiosk.setup") {
    await router.push({ name: "kiosk.home" });
  }
});
</script>

<template>
  <AppShell :config="config">
    <KioskSessionBar />
    <slot />
  </AppShell>
</template>
