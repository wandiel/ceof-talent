<script setup lang="ts">
import { computed } from 'vue'
import { PERIODS, periodRange, type FilterOptions, type PeriodPreset } from '@/api/reports'
import { inputClass } from '@/components/ui/inputClass'

export interface FilterState {
  preset: PeriodPreset
  from: string | null
  to: string | null
  department: string | null
  unit: string | null
  area: string | null
}

const model = defineModel<FilterState>({ required: true })
const props = defineProps<{ options: FilterOptions | null }>()
const emit = defineEmits<{ change: [] }>()

const select = inputClass().replace('w-full', 'w-auto min-w-40')

function setPreset(p: PeriodPreset) {
  model.value.preset = p
  if (p !== 'custom') Object.assign(model.value, periodRange(p))
  emit('change')
}

const hasExtra = computed(() => !!(model.value.unit || model.value.area || (!props.options?.department_locked && model.value.department)))

function clear() {
  Object.assign(model.value, { unit: null, area: null, department: props.options?.department_locked ? model.value.department : null })
  emit('change')
}
</script>

<template>
  <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm" role="search" aria-label="Filtros">
    <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
      Período
      <select :value="model.preset" :class="select" aria-label="Período" @change="setPreset(($event.target as HTMLSelectElement).value as PeriodPreset)">
        <option v-for="p in PERIODS" :key="p.value" :value="p.value">{{ p.label }}</option>
      </select>
    </label>
    <template v-if="model.preset === 'custom'">
      <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
        De
        <input id="f-from" v-model="model.from" type="date" :class="select" @change="emit('change')" />
      </label>
      <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
        Até
        <input id="f-to" v-model="model.to" type="date" :class="select" @change="emit('change')" />
      </label>
    </template>
    <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
      Departamento
      <select id="f-department" v-model="model.department" :class="select" :disabled="options?.department_locked" @change="emit('change')">
        <option v-if="!options?.department_locked" :value="null">Todos</option>
        <option v-for="d in options?.departments ?? []" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
      Unidade
      <select id="f-unit" v-model="model.unit" :class="select" @change="emit('change')">
        <option :value="null">Todas</option>
        <option v-for="u in options?.units ?? []" :key="u.id" :value="u.id">{{ u.name }}</option>
      </select>
    </label>
    <label class="flex flex-col gap-1 text-xs font-medium text-slate-600">
      Área profissional
      <select id="f-area" v-model="model.area" :class="select" @change="emit('change')">
        <option :value="null">Todas</option>
        <option v-for="a in options?.areas ?? []" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
    </label>
    <button v-if="hasExtra" type="button" class="mb-2 text-sm text-brand-700 hover:underline" @click="clear">Limpar filtros</button>
    <slot />
  </div>
</template>
