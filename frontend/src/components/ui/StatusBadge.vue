<script setup lang="ts">
defineProps<{ active: boolean }>()
</script>

<template>
  <span
    class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium"
    :class="active ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-slate-100 text-slate-600 ring-1 ring-slate-200'"
  >
    <span class="size-1.5 rounded-full" :class="active ? 'bg-emerald-500' : 'bg-slate-400'" />
    {{ active ? 'Ativo' : 'Inativo' }}
  </span>
</template>
