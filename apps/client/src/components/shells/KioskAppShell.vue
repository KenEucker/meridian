<script setup lang="ts">
import { appConfigForUiMode } from "@/app/appConfig";
import AppShell from "@/components/AppShell.vue";
import KioskSessionBar from "@/components/KioskSessionBar.vue";

/*
 * The session bar sits here rather than inside `AppShell`, above the routed
 * surface and outside anything a surface can collapse or scroll away. "The active
 * user is shown prominently at all times" (technical spec 13.3) is not a property
 * a screen can be trusted to preserve, and only Kiosk has a shared-workstation
 * session to show.
 */

const config = appConfigForUiMode("kiosk");
</script>

<template>
  <AppShell :config="config">
    <KioskSessionBar />
    <slot />
  </AppShell>
</template>
