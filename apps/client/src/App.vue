<script setup lang="ts">
import { computed } from "vue";

import { meridianAppConfig } from "@/app/appConfig";
import AdminAppShell from "@/components/shells/AdminAppShell.vue";
import FieldAppShell from "@/components/shells/FieldAppShell.vue";
import KioskAppShell from "@/components/shells/KioskAppShell.vue";

document.title = meridianAppConfig.productName;

const shellComponent = computed(() => {
  switch (meridianAppConfig.uiMode) {
    case "field":
      return FieldAppShell;
    case "kiosk":
      return KioskAppShell;
    case "admin":
      return AdminAppShell;
  }
});
</script>

<template>
  <component :is="shellComponent">
    <RouterView />
  </component>
</template>
