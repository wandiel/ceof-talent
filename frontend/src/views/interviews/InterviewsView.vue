<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import { interviewsApi, type Interview } from '@/api/interviews'
import type { Paginated } from '@/api/types'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import InterviewStatusBadge from './components/InterviewStatusBadge.vue'

const auth = useAuthStore()
const toast = useToastStore()
const route = useRoute()
const router = useRouter()

/** Entrevistador sem acesso ao processo vê só as próprias entrevistas. */
const onlyMine = !auth.can('recruitment.view')

const views = [
  { key: 'proximas', label: 'Próximas' },
  { key: 'hoje', label: 'Hoje' },
  { key: 'pendentes', label: 'Pareceres pendentes' },
  { key: 'passadas', label: 'Anteriores' },
] as const
type ViewKey = (typeof views)[number]['key']

const state = reactive({
  view: (views.some((v) => v.key === route.query.ver) ? route.query.ver : 'proximas') as ViewKey,
  mine: onlyMine || route.query.minhas === '1',
  search: typeof route.query.busca === 'string' ? route.query.busca : '',
  page: 1,
})

const result = ref<Paginated<Interview> | null>(null)
const loading = ref(false)
let timer: number | undefined
let request = 0

function localDate(offsetDays = 0): string {
  const d = new Date(Date.now() + offsetDays * 86400000)
  return new Date(d.getTime() - d.getTimezoneOffset() * 60000).toISOString().slice(0, 10)
}

async function load() {
  const current = ++request
  loading.value = true
  const base = { mine: state.mine, search: state.search, page: state.page, per_page: 30 }
  const params = {
    proximas: { ...base, from: localDate(), status: ['scheduled'] },
    hoje: { ...base, from: localDate(), to: localDate() },
    pendentes: { ...base, pending_evaluation: true, mine: true, sort: '-scheduled_at' },
    passadas: { ...base, to: localDate(-1), sort: '-scheduled_at' },
  }[state.view]
  try {
    const data = await interviewsApi.list(params)
    if (current === request) result.value = data
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    if (current === request) loading.value = false
  }
}

onMounted(load)
watch(() => [state.view, state.mine], () => {
  state.page = 1
  load()
})
watch(() => state.search, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => {
    state.page = 1
    load()
  }, 300)
})
watch(state, () => router.replace({ query: { ver: state.view === 'proximas' ? undefined : state.view, minhas: !onlyMine && state.mine ? '1' : undefined, busca: state.search || undefined } }))

/** Agrupa por dia para a agenda. */
const days = computed(() => {
  const groups = new Map<string, Interview[]>()
  for (const i of result.value?.data ?? []) {
    const key = new Date(i.scheduled_at).toLocaleDateString('pt-BR', { weekday: 'long', day: '2-digit', month: 'long', year: 'numeric' })
    groups.set(key, [...(groups.get(key) ?? []), i])
  }
  return [...groups.entries()]
})

const time = (iso: string) => new Date(iso).toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <PageHeader title="Entrevistas" :subtitle="onlyMine ? 'Suas entrevistas e pareceres.' : 'Agenda de entrevistas dos processos seletivos.'">
    <template v-if="auth.can('jobs.manage')" #actions>
      <RouterLink :to="{ name: 'criteria' }" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Critérios de avaliação</RouterLink>
    </template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-wrap gap-1 border-b border-slate-100 px-3 pt-3" role="tablist">
      <button
        v-for="v in views"
        :key="v.key"
        type="button"
        role="tab"
        :aria-selected="state.view === v.key"
        class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium"
        :class="state.view === v.key ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="state.view = v.key"
      >
        {{ v.label }}
      </button>
    </div>
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row sm:items-center">
      <input v-model="state.search" type="search" placeholder="Buscar pelo nome do candidato" aria-label="Buscar entrevistas" :class="inputClass()" class="sm:max-w-xs" />
      <label v-if="!onlyMine && state.view !== 'pendentes'" class="inline-flex items-center gap-2 text-sm text-slate-600">
        <input v-model="state.mine" type="checkbox" class="size-4 rounded border-slate-300" /> Só as que eu entrevisto
      </label>
      <p class="text-xs text-slate-500 sm:ml-auto" aria-live="polite">{{ loading ? 'Carregando…' : `${result?.meta.total ?? 0} entrevista${result?.meta.total === 1 ? '' : 's'}` }}</p>
    </div>

    <div :class="{ 'opacity-60': loading }">
      <section v-for="[day, items] in days" :key="day">
        <h2 class="bg-slate-50 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-slate-500">{{ day }}</h2>
        <ul class="divide-y divide-slate-100">
          <li v-for="i in items" :key="i.id">
            <RouterLink :to="{ name: 'interview-detail', params: { id: i.id } }" class="flex flex-col gap-2 px-4 py-3 hover:bg-slate-50/60 sm:flex-row sm:items-center">
              <div class="w-28 shrink-0 text-sm font-semibold text-slate-900">{{ time(i.scheduled_at) }}–{{ time(i.ends_at) }}</div>
              <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                  <span class="font-medium text-slate-900">{{ i.candidate?.name }}</span>
                  <InterviewStatusBadge :status="i.status" :label="i.status_label" />
                  <span v-if="i.my_evaluation_pending && new Date(i.scheduled_at) < new Date()" class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900">Seu parecer está pendente</span>
                </div>
                <p class="text-sm text-slate-600">{{ i.type_label }} · {{ i.mode_label }}<template v-if="i.location"> · {{ i.location }}</template></p>
                <p class="text-xs text-slate-500">{{ i.job?.code }} · {{ i.job?.title }} · {{ (i.interviewers ?? []).map((u) => u.name).join(', ') }}</p>
              </div>
              <div v-if="i.evaluations_count" class="text-xs text-slate-500">{{ i.evaluations_count }} parecer{{ i.evaluations_count > 1 ? 'es' : '' }}</div>
            </RouterLink>
          </li>
        </ul>
      </section>
      <p v-if="!loading && !days.length" class="px-4 py-12 text-center text-slate-500">
        {{ state.view === 'pendentes' ? 'Nenhum parecer pendente. 👍' : 'Nenhuma entrevista neste período.' }}
      </p>
    </div>
    <PaginationBar v-if="result" :meta="result.meta" @page="(p) => { state.page = p; load() }" />
  </div>
</template>
