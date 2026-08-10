<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from "vue";
import { useRouter } from "vue-router";

import {
  endWorkstationSession,
  evaluateWorkstationSession,
  continueWorkstationSession,
  recordWorkstationActivity,
  workstationSessionState,
} from "@/session/workstationSession";

/*
 * The shared-workstation session's controls (M16.9, M18.66; technical spec
 * 13.3; kiosk guide 4.4).
 *
 * Two requirements, and neither of them is a second header:
 *
 *  1. "Users must explicitly end their session before switching users." The
 *     control sits beside the shell's user control, which is where the other
 *     session controls are and where somebody looks for it.
 *  2. Warn before the timeout and offer a way to continue.
 *
 * "The active user is shown prominently at all times" is satisfied by the shell
 * itself: the header's user control renders the active user's name, visibly and
 * on every screen. This component used to render it a second time in a bar of
 * its own, which was the same fact twice in one piece of chrome — so the name
 * is the shell's to show and this is only the controls.
 *
 * The clock ticks here rather than in the session module so the countdown stops
 * when nothing is rendering it. Activity is read from the events that mean a
 * person is present, in the capture phase, so an interaction anywhere in the
 * Kiosk counts whether or not the surface it landed on knows about sessions.
 *
 * Nothing here decides authority. It ends a session and reports what it is told.
 */

const TICK_MS = 1_000;

const router = useRouter();
const now = ref(Date.now());
let ticking: number | undefined;

const active = computed(() => workstationSessionState.status === "active");

/** Whole seconds left, for the warning. Never negative: zero is the timeout. */
const secondsLeft = computed(() => {
  const expiresAt = workstationSessionState.expiresAt;

  if (expiresAt === null) {
    return null;
  }

  const remaining = Date.parse(expiresAt) - now.value;

  return Number.isNaN(remaining) ? null : Math.max(0, Math.ceil(remaining / 1_000));
});

function tick(): void {
  now.value = Date.now();
  evaluateWorkstationSession(new Date(now.value));
}

function onActivity(): void {
  recordWorkstationActivity();
}

const activityEvents = ["pointerdown", "keydown", "wheel", "touchstart"] as const;

onMounted(() => {
  ticking = window.setInterval(tick, TICK_MS);

  for (const name of activityEvents) {
    window.addEventListener(name, onActivity, { capture: true, passive: true });
  }
});

onBeforeUnmount(() => {
  if (ticking !== undefined) {
    window.clearInterval(ticking);
  }

  for (const name of activityEvents) {
    window.removeEventListener(name, onActivity, { capture: true });
  }
});

/*
 * Where a session ending lands. A timeout goes to the safe surface, because the
 * screen it left is somebody's records and nobody is watching it; an explicit
 * sign-out goes to the login screen, because the person who ended it is standing
 * there and knows why.
 */
watch(
  () => workstationSessionState.endedReason,
  async (reason) => {
    if (workstationSessionState.status === "active" || reason === null) {
      return;
    }

    await router.push({
      name: reason === "timed_out" ? "kiosk.safe-timeout" : "kiosk.workstation-login",
    });
  },
);
</script>

<template>
  <div v-if="active" class="kiosk-session" data-testid="kiosk-session-bar">
    <div
      v-if="workstationSessionState.expiring"
      class="kiosk-session__warning"
      role="alert"
    >
      <span>
        Signing out in {{ secondsLeft ?? 0 }}
        {{ secondsLeft === 1 ? "second" : "seconds" }}.
      </span>
      <button
        class="kiosk-session__continue"
        type="button"
        @click="continueWorkstationSession()"
      >
        I'm still here
      </button>
    </div>

    <button
      class="kiosk-session__end"
      type="button"
      @click="endWorkstationSession('signed_out')"
    >
      End session
    </button>
  </div>
</template>

<style scoped>
/*
 * Controls in the header's action row, beside the user control (M18.66). No
 * background, no border, no container: it is two buttons among the shell's
 * other buttons, not a bar of its own.
 */
.kiosk-session {
  display: contents;
}

.kiosk-session__warning {
  display: flex;
  align-items: center;
  gap: var(--m-space-2);
  padding: var(--m-space-1) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-left: 4px solid var(--m-status-warning, var(--m-status-neutral));
  border-radius: var(--m-radius-md);
  color: var(--m-text-secondary);
}

.kiosk-session__continue,
.kiosk-session__end {
  cursor: pointer;
  min-height: 2.75rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 6px;
  background: var(--m-surface-default, transparent);
  color: var(--m-text-primary);
  font-size: var(--m-text-sm);
  font-weight: 800;
}

.kiosk-session__continue:focus-visible,
.kiosk-session__end:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
