<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { candidatesApi, type CandidateListItem } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import { privacyApi, requestStatusClasses, type PageMeta, type PrivacyOverview, type PrivacyRequestDetail, type PrivacyRequestItem } from '@/api/privacy'
import type { Option } from '@/api/candidates'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import FormField from '@/components/ui/FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'
import { formatDate, formatDateTime } from '@/utils/format'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const tab = ref<'geral' | 'solicitacoes'>(route.query.aba === 'solicitacoes' ? 'solicitacoes' : 'geral')
watch(tab, (t) => router.replace({ query: { ...route.query, aba: t } }))

// ---------- Visão geral ----------
const overview = ref<PrivacyOverview | null>(null)
async function loadOverview() {
  try {
    overview.value = await privacyApi.overview()
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
}

// ---------- Solicitações ----------
const opts = ref<{ types: Option[]; statuses: Option[]; channels: Option[]; deadline_days: number } | null>(null)
const filters = reactive({ search: '', status: 'pending', type: '', page: 1 })
const items = ref<PrivacyRequestItem[]>([])
const meta = ref<PageMeta>({ total: 0, current_page: 1, last_page: 1, per_page: 15, from: null, to: null })
const loading = ref(false)
let timer: number | undefined

async function loadRequests() {
  loading.value = true
  try {
    const status = filters.status === 'pending' ? ['open', 'in_progress'] : filters.status === 'overdue' ? ['open', 'in_progress'] : filters.status ? [filters.status] : undefined
    const res = await privacyApi.requests({
      search: filters.search || undefined,
      type: filters.type || undefined,
      status,
      overdue: filters.status === 'overdue' ? 1 : undefined,
      page: filters.page,
    })
    items.value = res.data
    meta.value = res.meta
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
  }
}
watch(() => [filters.status, filters.type], () => { filters.page = 1; loadRequests() })
watch(() => filters.search, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(() => { filters.page = 1; loadRequests() }, 350)
})

// Novo pedido
const creating = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const emptyForm = () => ({ requester_name: '', requester_email: '', requester_phone: '', type: 'access', channel: 'email', description: '', candidate: '' })
const form = reactive(emptyForm())
const candidateSearch = ref('')
const candidateResults = ref<CandidateListItem[]>([])
const candidateLabel = ref('')
let candTimer: number | undefined
watch(candidateSearch, (q) => {
  window.clearTimeout(candTimer)
  if (q.trim().length < 2) { candidateResults.value = []; return }
  candTimer = window.setTimeout(async () => {
    try {
      candidateResults.value = (await candidatesApi.list({ search: q, per_page: 6 })).data
    } catch { candidateResults.value = [] }
  }, 300)
})
function pickCandidate(c: CandidateListItem) {
  form.candidate = c.id
  candidateLabel.value = `${c.full_name}${c.email ? ` (${c.email})` : ''}`
  candidateSearch.value = ''
  candidateResults.value = []
  if (!form.requester_name) form.requester_name = c.full_name
  if (!form.requester_email && c.email) form.requester_email = c.email
}
function openCreate() {
  Object.assign(form, emptyForm())
  candidateLabel.value = ''
  candidateSearch.value = ''
  errors.value = {}
  creating.value = true
}
async function submitCreate() {
  saving.value = true
  errors.value = {}
  try {
    const r = await privacyApi.createRequest({ ...form, candidate: form.candidate || null })
    toast.success(`Solicitação ${r.protocol} registrada. Prazo de resposta: ${formatDate(r.due_on)}.`)
    creating.value = false
    filters.status = 'pending'
    await Promise.all([loadRequests(), loadOverview()])
    openDetail(r.id)
  } catch (e) {
    const p = parseApiError(e)
    errors.value = p.fields
    if (!Object.keys(p.fields).length) toast.error(p.message)
  } finally {
    saving.value = false
  }
}

