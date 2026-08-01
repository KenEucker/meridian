<script setup lang="ts">
/**
 * One operational state, said in a shape you can find without reading.
 *
 * The desks run on state. Somebody is on-site or off-site, a shift is active or
 * completed, a radio is checked out or missing — and until now every one of
 * those was a run of ordinary text among other ordinary text, which is fine to
 * read and useless to scan. An operator working a queue is scanning.
 *
 * Two rules make that safe rather than merely colorful:
 *
 *  1. **The word carries the state; the color repeats it.** The label is always
 *     rendered, so a pill read with no color at all — greyscale, a bright tent,
 *     a color vision difference — says exactly as much (accessibility
 *     checklist). Color is never the only channel.
 *  2. **Tone is about consequence, not about vocabulary.** `positive` is a state
 *     nobody needs to act on, `caution` is one somebody might, `critical` is one
 *     somebody must, and `neutral` is one that is simply true. A caller maps its
 *     own states onto that scale, which is why this component knows nothing
 *     about presence, attendance, or equipment.
 */
export type StatusPillTone =
  | "positive"
  | "caution"
  | "critical"
  | "neutral"
  | "info";

withDefaults(
  defineProps<{
    /** The state, in words. Always rendered. */
    label: string;
    tone?: StatusPillTone;
    /**
     * A word naming what the state is *of* — "Presence", "Shift" — read out to
     * assistive technology so a pill on its own is not an unattached adjective.
     */
    srPrefix?: string;
  }>(),
  {
    tone: "neutral",
    srPrefix: undefined,
  },
);
</script>

<template>
  <span class="status-pill" :class="`status-pill--${tone}`" :data-tone="tone">
    <span v-if="srPrefix" class="status-pill__sr">{{ srPrefix }}:</span>
    <span class="status-pill__dot" aria-hidden="true" />
    {{ label }}
  </span>
</template>

<style scoped>
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: var(--m-space-2);
  padding: 0.15rem var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-raised);
  color: var(--m-text-secondary);
  font-size: var(--m-text-sm);
  font-weight: 800;
  line-height: 1.6;
  white-space: nowrap;
}

.status-pill__sr {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

/*
 * The dot is the scannable part and the tint is the surface it sits on. Both
 * are mixed against the page's own surface rather than set to a flat color, so
 * a pill keeps its contrast in both themes without a second set of values.
 */
.status-pill__dot {
  flex: none;
  width: 0.5rem;
  height: 0.5rem;
  border-radius: var(--m-radius-pill);
  background: currentColor;
}

.status-pill--positive {
  border-color: color-mix(
    in srgb,
    var(--m-status-success) 55%,
    var(--m-border-default)
  );
  background: color-mix(
    in srgb,
    var(--m-status-success) 16%,
    var(--m-surface-raised)
  );
  color: color-mix(in srgb, var(--m-status-success) 78%, var(--m-text-primary));
}

.status-pill--caution {
  border-color: color-mix(
    in srgb,
    var(--m-status-warning) 55%,
    var(--m-border-default)
  );
  background: color-mix(
    in srgb,
    var(--m-status-warning) 16%,
    var(--m-surface-raised)
  );
  color: color-mix(in srgb, var(--m-status-warning) 78%, var(--m-text-primary));
}

.status-pill--critical {
  border-color: color-mix(
    in srgb,
    var(--m-status-danger) 55%,
    var(--m-border-default)
  );
  background: color-mix(
    in srgb,
    var(--m-status-danger) 16%,
    var(--m-surface-raised)
  );
  color: color-mix(in srgb, var(--m-status-danger) 78%, var(--m-text-primary));
}

.status-pill--info {
  border-color: color-mix(
    in srgb,
    var(--m-status-neutral) 55%,
    var(--m-border-default)
  );
  background: color-mix(
    in srgb,
    var(--m-status-neutral) 16%,
    var(--m-surface-raised)
  );
  color: color-mix(in srgb, var(--m-status-neutral) 78%, var(--m-text-primary));
}

.status-pill--neutral {
  color: var(--m-text-secondary);
}
</style>
