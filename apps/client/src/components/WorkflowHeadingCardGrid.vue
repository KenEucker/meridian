<template>
  <div class="workflow-heading-card-grid">
    <slot />
  </div>
</template>

<style scoped>
.workflow-heading-card-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr);
  gap: var(--m-space-2);
  min-width: 0;
}

@media (min-width: 44rem) {
  .workflow-heading-card-grid {
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
  }
}
</style>
