<script setup lang="ts">
import { ref, watch } from 'vue'
import { candidatesApi, type CandidateListItem } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import { jobsApi } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ open: boolean; jobId: string; existing: string[] }>()
const emit = defineEmits<{ close: []; added: [] }>()
const toast = useToastStore()

const search = ref('')
const results = ref<CandidateListItem[]>([])
const selected = ref<Map<string, string>>(new Map())
const loading = ref(false)
const saving = ref(false)
let timer: number | undefined
let request = 0

async function load() {
  const current = ++request
  loading.value = true
  try {
    const page = await candidatesApi.list({ search: search.value, status: 'active', per_page: 20, sort: search.value ? '' : '-created_at' })
    if (current === request) results.value = page.data
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    if (current === request) loading.value = false
  }
}

watch(
  () => props.open,
  (open) => {
    if (!open) return
    search.value = ''
    selected.value = new Map()
    load()
  },
)
watch(search, () => {
  window.clearTimeout(timer)
  timer = window.setTimeout(load, 300)
})

function toggle(c: CandidateListItem) {
  const next = new Map(selected.value)
  if (next.has(c.id)) next.delete(c.id)
  else next.set(c.id, c.social_name || c.full_name)
  selected.value = next
}

async function submit() {
  saving.value = true
  try {
    const { meta } = await jobsApi.addCandidates(props.jobId, [...selected.value.keys()])
    const parts = [`${meta.added} candidato${meta.added === 1 ? '' : 's'} inscrito${meta.added === 1 ? '' : 's'} na vaga.`]
    if (meta.already.length) parts.push(`Já estavam no processo: ${meta.already.join(', ')}.`)
    if (meta.inactive.length) parts.push(`Inativos (não inscritos): ${meta.inactive.join(', ')}.`)
    toast.success(parts.join(' '))
    emit('added')
    emit('close')
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="open" title="Adicionar candidatos do banco de talentos" size="lg" @close="emit('close')">
    <input v-model="search" type="search" placeholder="Buscar por nome, e-mail, telefone ou CPF" aria-label="Buscar candidatos" :class="inputClass()" />
    <p class="mt-2 text-xs text-slate-500">Apenas candidatos ativos. Para filtros avançados, use o Banco de talentos.</p>

    <ul class="mt-3 max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-lg border border-slate-200" :class="{ 'opacity-60': loading }">
      <li v-for="c in results" :key="c.id">
        <label class="flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-slate-50" :class="{ 'cursor-not-allowed opacity-60': existing.includes(c.id) }">
          <input type="checkbox" class="size-4 rounded border-slate-300" :checked="selected.has(c.id) || existing.includes(c.id)" :disabled="existing.includes(c.id)" @change="toggle(c)" />
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium text-slate-900">{{ c.social_name || c.full_name }}</span>
            <span class="block truncate text-xs text-slate-500">{{ c.desired_position || 'Cargo pretendido não informado' }} · {{ c.professional_area_label }}<template v-if="c.city"> · {{ c.city }}</template></span>
          </span>
          <span v-if="existing.includes(c.id)" class="text-xs text-slate-500">Já no processo</span>
        </label>
      </li>
      <li v-if="!loading && !results.length" class="px-3 py-8 text-center text-sm text-slate-500">Nenhum candidato encontrado.</li>
    </ul>

    <template #footer>
      <span class="mr-auto self-center text-sm text-slate-600">{{ selected.size }} selecionado{{ selected.size === 1 ? '' : 's' }}</span>
      <BaseButton variant="secondary" @click="emit('close')">Cancelar</BaseButton>
      <BaseButton :disabled="!selected.size" :loading="saving" @click="submit">Inscrever na vaga</BaseButton>
    </template>
  </BaseModal>
</template>
