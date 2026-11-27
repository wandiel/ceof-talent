<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { parseApiError } from '@/api/http'
import { dashboardApi, formatNumber, periodRange, type Dashboard, type FilterOptions } from '@/api/reports'
import { useToastStore } from '@/stores/toast'
import BarList from './components/BarList.vue'
import ColumnChart from './components/ColumnChart.vue'
import ReportFilterBar, { type FilterState } from './components/ReportFilterBar.vue'

const toast = useToastStore()
const data = ref<Dashboard | null>(null)
const options = ref<FilterOptions | null>(null)
const canViewReports = ref(false)
const loading = ref(true)
const filters = reactive<FilterState>({ preset: '12m', ...periodRange('12m'), department: null, unit: null, area: null })

async function load() {
  loading.value = true
  try {
    const res = await dashboardApi.get(filters)
    data.value = res.data
    const { department_locked, departments, units, areas } = res.meta
    options.value = { department_locked, departments, units, areas }
    canViewReports.value = res.meta.can_view_reports
    if (department_locked) filters.department = res.meta.filters.department ?? null
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
  }
}
onMounted(load)

const periodLabel = computed(() => (filters.preset === 'all' ? 'em todo o período' : 'no período'))

const kpis = computed(() => {
  const k = data.value?.kpis
  if (!k) return []
  return [
    { label: 'Candidatos no banco', value: k.total_candidates, hint: `${formatNumber(k.active_candidates)} ativos`, to: '/candidatos' },
    { label: 'Novos cadastros', value: k.new_candidates, hint: periodLabel.value, to: '/candidatos' },
    { label: 'Vagas abertas', value: k.open_jobs, hint: `${formatNumber(k.open_positions)} ${k.open_positions === 1 ? 'posição' : 'posições'}`, to: '/vagas' },
    { label: 'Candidatos em processo', value: k.active_processes, hint: 'em vagas abertas', to: '/vagas' },
    { label: 'Entrevistas agendadas', value: k.scheduled_interviews, hint: 'a partir de hoje', to: '/entrevistas' },
    { label: 'Aprovações', value: k.approvals, hint: periodLabel.value },
    { label: 'Contratações', value: k.hires, hint: periodLabel.value },
    { label: 'Tempo médio de contratação', value: k.avg_days_to_hire, hint: 'dias, da inscrição à contratação', decimal: true },
  ]
})

const series = (key: 'candidates' | 'applications' | 'hires') => (data.value?.monthly ?? []).map((m) => ({ label: m.label, value: m[key] }))

const funnel = computed(() => {
  const f = data.value?.funnel
  if (!f) return []
  const first = Math.max(1, f.stages[0]?.count ?? 1)
  return f.stages.map((s) => ({ label: s.label, value: s.count, hint: `${Math.round((s.count / first) * 100)}%` }))
})

