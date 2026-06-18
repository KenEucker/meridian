import { createApp } from "vue";

import App from "@/App.vue";
import { router } from "@/router";
import "@meridian/ui-tokens/tokens.css";
import "@/assets/base.css";

createApp(App).use(router).mount("#app");
