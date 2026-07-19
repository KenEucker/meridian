<script setup lang="ts">
import { appConfigForUiMode } from "@/app/appConfig";
import AppShell from "@/components/AppShell.vue";

const config = appConfigForUiMode("admin");
</script>

<template>
  <AppShell :config="config">
    <slot />
  </AppShell>
</template>