const when = (iso: string) => new Date(iso).toLocaleString('pt-BR', { weekday: 'short', day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
</script>

<template>
  <section class="mt-6" aria-labelledby="dash-title">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-2">
      <h2 id="dash-title" class="text-lg font-semibold text-slate-900">Indicadores de recrutamento</h2>
      <RouterLink v-if="canViewReports" to="/relatorios" class="text-sm font-medium text-brand-700 hover:underline">Ver relatórios detalhados →</RouterLink>
    </div>
    <ReportFilterBar v-model="filters" :options="options" @change="load" />

    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4" :class="{ 'opacity-60': loading }" aria-live="polite">
      <component
        :is="k.to ? RouterLink : 'div'"
        v-for="k in kpis"
        :key="k.label"
        :to="k.to"
        class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"
        :class="k.to ? 'transition hover:border-brand-200 hover:shadow' : ''"
      >
        <p class="text-sm text-slate-500">{{ k.label }}</p>
        <p class="mt-1 text-3xl font-semibold text-slate-900 tabular-nums">
          {{ k.value === null ? '—' : k.decimal ? k.value.toLocaleString('pt-BR', { maximumFractionDigits: 1 }) : formatNumber(k.value) }}
        </p>
        <p class="mt-1 text-xs text-slate-500">{{ k.hint }}</p>
      </component>
    </div>
    <div v-if="loading && !data" class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div v-for="i in 8" :key="i" class="h-28 animate-pulse rounded-2xl bg-slate-100" />
    </div>

    <template v-if="data">
      <div class="mt-6 grid gap-4 xl:grid-cols-3">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 class="font-semibold text-slate-900">Novos cadastros por mês</h3>
          <p class="mb-2 text-xs text-slate-500">Total: {{ formatNumber(series('candidates').reduce((s, d) => s + d.value, 0)) }}</p>
          <ColumnChart :data="series('candidates')" unit="cadastros" />
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 class="font-semibold text-slate-900">Inscrições em vagas por mês</h3>
          <p class="mb-2 text-xs text-slate-500">Total: {{ formatNumber(series('applications').reduce((s, d) => s + d.value, 0)) }}</p>
          <ColumnChart :data="series('applications')" unit="inscrições" color="#2563eb" />
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 class="font-semibold text-slate-900">Contratações por mês</h3>
          <p class="mb-2 text-xs text-slate-500">Total: {{ formatNumber(series('hires').reduce((s, d) => s + d.value, 0)) }}</p>
          <ColumnChart :data="series('hires')" unit="contratações" color="#1e3a8a" />
        </article>
      </div>

      <div class="mt-4 grid gap-4 xl:grid-cols-2">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 class="font-semibold text-slate-900">Funil do processo seletivo</h3>
          <p class="mb-4 text-xs text-slate-500">
            Das {{ formatNumber(data.funnel.total) }} inscrições {{ periodLabel }}, quantas chegaram a cada etapa.
            Reprovados: {{ formatNumber(data.funnel.rejected) }} · Desistentes: {{ formatNumber(data.funnel.withdrawn) }}
          </p>
          <BarList :items="funnel" :empty="`Nenhuma inscrição ${periodLabel}.`" />
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 class="font-semibold text-slate-900">Candidatos por área profissional</h3>
          <p class="mb-4 text-xs text-slate-500">Todo o banco de talentos (com os filtros de departamento e área).</p>
          <BarList :items="data.by_area.map((a) => ({ label: a.label, value: a.count }))" />
        </article>
      </div>

      <div class="mt-4 grid gap-4 xl:grid-cols-3">
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm xl:col-span-2">
          <h3 class="mb-3 font-semibold text-slate-900">Por departamento</h3>
          <div v-if="data.by_department.length" class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="text-left text-xs text-slate-500 uppercase">
                <tr>
                  <th class="py-2 pr-3 font-medium">Departamento</th>
                  <th class="px-3 py-2 text-right font-medium">Vagas abertas</th>
                  <th class="px-3 py-2 text-right font-medium">Inscrições {{ periodLabel }}</th>
                  <th class="py-2 pl-3 text-right font-medium">Contratações {{ periodLabel }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-slate-100">
                <tr v-for="d in data.by_department" :key="d.id">
                  <td class="py-2 pr-3 text-slate-800">{{ d.name }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(d.open_jobs) }}</td>
                  <td class="px-3 py-2 text-right tabular-nums">{{ formatNumber(d.applications) }}</td>
                  <td class="py-2 pl-3 text-right tabular-nums">{{ formatNumber(d.hires) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <p v-else class="text-sm text-slate-500">Sem vagas ou inscrições com esses filtros.</p>
        </article>
        <article class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 class="mb-3 font-semibold text-slate-900">Próximas entrevistas</h3>
          <ul v-if="data.upcoming_interviews.length" class="divide-y divide-slate-100">
            <li v-for="i in data.upcoming_interviews" :key="i.id" class="py-2">
              <RouterLink :to="`/entrevistas/${i.id}`" class="block hover:text-brand-700">
                <span class="text-sm font-medium text-slate-900">{{ i.candidate }}</span>
                <span class="block text-xs text-slate-500">{{ when(i.scheduled_at) }} · {{ i.type_label }}</span>
                <span class="block truncate text-xs text-slate-400">{{ i.job }}</span>
              </RouterLink>
            </li>
          </ul>
          <p v-else class="text-sm text-slate-500">Nenhuma entrevista agendada.</p>
        </article>
      </div>
    </template>
  </section>
</template>
