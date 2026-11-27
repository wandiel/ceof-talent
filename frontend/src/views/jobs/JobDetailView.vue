<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import { applicationsApi, jobsApi, type ApplicationItem, type Job, type JobOptions, type Stage } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useToastStore } from '@/stores/toast'
import AddCandidatesModal from './components/AddCandidatesModal.vue'
import ApplicationDrawer from './components/ApplicationDrawer.vue'
import JobActionDialog from './components/JobActionDialog.vue'
import JobDetails from './components/JobDetails.vue'
import JobHistory from './components/JobHistory.vue'
import JobStatusBadge from './components/JobStatusBadge.vue'
import MoveDialog from './components/MoveDialog.vue'
import PipelineBoard from './components/PipelineBoard.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const job = ref<Job | null>(null)
const options = ref<JobOptions | null>(null)
const applications = ref<ApplicationItem[]>([])
const loading = ref(true)
const notFound = ref(false)
const historyKey = ref(0)

const tabs = computed(() => [
  ...(job.value?.abilities.view_applications ? [{ key: 'processo', label: 'Processo seletivo' }] : []),
  { key: 'detalhes', label: 'Detalhes' },
  { key: 'historico', label: 'Histórico' },
])
const tab = computed<string>({
  get: () => (tabs.value.some((t) => t.key === route.query.aba) ? (route.query.aba as string) : tabs.value[0]!.key),
  set: (value) => router.replace({ query: { ...route.query, aba: value } }),
})

const frozen = computed(() => !job.value || !['approved', 'published', 'on_hold'].includes(job.value.status))
const moveAbilities = computed(() => ({ move: !!job.value?.abilities.move, decide: !!job.value?.abilities.decide }))

