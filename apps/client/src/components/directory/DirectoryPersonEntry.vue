<script setup lang="ts">
import { lettermarkFor } from "@/branding/lettermark";
import type { DirectoryPerson } from "@/directory/directoryModel";

/**
 * One Directory person entry (DIR-029; UI contract 19D.5): the profile
 * picture, the handle, the authorized locations, and years of service. That
 * is the complete list — no name, no contact control, no messaging control,
 * no administrative action, and no profile edit exists here to render.
 *
 * Where a person has no picture, the generated-lettermark convention applies,
 * derived from the handle rather than from a name (19D.5).
 *
 * `highlighted` is the search-selection marker (DIR-035): a state attribute
 * plus accessible text, never color alone (section 20).
 */
const props = withDefaults(
  defineProps<{
    person: DirectoryPerson;
    locationLabels: readonly string[];
    highlighted?: boolean;
  }>(),
  { highlighted: false },
);

function yearsLabel(): string {
  return props.person.yearsOfService === 1
    ? "1 year of service"
    : `${props.person.yearsOfService} years of service`;
}

/**
 * A person who has not chosen a handle yet renders as exactly that. The
 * handle is the only person-identifying text this surface may carry
 * (DIR-027), so no other name may stand in for a missing one — but a blank
 * entry would read as a rendering bug rather than as a fact about the record.
 */
function hasHandle(): boolean {
  return props.person.handle.trim() !== "";
}
</script>

<template>
  <article
    class="directory-person"
    :data-highlighted="highlighted ? 'true' : undefined"
    :data-staff-id="person.id"
  >
    <span class="directory-person__portrait" aria-hidden="true">
      <img
        v-if="person.profilePictureUrl"
        :src="person.profilePictureUrl"
        alt=""
      />
      <span v-else class="directory-person__lettermark">
        {{ hasHandle() ? lettermarkFor(person.handle) : "?" }}
      </span>
    </span>
    <span class="directory-person__text">
      <span
        class="directory-person__handle"
        :class="{ 'directory-person__handle--unset': !hasHandle() }"
      >
        {{ hasHandle() ? person.handle : "No handle yet" }}
        <mark v-if="highlighted" class="directory-person__match">
          Search match
        </mark>
      </span>
      <span class="directory-person__meta">{{ yearsLabel() }}</span>
      <span v-if="locationLabels.length > 0" class="directory-person__meta">
        {{ locationLabels.join("; ") }}
      </span>
    </span>
  </article>
</template>

<style scoped>
.directory-person {
  display: flex;
  gap: var(--m-space-2);
  align-items: center;
  min-height: 44px;
  padding: var(--m-space-1) 0;
}

.directory-person[data-highlighted="true"] {
  padding-inline: var(--m-space-2);
  border-radius: var(--m-radius-sm);
  outline: 2px solid var(--m-border-strong, currentColor);
  background: var(--m-surface-raised);
}

.directory-person__portrait {
  flex: none;
  width: 40px;
  height: 40px;
  overflow: hidden;
  border-radius: 50%;
  background: var(--m-surface-raised);
}

.directory-person__portrait img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.directory-person__lettermark {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.directory-person__text {
  display: grid;
}

.directory-person__handle {
  font-weight: 700;
}

.directory-person__handle--unset {
  color: var(--m-text-muted);
  font-style: italic;
  font-weight: 400;
}

.directory-person__match {
  margin-left: var(--m-space-1);
  padding: 0 var(--m-space-1);
  border-radius: var(--m-radius-sm);
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.directory-person__meta {
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}
</style>
