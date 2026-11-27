<script setup lang="ts">
import { computed, ref } from 'vue'
import type { Option } from '@/api/candidates'
import { canMoveTo, PIPELINE, stageClasses, type ApplicationItem, type Stage } from '@/api/recruitment'
import { formatDate } from '@/utils/format'

const props = defineProps<{
  applications: ApplicationItem[]
  stages: Option[]
  abilities: { move: boolean; decide: boolean }
  frozen: boolean
}>()
const emit = defineEmits<{ open: [application: ApplicationItem]; drop: [application: ApplicationItem, stage: Stage]; menu: [application: ApplicationItem] }>()

const columns = computed(() => [...PIPELINE, 'rejected', 'withdrawn'] as Stage[])
const label = (s: Stage) => props.stages.find((o) => o.value === s)?.label ?? s
const byStage = computed(() => {
  const map = new Map<Stage, ApplicationItem[]>(columns.value.map((s) => [s, []]))
  for (const a of props.applications) map.get(a.stage)?.push(a)
  return map
})

const dragging = ref<ApplicationItem | null>(null)
const over = ref<Stage | null>(null)
const canDrag = computed(() => !props.frozen && (props.abilities.move || props.abilities.decide))

function accepts(stage: Stage): boolean {
  return !!dragging.value && canMoveTo(dragging.value.stage, stage, props.abilities)
}

function onDragStart(event: DragEvent, a: ApplicationItem) {
  dragging.value = a
  event.dataTransfer?.setData('text/plain', a.id)
  if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move'
}

function onDragOver(event: DragEvent, stage: Stage) {
  if (!accepts(stage)) return
  event.preventDefault()
  over.value = stage
}

function onDrop(stage: Stage) {
  const a = dragging.value
  dragging.value = null
  over.value = null
  if (a && canMoveTo(a.stage, stage, props.abilities)) emit('drop', a, stage)
}

function onDragEnd() {
  dragging.value = null
  over.value = null
}

function name(a: ApplicationItem) {
  return a.candidate?.social_name || a.candidate?.full_name || '—'
}

function days(a: ApplicationItem): string {
  const d = Math.floor((Date.now() - new Date(a.stage_changed_at).getTime()) / 86_400_000)
  return d <= 0 ? 'hoje' : d === 1 ? 'há 1 dia' : `há ${d} dias`
}
</script>

<template>
  <div class="-mx-4 overflow-x-auto px-4 pb-2 sm:mx-0 sm:px-0">
    <div class="flex min-w-max gap-3">
      <section
        v-for="stage in columns"
        :key="stage"
        class="flex w-56 shrink-0 flex-col rounded-xl border transition"
        :class="[
          over === stage ? 'border-brand-400 bg-brand-50/60' : dragging && accepts(stage) ? 'border-dashed border-brand-300 bg-slate-50' : 'border-slate-200 bg-slate-50',
          dragging && !accepts(stage) && dragging.stage !== stage ? 'opacity-50' : '',
        ]"
        :aria-label="label(stage)"
        :data-stage="stage"
        @dragover="onDragOver($event, stage)"
        @dragleave="over = over === stage ? null : over"
        @drop.prevent="onDrop(stage)"
      >
        <header class="flex items-center gap-2 px-3 py-2.5">
          <span class="size-2 rounded-full" :class="stageClasses[stage]" />
          <h3 class="text-sm font-semibold text-slate-800">{{ label(stage) }}</h3>
          <span class="ml-auto rounded-full bg-white px-2 text-xs font-medium text-slate-600 ring-1 ring-slate-200">{{ byStage.get(stage)?.length ?? 0 }}</span>
        </header>
        <ul class="flex min-h-24 flex-1 flex-col gap-2 px-2 pb-2">
          <li
            v-for="a in byStage.get(stage)"
            :key="a.id"
            :draggable="canDrag"
            class="group rounded-lg border border-slate-200 bg-white p-2.5 shadow-sm"
            :class="[canDrag ? 'cursor-grab active:cursor-grabbing' : 'cursor-pointer', dragging?.id === a.id ? 'opacity-40' : '']"
            :data-application="a.id"
            @dragstart="onDragStart($event, a)"
            @dragend="onDragEnd"
            @click="emit('open', a)"
          >
            <div class="flex items-start gap-2">
              <p class="min-w-0 flex-1 truncate text-sm font-medium text-slate-900">{{ name(a) }}</p>
              <button
                v-if="canDrag"
                type="button"
                class="-m-1 rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-700"
                :aria-label="`Movimentar ${name(a)}`"
                @click.stop="emit('menu', a)"
              >
                <svg class="size-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10 3a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm0 5.5a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm1.5 7a1.5 1.5 0 1 0-3 0 1.5 1.5 0 0 0 3 0Z" /></svg>
              </button>
            </div>
            <p class="truncate text-xs text-slate-500">{{ a.candidate?.desired_position || '—' }}<template v-if="a.candidate?.city"> · {{ a.candidate.city }}</template></p>
            <p v-if="a.rejection_reason_label" class="mt-1 text-xs text-red-700">{{ a.rejection_reason_label }}</p>
            <p class="mt-1 text-[11px] text-slate-400" :title="`Nesta etapa desde ${formatDate(a.stage_changed_at)}`">Nesta etapa {{ days(a) }}</p>
          </li>
        </ul>
      </section>
    </div>
  </div>
</template>
