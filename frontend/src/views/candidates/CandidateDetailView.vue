<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { candidatesApi, type Candidate, type CandidateOptions } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useToastStore } from '@/stores/toast'
import { formatDate, formatDateTime, formatMoney, formatPhone } from '@/utils/format'
import CandidateDocuments from './components/CandidateDocuments.vue'
import DuplicateAlert from './components/DuplicateAlert.vue'
import FavoriteButton from './components/FavoriteButton.vue'
import TagsEditor from './components/TagsEditor.vue'
import { useAuthStore } from '@/stores/auth'
import CandidateHistory from './components/CandidateHistory.vue'
import CandidateNotes from './components/CandidateNotes.vue'
import CandidateProfile from './components/CandidateProfile.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()
const auth = useAuthStore()

const candidate = ref<Candidate | null>(null)
const options = ref<CandidateOptions | null>(null)
const loading = ref(true)
const notFound = ref(false)
const confirm = ref<'status' | 'delete' | null>(null)
const acting = ref(false)
const historyKey = ref(0)

const tabs = [
  { key: 'perfil', label: 'Perfil' },
  { key: 'documentos', label: 'Documentos' },
  { key: 'anotacoes', label: 'Anotações' },
  { key: 'historico', label: 'Histórico' },
] as const
type TabKey = (typeof tabs)[number]['key']

const tab = computed<TabKey>({
  get: () => (tabs.some((t) => t.key === route.query.aba) ? (route.query.aba as TabKey) : 'perfil'),
  set: (value) => router.replace({ query: { ...route.query, aba: value === 'perfil' ? undefined : value } }),
})

