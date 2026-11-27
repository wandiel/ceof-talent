<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { parseApiError } from '@/api/http'
import { formatDateBr, portalApi, type PortalOptions, type PublicJob } from '@/api/portal'
import { inputClass } from '@/components/ui/inputClass'

const jobs = ref<PublicJob[]>([])
const options = ref<PortalOptions | null>(null)
const search = ref('')
const area = ref('')
const loading = ref(true)
const error = ref('')
let timer: ReturnType<typeof setTimeout> | undefined

async function load() {
  loading.value = true
  error.value = ''
  try {
    jobs.value = await portalApi.jobs({ search: search.value || undefined, area: area.value || undefined })
  } catch (e) {
    error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  load()
  options.value = await portalApi.options().catch(() => null)
})
watch(search, () => {
  clearTimeout(timer)
  timer = setTimeout(load, 300)
})
watch(area, load)
</script>

<template>
  <section class="rounded-2xl bg-gradient-to-r from-brand-700 to-brand-800 px-6 py-10 text-white sm:px-10">
    <h1 class="text-2xl font-semibold sm:text-3xl">Venha cuidar da visão das pessoas com a gente</h1>
    <p class="mt-2 max-w-2xl text-brand-100">Confira as vagas abertas no Hospital de Olhos CEOF. Não encontrou a sua? Envie o seu currículo para o nosso banco de talentos.</p>
    <RouterLink to="/trabalhe-conosco/cadastro" class="mt-5 inline-flex rounded-lg bg-white px-4 py-2 text-sm font-semibold text-brand-800 shadow-sm hover:bg-brand-50">Enviar currículo sem vaga específica</RouterLink>
  </section>

  <div class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-end">
    <label class="flex-1 text-sm font-medium text-slate-700">
      Buscar vaga
      <input v-model="search" type="search" placeholder="Ex.: enfermagem, recepção" :class="inputClass()" class="mt-1" />
    </label>
    <label class="text-sm font-medium text-slate-700 sm:w-64">
      Área
      <select v-model="area" :class="inputClass()" class="mt-1">
        <option value="">Todas as áreas</option>
        <option v-for="a in options?.areas ?? []" :key="a.value" :value="a.value">{{ a.label }}</option>
      </select>
    </label>
  </div>

  <p class="mt-6 text-sm text-slate-500" aria-live="polite">
    <template v-if="loading">Carregando vagas…</template>
    <template v-else-if="error">{{ error }}</template>
    <template v-else>{{ jobs.length }} {{ jobs.length === 1 ? 'vaga aberta' : 'vagas abertas' }}</template>
  </p>

  <ul class="mt-3 grid gap-4 md:grid-cols-2">
    <li v-for="job in jobs" :key="job.id">
      <RouterLink :to="`/trabalhe-conosco/vagas/${job.id}`" class="block h-full rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-brand-200 hover:shadow">
        <p class="text-xs font-medium tracking-wide text-brand-700 uppercase">{{ job.professional_area_label }}</p>
        <h2 class="mt-1 text-lg font-semibold text-slate-900">{{ job.title }}</h2>
        <p class="mt-1 text-sm text-slate-500">
          {{ job.employment_type_label }}<span v-if="job.unit"> · {{ job.unit.city ?? job.unit.name }}<span v-if="job.unit.state">/{{ job.unit.state }}</span></span>
          · {{ job.positions_count }} {{ job.positions_count === 1 ? 'vaga' : 'vagas' }}
        </p>
        <p class="mt-3 text-sm text-slate-600">{{ job.summary }}</p>
        <p v-if="job.closes_on" class="mt-3 text-xs text-slate-500">Inscrições até {{ formatDateBr(job.closes_on) }}</p>
      </RouterLink>
    </li>
  </ul>
  <div v-if="!loading && !error && !jobs.length" class="mt-3 rounded-2xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-600">
    Nenhuma vaga aberta com esses filtros no momento.
    <RouterLink to="/trabalhe-conosco/cadastro" class="font-medium text-brand-700 hover:underline">Envie o seu currículo</RouterLink> e avisaremos quando surgir uma oportunidade.
  </div>
</template>
