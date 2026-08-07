<script setup lang="ts">
import { computed, inject, nextTick, ref, watch } from "vue";
import { routerKey } from "vue-router";

import {
  groupCommandPaletteResults,
  matchCommandPaletteResults,
  type CommandPaletteResult,
} from "@/components/commandPalette";

/*
 * The command palette overlay (M18.33; UI contract 7.1, 7.2; component library
 * 12 `CommandPalette`; accessibility checklist 11).
 *
 * It renders results and nothing else decides them: what may appear is settled
 * before it arrives here (see `commandPalette.ts`), so this component cannot
 * widen a reader's reach by rendering carelessly, and a permission change moves
 * the list without this file knowing anything happened.
 *
 * The keyboard shape is the combobox one rather than a roving tab stop. Focus
 * stays in the input for the whole life of the dialog and the active option is
 * named by `aria-activedescendant`, which is what lets Escape, Enter, and the
 * arrows all arrive at one handler — and, incidentally, what makes the
 * "focus stays inside modal dialogs until closed" rule true by construction
 * instead of by a focus trap that has to be maintained.
 *
 * Focus returns to whatever opened it on close (accessibility checklist 11, 12).
 * That is the shell's trigger for a pointer user and could be anything at all
 * for somebody who pressed `Ctrl+K` mid-form, which is why it is remembered on
 * open rather than assumed.
 */

const props = defineProps<{
  readonly open: boolean;
  readonly results: readonly CommandPaletteResult[];
}>();

const emit = defineEmits<{
  (event: "close"): void;
}>();

const router = inject(routerKey, null);

const query = ref("");
const activeIndex = ref(0);
const input = ref<HTMLInputElement | null>(null);
let previouslyFocused: HTMLElement | null = null;

const matches = computed(() =>
  matchCommandPaletteResults(props.results, query.value),
);
const groups = computed(() => groupCommandPaletteResults(matches.value));
const activeResult = computed(() => matches.value[activeIndex.value] ?? null);

/*
 * A narrowed query can leave the active index past the end of the list. Clamping
 * on the results rather than in the input handler keeps it correct however the
 * list changed — including when a permission was withdrawn while the palette was
 * open, which moves the list under the reader without a keystroke.
 */
watch(matches, (current) => {
  if (activeIndex.value > current.length - 1) {
    activeIndex.value = current.length === 0 ? 0 : current.length - 1;
  }
});

watch(
  () => props.open,
  async (open) => {
    if (open) {
      previouslyFocused =
        document.activeElement instanceof HTMLElement ? document.activeElement : null;
      query.value = "";
      activeIndex.value = 0;
      await nextTick();
      input.value?.focus();
      return;
    }

    previouslyFocused?.focus();
    previouslyFocused = null;
  },
);

function close(): void {
  emit("close");
}

function moveActive(delta: number): void {
  const total = matches.value.length;

  if (total === 0) {
    return;
  }

  activeIndex.value = (activeIndex.value + delta + total) % total;
}

async function activate(result: CommandPaletteResult | null): Promise<void> {
  if (result === null) {
    return;
  }

  /*
   * Closed first, then performed. Both a route change and an action can move
   * what is under the palette, and a dialog still open over the surface it just
   * navigated to is a dialog covering the thing somebody asked for.
   */
  close();

  if (result.to !== null) {
    await router?.push?.(result.to);
    return;
  }

  await result.run?.();
}

async function onKeydown(event: KeyboardEvent): Promise<void> {
  switch (event.key) {
    case "Escape":
      event.preventDefault();
      close();
      return;
    case "ArrowDown":
      event.preventDefault();
      moveActive(1);
      return;
    case "ArrowUp":
      event.preventDefault();
      moveActive(-1);
      return;
    case "Home":
      event.preventDefault();
      activeIndex.value = 0;
      return;
    case "End":
      event.preventDefault();
      activeIndex.value = Math.max(0, matches.value.length - 1);
      return;
    case "Enter":
      event.preventDefault();
      await activate(activeResult.value);
      return;
    default:
  }
}

function indexOf(result: CommandPaletteResult): number {
  return matches.value.indexOf(result);
}
</script>

