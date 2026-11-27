<script setup lang="ts">
import type { Paginated } from '@/api/types'

defineProps<{ meta: Paginated<unknown>['meta'] }>()
const emit = defineEmits<{ page: [number] }>()
</script>

<template>
  <nav class="flex flex-col items-center justify-between gap-3 border-t border-slate-100 px-4 py-3 text-sm text-slate-600 sm:flex-row" aria-label="Paginação">
    <p>
      <template v-if="meta.total">Mostrando <strong>{{ meta.from }}</strong>–<strong>{{ meta.to }}</strong> de <strong>{{ meta.total }}</strong></template>
      <template v-else>Nenhum registro</template>
    </p>
    <div class="flex items-center gap-2">
      <button
        type="button"
        class="rounded-lg px-3 py-1.5 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-40"
        :disabled="meta.current_page <= 1"
        @click="emit('page', meta.current_page - 1)"
      >
        Anterior
      </button>
      <span class="tabular-nums">{{ meta.current_page }} / {{ Math.max(meta.last_page, 1) }}</span>
      <button
        type="button"
        class="rounded-lg px-3 py-1.5 ring-1 ring-slate-300 hover:bg-slate-50 disabled:opacity-40"
        :disabled="meta.current_page >= meta.last_page"
        @click="emit('page', meta.current_page + 1)"
      >
        Próxima
      </button>
    </div>
  </nav>
</template>
