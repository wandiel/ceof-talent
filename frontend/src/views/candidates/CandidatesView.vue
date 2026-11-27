<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { candidatesApi, type CandidateListItem, type CandidateOptions } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import ListToolbar from '@/components/ui/ListToolbar.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SortHeader from '@/components/ui/SortHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useListing } from '@/composables/useListing'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { formatDate, formatPhone } from '@/utils/format'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()
const canManage = auth.can('candidates.manage')

const { params, items, meta, loading, goTo } = useListing<CandidateListItem>(candidatesApi.list, {
  sort: '-created_at',
  professional_area: '',
  education_level: '',
  source: '',
  state: '',
})
const options = ref<CandidateOptions | null>(null)
const showFilters = ref(false)

onMounted(async () => {
  try {
    options.value = await candidatesApi.options()
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
})

function clearFilters() {
  Object.assign(params, { professional_area: '', education_level: '', source: '', state: '', status: '', search: '' })
}

function open(candidate: CandidateListItem) {
  router.push({ name: 'candidate-detail', params: { id: candidate.id } })
}
</script>

<template>
  <PageHeader title="Candidatos" subtitle="Cadastro de candidatos, currículos e histórico.">
    <template v-if="canManage" #actions>
      <BaseButton @click="router.push({ name: 'candidate-create' })">+ Novo candidato</BaseButton>
    </template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <ListToolbar v-model:search="params.search as string" v-model:status="params.status as string" placeholder="Buscar por nome, e-mail, telefone ou cargo">
      <BaseButton variant="secondary" class="sm:w-auto" :aria-expanded="showFilters" @click="showFilters = !showFilters">
        Filtros{{ [params.professional_area, params.education_level, params.source, params.state].filter(Boolean).length ? ` (${[params.professional_area, params.education_level, params.source, params.state].filter(Boolean).length})` : '' }}
      </BaseButton>
    </ListToolbar>

    <div v-if="showFilters && options" class="grid gap-3 border-b border-slate-100 bg-slate-50/60 p-4 sm:grid-cols-2 lg:grid-cols-5">
      <select v-model="params.professional_area" :class="inputClass()" aria-label="Área profissional">
        <option value="">Todas as áreas</option>
        <option v-for="o in options.professional_areas" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <select v-model="params.education_level" :class="inputClass()" aria-label="Escolaridade">
        <option value="">Toda escolaridade</option>
        <option v-for="o in options.education_levels" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <select v-model="params.source" :class="inputClass()" aria-label="Origem">
        <option value="">Todas as origens</option>
        <option v-for="o in options.sources" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
      <select v-model="params.state" :class="inputClass()" aria-label="UF">
        <option value="">Todas as UFs</option>
        <option v-for="uf in options.states" :key="uf" :value="uf">{{ uf }}</option>
      </select>
      <BaseButton variant="ghost" @click="clearFilters">Limpar filtros</BaseButton>
    </div>

    <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <SortHeader label="Nome" field="full_name" :sort="params.sort as string" @sort="params.sort = $event" />
            <th scope="col" class="px-4 py-3">Área e escolaridade</th>
            <th scope="col" class="hidden px-4 py-3 md:table-cell">Contato</th>
            <SortHeader label="Cidade" field="city" :sort="params.sort as string" @sort="params.sort = $event" />
            <th scope="col" class="px-4 py-3">Situação</th>
            <SortHeader label="Cadastro" field="created_at" :sort="params.sort as string" @sort="params.sort = $event" />
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="c in items" :key="c.id" class="cursor-pointer hover:bg-slate-50/60" @click="open(c)">
            <td class="px-4 py-3">
              <RouterLink :to="{ name: 'candidate-detail', params: { id: c.id } }" class="font-medium text-slate-900 hover:text-brand-700" @click.stop>
                {{ c.social_name || c.full_name }}
              </RouterLink>
              <p class="text-xs text-slate-500">{{ c.desired_position || 'Cargo pretendido não informado' }}</p>
              <p v-if="c.documents_count" class="mt-0.5 text-xs text-brand-700">{{ c.documents_count }} anexo{{ c.documents_count > 1 ? 's' : '' }}</p>
            </td>
            <td class="px-4 py-3">
              <p class="text-slate-700">{{ c.professional_area_label }}</p>
              <p class="text-xs text-slate-500">{{ c.education_level_label }}</p>
            </td>
            <td class="hidden px-4 py-3 text-slate-600 md:table-cell">
              <p>{{ c.email || '—' }}</p>
              <p class="text-xs text-slate-500">{{ formatPhone(c.phone) }}</p>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ c.city ? `${c.city}${c.state ? '/' + c.state : ''}` : '—' }}</td>
            <td class="px-4 py-3"><StatusBadge :active="c.status === 'active'" /></td>
            <td class="whitespace-nowrap px-4 py-3 text-slate-600">
              {{ formatDate(c.created_at) }}
              <p class="text-xs text-slate-500">{{ c.source_label }}</p>
            </td>
          </tr>
          <tr v-if="!loading && items.length === 0">
            <td colspan="6" class="px-4 py-12 text-center text-slate-500">Nenhum candidato encontrado com os filtros atuais.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :meta="meta" @page="goTo" />
  </div>
</template>