<template>
  <div
    v-if="open"
    class="command-palette"
    @pointerdown.self="close"
  >
    <div
      class="command-palette__panel"
      role="dialog"
      aria-modal="true"
      aria-label="Command palette"
    >
      <input
        ref="input"
        v-model="query"
        class="command-palette__input"
        type="text"
        role="combobox"
        aria-expanded="true"
        aria-controls="command-palette-results"
        aria-autocomplete="list"
        :aria-activedescendant="activeResult?.id"
        aria-label="Search pages and actions"
        placeholder="Search pages and actions"
        autocomplete="off"
        spellcheck="false"
        @keydown="onKeydown"
      />

      <div
        id="command-palette-results"
        class="command-palette__results"
        role="listbox"
        aria-label="Pages and actions"
      >
        <div
          v-for="group in groups"
          :key="group.group"
          class="command-palette__group"
          role="group"
          :aria-label="group.group"
        >
          <p class="command-palette__group-label" aria-hidden="true">
            {{ group.group }}
          </p>
          <div
            v-for="result in group.results"
            :id="result.id"
            :key="result.id"
            class="command-palette__result"
            role="option"
            :data-result-type="result.type"
            :aria-selected="result.id === activeResult?.id"
            :data-active="result.id === activeResult?.id"
            @pointerdown.prevent="activate(result)"
            @pointermove="activeIndex = indexOf(result)"
          >
            <span class="command-palette__result-label">{{ result.label }}</span>
            <!--
              The shortcut a result has of its own, where it has one (operating
              guide 10.6). No Alpha 1 result does — the palette's own keys open
              it rather than belonging to anything inside it — so this is the
              contract's `shortcut` field rendered rather than behaviour anybody
              currently sees.
            -->
            <kbd v-if="result.shortcut" class="command-palette__result-shortcut">
              {{ result.shortcut }}
            </kbd>
            <span v-if="result.description" class="command-palette__result-description">
              {{ result.description }}
            </span>
          </div>
        </div>
      </div>

      <!--
        Nothing matched, said plainly, and outside the listbox because a listbox
        holds options. An empty result list is not the same fact as a palette
        with nothing in it, so the sentence names what was typed.
      -->
      <p v-if="matches.length === 0" class="command-palette__empty" role="status">
        Nothing here matches “{{ query }}”.
      </p>

      <p class="command-palette__hint">
        <kbd>&uarr;</kbd><kbd>&darr;</kbd> to move, <kbd>Enter</kbd> to open,
        <kbd>Esc</kbd> to close.
      </p>
    </div>
  </div>
</template>

<style scoped>
.command-palette {
  position: fixed;
  inset: 0;
  z-index: 60;
  display: flex;
  justify-content: center;
  /*
   * Not centred vertically. The palette opens under the reader's eye line and
   * grows downward, so the input stays where it was the last time it opened
   * however many results the query left behind it.
   */
  align-items: start;
  padding: max(var(--m-space-6), env(safe-area-inset-top)) var(--m-space-4)
    var(--m-space-4);
  background: color-mix(in srgb, var(--m-surface-app) 70%, transparent);
}

.command-palette__panel {
  display: grid;
  gap: var(--m-space-2);
  width: min(40rem, 100%);
  max-height: min(32rem, 80vh);
  padding: var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 8px;
  background: var(--m-surface-overlay);
  box-shadow: var(--m-shadow-overlay);
}

.command-palette__input {
  width: 100%;
  min-height: 3rem;
  padding: 0 var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: 6px;
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-md);
  font-weight: 700;
}

.command-palette__input:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.command-palette__results {
  min-height: 0;
  overflow-y: auto;
}

.command-palette__group + .command-palette__group {
  margin-top: var(--m-space-2);
}

.command-palette__group-label {
  margin: 0 0 var(--m-space-1);
  padding: 0 var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 800;
  text-transform: uppercase;
}

.command-palette__result {
  display: grid;
  gap: 0.1rem;
  grid-template-columns: minmax(0, 1fr) auto;
  min-height: 2.75rem;
  align-content: center;
  padding: var(--m-space-2);
  border-radius: 6px;
  cursor: pointer;
}

/*
 * The active row is the one Enter opens, so it is marked rather than merely
 * tinted: colour alone does not carry state (style guide 15), and the row a
 * keyboard user is standing on is exactly the state they cannot see a pointer
 * for.
 */
.command-palette__result[data-active="true"] {
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
  box-shadow: inset 3px 0 0 0 var(--m-focus-ring);
}

.command-palette__result-label {
  min-width: 0;
  overflow: hidden;
  font-size: var(--m-text-sm);
  font-weight: 800;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.command-palette__result-description {
  grid-column: 1 / -1;
  min-width: 0;
  overflow: hidden;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.command-palette__result[data-active="true"] .command-palette__result-description {
  color: inherit;
}

.command-palette__result-shortcut,
.command-palette__hint kbd {
  display: inline-flex;
  align-items: center;
  min-height: 1.25rem;
  padding: 0 0.35rem;
  border: 1px solid var(--m-border-default);
  border-radius: 4px;
  background: var(--m-surface-base);
  color: var(--m-text-secondary);
  font-family: inherit;
  font-size: var(--m-text-xs);
  font-weight: 800;
}

.command-palette__empty {
  margin: 0;
  padding: var(--m-space-3) var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.command-palette__hint {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.25rem;
  margin: 0;
  padding: 0 var(--m-space-2);
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
  font-weight: 700;
}

/*
 * On a phone the palette takes the screen. A 40rem panel floating in a 20rem
 * viewport is a dialog with margins it cannot afford, and the results list is
 * the whole point of the surface.
 */
@media (max-width: 32rem) {
  .command-palette {
    padding: var(--m-space-2);
  }

  .command-palette__panel {
    max-height: calc(100dvh - 2 * var(--m-space-2));
  }
}
</style>
