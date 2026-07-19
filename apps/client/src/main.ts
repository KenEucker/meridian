import { createApp } from "vue";

import App from "@/App.vue";
import { installDevelopmentFieldSessionFromEnv } from "@/field-reports/fieldSession";
import { router } from "@/router";
import "@meridian/ui-tokens/tokens.css";
import "@/assets/base.css";

installDevelopmentFieldSessionFromEnv();

createApp(App).use(router).mount("#app");
