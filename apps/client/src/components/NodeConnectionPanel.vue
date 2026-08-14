<script setup lang="ts">
import { computed, ref, watch } from "vue";

import {
  clearNodeUrl,
  nodeConnection,
  NodeUrlError,
  setNodeUrl,
  type NodeUrlSource,
} from "@/app/nodeConnection";

// Which node this device works against (technical spec 7.1, 8.1).
//
// Two surfaces host this panel. Device readiness keeps it for the technician
// preparing a device, where the checklist is already the frame. Settings holds
// it behind an "Advanced" disclosure for the person who installed the app and
// has nothing yet: on a packaged app, pointing the device at a node comes
// before sign-in can work at all, which is why both hosts are reachable signed
// out (QA-PKG-01 step 13).
//
// The desktop wrapper and the mobile app are the clients that need it. A
// browser client is served by a node and already knows which one, so the
// panel says so instead of implying a choice has to be made.

const connection = computed(() => nodeConnection.value);
const draft = ref(connection.value.url);
const errorMessage = ref<string | null>(null);
const savedMessage = ref<string | null>(null);

watch(
  () => connection.value.url,
  (url) => {
    draft.value = url;
  },
);

const sourceDescription: Record<NodeUrlSource, string> = {
  configured: "Set on this device.",
  served: "The node that served this app.",
  build: "Built into this app.",
  discovered: "Found on this network.",
  convention:
    "The standard on-site node name. Nothing has been set on this device.",
  default: "Local development default. No node has been set.",
};

const unchanged = computed(() => draft.value.trim() === connection.value.url);

function save(): void {
  errorMessage.value = null;
  savedMessage.value = null;

  try {
    const url = setNodeUrl(draft.value);
    savedMessage.value = `This device now works against ${url}.`;
  } catch (error) {
    errorMessage.value =
      error instanceof NodeUrlError
        ? error.message
        : "That node address could not be used.";
  }
}

function reset(): void {
  errorMessage.value = null;
  clearNodeUrl();
  savedMessage.value = `Cleared. This device is back to ${nodeConnection.value.url}.`;
}
</script>

<template>
  <section class="node-connection" aria-labelledby="node-connection-heading">
    <h2 id="node-connection-heading" class="node-connection__heading">
      Node connection
    </h2>
    <p class="node-connection__lede">
      Meridian runs on nodes: a central node, and an on-site node at each event.
      This is the one this device talks to.
    </p>

    <dl class="node-connection__current">
      <dt>Node</dt>
      <dd>{{ connection.url }}</dd>
      <dt>Source</dt>
      <dd>{{ sourceDescription[connection.source] }}</dd>
    </dl>

    <p v-if="connection.overridesServingNode" class="node-connection__warning" role="note">
      This device is set to a different node than the one that served this app
      ({{ connection.servedUrl }}). Signing in against the serving node will not
      carry over.
    </p>

    <form class="node-connection__form" @submit.prevent="save">
      <label class="node-connection__label" for="node-url">Node address</label>
      <input
        id="node-url"
        v-model="draft"
        class="node-connection__input"
        type="url"
        inputmode="url"
        autocomplete="off"
        spellcheck="false"
        placeholder="https://onsite.example.org"
        aria-describedby="node-url-help"
      />
      <p id="node-url-help" class="node-connection__help">
        The address of the Meridian server for this node, including http:// or
        https://.
      </p>

      <p v-if="errorMessage" class="node-connection__error" role="alert">
        {{ errorMessage }}
      </p>
      <p v-else-if="savedMessage" class="node-connection__saved" role="status">
        {{ savedMessage }}
      </p>

      <div class="node-connection__actions">
        <button class="node-connection__submit" type="submit" :disabled="unchanged">
          Use this node
        </button>
        <button
          v-if="connection.source === 'configured'"
          class="node-connection__reset"
          type="button"
          @click="reset"
        >
          Clear
        </button>
      </div>
    </form>
  </section>
</template>

<style scoped>
.node-connection {
  margin: 0 0 var(--m-space-6);
  padding: var(--m-space-4);
  border: 1px solid var(--m-border-subtle);
  border-radius: var(--m-radius-md);
  background: var(--m-surface-base);
}

.node-connection__heading {
  margin: 0 0 var(--m-space-2);
  font-family: var(--m-font-heading);
  font-size: var(--m-text-lg);
}

.node-connection__lede {
  margin: 0 0 var(--m-space-4);
  color: var(--m-text-muted);
  font-size: var(--m-text-sm);
}

.node-connection__current {
  display: grid;
  grid-template-columns: max-content 1fr;
  gap: var(--m-space-1) var(--m-space-4);
  margin: 0 0 var(--m-space-4);
  font-size: var(--m-text-sm);
}

.node-connection__current dt {
  color: var(--m-text-muted);
  font-weight: 600;
}

.node-connection__current dd {
  margin: 0;
  overflow-wrap: anywhere;
}

.node-connection__warning {
  margin: 0 0 var(--m-space-4);
  padding: var(--m-space-3);
  border-inline-start: 3px solid var(--m-status-warning);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-raised);
  font-size: var(--m-text-sm);
}

.node-connection__form {
  display: grid;
  gap: var(--m-space-2);
}

.node-connection__label {
  font-size: var(--m-text-sm);
  font-weight: 600;
}

.node-connection__input {
  min-block-size: 2.5rem;
  padding: var(--m-space-2) var(--m-space-3);
  border: 1px solid var(--m-border-default);
  border-radius: var(--m-radius-sm);
  background: var(--m-surface-base);
  color: var(--m-text-primary);
  font: inherit;
  font-size: var(--m-text-sm);
}

.node-connection__help {
  margin: 0;
  color: var(--m-text-muted);
  font-size: var(--m-text-xs);
}

.node-connection__error,
.node-connection__saved {
  margin: 0;
  font-size: var(--m-text-sm);
}

.node-connection__error {
  color: var(--m-text-primary);
  font-weight: 600;
}

.node-connection__actions {
  display: flex;
  gap: var(--m-space-3);
  margin-top: var(--m-space-2);
}

.node-connection__submit,
.node-connection__reset {
  min-block-size: 2.5rem;
  padding: var(--m-space-2) var(--m-space-4);
  border: 1px solid transparent;
  border-radius: var(--m-radius-sm);
  font: inherit;
  font-size: var(--m-text-sm);
  font-weight: 700;
}

.node-connection__submit {
  background: var(--m-action-primary-bg);
  color: var(--m-action-primary-text);
}

.node-connection__submit:disabled {
  background: var(--m-surface-raised);
  border-color: var(--m-border-strong);
  color: var(--m-text-muted);
  cursor: not-allowed;
}

.node-connection__reset {
  background: var(--m-surface-raised);
  border-color: var(--m-border-default);
  color: var(--m-text-primary);
}

.node-connection__input:focus-visible,
.node-connection__submit:focus-visible,
.node-connection__reset:focus-visible {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}
</style>
