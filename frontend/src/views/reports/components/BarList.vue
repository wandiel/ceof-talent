<script setup lang="ts">
import { computed } from 'vue'
import { formatNumber } from '@/api/reports'

/** Barras horizontais com rótulo e valor em texto (o valor nunca depende só da cor). */
const props = withDefaults(defineProps<{ items: { label: string; value: number; hint?: string }[]; color?: string; max?: number; empty?: string }>(), {
  color: '#1d4ed8',
  max: undefined,
  empty: 'Sem dados no período.',
})

const top = computed(() => props.max ?? Math.max(1, ...props.items.map((i) => i.value)))
</script>

<template>
  <ul v-if="items.length" class="space-y-2.5">
    <li v-for="item in items" :key="item.label" class="grid grid-cols-[minmax(0,10rem)_1fr_auto] items-center gap-3 text-sm" :title="`${item.label}: ${formatNumber(item.value)}${item.hint ? ' (' + item.hint + ')' : ''}`">
      <span class="truncate text-slate-700">{{ item.label }}</span>
      <span class="h-2.5 rounded-full bg-slate-100">
        <span class="block h-2.5 rounded-full" :style="{ width: `${item.value ? Math.max(2, (item.value / top) * 100) : 0}%`, background: color }" />
      </span>
      <span class="min-w-12 text-right font-medium text-slate-900 tabular-nums">
        {{ formatNumber(item.value) }}<span v-if="item.hint" class="ml-1 text-xs font-normal text-slate-500">{{ item.hint }}</span>
      </span>
    </li>
  </ul>
  <p v-else class="text-sm text-slate-500">{{ empty }}</p>
</template>
