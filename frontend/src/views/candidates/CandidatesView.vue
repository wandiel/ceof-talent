<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { candidatesApi, type CandidateListItem, type CandidateOptions } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import type { ListParams } from '@/api/types'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useListing } from '@/composables/useListing'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { formatDate, formatExperience, formatPhone } from '@/utils/format'
import FavoriteButton from './components/FavoriteButton.vue'
import TagBadge from './components/TagBadge.vue'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()
const route = useRoute()
const canManage = auth.can('candidates.manage')
const canTalentPool = auth.can('talent_pool.manage')

/** Filtros avançados: editados num rascunho e aplicados pelo botão. */
const ADVANCED = [
  'keywords', 'professional_area', 'education_level_min', 'education_course', 'experience_title', 'experience_company',
  'min_experience_years', 'source', 'state', 'city', 'availability', 'work_shift', 'salary_max', 'age_min', 'age_max',
  'willing_to_relocate', 'has_resume', 'created_from', 'created_to',
] as const
const ARRAYS = ['skills', 'tags'] as const

function fromQuery(): ListParams {
  const q = route.query
  const params: ListParams = {}
  for (const key of [...ADVANCED, 'search', 'status', 'sort', 'favorites']) {
    if (typeof q[key] === 'string') params[key] = q[key] as string
  }
  for (const key of ARRAYS) {
    const value = q[key] ?? q[`${key}[]`]
    params[key] = Array.isArray(value) ? (value.filter(Boolean) as string[]) : typeof value === 'string' ? [value] : []
  }
  return params
}

const blank = Object.fromEntries(ADVANCED.map((k) => [k, ''])) as ListParams
const { params, items, meta, loading, goTo } = useListing<CandidateListItem>(candidatesApi.list, {
  ...blank,
  sort: '',
  favorites: '',
  skills: [],
  tags: [],
  ...fromQuery(),
})

const options = ref<CandidateOptions | null>(null)
const showAdvanced = ref(ADVANCED.some((k) => params[k]) || (params.skills as string[]).length > 0)
const draft = reactive<ListParams>({})
const skillText = ref('')

function loadDraft() {
  for (const k of ADVANCED) draft[k] = params[k] ?? ''
  draft.skills = [...((params.skills as string[]) ?? [])]
  draft.tags = [...((params.tags as string[]) ?? [])]
}
loadDraft()

