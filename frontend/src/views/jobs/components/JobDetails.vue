<script setup lang="ts">
import type { Job } from '@/api/recruitment'
import { formatDate, formatDateTime, formatMoney } from '@/utils/format'

defineProps<{ job: Job }>()
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-3">
    <div class="space-y-6 lg:col-span-2">
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-2 text-base font-semibold text-slate-900">Descrição</h2>
        <p class="whitespace-pre-line text-sm text-slate-700">{{ job.description }}</p>
        <template v-if="job.responsibilities">
          <h3 class="mb-1 mt-5 text-sm font-semibold text-slate-900">Principais atividades</h3>
          <p class="whitespace-pre-line text-sm text-slate-700">{{ job.responsibilities }}</p>
        </template>
        <template v-if="job.benefits">
          <h3 class="mb-1 mt-5 text-sm font-semibold text-slate-900">Benefícios</h3>
          <p class="whitespace-pre-line text-sm text-slate-700">{{ job.benefits }}</p>
        </template>
      </section>
      <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="mb-3 text-base font-semibold text-slate-900">Requisitos</h2>
        <ul v-if="job.requirements.length" class="space-y-2">
          <li v-for="(r, i) in job.requirements" :key="i" class="flex items-start gap-2 text-sm text-slate-700">
            <span class="mt-0.5 shrink-0 rounded px-1.5 text-xs font-medium" :class="r.is_mandatory ? 'bg-brand-50 text-brand-800' : 'bg-slate-100 text-slate-600'">{{ r.is_mandatory ? 'Obrigatório' : 'Desejável' }}</span>
            {{ r.description }}
          </li>
        </ul>
        <p v-else class="text-sm text-slate-500">Nenhum requisito cadastrado.</p>
      </section>
    </div>

    <section class="h-fit rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="mb-3 text-base font-semibold text-slate-900">Ficha da vaga</h2>
      <dl class="space-y-3 text-sm">
        <div><dt class="text-xs text-slate-500">Departamento solicitante</dt><dd class="text-slate-800">{{ job.department.name }}</dd></div>
        <div><dt class="text-xs text-slate-500">Unidade</dt><dd class="text-slate-800">{{ job.unit?.name ?? '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Cargo</dt><dd class="text-slate-800">{{ job.position?.name ?? '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Área profissional</dt><dd class="text-slate-800">{{ job.professional_area_label }}</dd></div>
        <div><dt class="text-xs text-slate-500">Contratação</dt><dd class="text-slate-800">{{ job.employment_type_label }} · {{ job.reason_label }}<template v-if="job.replaced_employee"> ({{ job.replaced_employee }})</template></dd></div>
        <div><dt class="text-xs text-slate-500">Posições</dt><dd class="text-slate-800">{{ job.hired_total }} de {{ job.positions_count }} preenchida{{ job.hired_total === 1 ? '' : 's' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Jornada</dt><dd class="text-slate-800">{{ job.work_schedule || '—' }}</dd></div>
        <div>
          <dt class="text-xs text-slate-500">Faixa salarial</dt>
          <dd class="text-slate-800">
            <template v-if="job.salary_min || job.salary_max">{{ formatMoney(job.salary_min) }} a {{ formatMoney(job.salary_max) }}</template><template v-else>—</template>
            <span class="text-xs text-slate-500"> · {{ job.show_salary ? 'divulgada' : 'não divulgada' }}</span>
          </dd>
        </div>
        <div><dt class="text-xs text-slate-500">Escolaridade mínima</dt><dd class="text-slate-800">{{ job.education_level_min_label ?? 'Não exigida' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Inscrições até</dt><dd class="text-slate-800">{{ job.closes_on ? formatDate(job.closes_on) : 'Sem data limite' }}</dd></div>
        <div class="border-t border-slate-100 pt-3"><dt class="text-xs text-slate-500">Solicitada por</dt><dd class="text-slate-800">{{ job.requester?.name ?? '—' }} · {{ formatDateTime(job.created_at) }}</dd></div>
        <div v-if="job.approved_at"><dt class="text-xs text-slate-500">Aprovada por</dt><dd class="text-slate-800">{{ job.approver?.name ?? '—' }} · {{ formatDateTime(job.approved_at) }}</dd></div>
        <div v-if="job.published_at"><dt class="text-xs text-slate-500">Publicada em</dt><dd class="text-slate-800">{{ formatDateTime(job.published_at) }}</dd></div>
        <div v-if="job.closed_at"><dt class="text-xs text-slate-500">Encerrada em</dt><dd class="text-slate-800">{{ formatDateTime(job.closed_at) }}</dd></div>
      </dl>
    </section>
  </div>
</template>
