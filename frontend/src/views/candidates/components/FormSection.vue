<script setup lang="ts">
defineProps<{ title: string; description?: string; invalid?: boolean }>()
</script>

<template>
  <section class="rounded-2xl border bg-white p-5 shadow-sm sm:p-6" :class="invalid ? 'border-red-300' : 'border-slate-200'">
    <header class="mb-4">
      <h2 class="text-base font-semibold text-slate-900">{{ title }}</h2>
      <p v-if="description" class="mt-0.5 text-sm text-slate-500">{{ description }}</p>
      <p v-if="invalid" class="mt-1 text-xs text-red-600" role="alert">Há campos a corrigir nesta seção.</p>
    </header>
    <slot />
  </section>
</template>
