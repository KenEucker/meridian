<template>
  <nav class="workflow-page-navigation" aria-label="Page navigation">
    <slot />
  </nav>
</template>

<style scoped>
.workflow-page-navigation {
  display: grid;
  gap: var(--m-space-2);
}

.workflow-page-navigation :deep(a),
.workflow-page-navigation :deep(button) {
  display: flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
  min-height: 2.75rem;
  padding: var(--m-space-3);
  border: 1px solid var(--m-action-secondary-bg);
  border-radius: 8px;
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
  font: inherit;
  font-weight: 800;
  text-align: center;
  text-decoration: none;
  box-shadow: var(--m-shadow-sm);
}

.workflow-page-navigation :deep(a.router-link-active),
.workflow-page-navigation :deep(a.router-link-exact-active) {
  border-color: var(--m-action-secondary-bg);
  background: var(--m-action-secondary-bg);
  color: var(--m-action-secondary-text);
}

.workflow-page-navigation :deep(a:focus-visible),
.workflow-page-navigation :deep(button:focus-visible) {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

@media (min-width: 48rem) {
  .workflow-page-navigation {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}
</style>
