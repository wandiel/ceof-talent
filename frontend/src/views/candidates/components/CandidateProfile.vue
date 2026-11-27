<script setup lang="ts">
import type { Candidate } from '@/api/candidates'
import { formatDate, formatMonth, formatPhone } from '@/utils/format'

defineProps<{ candidate: Candidate }>()
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <section v-if="candidate.summary" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-2 text-sm font-semibold uppercase tracking-wider text-slate-500">Resumo profissional</h2>
        <p class="whitespace-pre-line text-sm text-slate-700">{{ candidate.summary }}</p>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">Experiência profissional</h2>
        <ol v-if="candidate.experiences.length" class="space-y-4 border-l border-slate-200 pl-4">
          <li v-for="(x, i) in candidate.experiences" :key="i" class="relative">
            <span class="absolute -left-[21px] top-1.5 size-2.5 rounded-full" :class="x.is_current ? 'bg-brand-600' : 'bg-slate-300'" />
            <p class="font-medium text-slate-900">{{ x.job_title }}</p>
            <p class="text-sm text-slate-600">{{ x.company }}</p>
            <p class="text-xs text-slate-500">{{ formatMonth(x.start_date) }} – {{ x.is_current ? 'atual' : formatMonth(x.end_date) }}</p>
            <p v-if="x.description" class="mt-1 whitespace-pre-line text-sm text-slate-600">{{ x.description }}</p>
          </li>
        </ol>
        <p v-else class="text-sm text-slate-500">Nenhuma experiência informada.</p>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">Formação acadêmica</h2>
        <ul v-if="candidate.educations.length" class="divide-y divide-slate-100">
          <li v-for="(e, i) in candidate.educations" :key="i" class="py-2 first:pt-0 last:pb-0">
            <p class="font-medium text-slate-900">{{ e.course || e.level_label }}</p>
            <p class="text-sm text-slate-600">{{ e.institution }} · {{ e.level_label }}</p>
            <p class="text-xs text-slate-500">{{ e.status_label }}<template v-if="e.start_year && e.end_year"> · {{ e.start_year }}–{{ e.end_year }}</template><template v-else-if="e.end_year"> · {{ e.status === 'in_progress' ? 'previsão ' : '' }}{{ e.end_year }}</template><template v-else-if="e.start_year"> · desde {{ e.start_year }}</template></p>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-500">Nenhuma formação informada.</p>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-4 text-sm font-semibold uppercase tracking-wider text-slate-500">Cursos e certificações</h2>
        <ul v-if="candidate.courses.length" class="divide-y divide-slate-100">
          <li v-for="(c, i) in candidate.courses" :key="i" class="flex items-start justify-between gap-3 py-2 first:pt-0 last:pb-0">
            <div>
              <p class="font-medium text-slate-900">{{ c.name }}</p>
              <p class="text-sm text-slate-600">{{ [c.institution, c.year, c.workload_hours ? `${c.workload_hours} h` : null].filter(Boolean).join(' · ') }}</p>
              <p v-if="c.credential" class="text-xs text-slate-500">Registro: {{ c.credential }}</p>
            </div>
            <span class="shrink-0 rounded-md bg-slate-100 px-2 py-0.5 text-xs text-slate-600">{{ c.type_label }}</span>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-500">Nenhum curso informado.</p>
      </section>
    </div>

    <div class="space-y-6">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-500">Competências</h2>
        <ul v-if="candidate.skills.length" class="flex flex-wrap gap-2">
          <li v-for="s in candidate.skills" :key="s.name" class="rounded-lg bg-brand-50 px-2.5 py-1 text-sm text-brand-900 ring-1 ring-brand-200">
            {{ s.name }}<span v-if="s.level" class="text-xs text-brand-700"> · {{ { basic: 'básico', intermediate: 'intermediário', advanced: 'avançado' }[s.level] }}</span>
          </li>
        </ul>
        <p v-else class="text-sm text-slate-500">Nenhuma competência informada.</p>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-500">Dados pessoais e contato</h2>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between gap-3"><dt class="text-slate-500">CPF</dt><dd class="text-right text-slate-800">{{ candidate.cpf || candidate.cpf_masked || '—' }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Nascimento</dt><dd class="text-right text-slate-800">{{ candidate.birth_date ? `${formatDate(candidate.birth_date)} (${candidate.age} anos)` : '—' }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Telefone 2</dt><dd class="text-right text-slate-800">{{ formatPhone(candidate.phone_secondary) || '—' }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Endereço</dt><dd class="text-right text-slate-800">{{ [candidate.neighborhood, candidate.city, candidate.state].filter(Boolean).join(', ') || '—' }}</dd></div>
          <div v-if="candidate.linkedin_url" class="flex justify-between gap-3"><dt class="text-slate-500">LinkedIn</dt><dd class="truncate text-right"><a :href="candidate.linkedin_url" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">Abrir perfil</a></dd></div>
          <div v-if="candidate.portfolio_url" class="flex justify-between gap-3"><dt class="text-slate-500">Portfólio</dt><dd class="truncate text-right"><a :href="candidate.portfolio_url" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">Abrir site</a></dd></div>
        </dl>
      </section>

      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold uppercase tracking-wider text-slate-500">Disponibilidade</h2>
        <dl class="space-y-2 text-sm">
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Turnos</dt><dd class="text-right text-slate-800">{{ candidate.work_shifts_labels.join(', ') || '—' }}</dd></div>
          <div class="flex justify-between gap-3"><dt class="text-slate-500">Muda de cidade</dt><dd class="text-right text-slate-800">{{ candidate.willing_to_relocate ? 'Sim' : 'Não' }}</dd></div>
          <div v-if="candidate.source_detail" class="flex justify-between gap-3"><dt class="text-slate-500">Origem</dt><dd class="text-right text-slate-800">{{ candidate.source_detail }}</dd></div>
        </dl>
      </section>
    </div>
  </div>
</template>
