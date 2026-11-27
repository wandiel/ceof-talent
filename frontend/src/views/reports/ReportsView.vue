<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import { formatCell, formatNumber, reportsApi, type ExportFormat, type FilterOptions, type ReportData, type ReportSummary } from '@/api/reports'
import BaseButton from '@/components/ui/BaseButton.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useToastStore } from '@/stores/toast'
import ReportFilterBar, { type FilterState } from './components/ReportFilterBar.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const catalog = ref<ReportSummary[]>([])
const options = ref<FilterOptions | null>(null)
const canExport = ref(false)
const report = ref<ReportData | null>(null)
const meta = ref<{ total: number; shown: number } | null>(null)
const loading = ref(false)
const exporting = ref<ExportFormat | null>(null)
const filters = reactive<FilterState>({ preset: 'all', from: null, to: null, department: null, unit: null, area: null })

const selected = computed(() => (typeof route.query.r === 'string' ? route.query.r : catalog.value[0]?.key))
const groups = computed(() => {
  const map = new Map<string, ReportSummary[]>()
  for (const r of catalog.value) map.set(r.group, [...(map.get(r.group) ?? []), r])
  return [...map.entries()]
})

const numeric = (type: string) => ['number', 'decimal', 'percent'].includes(type)

async function load() {
  if (!selected.value) return
  loading.value = true
  try {
    const res = await reportsApi.show(selected.value, filters)
    report.value = res.data
    meta.value = res.meta
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
  }
}

async function download(format: ExportFormat) {
  if (!selected.value) return
  exporting.value = format
  try {
    await reportsApi.download(selected.value, format, filters)
    toast.success('Arquivo gerado. Confira a pasta de downloads.')
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    exporting.value = null
  }
}

onMounted(async () => {
  try {
    const res = await reportsApi.index()
    catalog.value = res.data
    const { department_locked, departments, units, areas } = res.meta
    options.value = { department_locked, departments, units, areas }
    canExport.value = res.meta.can_export
    if (department_locked) filters.department = departments[0]?.id ?? null
    await load()
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
})

watch(selected, (v, old) => old && v !== old && load())
const choose = (key: string) => router.replace({ query: { ...route.query, r: key } })
</script>

<template>
  <PageHeader title="Relatórios" subtitle="Escolha um relatório, ajuste os filtros e exporte em PDF, Excel (XLSX) ou CSV." />

  <div class="grid gap-6 lg:grid-cols-[14rem_1fr]">
    <nav aria-label="Relatórios disponíveis" class="space-y-5">
      <div v-for="[group, items] in groups" :key="group">
        <h2 class="mb-1.5 px-3 text-xs font-semibold tracking-wider text-slate-500 uppercase">{{ group }}</h2>
        <ul class="space-y-0.5">
          <li v-for="r in items" :key="r.key">
            <button
              type="button"
              class="w-full rounded-lg px-3 py-2 text-left text-sm transition"
              :class="r.key === selected ? 'bg-brand-50 font-medium text-brand-800' : 'text-slate-700 hover:bg-slate-100'"
              :aria-current="r.key === selected ? 'page' : undefined"
              @click="choose(r.key)"
            >
              {{ r.title }}
            </button>
          </li>
        </ul>
      </div>
    </nav>

    <section class="min-w-0">
      <ReportFilterBar v-model="filters" :options="options" @change="load">
        <div v-if="canExport" class="ml-auto flex gap-2">
          <BaseButton variant="secondary" size="sm" :loading="exporting === 'pdf'" :disabled="!!exporting" @click="download('pdf')">Exportar PDF</BaseButton>
          <BaseButton variant="secondary" size="sm" :loading="exporting === 'xlsx'" :disabled="!!exporting" @click="download('xlsx')">Exportar Excel</BaseButton>
          <BaseButton variant="secondary" size="sm" :loading="exporting === 'csv'" :disabled="!!exporting" @click="download('csv')">Exportar CSV</BaseButton>
        </div>
      </ReportFilterBar>

      <div class="mt-4 rounded-2xl border border-slate-200 bg-white shadow-sm" :class="{ 'opacity-60': loading }">
        <header v-if="report" class="border-b border-slate-200 px-5 py-4">
          <h2 class="text-lg font-semibold text-slate-900">{{ report.title }}</h2>
          <p class="text-sm text-slate-500">{{ report.description }}</p>
        </header>
        <div v-if="report" class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs text-slate-500 uppercase">
              <tr>
                <th v-for="c in report.columns" :key="c.key" scope="col" class="px-4 py-2.5 font-medium whitespace-nowrap" :class="{ 'text-right': numeric(c.type) }">{{ c.label }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
              <tr v-for="(row, i) in report.rows" :key="i" class="hover:bg-slate-50">
                <td v-for="c in report.columns" :key="c.key" class="px-4 py-2 text-slate-700" :class="[numeric(c.type) ? 'text-right tabular-nums' : '', c.key === 'code' || c.type === 'date' || c.type === 'datetime' ? 'whitespace-nowrap' : '']">{{ formatCell(row[c.key], c.type) }}</td>
              </tr>
              <tr v-if="!report.rows.length">
                <td :colspan="report.columns.length" class="px-4 py-10 text-center text-slate-500">Nenhum registro encontrado com esses filtros.</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-else class="h-64 animate-pulse rounded-2xl bg-slate-50" />
        <footer v-if="meta && report" class="border-t border-slate-200 px-5 py-3 text-sm text-slate-500">
          <template v-if="meta.total > meta.shown">Mostrando os primeiros {{ formatNumber(meta.shown) }} de {{ formatNumber(meta.total) }} registros. Exporte em Excel ou CSV para ver todos.</template>
          <template v-else>{{ formatNumber(meta.total) }} {{ meta.total === 1 ? 'registro' : 'registros' }}</template>
        </footer>
      </div>
    </section>
  </div>
</template>
