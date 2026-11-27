<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { jobsApi, type JobHistoryEntry } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import { formatDateTime } from '@/utils/format'

const props = defineProps<{ jobId: string }>()
const entries = ref<JobHistoryEntry[]>([])
const page = ref(1)
const lastPage = ref(1)
const loading = ref(false)
const error = ref('')

async function load(reset = true) {
  loading.value = true
  try {
    if (reset) page.value = 1
    const result = await jobsApi.history(props.jobId, page.value)
    entries.value = reset ? result.data : [...entries.value, ...result.data]
    lastPage.value = result.meta.last_page
  } catch (e) {
    error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}
onMounted(() => load())

const dot: Record<string, string> = {
  'job.created': 'bg-slate-400',
  'job.approve': 'bg-sky-500',
  'job.publish': 'bg-emerald-500',
  'job.reject': 'bg-red-500',
  'job.cancel': 'bg-red-500',
  'job.suspend': 'bg-orange-500',
  'job.close': 'bg-slate-600',
}
</script>

<template>
  <section class="max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <h2 class="text-base font-semibold text-slate-900">Histórico da vaga</h2>
    <p class="mb-4 text-sm text-slate-500">Criação, alterações, aprovação, publicação e encerramento. As movimentações de candidatos ficam no quadro e na ficha de cada um.</p>
    <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
    <ol v-else-if="entries.length" class="space-y-4 border-l border-slate-200 pl-4">
      <li v-for="e in entries" :key="e.id" class="relative">
        <span class="absolute -left-[21px] top-1.5 size-2.5 rounded-full" :class="dot[e.event] ?? 'bg-brand-500'" />
        <p class="text-sm font-medium text-slate-900">{{ e.event_label }}</p>
        <p v-if="e.description && e.description !== e.event_label" class="text-sm text-slate-600">{{ e.description }}</p>
        <p v-if="e.reason" class="text-sm text-slate-600">Motivo: {{ e.reason }}</p>
        <p class="text-xs text-slate-500">{{ e.user ?? 'Sistema' }} · {{ formatDateTime(e.created_at) }}</p>
      </li>
    </ol>
    <p v-else-if="!loading" class="text-sm text-slate-500">Nenhum registro.</p>
    <BaseButton v-if="page < lastPage" variant="secondary" size="sm" class="mt-4" :loading="loading" @click="page++; load(false)">Carregar mais</BaseButton>
  </section>
</template>
