<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { parseApiError } from '@/api/http'
import { formatDateBr, formatMoney, portalApi, type PublicJob } from '@/api/portal'

const route = useRoute()
const job = ref<PublicJob | null>(null)
const error = ref('')

onMounted(async () => {
  try {
    job.value = await portalApi.job(String(route.params.id))
    document.title = `${job.value.title} · Trabalhe conosco · CEOF`
  } catch (e) {
    const err = parseApiError(e)
    error.value = err.status === 404 ? 'Esta vaga não está mais disponível.' : err.message
  }
})

const salary = (j: PublicJob) => {
  if (!j.salary) return null
  const min = formatMoney(j.salary.min)
  const max = formatMoney(j.salary.max)
  return min && max ? `${min} a ${max}` : (min ?? max)
}
</script>

<template>
  <RouterLink to="/trabalhe-conosco" class="text-sm text-slate-600 hover:text-brand-700">← Todas as vagas</RouterLink>

  <div v-if="error" class="mt-6 rounded-2xl border border-slate-200 bg-white p-8 text-center">
    <p class="text-slate-700">{{ error }}</p>
    <RouterLink to="/trabalhe-conosco" class="mt-3 inline-block font-medium text-brand-700 hover:underline">Ver as vagas abertas</RouterLink>
  </div>

  <div v-else-if="job" class="mt-4 grid gap-6 lg:grid-cols-[1fr_20rem]">
    <article class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
      <p class="text-xs font-medium tracking-wide text-brand-700 uppercase">{{ job.professional_area_label }} · {{ job.code }}</p>
      <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ job.title }}</h1>

      <h2 class="mt-6 font-semibold text-slate-900">Sobre a vaga</h2>
      <p class="mt-2 whitespace-pre-line text-slate-700">{{ job.description }}</p>

      <template v-if="job.responsibilities">
        <h2 class="mt-6 font-semibold text-slate-900">Atividades</h2>
        <p class="mt-2 whitespace-pre-line text-slate-700">{{ job.responsibilities }}</p>
      </template>

      <template v-if="job.requirements?.length">
        <h2 class="mt-6 font-semibold text-slate-900">Requisitos</h2>
        <ul class="mt-2 space-y-1.5 text-slate-700">
          <li v-for="r in job.requirements" :key="r.description" class="flex gap-2">
            <span aria-hidden="true" class="text-brand-700">•</span>
            <span>{{ r.description }} <span class="text-xs text-slate-500">({{ r.is_mandatory ? 'obrigatório' : 'desejável' }})</span></span>
          </li>
        </ul>
      </template>

      <template v-if="job.benefits">
        <h2 class="mt-6 font-semibold text-slate-900">Benefícios</h2>
        <p class="mt-2 whitespace-pre-line text-slate-700">{{ job.benefits }}</p>
      </template>
    </article>

    <aside class="h-fit space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm lg:sticky lg:top-6">
      <dl class="space-y-3 text-sm">
        <div><dt class="text-slate-500">Contratação</dt><dd class="font-medium text-slate-900">{{ job.employment_type_label }}</dd></div>
        <div v-if="job.unit"><dt class="text-slate-500">Local</dt><dd class="font-medium text-slate-900">{{ job.unit.name }}<span v-if="job.unit.city"> · {{ job.unit.city }}/{{ job.unit.state }}</span></dd></div>
        <div v-if="job.work_schedule"><dt class="text-slate-500">Jornada</dt><dd class="font-medium text-slate-900">{{ job.work_schedule }}</dd></div>
        <div v-if="salary(job)"><dt class="text-slate-500">Salário</dt><dd class="font-medium text-slate-900">{{ salary(job) }}</dd></div>
        <div v-if="job.education_level_min_label"><dt class="text-slate-500">Escolaridade mínima</dt><dd class="font-medium text-slate-900">{{ job.education_level_min_label }}</dd></div>
        <div><dt class="text-slate-500">Vagas</dt><dd class="font-medium text-slate-900">{{ job.positions_count }}</dd></div>
        <div v-if="job.closes_on"><dt class="text-slate-500">Inscrições até</dt><dd class="font-medium text-slate-900">{{ formatDateBr(job.closes_on) }}</dd></div>
      </dl>
      <RouterLink :to="{ path: '/trabalhe-conosco/cadastro', query: { vaga: job.id } }" class="block rounded-lg bg-brand-700 px-4 py-2.5 text-center text-sm font-semibold text-white shadow-sm hover:bg-brand-800">Quero me candidatar</RouterLink>
    </aside>
  </div>
  <div v-else class="mt-4 h-96 animate-pulse rounded-2xl bg-slate-100" />
</template>
