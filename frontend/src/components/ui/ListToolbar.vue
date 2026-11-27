<script setup lang="ts">
import { inputClass } from './inputClass'

const search = defineModel<string>('search', { default: '' })
const status = defineModel<string>('status', { default: '' })
defineProps<{ placeholder?: string }>()
</script>

<template>
  <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center">
    <div class="relative flex-1">
      <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.45 4.39l3.08 3.08a.75.75 0 1 1-1.06 1.06l-3.08-3.08A7 7 0 0 1 2 9Z" clip-rule="evenodd" /></svg>
      <input v-model="search" type="search" :placeholder="placeholder ?? 'Buscar…'" :class="inputClass()" class="pl-9" aria-label="Buscar" />
    </div>
    <slot />
    <select v-model="status" :class="inputClass()" class="sm:w-40" aria-label="Situação">
      <option value="">Todas as situações</option>
      <option value="active">Ativos</option>
      <option value="inactive">Inativos</option>
    </select>
  </div>
</template>
