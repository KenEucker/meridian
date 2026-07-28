<script setup lang="ts">
import { computed } from "vue";

import { lettermarkFor } from "@/branding/lettermark";

/**
 * Department identity badge (M15A.9; BRAND-010; UI implementation contract
 * 11.3; component library 5.1).
 *
 * Long specified, never built. The contract it implements is narrow on purpose:
 * a badge identifies a department, it does not theme the surface it sits on.
 *
 *   - The leading glyph falls back logo → icon → generated lettermark, so a
 *     department that has uploaded nothing still has a recognisable mark.
 *   - The lettermark is decorative. It is initials, and initials are ambiguous
 *     between departments; the accessible name always carries the full
 *     department name even when the visible text is a short label.
 *   - The accent is a 3px rule, not a fill. It renders on every surface the
 *     badge appears on, including surfaces that must not take a department
 *     background such as IMS and The Briefing (BRAND-012) — identity travels,
 *     backgrounds do not.
 *   - Nothing here sets `data-department-surface`. Whether a surface is
 *     department-scoped is a routing decision, not a component one.
 */

export interface DepartmentBadgeDepartment {
  readonly id: string;
  readonly name: string;
  readonly shortLabel?: string | null;
  /** A single character or short glyph, used when there is no logo. */
  readonly icon?: string | null;
  readonly logoUrl?: string | null;
  readonly accentColor?: string | null;
  /** Server-generated lettermark; computed locally when absent. */
  readonly lettermark?: string | null;
}

const props = withDefaults(
  defineProps<{
    department: DepartmentBadgeDepartment;
    showLogo?: boolean;
    showAccent?: boolean;
    size?: "sm" | "md" | "lg";
    /** Visible label override. Never replaces the accessible name. */
    label?: string | null;
  }>(),
  {
    showLogo: true,
    showAccent: true,
    size: "md",
    label: null,
  },
);

const visibleLabel = computed(
  () => props.label ?? props.department.shortLabel ?? props.department.name,
);

const lettermark = computed(
  () => props.department.lettermark ?? lettermarkFor(props.department.name),
);

const logoUrl = computed(() =>
  props.showLogo ? (props.department.logoUrl ?? null) : null,
);

const icon = computed(() =>
  logoUrl.value ? null : (props.department.icon ?? null),
);

// The accessible name always includes the full department name. A short label
// or a lettermark is a convenience for sighted readers scanning a dense list;
// "DPW" is not a department to anyone who has not memorised the roster.
const accessibleName = computed(() =>
  visibleLabel.value === props.department.name
    ? props.department.name
    : `${visibleLabel.value} (${props.department.name})`,
);

const accentStyle = computed(() =>
  props.showAccent && props.department.accentColor
    ? { "--m-department-badge-accent": props.department.accentColor }
    : {},
);
</script>

<template>
  <span
    class="department-badge"
    :class="[
      `department-badge--${size}`,
      { 'department-badge--accented': showAccent },
    ]"
    :data-department-id="department.id"
    :style="accentStyle"
    role="img"
    :aria-label="accessibleName"
  >
    <span class="department-badge__glyph" aria-hidden="true">
      <img
        v-if="logoUrl"
        class="department-badge__logo"
        :src="logoUrl"
        alt=""
      />
      <span v-else-if="icon" class="department-badge__icon">{{ icon }}</span>
      <span v-else class="department-badge__lettermark">{{ lettermark }}</span>
    </span>
    <span class="department-badge__label">{{ visibleLabel }}</span>
  </span>
</template>

<style scoped>
.department-badge {
  --m-department-badge-accent: var(--m-department-accent);

  display: inline-flex;
  align-items: center;
  gap: var(--m-space-2);
  max-width: 100%;
  min-width: 0;
  padding: var(--m-space-1) var(--m-space-2);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-pill);
  background: var(--m-surface-raised);
  color: var(--m-text-primary);
  font-weight: 700;
  line-height: 1.2;
}

/*
 * The accent is a rule on the leading edge, not a fill. A filled badge would
 * make the accent the component's theme, and it would put the department's
 * color behind text whose contrast was validated against the organization
 * surface rather than against the accent.
 */
.department-badge--accented {
  border-left: 3px solid var(--m-department-badge-accent);
}

.department-badge__glyph {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: none;
  overflow: hidden;
  border-radius: var(--m-radius-pill);
  background: color-mix(
    in srgb,
    var(--m-department-badge-accent) 16%,
    transparent
  );
  color: var(--m-text-primary);
  font-weight: 800;
}

.department-badge__logo {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.department-badge__label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.department-badge--sm {
  font-size: var(--m-text-xs);
}

.department-badge--sm .department-badge__glyph {
  width: 1.25rem;
  height: 1.25rem;
  font-size: 0.6rem;
}

.department-badge--md {
  font-size: var(--m-text-sm);
}

.department-badge--md .department-badge__glyph {
  width: 1.75rem;
  height: 1.75rem;
  font-size: 0.7rem;
}

.department-badge--lg {
  font-size: var(--m-text-md);
}

.department-badge--lg .department-badge__glyph {
  width: 2.25rem;
  height: 2.25rem;
  font-size: 0.85rem;
}
</style>
