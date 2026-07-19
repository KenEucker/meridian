<script setup lang="ts">
defineProps<{
  title: string;
  eyebrow: string;
  lede?: string;
  freshness?: string;
}>();
</script>

<template>
  <section class="dept-ops" :aria-labelledby="'dept-ops-heading'">
    <p class="dept-ops__nav">
      <slot name="nav" />
    </p>
    <header class="dept-ops__header">
      <div>
        <p class="dept-ops__eyebrow">{{ eyebrow }}</p>
        <h1 id="dept-ops-heading" class="dept-ops__heading">{{ title }}</h1>
        <p v-if="lede" class="dept-ops__lede">{{ lede }}</p>
      </div>
      <p v-if="freshness" class="dept-ops__freshness" role="status">
        {{ freshness }}
      </p>
    </header>
    <slot />
  </section>
</template>

<style scoped>
.dept-ops {
  width: min(100%, 56rem);
}

.dept-ops__nav {
  margin: 0 0 var(--m-space-3);
}

.dept-ops__nav :deep(a) {
  color: var(--m-text-primary);
}

.dept-ops__nav :deep(a:focus-visible) {
  outline: 2px solid var(--m-focus-ring);
  outline-offset: 2px;
}

.dept-ops__header {
  display: grid;
  gap: var(--m-space-3);
  margin-bottom: var(--m-space-5);
}

.dept-ops__eyebrow,
.dept-ops__lede,
.dept-ops__freshness {
  margin: 0;
  color: var(--m-text-muted);
}

.dept-ops__eyebrow {
  font-size: var(--m-text-sm);
  font-weight: 700;
  text-transform: uppercase;
}

.dept-ops__heading {
  margin: var(--m-space-1) 0 0;
  font-family: var(--m-font-heading);
  font-size: var(--m-text-xl);
}

.dept-ops__lede,
.dept-ops__freshness {
  margin-top: var(--m-space-2);
  font-size: var(--m-text-sm);
}

@media (min-width: 48rem) {
  .dept-ops__header {
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: start;
  }
}
</style>