onMounted(async () => {
  try {
    options.value = await candidatesApi.options()
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
})

// Mantém os filtros na URL: dá para voltar, recarregar ou compartilhar a busca.
watch(
  () => JSON.stringify(params),
  () => {
    const query: Record<string, string | string[]> = {}
    for (const [k, v] of Object.entries(params)) {
      if (k === 'page' || k === 'per_page') continue
      if (Array.isArray(v) ? v.length : v !== '' && v !== undefined && v !== null) query[k] = Array.isArray(v) ? v : String(v)
    }
    router.replace({ query })
  },
)

function addSkill() {
  const value = skillText.value.trim()
  const list = draft.skills as string[]
  if (value && !list.some((s) => s.toLowerCase() === value.toLowerCase())) list.push(value)
  skillText.value = ''
}

function applyAdvanced() {
  if (skillText.value.trim()) addSkill()
  Object.assign(params, { ...draft, skills: [...(draft.skills as string[])], tags: [...(draft.tags as string[])] })
  if (params.keywords && !params.sort) params.sort = ''
}

function clearAll() {
  Object.assign(params, { ...blank, search: '', status: '', favorites: '', sort: '', skills: [], tags: [] })
  loadDraft()
}

const activeCount = computed(() => ADVANCED.filter((k) => params[k]).length + (params.skills as string[]).length + (params.tags as string[]).length)
const highlight = computed(() => new Set((params.skills as string[]).map((s) => s.toLowerCase())))
const tagName = (id: string) => options.value?.tags.find((t) => t.id === id)

function toggleTag(id: string) {
  const list = draft.tags as string[]
  const i = list.indexOf(id)
  if (i >= 0) list.splice(i, 1)
  else list.push(id)
}

function open(candidate: CandidateListItem) {
  router.push({ name: 'candidate-detail', params: { id: candidate.id } })
}
</script>

<template>
  <PageHeader title="Banco de talentos" subtitle="Pesquise currículos por palavras-chave, experiência, formação e competências.">
    <template v-if="canManage" #actions>
      <BaseButton @click="router.push({ name: 'candidate-create' })">+ Novo candidato</BaseButton>
    </template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <div class="space-y-3 border-b border-slate-100 p-4">
      <div class="relative">
        <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M9 3.5a5.5 5.5 0 1 0 0 11 5.5 5.5 0 0 0 0-11ZM2 9a7 7 0 1 1 12.45 4.39l3.08 3.08a.75.75 0 1 1-1.06 1.06l-3.08-3.08A7 7 0 0 1 2 9Z" clip-rule="evenodd" /></svg>
        <input v-model="params.search" type="search" placeholder="Busca rápida: nome, e-mail, telefone ou cargo pretendido" :class="inputClass()" class="pl-9" aria-label="Busca rápida" />
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <div class="inline-flex rounded-lg bg-slate-100 p-1 text-sm" role="group" aria-label="Mostrar">
          <button type="button" class="rounded-md px-3 py-1" :class="!params.favorites ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600'" @click="params.favorites = ''">Todos</button>
          <button type="button" class="rounded-md px-3 py-1" :class="params.favorites ? 'bg-white font-medium text-slate-900 shadow-sm' : 'text-slate-600'" @click="params.favorites = '1'">★ Meus favoritos</button>
        </div>
        <div class="w-44">
          <select v-model="params.status" :class="inputClass()" aria-label="Situação">
            <option value="">Todas as situações</option>
            <option value="active">Ativos</option>
            <option value="inactive">Inativos</option>
          </select>
        </div>
        <div class="w-52">
          <select v-model="params.sort" :class="inputClass()" aria-label="Ordenar por">
            <option value="">{{ params.keywords ? 'Mais relevantes' : 'Mais recentes' }}</option>
            <option v-if="params.keywords" value="-created_at">Mais recentes</option>
            <option value="full_name">Nome (A–Z)</option>
            <option value="-experience">Mais experiência</option>
            <option value="-updated_at">Atualizados recentemente</option>
          </select>
        </div>
        <BaseButton variant="secondary" class="sm:ml-auto" :aria-expanded="showAdvanced" @click="showAdvanced = !showAdvanced">
          Busca avançada{{ activeCount ? ` (${activeCount})` : '' }}
        </BaseButton>
      </div>
    </div>

    <form v-if="showAdvanced && options" class="space-y-4 border-b border-slate-100 bg-slate-50/60 p-4" @submit.prevent="applyAdvanced">
      <FormField label="Palavras-chave no currículo" for="f-keywords" hint='Procura em todo o cadastro. Use aspas para frase exata ("centro cirúrgico") e hífen para excluir (-estágio).'>
        <input id="f-keywords" v-model="draft.keywords" type="text" placeholder="Ex.: enfermagem oftalmologia plantão" :class="inputClass()" />
      </FormField>

      <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <FormField label="Competências (todas)" for="f-skill">
          <input id="f-skill" v-model="skillText" type="text" placeholder="Digite e tecle Enter" :class="inputClass()" @keydown.enter.prevent="addSkill" />
          <div v-if="(draft.skills as string[]).length" class="mt-2 flex flex-wrap gap-1">
            <button v-for="(s, i) in draft.skills as string[]" :key="s" type="button" class="rounded-md bg-brand-50 px-2 py-0.5 text-xs text-brand-800 ring-1 ring-brand-200" :aria-label="`Remover ${s}`" @click="(draft.skills as string[]).splice(i, 1)">{{ s }} ×</button>
          </div>
        </FormField>
        <FormField label="Já trabalhou como" for="f-title">
          <input id="f-title" v-model="draft.experience_title" type="text" placeholder="Ex.: recepcionista" :class="inputClass()" />
        </FormField>
        <FormField label="Empresa" for="f-company">
          <input id="f-company" v-model="draft.experience_company" type="text" :class="inputClass()" />
        </FormField>
        <FormField label="Experiência mínima (anos)" for="f-years">
          <input id="f-years" v-model="draft.min_experience_years" type="number" min="0" max="60" step="0.5" :class="inputClass()" />
        </FormField>
        <FormField label="Escolaridade mínima" for="f-edu">
          <select id="f-edu" v-model="draft.education_level_min" :class="inputClass()">
            <option value="">Qualquer</option>
            <option v-for="o in options.education_levels" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Curso de formação" for="f-course">
          <input id="f-course" v-model="draft.education_course" type="text" placeholder="Ex.: enfermagem" :class="inputClass()" />
        </FormField>
        <FormField label="Área profissional" for="f-area">
          <select id="f-area" v-model="draft.professional_area" :class="inputClass()">
            <option value="">Todas</option>
            <option v-for="o in options.professional_areas" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Origem" for="f-source">
          <select id="f-source" v-model="draft.source" :class="inputClass()">
            <option value="">Todas</option>
            <option v-for="o in options.sources" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Cidade" for="f-city">
          <input id="f-city" v-model="draft.city" type="text" :class="inputClass()" />
        </FormField>
        <FormField label="UF" for="f-state">
          <select id="f-state" v-model="draft.state" :class="inputClass()">
            <option value="">Todas</option>
            <option v-for="uf in options.states" :key="uf" :value="uf">{{ uf }}</option>
          </select>
        </FormField>
        <FormField label="Disponibilidade" for="f-avail">
          <select id="f-avail" v-model="draft.availability" :class="inputClass()">
            <option value="">Qualquer</option>
            <option v-for="o in options.availabilities" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Turno" for="f-shift">
          <select id="f-shift" v-model="draft.work_shift" :class="inputClass()">
            <option value="">Qualquer</option>
            <option v-for="o in options.work_shifts" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Pretensão salarial até (R$)" for="f-salary">
          <input id="f-salary" v-model="draft.salary_max" type="number" min="0" step="100" :class="inputClass()" />
        </FormField>
        <FormField label="Idade" for="f-age-min">
          <div class="flex items-center gap-2">
            <input id="f-age-min" v-model="draft.age_min" type="number" min="14" max="100" placeholder="de" :class="inputClass()" aria-label="Idade mínima" />
            <input v-model="draft.age_max" type="number" min="14" max="100" placeholder="até" :class="inputClass()" aria-label="Idade máxima" />
          </div>
        </FormField>
        <FormField label="Cadastrado entre" for="f-from" class="sm:col-span-2">
          <div class="flex items-center gap-2">
            <input id="f-from" v-model="draft.created_from" type="date" :class="inputClass()" aria-label="Cadastrado desde" />
            <input v-model="draft.created_to" type="date" :class="inputClass()" aria-label="Cadastrado até" />
          </div>
        </FormField>
      </div>

      <fieldset v-if="options.tags.length">
        <legend class="mb-1 text-sm font-medium text-slate-700">Etiquetas (qualquer uma)</legend>
        <div class="flex flex-wrap gap-2">
          <button
            v-for="t in options.tags"
            :key="t.id"
            type="button"
            class="rounded-md transition"
            :class="(draft.tags as string[]).includes(t.id) ? 'ring-2 ring-brand-500 ring-offset-1' : 'opacity-70 hover:opacity-100'"
            :aria-pressed="(draft.tags as string[]).includes(t.id)"
            @click="toggleTag(t.id)"
          >
            <TagBadge :name="t.name" :color="t.color" />
          </button>
        </div>
      </fieldset>

      <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-700">
        <label class="inline-flex items-center gap-2"><input v-model="draft.has_resume" type="checkbox" true-value="1" false-value="" class="size-4 rounded border-slate-300" /> Com currículo anexado</label>
        <label class="inline-flex items-center gap-2"><input v-model="draft.willing_to_relocate" type="checkbox" true-value="1" false-value="" class="size-4 rounded border-slate-300" /> Aceita mudar de cidade</label>
      </div>

      <div class="flex flex-wrap justify-end gap-2">
        <BaseButton variant="ghost" @click="clearAll">Limpar tudo</BaseButton>
        <BaseButton type="submit">Pesquisar</BaseButton>
      </div>
    </form>

    <div v-if="activeCount && !showAdvanced" class="flex flex-wrap items-center gap-2 border-b border-slate-100 px-4 py-2 text-xs text-slate-600">
      <span>Filtros ativos:</span>
      <span v-if="params.keywords" class="rounded bg-slate-100 px-2 py-0.5">“{{ params.keywords }}”</span>
      <span v-for="s in params.skills as string[]" :key="s" class="rounded bg-brand-50 px-2 py-0.5 text-brand-800">{{ s }}</span>
      <template v-for="id in params.tags as string[]" :key="id"><TagBadge v-if="tagName(id)" :name="tagName(id)!.name" :color="tagName(id)!.color" /></template>
      <button type="button" class="text-brand-700 hover:underline" @click="clearAll">Limpar</button>
    </div>

    <p class="px-4 pt-3 text-xs text-slate-500" aria-live="polite">{{ loading ? 'Pesquisando…' : `${meta.total} candidato${meta.total === 1 ? '' : 's'} encontrado${meta.total === 1 ? '' : 's'}` }}</p>

    <ul class="divide-y divide-slate-100" :class="{ 'opacity-60': loading }">
      <li v-for="c in items" :key="c.id" class="flex cursor-pointer gap-3 px-4 py-4 hover:bg-slate-50/60 sm:gap-4" @click="open(c)">
        <FavoriteButton v-if="canTalentPool" v-model="c.is_favorite" :candidate-id="c.id" class="mt-0.5 self-start" />
        <div class="min-w-0 flex-1">
          <div class="flex flex-wrap items-center gap-2">
            <RouterLink :to="{ name: 'candidate-detail', params: { id: c.id } }" class="font-medium text-slate-900 hover:text-brand-700" @click.stop>
              {{ c.social_name || c.full_name }}
            </RouterLink>
            <StatusBadge v-if="c.status !== 'active'" :active="false" />
            <TagBadge v-for="t in c.tags" :key="t.id" :name="t.name" :color="t.color" />
          </div>
          <p class="mt-0.5 text-sm text-slate-600">
            {{ c.desired_position || 'Cargo pretendido não informado' }} · {{ c.professional_area_label }} · {{ c.education_level_label }}
          </p>
          <p class="mt-0.5 text-xs text-slate-500">
            {{ formatExperience(c.experience_months) }}
            <template v-if="c.city"> · {{ c.city }}{{ c.state ? '/' + c.state : '' }}</template>
            <template v-if="c.phone"> · {{ formatPhone(c.phone) }}</template>
            <template v-if="c.documents_count"> · {{ c.documents_count }} anexo{{ c.documents_count > 1 ? 's' : '' }}</template>
          </p>
          <ul v-if="c.skills_preview?.length" class="mt-2 flex flex-wrap gap-1">
            <li v-for="s in c.skills_preview" :key="s" class="rounded px-1.5 py-0.5 text-xs" :class="highlight.has(s.toLowerCase()) ? 'bg-amber-100 font-medium text-amber-900' : 'bg-slate-100 text-slate-600'">{{ s }}</li>
          </ul>
        </div>
        <div class="hidden shrink-0 text-right text-xs text-slate-500 sm:block">
          <p>Cadastrado em {{ formatDate(c.created_at) }}</p>
          <p>{{ c.source_label }}</p>
        </div>
      </li>
      <li v-if="!loading && items.length === 0" class="px-4 py-12 text-center text-slate-500">
        Nenhum candidato encontrado.
        <button v-if="activeCount || params.search || params.favorites" type="button" class="text-brand-700 hover:underline" @click="clearAll">Limpar filtros</button>
      </li>
    </ul>
    <PaginationBar :meta="meta" @page="goTo" />
  </div>
</template>
