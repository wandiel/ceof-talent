<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import { jobsApi, type JobListItem, type JobOptions } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useListing } from '@/composables/useListing'
import { useToastStore } from '@/stores/toast'
import { formatDate } from '@/utils/format'
import JobStatusBadge from './components/JobStatusBadge.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

/** Atalhos de situação (cada um agrupa uma ou mais situações). */
const groups = [
  { key: '', label: 'Todas', statuses: [] },
  { key: 'abertas', label: 'Em andamento', statuses: ['approved', 'published', 'on_hold'] },
  { key: 'aprovacao', label: 'Aguardando aprovação', statuses: ['pending_approval'] },
  { key: 'rascunhos', label: 'Rascunhos e devolvidas', statuses: ['draft', 'rejected'] },
  { key: 'encerradas', label: 'Encerradas', statuses: ['closed', 'cancelled'] },
] as const

const q = route.query
const { params, items, meta, loading, goTo } = useListing<JobListItem>(
  (p) => jobsApi.list({ ...p, statuses: groups.find((g) => g.key === p.group)?.statuses as unknown as string[], mine: p.mine === '1' } as never),
  {
    sort: '-created_at',
    search: typeof q.search === 'string' ? q.search : '',
    group: typeof q.situacao === 'string' ? q.situacao : '',
    department_id: typeof q.departamento === 'string' ? q.departamento : '',
    mine: q.minhas === '1' ? '1' : '',
  },
)

const options = ref<JobOptions | null>(null)
onMounted(async () => {
  try {
    options.value = await jobsApi.options()
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
})

watch(
  () => [params.search, params.group, params.department_id, params.mine],
  () => {
    router.replace({
      query: {
        search: params.search || undefined,
        situacao: params.group || undefined,
        departamento: params.department_id || undefined,
        minhas: params.mine || undefined,
      },
    })
  },
)

const subtitle = computed(() => (loading.value ? 'Carregando…' : `${meta.value.total} vaga${meta.value.total === 1 ? '' : 's'}`))

function progress(job: JobListItem): number {
  return Math.min(100, Math.round(((job.hired_count ?? 0) / job.positions_count) * 100))
}
</script>

<template>
  <PageHeader title="Vagas" subtitle="Requisições de pessoal, aprovações e processos seletivos.">
    <template #actions>
      <BaseButton v-if="options?.can_create" @click="router.push({ name: 'job-create' })">Nova vaga</BaseButton>
    </template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-wrap gap-1 border-b border-slate-100 px-3 pt-3" role="tablist">
      <button
        v-for="g in groups"
        :key="g.key"
        type="button"
        role="tab"
        :aria-selected="params.group === g.key"
        class="-mb-px whitespace-nowrap rounded-t-lg border-b-2 px-3 py-2 text-sm font-medium"
        :class="params.group === g.key ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="params.group = g.key"
      >
        {{ g.label }}
      </button>
    </div>

    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center">
      <input v-model="params.search" type="search" placeholder="Buscar por título ou código" aria-label="Buscar vagas" :class="inputClass()" class="sm:max-w-xs" />
      <div v-if="options?.can_choose_department" class="sm:w-56">
        <select v-model="params.department_id" aria-label="Departamento" :class="inputClass()">
          <option value="">Todos os departamentos</option>
          <option v-for="d in options.departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </div>
      <label class="inline-flex items-center gap-2 text-sm text-slate-600">
        <input v-model="params.mine" type="checkbox" true-value="1" false-value="" class="size-4 rounded border-slate-300" />
        Só as que eu solicitei
      </label>
      <p class="text-xs text-slate-500 sm:ml-auto" aria-live="polite">{{ subtitle }}</p>
    </div>

    <ul class="divide-y divide-slate-100" :class="{ 'opacity-60': loading }">
      <li v-for="job in items" :key="job.id" class="flex cursor-pointer flex-col gap-3 px-4 py-4 hover:bg-slate-50/60 sm:flex-row sm:items-center" @click="router.push({ name: 'job-detail', params: { id: job.id } })">
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-xs text-slate-500">{{ job.code }}</span>
            <RouterLink :to="{ name: 'job-detail', params: { id: job.id } }" class="font-medium text-slate-900 hover:text-brand-700" @click.stop>{{ job.title }}</RouterLink>
            <JobStatusBadge :status="job.status" :label="job.status_label" />
          </div>
          <p class="mt-0.5 text-sm text-slate-600">
            {{ job.department.name }}<template v-if="job.unit"> · {{ job.unit.name }}</template> · {{ job.employment_type_label }}
          </p>
          <p class="mt-0.5 text-xs text-slate-500">
            Solicitada por {{ job.requester?.name ?? '—' }} em {{ formatDate(job.created_at) }}
            <template v-if="job.closes_on"> · Inscrições até {{ formatDate(job.closes_on) }}</template>
          </p>
        </div>
        <div class="flex shrink-0 items-center gap-6 text-sm">
          <div class="text-center">
            <p class="text-lg font-semibold text-slate-900">{{ job.active_applications_count ?? 0 }}</p>
            <p class="text-xs text-slate-500">em processo</p>
          </div>
          <div class="w-32">
            <p class="text-xs text-slate-600"><strong>{{ job.hired_count ?? 0 }}</strong> de {{ job.positions_count }} posição{{ job.positions_count > 1 ? 'ões' : '' }} preenchida{{ (job.hired_count ?? 0) === 1 ? '' : 's' }}</p>
            <div class="mt-1 h-1.5 rounded-full bg-slate-100"><div class="h-1.5 rounded-full bg-emerald-500" :style="{ width: progress(job) + '%' }" /></div>
          </div>
        </div>
      </li>
      <li v-if="!loading && items.length === 0" class="px-4 py-12 text-center text-slate-500">Nenhuma vaga encontrada.</li>
    </ul>
    <PaginationBar :meta="meta" @page="goTo" />
  </div>
</template>
