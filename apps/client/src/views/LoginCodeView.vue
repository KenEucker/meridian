<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";

import {
  apiLoginState,
  cancelLoginCode,
  requestLoginCode,
  submitLoginCode,
} from "@/session/apiLogin";

/*
 * `auth.code-entry` — in-application login code entry (UI implementation
 * contract 12.1; M16.11; AUTH-019, AUTH-021).
 *
 * The confirmation that a code was sent is a state of this screen rather than a
 * screen of its own: `auth.magic-link-sent` would be a page whose only content
 * is a sentence and a button leading here, and the person is holding the code
 * already. What the contract asks that screen to say — which address it went to
 * and how long it lasts — is said at the top of this one.
 *
 * The code is exchanged together with this device's identity, and the token that
 * comes back is bound to it (AUTH-021). A device that cannot present a signing
 * key says so here rather than reporting a bad code.
 */

const router = useRouter();

const code = ref("");
const email = computed(() => apiLoginState.email);
const canSubmit = computed(
  () =>
    email.value !== null &&
    code.value.trim() !== "" &&
    !apiLoginState.submitting,
);

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return;
  }

  const outcome = await submitLoginCode(code.value);

  // Cleared on a refusal too: a code that was refused is spent, wrong, or
  // expired, and leaving it in the field invites the same attempt again.
  code.value = "";

  if (outcome === "signed_in") {
    await router.push({ name: "home" });
  }
}

async function resend(): Promise<void> {
  if (email.value !== null) {
    await requestLoginCode(email.value);
  }
}

async function startOver(): Promise<void> {
  cancelLoginCode();
  await router.push({ name: "login" });
}
</script>

<template>
  <section class="login-code" aria-labelledby="login-code-heading">
    <h1 id="login-code-heading" class="login-code__heading">Enter your login code</h1>

    <p v-if="email" class="login-code__lede">
      Meridian sent a code to <strong>{{ email }}</strong
      ><template v-if="apiLoginState.codeExpiresInMinutes !== null">. It is good for
      {{ apiLoginState.codeExpiresInMinutes }} minutes</template
      >.
    </p>
    <p v-else class="login-code__lede" role="status">
      This device does not know which address to check a code against. Start from
      the sign-in screen.
    </p>

    <form v-if="email" class="login-code__form" @submit.prevent="submit">
      <label class="login-code__label" for="login-code-field">Login code</label>
      <input
        id="login-code-field"
        v-model="code"
        class="login-code__field"
        type="text"
        inputmode="text"
        autocomplete="one-time-code"
        autocapitalize="characters"
        spellcheck="false"
        maxlength="16"
        :aria-describedby="apiLoginState.error ? 'login-code-error' : undefined"
        :aria-invalid="apiLoginState.error !== null"
      />

      <p
        v-if="apiLoginState.error"
        id="login-code-error"
        class="login-code__error"
        role="alert"
      >
        {{ apiLoginState.error }}
      </p>

      <button class="login-code__submit" type="submit" :disabled="!canSubmit">
        {{ apiLoginState.submitting ? "Signing in…" : "Sign in" }}
      </button>
    </form>

    <p class="login-code__alternate">
      <button
        v-if="email"
        type="button"
        class="login-code__link"
        :disabled="apiLoginState.requesting"
        @click="resend"
      >
        Send another code
      </button>
      <button type="button" class="login-code__link" @click="startOver">
        Use a different address
      </button>
    </p>
  </section>
</template>

<style scoped>
.login-code {
  width: var(--m-content-narrow);
}

.login-code__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.login-code__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
}

.login-code__form {
  display: grid;
  gap: var(--m-space-2);
}

.login-code__label {
  font-size: var(--m-text-sm);
  font-weight: 800;
}

/* Sized like the workstation code field: typed from a phone screen, often in
   the dark, by somebody who would rather be doing something else. */
.login-code__field {
  min-height: 3.5rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-family: var(--m-font-mono, monospace);
  font-size: var(--m-text-xl);
  letter-spacing: 0.25em;
  text-transform: uppercase;
}

.login-code__field:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.login-code__error {
  margin: 0;
  color: var(--m-status-critical, var(--m-text-primary));
  font-weight: 700;
}

.login-code__submit {
  min-height: 3.5rem;
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-action-primary-bg, var(--m-surface-raised));
  color: var(--m-action-primary-text, var(--m-text-primary));
  font: inherit;
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.login-code__submit:disabled {
  opacity: 0.6;
}

.login-code__submit:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.login-code__alternate {
  display: flex;
  flex-wrap: wrap;
  gap: var(--m-space-3);
  margin: var(--m-space-4) 0 0;
}

.login-code__link {
  padding: 0;
  border: 0;
  background: none;
  color: var(--m-text-primary);
  font: inherit;
  text-decoration: underline;
  cursor: pointer;
}

.login-code__link:disabled {
  cursor: default;
  opacity: 0.6;
}

.login-code__link:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
