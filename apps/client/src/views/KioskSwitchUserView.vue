<script setup lang="ts">
import { computed, ref } from "vue";
import { useRouter } from "vue-router";

import {
  commandOutbox,
  commandOutboxRevision,
} from "@/outbox/commandOutboxRuntime";
import {
  endWorkstationSession,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * `kiosk.switch-user` — handing the workstation to the next person (M18.32; UI
 * implementation contract 12.8; UI-017; technical spec 13.3; kiosk guide 4.4).
 *
 * "Switching users requires ending the current session first — there is no quiet
 * handover." That sentence is the whole screen. It is not a user picker and it
 * holds no list of who might be next: the only thing it can do is end the
 * session it is standing in, and the next person signs in with their own code.
 *
 * What it adds over the End session control in the session bar is the sentence
 * somebody in a queue actually needs, which is what survives the handover. Ending
 * abandons unsaved form state and keeps queued commands, and a person who
 * recorded a check-in ninety seconds ago is entitled to know which of those their
 * work is (kiosk guide 4.4). The count is read from the outbox rather than
 * described in the abstract, because "2 queued commands are still on this
 * workstation" is a fact and "queued work is preserved" is a promise.
 *
 * It is reachable only with a session live. Without one the workstation is
 * already handed over, and the router sends it to code entry.
 */

const router = useRouter();

const user = computed(() => workstationSessionState.user);
const workstation = computed(() => workstationSessionState.workstation);
const ending = ref(false);

const queuedCount = computed(() => {
  void commandOutboxRevision.value;

  return commandOutbox.unsent().length;
});

/**
 * End the session and go to code entry.
 *
 * The session bar watches the end and routes there itself, so this awaits the
 * end and does not race it with a push of its own.
 */
async function endSession(): Promise<void> {
  if (ending.value) {
    return;
  }

  ending.value = true;

  try {
    await endWorkstationSession("signed_out");
  } finally {
    ending.value = false;
  }
}

function stay(): void {
  void router.push({ name: "kiosk.home" });
}
</script>

<template>
  <section class="switch-user" aria-labelledby="switch-user-heading">
    <h1 id="switch-user-heading" class="switch-user__heading">Switch user</h1>

    <p class="switch-user__lede">
      <strong>{{ user?.name ?? "Somebody" }}</strong> is signed in
      <template v-if="workstation"> at {{ workstation.name }}</template
      >. Ending this session is how the next person signs in — there is no way to
      hand the workstation over without it.
    </p>

    <ul class="switch-user__consequences">
      <li>Anything typed and not yet saved is abandoned.</li>
      <li v-if="queuedCount > 0" data-testid="switch-user-queued">
        {{
          queuedCount === 1
            ? "1 queued command stays"
            : `${queuedCount} queued commands stay`
        }}
        on this workstation and syncs when the node is reachable.
      </li>
      <li v-else>Nothing is waiting to be sent from this workstation.</li>
      <li>This screen and everything behind it clears.</li>
    </ul>

    <div class="switch-user__actions">
      <button
        class="switch-user__end"
        type="button"
        :disabled="ending"
        @click="endSession"
      >
        {{ ending ? "Ending session…" : "End session and switch user" }}
      </button>

      <button class="switch-user__stay" type="button" @click="stay">
        Stay signed in
      </button>
    </div>
  </section>
</template>

<style scoped>
.switch-user {
  width: var(--m-content-narrow);
}

.switch-user__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
  letter-spacing: 0;
}

.switch-user__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-secondary);
}

.switch-user__consequences {
  display: grid;
  gap: var(--m-space-2);
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-3) var(--m-space-4) var(--m-space-3) var(--m-space-5);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-neutral);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
}

.switch-user__actions {
  display: grid;
  gap: var(--m-space-3);
}

.switch-user__end,
.switch-user__stay {
  min-height: 3.5rem;
  padding: 0 var(--m-space-4);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-size: var(--m-text-lg);
  font-weight: 900;
}

.switch-user__end {
  background: var(--m-action-primary-bg, var(--m-surface-raised));
  color: var(--m-action-primary-fg, var(--m-text-primary));
}

.switch-user__end:disabled {
  opacity: 0.6;
}

.switch-user__end:focus-visible,
.switch-user__stay:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 44rem) {
  .switch-user__actions {
    display: flex;
    flex-wrap: wrap;
  }
}
</style>