// Detalhe
const detail = ref<PrivacyRequestDetail | null>(null)
const update = reactive({ status: '', response: '' })
async function fetchDetail(id: string) {
  detail.value = await privacyApi.request(id)
  update.status = detail.value.status
  update.response = detail.value.response ?? ''
  errors.value = {}
}
async function openDetail(id: string) {
  try {
    await fetchDetail(id)
    router.replace({ query: { ...route.query, aba: 'solicitacoes', pedido: id } })
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
}
function closeDetail() {
  detail.value = null
  const q = { ...route.query }
  delete q.pedido
  router.replace({ query: q })
}
async function submitUpdate() {
  if (!detail.value) return
  saving.value = true
  errors.value = {}
  try {
    await privacyApi.updateRequest(detail.value.id, { status: update.status, response: update.response || null })
    toast.success('Solicitação atualizada.')
    await Promise.all([loadRequests(), loadOverview(), fetchDetail(detail.value.id)])
  } catch (e) {
    const p = parseApiError(e)
    errors.value = p.fields
    if (!Object.keys(p.fields).length) toast.error(p.message)
  } finally {
    saving.value = false
  }
}

const typeHelp: Record<string, string> = {
  access: 'Abra o cadastro do titular, aba Privacidade (LGPD), e use "Exportar dados do titular" para gerar o relatório.',
  portability: 'Use "Exportar dados do titular" (JSON) no cadastro do candidato e envie o arquivo ao titular.',
  deletion: 'Se não houver processo seletivo em andamento, use "Anonimizar cadastro" na aba Privacidade do candidato.',
  revoke_consent: 'Revogue o consentimento na aba Privacidade do candidato. O cadastro passa a seguir o prazo de retenção sem consentimento.',
  correction: 'Corrija os dados pelo botão Editar no cadastro do candidato.',
}
const isLate = computed(() => !!detail.value && detail.value.overdue)

onMounted(async () => {
  loadOverview()
  loadRequests()
  try { opts.value = await privacyApi.options() } catch { /* opções carregam no próximo acesso */ }
  if (typeof route.query.pedido === 'string') openDetail(route.query.pedido)
})

const tabClass = (active: boolean) =>
  active ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-700'
</script>

<template>
  <PageHeader title="LGPD e privacidade" subtitle="Solicitações dos titulares, consentimentos, política de retenção e registro das atividades de tratamento.">
    <template #actions>
      <BaseButton @click="openCreate">+ Registrar solicitação</BaseButton>
    </template>
  </PageHeader>

  <div class="mb-5 flex gap-6 border-b border-slate-200 text-sm font-medium" role="tablist">
    <button type="button" role="tab" :aria-selected="tab === 'geral'" class="-mb-px border-b-2 pb-2" :class="tabClass(tab === 'geral')" @click="tab = 'geral'">Visão geral</button>
    <button type="button" role="tab" :aria-selected="tab === 'solicitacoes'" class="-mb-px border-b-2 pb-2" :class="tabClass(tab === 'solicitacoes')" @click="tab = 'solicitacoes'">
      Solicitações dos titulares
      <span v-if="overview?.requests.open" class="ml-1 rounded-full bg-amber-100 px-1.5 text-xs text-amber-800">{{ overview.requests.open }}</span>
    </button>
  </div>

  <!-- VISÃO GERAL -->
  <template v-if="tab === 'geral'">
    <div v-if="!overview" class="h-40 animate-pulse rounded-2xl bg-slate-100" />
    <div v-else class="space-y-5">
      <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <button type="button" class="rounded-2xl border border-slate-200 bg-white p-4 text-left shadow-sm hover:border-brand-300" @click="tab = 'solicitacoes'; filters.status = 'pending'">
          <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Solicitações em aberto</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ overview.requests.open }}</p>
          <p class="text-xs" :class="overview.requests.overdue ? 'font-medium text-red-700' : 'text-slate-500'">{{ overview.requests.overdue }} com prazo vencido</p>
        </button>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Atendidas em 30 dias</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ overview.requests.completed_last_30_days }}</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Consentimentos ativos</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ overview.consents.talent_pool_active }}</p>
          <p class="text-xs text-slate-500">banco de talentos · {{ overview.consents.talent_pool_revoked }} revogados</p>
        </div>
        <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <p class="text-xs font-medium uppercase tracking-wider text-slate-500">Cadastros anonimizados</p>
          <p class="mt-1 text-2xl font-semibold text-slate-900">{{ overview.retention.anonymized_total }}</p>
          <p class="text-xs text-slate-500">{{ overview.retention.due_now }} aguardando a rotina diária</p>
        </div>
      </div>

      <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="font-semibold text-slate-900">Política de retenção</h2>
          <ul class="mt-3 space-y-2 text-sm text-slate-700">
            <li>Banco de talentos (com consentimento ou cadastrado pelo RH): <strong>{{ overview.retention.settings.talent_pool_months }} meses</strong> após a última atividade.</li>
            <li>Quem não autorizou o banco de talentos no portal ou revogou a autorização: <strong>{{ overview.retention.settings.no_consent_months }} meses</strong> após a última atividade.</li>
            <li>Cadastros excluídos: anonimizados após <strong>{{ overview.retention.settings.deleted_grace_days }} dias</strong>.</li>
            <li>Contratados: {{ overview.retention.settings.keep_hired ? 'mantidos (os dados passam ao RH de pessoal)' : 'seguem a mesma regra' }}.</li>
            <li>Trilha de auditoria: mantida por <strong>{{ overview.retention.settings.audit_months }} meses</strong>.</li>
            <li>Candidatos em processo seletivo nunca são anonimizados automaticamente.</li>
          </ul>
          <p class="mt-3 rounded-lg px-3 py-2 text-xs" :class="overview.retention.settings.enabled ? 'bg-emerald-50 text-emerald-800' : 'bg-amber-50 text-amber-800'">
            {{ overview.retention.settings.enabled ? 'A rotina automática roda todos os dias às 03:15.' : 'A rotina automática está desligada (PRIVACY_RETENTION_ENABLED=false).' }}
          </p>
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="font-semibold text-slate-900">Vencem nos próximos 30 dias</h2>
          <p class="text-xs text-slate-500">Se o candidato voltar a participar ou renovar o consentimento, o prazo recomeça.</p>
          <ul v-if="overview.retention.upcoming.length" class="mt-3 divide-y divide-slate-100 text-sm">
            <li v-for="c in overview.retention.upcoming" :key="c.id" class="flex justify-between py-2">
              <RouterLink :to="`/candidatos/${c.id}`" class="text-brand-700 hover:underline">{{ c.name }}</RouterLink>
              <span class="text-slate-500">{{ formatDate(c.expires_at) }}</span>
            </li>
          </ul>
          <p v-else class="mt-3 text-sm text-slate-500">Nenhum cadastro vence nos próximos 30 dias.</p>
          <p v-if="overview.retention.upcoming_count > overview.retention.upcoming.length" class="mt-2 text-xs text-slate-500">e mais {{ overview.retention.upcoming_count - overview.retention.upcoming.length }}.</p>
        </section>
      </div>

      <section class="rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="p-5 pb-3">
          <h2 class="font-semibold text-slate-900">Registro das atividades de tratamento</h2>
          <p class="text-xs text-slate-500">Finalidade e base legal de cada uso dos dados pessoais (art. 37 da LGPD). Controlador: {{ overview.dpo.controller }}.</p>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
              <tr><th class="px-4 py-2">Atividade</th><th class="px-4 py-2">Finalidade</th><th class="px-4 py-2">Base legal</th><th class="px-4 py-2">Dados</th><th class="px-4 py-2">Retenção</th><th class="px-4 py-2">Compartilhamento</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100 align-top">
              <tr v-for="a in overview.processing_activities" :key="a.name">
                <td class="px-4 py-3 font-medium text-slate-900">{{ a.name }}</td>
                <td class="px-4 py-3 text-slate-700">{{ a.purpose }}</td>
                <td class="px-4 py-3 text-slate-700">{{ a.legal_basis }}</td>
                <td class="px-4 py-3 text-slate-600">{{ a.data }}</td>
                <td class="px-4 py-3 text-slate-600">{{ a.retention }}</td>
                <td class="px-4 py-3 text-slate-600">{{ a.shared_with }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="border-t border-slate-100 px-5 py-3 text-xs text-slate-500">
          Encarregado (DPO): {{ overview.dpo.name || 'não informado (configure PRIVACY_DPO_NAME)' }}<span v-if="overview.dpo.email"> · {{ overview.dpo.email }}</span>.
          Aviso de privacidade versão {{ overview.consents.notice_version }} ·
          <RouterLink to="/privacidade" class="text-brand-700 hover:underline">ver aviso público</RouterLink>
        </p>
      </section>
    </div>
  </template>

  <!-- SOLICITAÇÕES -->
  <div v-else class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="flex flex-col gap-3 border-b border-slate-100 p-4 sm:flex-row">
      <input v-model="filters.search" :class="inputClass()" placeholder="Buscar por protocolo, nome ou e-mail" aria-label="Buscar" />
      <select v-model="filters.status" :class="inputClass()" class="sm:w-48" aria-label="Situação">
        <option value="pending">Pendentes</option>
        <option value="overdue">Prazo vencido</option>
        <option v-for="s in opts?.statuses ?? []" :key="s.value" :value="s.value">{{ s.label }}</option>
        <option value="">Todas</option>
      </select>
      <select v-model="filters.type" :class="inputClass()" class="sm:w-56" aria-label="Tipo">
        <option value="">Todos os tipos</option>
        <option v-for="t in opts?.types ?? []" :key="t.value" :value="t.value">{{ t.label }}</option>
      </select>
    </div>
    <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr><th class="px-4 py-3">Protocolo</th><th class="px-4 py-3">Titular</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Situação</th><th class="px-4 py-3">Prazo</th></tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="r in items" :key="r.id" class="cursor-pointer hover:bg-slate-50/60" @click="openDetail(r.id)">
            <td class="px-4 py-3 font-mono text-xs font-medium text-brand-800">{{ r.protocol }}</td>
            <td class="px-4 py-3">
              <p class="font-medium text-slate-900">{{ r.requester_name }}</p>
              <p class="text-xs text-slate-500">{{ r.requester_email ?? r.channel_label }}</p>
            </td>
            <td class="px-4 py-3 text-slate-700">{{ r.type_label }}</td>
            <td class="px-4 py-3"><span class="rounded-md px-2 py-0.5 text-xs font-medium ring-1" :class="requestStatusClasses[r.status]">{{ r.status_label }}</span></td>
            <td class="px-4 py-3" :class="r.overdue ? 'font-medium text-red-700' : 'text-slate-600'">{{ formatDate(r.due_on) }}<span v-if="r.overdue"> (vencido)</span></td>
          </tr>
          <tr v-if="!loading && !items.length"><td colspan="5" class="px-4 py-10 text-center text-slate-500">Nenhuma solicitação encontrada.</td></tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :meta="meta" @page="(p: number) => { filters.page = p; loadRequests() }" />
  </div>

  <!-- Modal: nova solicitação -->
  <BaseModal :open="creating" title="Registrar solicitação de titular" size="lg" @close="creating = false">
    <form id="new-request" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitCreate">
      <FormField label="Tipo de pedido" for="rq-type" required :error="errors.type">
        <select id="rq-type" v-model="form.type" :class="inputClass(errors.type)">
          <option v-for="t in opts?.types ?? []" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
      </FormField>
      <FormField label="Recebido por" for="rq-channel" required :error="errors.channel">
        <select id="rq-channel" v-model="form.channel" :class="inputClass(errors.channel)">
          <option v-for="c in opts?.channels ?? []" :key="c.value" :value="c.value">{{ c.label }}</option>
        </select>
      </FormField>
      <div class="sm:col-span-2">
        <FormField label="Candidato relacionado" for="rq-cand" hint="Opcional. Busque pelo nome, e-mail ou CPF para vincular o pedido ao cadastro.">
          <div v-if="form.candidate" class="flex items-center justify-between rounded-lg bg-brand-50 px-3 py-2 text-sm text-brand-900">
            {{ candidateLabel }}
            <button type="button" class="text-xs font-medium hover:underline" @click="form.candidate = ''">Remover</button>
          </div>
          <div v-else class="relative">
            <input id="rq-cand" v-model="candidateSearch" :class="inputClass()" autocomplete="off" placeholder="Digite ao menos 2 letras" />
            <ul v-if="candidateResults.length" class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-slate-200 bg-white shadow-lg">
              <li v-for="c in candidateResults" :key="c.id">
                <button type="button" class="block w-full px-3 py-2 text-left text-sm hover:bg-slate-50" @click="pickCandidate(c)">
                  {{ c.full_name }} <span class="text-xs text-slate-500">{{ c.email }} {{ c.city }}</span>
                </button>
              </li>
            </ul>
          </div>
        </FormField>
      </div>
      <FormField label="Nome do titular" for="rq-name" required :error="errors.requester_name">
        <input id="rq-name" v-model="form.requester_name" :class="inputClass(errors.requester_name)" maxlength="150" />
      </FormField>
      <FormField label="E-mail" for="rq-email" :error="errors.requester_email">
        <input id="rq-email" v-model="form.requester_email" type="email" :class="inputClass(errors.requester_email)" />
      </FormField>
      <FormField label="Telefone" for="rq-phone" :error="errors.requester_phone">
        <input id="rq-phone" v-model="form.requester_phone" :class="inputClass(errors.requester_phone)" maxlength="20" />
      </FormField>
      <div class="sm:col-span-2">
        <FormField label="O que o titular pediu" for="rq-desc" :error="errors.description">
          <textarea id="rq-desc" v-model="form.description" rows="3" :class="inputClass(errors.description)" maxlength="5000" />
        </FormField>
      </div>
      <p class="text-xs text-slate-500 sm:col-span-2">A LGPD pede resposta em até {{ opts?.deadline_days ?? 15 }} dias. O prazo é calculado automaticamente.</p>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="creating = false">Cancelar</BaseButton>
      <BaseButton type="submit" form="new-request" :loading="saving">Registrar</BaseButton>
    </template>
  </BaseModal>

  <!-- Modal: detalhe -->
  <BaseModal :open="!!detail" :title="detail ? `Solicitação ${detail.protocol}` : ''" size="lg" @close="closeDetail">
    <div v-if="detail" class="space-y-4 text-sm">
      <div class="grid gap-x-6 gap-y-2 sm:grid-cols-2">
        <p><span class="text-slate-500">Tipo:</span> {{ detail.type_label }}</p>
        <p><span class="text-slate-500">Recebido por:</span> {{ detail.channel_label }} em {{ formatDateTime(detail.created_at) }}</p>
        <p><span class="text-slate-500">Titular:</span> {{ detail.requester_name }}</p>
        <p><span class="text-slate-500">Contato:</span> {{ [detail.requester_email, detail.requester_phone].filter(Boolean).join(' · ') || '—' }}</p>
        <p><span class="text-slate-500">Prazo:</span> <span :class="isLate ? 'font-medium text-red-700' : ''">{{ formatDate(detail.due_on) }}{{ isLate ? ' (vencido)' : '' }}</span></p>
        <p>
          <span class="text-slate-500">Candidato:</span>{{ ' ' }}
          <RouterLink v-if="detail.candidate" :to="{ path: `/candidatos/${detail.candidate.id}`, query: { aba: 'privacidade' } }" class="text-brand-700 hover:underline">{{ detail.candidate.name }}</RouterLink>
          <template v-else>não vinculado</template>
        </p>
      </div>
      <p v-if="detail.description" class="whitespace-pre-line rounded-lg bg-slate-50 px-3 py-2 text-slate-700">{{ detail.description }}</p>
      <p v-if="typeHelp[detail.type] && !['completed', 'rejected'].includes(detail.status)" class="rounded-lg bg-brand-50 px-3 py-2 text-brand-900">
        <strong>Como atender:</strong> {{ typeHelp[detail.type] }}
      </p>
      <form id="update-request" class="grid gap-4 border-t border-slate-100 pt-4 sm:grid-cols-3" @submit.prevent="submitUpdate">
        <FormField label="Situação" for="up-status" :error="errors.status">
          <select id="up-status" v-model="update.status" :class="inputClass(errors.status)">
            <option v-for="s in opts?.statuses ?? []" :key="s.value" :value="s.value">{{ s.label }}</option>
          </select>
        </FormField>
        <div class="sm:col-span-2">
          <FormField label="Resposta ao titular" for="up-response" :required="['completed', 'rejected'].includes(update.status)" :error="errors.response" hint="Registre o que foi feito e como o titular foi informado.">
            <textarea id="up-response" v-model="update.response" rows="3" :class="inputClass(errors.response)" maxlength="5000" />
          </FormField>
        </div>
      </form>
      <div v-if="detail.history.length" class="border-t border-slate-100 pt-3">
        <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Histórico</h3>
        <ul class="mt-2 space-y-1 text-xs text-slate-600">
          <li v-for="(h, i) in detail.history" :key="i">{{ formatDateTime(h.created_at) }} · {{ h.user }} · {{ h.description }}</li>
        </ul>
      </div>
    </div>
    <template #footer>
      <BaseButton variant="secondary" @click="closeDetail">Fechar</BaseButton>
      <BaseButton type="submit" form="update-request" :loading="saving">Salvar</BaseButton>
    </template>
  </BaseModal>
</template>