async function loadApplications() {
  if (!job.value?.abilities.view_applications) return
  try {
    applications.value = (await jobsApi.applications(job.value.id)).data
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
}

async function load() {
  loading.value = true
  try {
    ;[job.value, options.value] = await Promise.all([jobsApi.get(route.params.id as string), jobsApi.options()])
    await loadApplications()
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

// Ações da vaga (enviar, aprovar, publicar...)
const pendingAction = ref<Job['abilities']['actions'][number] | null>(null)
const acting = ref(false)
const actionError = ref('')

async function runAction(reason: string) {
  if (!job.value || !pendingAction.value) return
  acting.value = true
  actionError.value = ''
  try {
    job.value = await jobsApi.transition(job.value.id, pendingAction.value.action, reason)
    toast.success(`Vaga: ${job.value.status_label.toLowerCase()}.`)
    pendingAction.value = null
    historyKey.value++
    await loadApplications()
  } catch (e) {
    actionError.value = parseApiError(e).message
  } finally {
    acting.value = false
  }
}

// Movimentação de candidatos
const moving = ref<ApplicationItem | null>(null)
const movingStage = ref<Stage | null>(null)
const moveErrors = ref<Record<string, string>>({})
const movingBusy = ref(false)
const openId = ref<string | null>(null)
const drawerKey = ref(0)
const showAdd = ref(false)

function askMove(a: ApplicationItem, stage: Stage | null = null) {
  moveErrors.value = {}
  movingStage.value = stage
  moving.value = a
}

function onDrop(a: ApplicationItem, stage: Stage) {
  // Reprovação pede motivo e contratação pede confirmação; o resto move direto.
  if (stage === 'rejected' || stage === 'hired') askMove(a, stage)
  else move(a, stage, null, null)
}

async function move(a: ApplicationItem, stage: Stage, reason: string | null, note: string | null) {
  if (!job.value) return
  movingBusy.value = true
  moveErrors.value = {}
  const previous = a.stage
  if (!moving.value) a.stage = stage // arrastar: o cartão já muda de coluna
  try {
    const { data, meta } = await applicationsApi.move(a.id, stage, reason, note)
    Object.assign(a, data)
    moving.value = null
    drawerKey.value++
    toast.success(`${a.candidate?.social_name || a.candidate?.full_name}: ${data.stage_label}.`)
    if (meta.job_closed) {
      toast.success('Todas as posições foram preenchidas: a vaga foi encerrada automaticamente.')
      job.value = await jobsApi.get(job.value.id)
      historyKey.value++
    } else if (stage === 'hired') {
      job.value = await jobsApi.get(job.value.id)
    }
  } catch (e) {
    a.stage = previous
    const parsed = parseApiError(e)
    if (moving.value) moveErrors.value = Object.keys(parsed.fields).length ? parsed.fields : { stage: parsed.message }
    else toast.error(parsed.message)
  } finally {
    movingBusy.value = false
  }
}

function confirmMove(stage: Stage, reason: string | null, note: string | null) {
  if (moving.value) move(moving.value, stage, reason, note)
}

const counts = computed(() => {
  const open = applications.value.filter((a) => !['hired', 'rejected', 'withdrawn'].includes(a.stage)).length
  return { total: applications.value.length, open }
})
</script>

<template>
  <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">Carregando…</div>

  <div v-else-if="notFound" class="rounded-2xl border border-slate-200 bg-white p-12 text-center">
    <p class="font-medium text-slate-900">Vaga não encontrada.</p>
    <BaseButton class="mt-4" variant="secondary" @click="router.push({ name: 'jobs' })">Voltar para as vagas</BaseButton>
  </div>

  <template v-else-if="job && options">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-800" @click="router.push({ name: 'jobs' })">← Vagas</button>

    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <span class="font-mono text-sm text-slate-500">{{ job.code }}</span>
            <h1 class="text-xl font-semibold text-slate-900">{{ job.title }}</h1>
            <JobStatusBadge :status="job.status" :label="job.status_label" />
          </div>
          <p class="mt-1 text-sm text-slate-600">
            {{ job.department.name }}<template v-if="job.unit"> · {{ job.unit.name }}</template> · {{ job.employment_type_label }} ·
            {{ job.positions_count }} posição{{ job.positions_count > 1 ? 'ões' : '' }}
          </p>
          <p v-if="job.status_reason && ['rejected', 'on_hold', 'closed', 'cancelled'].includes(job.status)" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200">
            <strong>Motivo:</strong> {{ job.status_reason }}
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <BaseButton v-if="job.abilities.update" variant="secondary" @click="router.push({ name: 'job-edit', params: { id: job.id } })">Editar</BaseButton>
          <BaseButton
            v-for="a in job.abilities.actions"
            :key="a.action"
            :variant="a.action === 'cancel' || a.action === 'reject' ? 'ghost' : a.action === 'suspend' || a.action === 'close' ? 'secondary' : 'primary'"
            :class="{ 'text-red-600': a.action === 'cancel' || a.action === 'reject' }"
            @click="actionError = ''; pendingAction = a"
          >
            {{ a.label }}
          </BaseButton>
        </div>
      </div>
      <dl class="mt-5 grid grid-cols-2 gap-4 border-t border-slate-100 pt-4 text-sm sm:grid-cols-4">
        <div><dt class="text-xs text-slate-500">Candidatos no processo</dt><dd class="text-lg font-semibold text-slate-900">{{ counts.open }}</dd></div>
        <div><dt class="text-xs text-slate-500">Total de inscritos</dt><dd class="text-lg font-semibold text-slate-900">{{ counts.total }}</dd></div>
        <div><dt class="text-xs text-slate-500">Contratados</dt><dd class="text-lg font-semibold text-slate-900">{{ job.hired_total }} de {{ job.positions_count }}</dd></div>
        <div><dt class="text-xs text-slate-500">Solicitada por</dt><dd class="font-medium text-slate-800">{{ job.requester?.name ?? '—' }}</dd></div>
      </dl>
    </div>

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
      </button>
    </div>

    <template v-if="tab === 'processo'">
      <div class="mb-3 flex flex-wrap items-center gap-3">
        <p v-if="frozen" class="text-sm text-slate-600">
          {{ ['draft', 'pending_approval', 'rejected'].includes(job.status) ? 'O quadro é liberado depois que a vaga for aprovada.' : 'Vaga encerrada: o quadro está congelado para consulta.' }}
        </p>
        <p v-else class="text-sm text-slate-600">Arraste os cartões entre as colunas ou use o botão ⋮ do cartão. Clique no cartão para ver o histórico.</p>
        <BaseButton v-if="job.abilities.add_applications" class="ml-auto" @click="showAdd = true">+ Adicionar candidatos</BaseButton>
      </div>
      <PipelineBoard :applications="applications" :stages="options.stages" :abilities="moveAbilities" :frozen="frozen" @open="openId = $event.id" @drop="onDrop" @menu="askMove($event)" />
    </template>
    <JobDetails v-else-if="tab === 'detalhes'" :job="job" />
    <JobHistory v-else :key="historyKey" :job-id="job.id" />

    <JobActionDialog :action="pendingAction" :loading="acting" :error="actionError" @confirm="runAction" @cancel="pendingAction = null" />
    <MoveDialog
      :application="moving"
      :initial-stage="movingStage"
      :stages="options.stages"
      :rejection-reasons="options.rejection_reasons"
      :abilities="moveAbilities"
      :loading="movingBusy"
      :errors="moveErrors"
      @confirm="confirmMove"
      @cancel="moving = null"
    />
    <AddCandidatesModal :open="showAdd" :job-id="job.id" :existing="applications.map((a) => a.candidate?.id ?? '')" @close="showAdd = false" @added="loadApplications" />
    <ApplicationDrawer :application-id="openId" :can-move="!frozen && (moveAbilities.move || moveAbilities.decide)" :job-open="!frozen" :refresh-key="drawerKey" @changed="loadApplications" @close="openId = null" @move="openId = null; askMove(applications.find((x) => x.id === $event.id) ?? $event)" />
  </template>
</template>
