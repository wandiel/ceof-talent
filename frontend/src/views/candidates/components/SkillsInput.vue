<script setup lang="ts">
import { ref, watch } from 'vue'
import { candidatesApi, type CandidateSkill, type Option } from '@/api/candidates'
import { inputClass } from '@/components/ui/inputClass'

const skills = defineModel<CandidateSkill[]>({ required: true })
defineProps<{ levels: Option[]; errors: Record<string, string> }>()

const text = ref('')
const suggestions = ref<string[]>([])
let timer: number | undefined

watch(text, (value) => {
  window.clearTimeout(timer)
  const term = value.trim()
  if (term.length < 2) {
    suggestions.value = []
    return
  }
  timer = window.setTimeout(async () => {
    try {
      const taken = new Set(skills.value.map((s) => s.name.toLowerCase()))
      suggestions.value = (await candidatesApi.skills(term)).map((s) => s.name).filter((n) => !taken.has(n.toLowerCase())).slice(0, 8)
    } catch {
      suggestions.value = []
    }
  }, 250)
})

function add(name = text.value) {
  const clean = name.trim().replace(/\s+/g, ' ')
  if (clean.length < 2 || skills.value.some((s) => s.name.toLowerCase() === clean.toLowerCase())) {
    text.value = ''
    return
  }
  skills.value.push({ name: clean.slice(0, 80), level: null })
  text.value = ''
  suggestions.value = []
}
</script>

<template>
  <div>
    <div class="relative">
      <input
        v-model="text"
        type="text"
        maxlength="80"
        placeholder="Ex.: Atendimento ao paciente, Excel, Tonometria"
        aria-label="Nova competência"
        :class="inputClass()"
        @keydown.enter.prevent="add()"
        @keydown.,.prevent="add()"
      />
      <ul v-if="suggestions.length" class="absolute z-20 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white text-sm shadow-lg">
        <li v-for="s in suggestions" :key="s">
          <button type="button" class="block w-full px-3 py-2 text-left hover:bg-brand-50" @mousedown.prevent="add(s)">{{ s }}</button>
        </li>
      </ul>
    </div>
    <ul v-if="skills.length" class="mt-3 flex flex-wrap gap-2">
      <li v-for="(s, i) in skills" :key="s.name" class="inline-flex items-center gap-1 rounded-lg bg-brand-50 py-1 pl-3 pr-1 text-sm text-brand-900 ring-1" :class="errors[`skills.${i}.name`] ? 'ring-red-400' : 'ring-brand-200'" :title="errors[`skills.${i}.name`]">
        {{ s.name }}
        <select v-model="s.level" class="rounded border-0 bg-transparent py-0 pl-1 pr-6 text-xs text-brand-800 focus:ring-1 focus:ring-brand-300" :aria-label="`Nível de ${s.name}`">
          <option :value="null">nível —</option>
          <option v-for="l in levels" :key="l.value" :value="l.value">{{ l.label }}</option>
        </select>
        <button type="button" class="rounded px-1.5 text-brand-700 hover:bg-brand-100" :aria-label="`Remover ${s.name}`" @click="skills.splice(i, 1)">×</button>
      </li>
    </ul>
    <p v-else class="mt-2 text-sm text-slate-500">Nenhuma competência informada.</p>
  </div>
</template>
