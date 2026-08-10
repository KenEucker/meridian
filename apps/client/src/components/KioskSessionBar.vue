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
 * The shared-workstation session, always on screen (M16.9; technical spec 13.3;
 * kiosk guide 4.4).
 *
 * Three requirements meet in one component because they are the same loop:
 *
 *  1. "The active user is shown prominently at all times." Not in a menu behind
 *     an avatar — a shared workstation that is unclear about who is signed in is
 *     worse than one that is signed out.
 *  2. "Users must explicitly end their session before switching users." The
 *     control is here rather than in a user menu, next to the name it ends.
 *  3. Warn before the timeout and offer a way to continue.
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
const userLabel = computed(() => workstationSessionState.user?.name ?? null);

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
    <p class="kiosk-session__user">
      <span class="kiosk-session__label">Signed in</span>
      <strong class="kiosk-session__name">{{ userLabel ?? "Unknown user" }}</strong>
    </p>

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
 * A strip of the shell's header rather than a panel of its own (M18.66). It
 * carries no background or border: it sits inside the header, and a raised
 * surface with its own border inside another one is what made it read as a
 * container floating over the page.
 */
.kiosk-session {
  display: flex;
  align-items: center;
  gap: var(--m-space-3);
  padding: 0 var(--m-space-4) var(--m-space-2);
  border-top: 1px solid var(--m-border-subtle, var(--m-border-default));
  padding-top: var(--m-space-2);
}

.kiosk-session__user {
  display: grid;
  gap: 0.1rem;
  margin: 0 auto 0 0;
  min-width: 0;
}

.kiosk-session__label {
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 900;
  text-transform: uppercase;
}

/* Prominent is the requirement, so the name is the largest thing in the bar. */
.kiosk-session__name {
  font-size: var(--m-text-lg);
  font-weight: 900;
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
