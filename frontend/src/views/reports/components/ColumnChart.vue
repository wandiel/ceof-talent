<script setup lang="ts">
import { computed, ref } from 'vue'
import { formatNumber } from '@/api/reports'

/** Gráfico de colunas de uma única série (a cor identifica a medida; o título dá o nome). */
const props = withDefaults(defineProps<{ data: { label: string; value: number }[]; unit: string; color?: string }>(), { color: '#1d4ed8' })

const W = 400
const H = 200
const pad = { top: 20, right: 4, bottom: 28, left: 4 }
const hover = ref<number | null>(null)

const max = computed(() => Math.max(1, ...props.data.map((d) => d.value)))
const slot = computed(() => (W - pad.left - pad.right) / Math.max(1, props.data.length))
const barW = computed(() => Math.max(4, Math.min(28, slot.value - 4)))
const plotH = H - pad.top - pad.bottom

const bars = computed(() =>
  props.data.map((d, i) => {
    const h = d.value === 0 ? 0 : Math.max(3, (d.value / max.value) * plotH)
    const x = pad.left + i * slot.value + (slot.value - barW.value) / 2
    return { ...d, x, y: pad.top + plotH - h, h, cx: x + barW.value / 2 }
  }),
)

/** Rótulos do eixo X: todos se couberem, senão um sim, um não. */
const every = computed(() => Math.max(1, Math.ceil(props.data.length / 6)))

/** Caminho de barra com cantos arredondados só no topo (4px), apoiada na base. */
function barPath(x: number, y: number, w: number, h: number) {
  const r = Math.min(4, w / 2, h)
  return `M${x},${y + h} V${y + r} Q${x},${y} ${x + r},${y} H${x + w - r} Q${x + w},${y} ${x + w},${y + r} V${y + h} Z`
}

const total = computed(() => props.data.reduce((s, d) => s + d.value, 0))
</script>

<template>
  <div class="relative">
    <svg :viewBox="`0 0 ${W} ${H}`" class="h-auto w-full" role="img" :aria-label="`${unit}: total ${formatNumber(total)}`" @mouseleave="hover = null">
      <line :x1="pad.left" :x2="W - pad.right" :y1="pad.top + plotH" :y2="pad.top + plotH" stroke="#cbd5e1" stroke-width="1" />
      <line :x1="pad.left" :x2="W - pad.right" :y1="pad.top" :y2="pad.top" stroke="#f1f5f9" stroke-width="1" />
      <text :x="W - pad.right" :y="pad.top - 4" text-anchor="end" class="fill-slate-400 text-[13px]">{{ formatNumber(max) }}</text>
      <g v-for="(b, i) in bars" :key="b.label">
        <rect :x="b.x - 3" :y="pad.top" :width="barW + 6" :height="plotH" fill="transparent" @mouseenter="hover = i" @focus="hover = i" />
        <path v-if="b.h > 0" :d="barPath(b.x, b.y, barW, b.h)" :fill="color" :opacity="hover === null || hover === i ? 1 : 0.45" class="pointer-events-none transition-opacity" />
        <text v-if="hover === i || (i % every === 0 && (hover === null || Math.abs(hover - i) > 1))" :x="i === 0 ? pad.left : i === bars.length - 1 ? W - pad.right : b.cx" :y="H - 8" :text-anchor="i === 0 ? 'start' : i === bars.length - 1 ? 'end' : 'middle'" class="fill-slate-500 text-[13px]">{{ b.label }}</text>
      </g>
    </svg>
    <div
      v-if="hover !== null && bars[hover]"
      class="pointer-events-none absolute -translate-y-full rounded-lg bg-slate-900 px-2.5 py-1.5 text-xs whitespace-nowrap text-white shadow-lg"
      :class="bars[hover].cx / W > 0.7 ? '-translate-x-full' : bars[hover].cx / W < 0.3 ? '' : '-translate-x-1/2'"
      :style="{ left: `${(bars[hover].cx / W) * 100}%`, top: `${(Math.max(bars[hover].y - 4, 8) / H) * 100}%` }"
    >
      <span class="font-medium">{{ bars[hover].label }}</span> · {{ formatNumber(bars[hover].value) }} {{ unit }}
    </div>
  </div>
</template>
