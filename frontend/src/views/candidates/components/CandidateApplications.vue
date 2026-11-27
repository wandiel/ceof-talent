<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { parseApiError } from '@/api/http'
import { applicationsApi, stageClasses, type ApplicationItem } from '@/api/recruitment'
import { formatDate } from '@/utils/format'
import JobStatusBadge from '@/views/jobs/components/JobStatusBadge.vue'
import StageTimeline from '@/views/jobs/components/StageTimeline.vue'

const props = defineProps<{ candidateId: string }>()
const items = ref<ApplicationItem[]>([])
const loading = ref(true)
const error = ref('')
const expanded = ref<string | null>(null)

onMounted(async () => {
  try {
    items.value = await applicationsApi.forCandidate(props.candidateId)
    expanded.value = items.value[0]?.id ?? null
  } catch (e) {
    error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <section class="max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-semibold text-slate-900">Processos seletivos</h2>
    <p class="mb-4 text-sm text-slate-500">Vagas em que o candidato está ou esteve inscrito, com as etapas percorridas.</p>

    <p v-if="loading" class="text-sm text-slate-500">Carregando…</p>
    <p v-else-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
    <p v-else-if="!items.length" class="text-sm text-slate-500">O candidato ainda não participou de nenhum processo seletivo.</p>

    <ul v-else class="divide-y divide-slate-100 rounded-lg border border-slate-200">
      <li v-for="a in items" :key="a.id">
        <button type="button" class="flex w-full flex-wrap items-center gap-2 px-4 py-3 text-left hover:bg-slate-50" :aria-expanded="expanded === a.id" @click="expanded = expanded === a.id ? null : a.id">
          <span class="font-mono text-xs text-slate-500">{{ a.job?.code }}</span>
          <span class="font-medium text-slate-900">{{ a.job?.title }}</span>
          <JobStatusBadge v-if="a.job" :status="a.job.status" :label="a.job.status_label" />
          <span class="ml-auto inline-flex items-center gap-1.5 text-sm text-slate-700">
            <span class="size-2 rounded-full" :class="stageClasses[a.stage]" />{{ a.stage_label }}
          </span>
        </button>
        <div v-if="expanded === a.id" class="border-t border-slate-100 bg-slate-50/50 px-4 py-3">
          <p class="mb-3 text-xs text-slate-500">
            {{ a.job?.department }} · Inscrito em {{ formatDate(a.created_at) }}
            <template v-if="a.rejection_reason_label"> · Motivo: {{ a.rejection_reason_label }}</template> ·
            <RouterLink v-if="a.job" :to="{ name: 'job-detail', params: { id: a.job.id } }" class="text-brand-700 hover:underline">abrir vaga</RouterLink>
          </p>
          <StageTimeline :entries="a.history ?? []" />
        </div>
      </li>
    </ul>
  </section>
</template>
