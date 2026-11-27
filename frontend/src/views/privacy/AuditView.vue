<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import { parseApiError } from '@/api/http'
import { privacyApi, type AuditEntry, type PageMeta } from '@/api/privacy'
import type { Option } from '@/api/candidates'
import BaseButton from '@/components/ui/BaseButton.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'
import { formatDateTime } from '@/utils/format'

const toast = useToastStore()
const empty = () => ({ group: '', user: '', from: '', to: '', search: '', ip: '' })
const filters = reactive(empty())
const page = ref(1)
const items = ref<AuditEntry[]>([])
const meta = ref<PageMeta>({ total: 0, current_page: 1, last_page: 1, per_page: 25, from: null, to: null })
const groups = ref<Option[]>([])
const users = ref<{ id: string; name: string }[]>([])
const loading = ref(false)
const expanded = ref<number | null>(null)
let timer: number | undefined
let reqId = 0

async function load() {
  const current = ++reqId
  loading.value = true
  try {
    const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v !== ''))
    const res = await privacyApi.audit({ ...params, page: page.value })
    if (current !== reqId) return
    items.value = res.data
    meta.value = res.meta
    groups.value = res.meta.groups
    users.value = res.meta.users
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    if (current === reqId) loading.value = false
  }
}
watch(() => [filters.group, filters.user, filters.from, filters.to], () => { page.value = 1; load() })
watch(() => [filters.search, filters.ip], () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => {
    if (filters.ip && !/^[\d.:a-fA-F]+$/.test(filters.ip)) return
    page.value = 1
    load()
  }, 400)
})
onMounted(load)

function clear() {
  Object.assign(filters, empty())
}

function fmt(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? 'sim' : 'não'
  if (typeof v === 'object') return JSON.stringify(v)
  return String(v)
}
function changedKeys(e: AuditEntry): string[] {
  return [...new Set([...Object.keys(e.old_values ?? {}), ...Object.keys(e.new_values ?? {})])]
}
function hasDetails(e: AuditEntry) {
  return changedKeys(e).length > 0 || (e.context && Object.keys(e.context).length > 0) || !!e.user_agent
}
function browser(ua: string | null) {
  if (!ua) return ''
  const m = /(Edg|Chrome|Firefox|Safari)\/[\d]+/.exec(ua)
  const os = /Windows|Android|iPhone|Mac OS|Linux/.exec(ua)?.[0] ?? ''
  return [m?.[0].replace('Edg', 'Edge'), os].filter(Boolean).join(' · ')
}
</script>

<template>
  <PageHeader title="Auditoria" subtitle="Registro de quem fez o quê e quando: acessos, alterações, exportações e ações de LGPD. Os registros não podem ser editados." />

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="grid gap-3 border-b border-slate-100 p-4 sm:grid-cols-2 lg:grid-cols-4">
      <input v-model="filters.search" :class="inputClass()" class="lg:col-span-2" placeholder="Buscar na descrição ou evento" aria-label="Buscar" />
      <select v-model="filters.group" :class="inputClass()" aria-label="Área">
        <option value="">Todas as áreas</option>
        <option v-for="g in groups" :key="g.value" :value="g.value">{{ g.label }}</option>
      </select>
      <select v-model="filters.user" :class="inputClass()" aria-label="Usuário">
        <option value="">Todos os usuários</option>
        <option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option>
      </select>
      <input v-model="filters.from" type="date" :class="inputClass()" aria-label="De" />
      <input v-model="filters.to" type="date" :class="inputClass()" aria-label="Até" />
      <input v-model="filters.ip" :class="inputClass()" placeholder="IP" aria-label="Endereço IP" />
      <div class="flex items-center gap-3">
        <BaseButton size="sm" variant="ghost" @click="clear">Limpar filtros</BaseButton>
        <span class="text-xs text-slate-500">{{ meta.total }} registro(s)</span>
      </div>
    </div>

    <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr><th class="px-4 py-3">Data e hora</th><th class="px-4 py-3">Usuário</th><th class="px-4 py-3">Ação</th><th class="px-4 py-3">Origem</th><th class="w-8" /></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <template v-for="e in items" :key="e.id">
            <tr class="align-top" :class="hasDetails(e) ? 'cursor-pointer hover:bg-slate-50/60' : ''" @click="hasDetails(e) && (expanded = expanded === e.id ? null : e.id)">
              <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ formatDateTime(e.created_at) }}</td>
              <td class="px-4 py-3 text-slate-800">{{ e.user?.name ?? 'Sistema' }}</td>
              <td class="px-4 py-3">
                <p class="text-slate-900">{{ e.description ?? e.event }}</p>
                <p class="font-mono text-xs text-slate-400">{{ e.event }}<span v-if="e.subject"> · {{ e.subject.type }} #{{ e.subject.id }}</span></p>
              </td>
              <td class="whitespace-nowrap px-4 py-3 text-xs text-slate-500">
                <p>{{ e.ip_address ?? '—' }}</p>
                <p>{{ browser(e.user_agent) }}</p>
              </td>
              <td class="px-2 py-3 text-slate-400">
                <span v-if="hasDetails(e)" :aria-label="expanded === e.id ? 'Recolher' : 'Ver detalhes'">{{ expanded === e.id ? '▾' : '▸' }}</span>
              </td>
            </tr>
            <tr v-if="expanded === e.id" class="bg-slate-50/70">
              <td colspan="5" class="px-4 py-3">
                <table v-if="changedKeys(e).length" class="mb-2 text-xs">
                  <thead class="text-left text-slate-500"><tr><th class="pr-6">Campo</th><th class="pr-6">Antes</th><th>Depois</th></tr></thead>
                  <tbody>
                    <tr v-for="k in changedKeys(e)" :key="k">
                      <td class="pr-6 font-mono text-slate-600">{{ k }}</td>
                      <td class="max-w-xs break-words pr-6 text-red-800">{{ fmt(e.old_values?.[k]) }}</td>
                      <td class="max-w-xs break-words text-emerald-800">{{ fmt(e.new_values?.[k]) }}</td>
                    </tr>
                  </tbody>
                </table>
                <p v-if="e.context && Object.keys(e.context).length" class="text-xs text-slate-600">
                  <span class="text-slate-500">Contexto:</span>
                  <span v-for="(v, k) in e.context" :key="k" class="ml-2"><span class="font-mono">{{ k }}</span>={{ fmt(v) }}</span>
                </p>
                <p v-if="e.user_agent" class="mt-1 break-all text-xs text-slate-400">{{ e.user_agent }}</p>
              </td>
            </tr>
          </template>
          <tr v-if="!loading && !items.length"><td colspan="5" class="px-4 py-10 text-center text-slate-500">Nenhum registro encontrado com esses filtros.</td></tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :meta="meta" @page="(p: number) => { page = p; load() }" />
  </div>
</template>