async function load() {
  loading.value = true
  try {
    ;[candidate.value, options.value] = await Promise.all([candidatesApi.get(route.params.id as string), candidatesApi.options()])
  } catch (e) {
    const parsed = parseApiError(e)
    if (parsed.status === 404) notFound.value = true
    else if (parsed.status === 403) router.replace({ name: 'forbidden' })
    else toast.error(parsed.message)
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => route.params.id, (id, old) => id && id !== old && load())

function counts(key: TabKey): number | null {
  if (!candidate.value) return null
  if (key === 'documentos') return candidate.value.documents.length
  if (key === 'anotacoes') return candidate.value.notes.length
  return null
}

async function toggleStatus() {
  if (!candidate.value) return
  acting.value = true
  try {
    candidate.value = await candidatesApi.setStatus(candidate.value.id, candidate.value.status === 'active' ? 'inactive' : 'active')
    toast.success(candidate.value.status === 'active' ? 'Candidato reativado.' : 'Candidato inativado.')
    historyKey.value++
    confirm.value = null
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    acting.value = false
  }
}

async function remove() {
  if (!candidate.value) return
  acting.value = true
  try {
    await candidatesApi.remove(candidate.value.id)
    toast.success('Cadastro excluído.')
    router.replace({ name: 'candidates' })
  } catch (e) {
    toast.error(parseApiError(e).message)
    acting.value = false
  }
}

function changed() {
  historyKey.value++
}
</script>

<template>
  <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">Carregando…</div>

  <div v-else-if="notFound" class="rounded-2xl border border-slate-200 bg-white p-12 text-center">
    <p class="font-medium text-slate-900">Candidato não encontrado.</p>
    <p class="mt-1 text-sm text-slate-500">O cadastro pode ter sido excluído.</p>
    <BaseButton class="mt-4" variant="secondary" @click="router.push({ name: 'candidates' })">Voltar para a lista</BaseButton>
  </div>

  <template v-else-if="candidate && options">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-800" @click="router.push({ name: 'candidates' })">← Banco de talentos</button>

    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="flex items-start gap-4">
          <span class="grid size-14 shrink-0 place-items-center rounded-full bg-brand-100 text-lg font-semibold text-brand-800" aria-hidden="true">
            {{ (candidate.social_name || candidate.full_name).split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() }}
          </span>
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h1 class="text-xl font-semibold text-slate-900">{{ candidate.social_name || candidate.full_name }}</h1>
              <FavoriteButton v-if="candidate.abilities.manage_talent_pool" v-model="candidate.is_favorite" :candidate-id="candidate.id" size="md" />
              <StatusBadge :active="candidate.status === 'active'" />
            </div>
            <p v-if="candidate.social_name" class="text-xs text-slate-500">Nome civil: {{ candidate.full_name }}</p>
            <p class="mt-1 text-sm text-slate-600">
              {{ candidate.desired_position || 'Cargo pretendido não informado' }} · {{ candidate.professional_area_label }}
            </p>
            <p class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
              <a v-if="candidate.email" :href="`mailto:${candidate.email}`" class="hover:text-brand-700">{{ candidate.email }}</a>
              <span v-if="candidate.phone">{{ formatPhone(candidate.phone) }}</span>
              <span v-if="candidate.city">{{ candidate.city }}{{ candidate.state ? '/' + candidate.state : '' }}</span>
            </p>
            <TagsEditor v-if="candidate.tags" v-model="candidate.tags" class="mt-2" :candidate-id="candidate.id" :editable="candidate.abilities.manage_talent_pool" @changed="changed" />
          </div>
        </div>
        <div v-if="candidate.abilities.update || candidate.abilities.delete" class="flex flex-wrap gap-2">
          <BaseButton v-if="candidate.abilities.update" @click="router.push({ name: 'candidate-edit', params: { id: candidate.id } })">Editar</BaseButton>
          <BaseButton v-if="candidate.abilities.update" variant="secondary" @click="confirm = 'status'">
            {{ candidate.status === 'active' ? 'Inativar' : 'Reativar' }}
          </BaseButton>
          <BaseButton v-if="candidate.abilities.delete" variant="ghost" class="text-red-600" @click="confirm = 'delete'">Excluir</BaseButton>
        </div>
      </div>
      <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-4">
        <div><dt class="text-xs text-slate-500">Escolaridade</dt><dd class="font-medium text-slate-800">{{ candidate.education_level_label }}</dd></div>
        <div><dt class="text-xs text-slate-500">Disponibilidade</dt><dd class="font-medium text-slate-800">{{ candidate.availability_label || '—' }}</dd></div>
        <div><dt class="text-xs text-slate-500">Pretensão salarial</dt><dd class="font-medium text-slate-800">{{ formatMoney(candidate.salary_expectation) }}</dd></div>
        <div><dt class="text-xs text-slate-500">Cadastrado em</dt><dd class="font-medium text-slate-800">{{ formatDate(candidate.created_at) }} · {{ candidate.source_label }}</dd></div>
      </dl>
    </div>

    <DuplicateAlert v-if="auth.can('candidates.view')" :key="candidate.id" :candidate-id="candidate.id" :can-dismiss="candidate.abilities.update" @changed="changed" />

    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-slate-200" role="tablist">
      <button
        v-for="t in tabs"
        :key="t.key"
        type="button"
        role="tab"
        :aria-selected="tab === t.key"
        class="-mb-px whitespace-nowrap border-b-2 px-4 py-2.5 text-sm font-medium transition"
        :class="tab === t.key ? 'border-brand-700 text-brand-800' : 'border-transparent text-slate-500 hover:text-slate-800'"
        @click="tab = t.key"
      >
        {{ t.label }}
        <span v-if="counts(t.key)" class="ml-1 rounded-full bg-slate-100 px-1.5 text-xs text-slate-600">{{ counts(t.key) }}</span>
      </button>
    </div>

    <CandidateProfile v-if="tab === 'perfil'" :candidate="candidate" />
    <CandidateDocuments v-else-if="tab === 'documentos'" v-model:documents="candidate.documents" :candidate="candidate" :types="options.document_types" @changed="changed" />
    <CandidateNotes v-else-if="tab === 'anotacoes'" v-model:notes="candidate.notes" :candidate="candidate" @changed="changed" />
    <CandidateHistory v-else :key="historyKey" :candidate-id="candidate.id" />

    <p class="mt-6 text-xs text-slate-400">
      Última atualização em {{ formatDateTime(candidate.updated_at) }}{{ candidate.updated_by ? ` por ${candidate.updated_by}` : '' }}.
    </p>

    <ConfirmDialog
      :open="confirm === 'status'"
      :title="candidate.status === 'active' ? 'Inativar candidato' : 'Reativar candidato'"
      :message="candidate.status === 'active'
        ? 'O candidato deixa de aparecer como ativo no banco de talentos. Os dados e o histórico são mantidos.'
        : 'O candidato volta a ficar ativo no banco de talentos.'"
      :confirm-label="candidate.status === 'active' ? 'Inativar' : 'Reativar'"
      :loading="acting"
      @confirm="toggleStatus"
      @cancel="confirm = null"
    />
    <ConfirmDialog
      :open="confirm === 'delete'"
      title="Excluir cadastro"
      :message="`O cadastro de ${candidate.full_name} sairá do banco de talentos. A exclusão fica registrada na auditoria.`"
      confirm-label="Excluir"
      danger
      :loading="acting"
      @confirm="remove"
      @cancel="confirm = null"
    />
  </template>
</template>
