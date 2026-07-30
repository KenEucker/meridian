<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";

import { meridianAppConfig } from "@/app/appConfig";
import {
  apiLoginState,
  requestLoginCode,
  signOut,
} from "@/session/apiLogin";

/*
 * `auth.login` — the sign-in entry point (UI implementation contract 12.1;
 * M16.11; AUTH-018, AUTH-019).
 *
 * One field, because there is one thing to ask for. Meridian has no passwords:
 * an address gets a login code, and the code gets a token bound to this device.
 *
 * The answer is deliberately the same whether or not the address belongs to
 * anybody — the node makes sure of that, and this screen does not add a
 * hint of its own by saying anything different for an address it does not
 * recognize.
 *
 * Provider sign-in (Google, Discord) completes in a system browser and is not
 * offered here yet; the node's handoff endpoints exist and wiring the client to
 * them is its own task.
 */

const router = useRouter();

const email = ref(apiLoginState.email ?? "");
const productName = meridianAppConfig.productName;
const canSubmit = computed(
  () => email.value.trim() !== "" && !apiLoginState.requesting,
);

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return;
  }

  if ((await requestLoginCode(email.value)) === "sent") {
    await router.push({ name: "auth.code.entry" });
  }
}
</script>

<template>
  <section class="login" aria-labelledby="login-heading">
    <h1 id="login-heading" class="login__heading">Sign in to {{ productName }}</h1>

    <template v-if="apiLoginState.status === 'signed_in'">
      <p class="login__lede">
        You are signed in as
        <strong>{{ apiLoginState.user?.name || apiLoginState.user?.email }}</strong>
        on this device.
      </p>
      <button class="login__submit" type="button" @click="signOut">
        Sign out
      </button>
    </template>

    <template v-else>
      <p class="login__lede">
        Enter your email address and Meridian will send you a login code. There
        is no password.
      </p>

      <form class="login__form" @submit.prevent="submit">
        <label class="login__label" for="login-email">Email address</label>
        <input
          id="login-email"
          v-model="email"
          class="login__field"
          type="email"
          inputmode="email"
          autocomplete="email"
          autocapitalize="none"
          spellcheck="false"
          :aria-describedby="apiLoginState.error ? 'login-error' : undefined"
          :aria-invalid="apiLoginState.error !== null"
        />

        <p v-if="apiLoginState.error" id="login-error" class="login__error" role="alert">
          {{ apiLoginState.error }}
        </p>

        <button class="login__submit" type="submit" :disabled="!canSubmit">
          {{ apiLoginState.requesting ? "Sending…" : "Send login code" }}
        </button>
      </form>

      <p class="login__alternate">
        Already have a code?
        <RouterLink :to="{ name: 'auth.code.entry' }">Enter it here</RouterLink>.
      </p>
    </template>
  </section>
</template>

<style scoped>
.login {
  width: var(--m-content-narrow);
}

.login__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.login__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.login__form {
  display: grid;
  gap: var(--m-space-2);
}

.login__label {
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.login__field {
  min-height: 3rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-lg);
}

.login__field:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.login__error {
  margin: 0;
  color: var(--m-status-critical, var(--m-text-primary));
  font-weight: 700;
}

.login__submit {
  min-height: 3rem;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-action-primary-bg, var(--m-surface-raised));
  color: var(--m-action-primary-text, var(--m-text-primary));
  font: inherit;
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.login__submit:disabled {
  opacity: 0.6;
}

.login__submit:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.login__alternate {
  margin: var(--m-space-4) 0 0;
  color: var(--m-text-muted);
}
</style>
