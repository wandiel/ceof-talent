<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import { formatWhen, interviewsApi, type HistoryItem, type Interview, type InterviewOptions } from '@/api/interviews'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'
import { formatDateTime } from '@/utils/format'
import EvaluationForm from './components/EvaluationForm.vue'
import InterviewStatusBadge from './components/InterviewStatusBadge.vue'
import ScheduleInterviewModal from './components/ScheduleInterviewModal.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const interview = ref<Interview | null>(null)
const options = ref<InterviewOptions | null>(null)
const history = ref<HistoryItem[]>([])
const loading = ref(true)
const notFound = ref(false)
const editing = ref(false)
const evaluating = ref(false)
const dialog = ref<'cancel' | 'no_show' | null>(null)
const reason = ref('')
const dialogError = ref('')
const acting = ref(false)

async function loadHistory() {
  if (interview.value) history.value = await interviewsApi.history(interview.value.id).catch(() => [])
}

onMounted(async () => {
  try {
    ;[interview.value, options.value] = await Promise.all([interviewsApi.get(route.params.id as string), interviewsApi.options()])
    evaluating.value = !!interview.value.abilities?.evaluate && !interview.value.evaluations?.some((e) => e.is_mine) && route.query.parecer === '1'
    await loadHistory()
  } catch (e) {
    const parsed = parseApiError(e)
    if (parsed.status === 404) notFound.value = true
    else if (parsed.status === 403) router.replace({ name: 'forbidden' })
    else toast.error(parsed.message)
  } finally {
    loading.value = false
  }
})

const myEvaluation = computed(() => interview.value?.evaluations?.find((e) => e.is_mine))
const average = computed(() => {
  const list = interview.value?.evaluations ?? []
  return list.length ? (list.reduce((sum, e) => sum + Number(e.average_score), 0) / list.length).toFixed(1).replace('.', ',') : null
})

function updated(i: Interview) {
  interview.value = i
  evaluating.value = false
  loadHistory()
}

async function confirmDialog() {
  if (!interview.value || !dialog.value) return
  acting.value = true
  dialogError.value = ''
  try {
    interview.value = dialog.value === 'cancel' ? await interviewsApi.cancel(interview.value.id, reason.value) : await interviewsApi.noShow(interview.value.id, reason.value || null)
    toast.success(dialog.value === 'cancel' ? 'Entrevista cancelada. Os entrevistadores foram avisados.' : 'Ausência registrada.')
    dialog.value = null
    loadHistory()
  } catch (e) {
    dialogError.value = parseApiError(e).message
  } finally {
    acting.value = false
  }
}

const recColor: Record<string, string> = {
  strong_yes: 'bg-emerald-100 text-emerald-800',
  yes: 'bg-emerald-50 text-emerald-700',
  with_reservations: 'bg-amber-50 text-amber-800',
  no: 'bg-red-50 text-red-700',
}
</script>

<template>
  <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">Carregando…</div>
  <div v-else-if="notFound" class="rounded-2xl border border-slate-200 bg-white p-12 text-center">
    <p class="font-medium text-slate-900">Entrevista não encontrada.</p>
    <BaseButton class="mt-4" variant="secondary" @click="router.push({ name: 'interviews' })">Voltar para a agenda</BaseButton>
  </div>

  <template v-else-if="interview && options">
    <button type="button" class="mb-3 text-sm text-slate-500 hover:text-slate-800" @click="router.push({ name: 'interviews' })">← Entrevistas</button>

    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <div class="flex flex-wrap items-center gap-2">
            <h1 class="text-xl font-semibold text-slate-900">{{ interview.type_label }} · {{ interview.candidate?.name }}</h1>
            <InterviewStatusBadge :status="interview.status" :label="interview.status_label" />
          </div>
          <p class="mt-1 text-sm text-slate-600">
            {{ formatWhen(interview.scheduled_at, interview.ends_at) }} · {{ interview.mode_label }}
            <template v-if="interview.location"> · {{ interview.location }}</template>
          </p>
          <p v-if="interview.meeting_url" class="mt-1 text-sm"><a :href="interview.meeting_url" target="_blank" rel="noopener noreferrer" class="text-brand-700 hover:underline">Entrar na videochamada ↗</a></p>
          <p class="mt-1 text-sm text-slate-500">
            Vaga
            <RouterLink v-if="interview.job" :to="{ name: 'job-detail', params: { id: interview.job.id } }" class="text-brand-700 hover:underline">{{ interview.job.code }} · {{ interview.job.title }}</RouterLink>
            · Etapa atual: {{ interview.application?.stage_label }}
          </p>
          <p class="mt-1 text-sm text-slate-500">Entrevistadores: {{ (interview.interviewers ?? []).map((u) => u.name).join(', ') }}</p>
          <p v-if="interview.status_reason" class="mt-2 rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-900 ring-1 ring-amber-200"><strong>Motivo:</strong> {{ interview.status_reason }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
          <RouterLink v-if="interview.abilities?.view_candidate && interview.candidate" :to="{ name: 'candidate-detail', params: { id: interview.candidate.id } }" class="inline-flex items-center rounded-lg px-4 py-2 text-sm font-medium text-slate-700 ring-1 ring-slate-300 hover:bg-slate-50">Ficha e currículo</RouterLink>
          <BaseButton v-if="interview.abilities?.evaluate && !evaluating" @click="evaluating = true">{{ myEvaluation ? 'Corrigir meu parecer' : 'Registrar parecer' }}</BaseButton>
          <BaseButton v-if="interview.abilities?.update" variant="secondary" @click="editing = true">Remarcar</BaseButton>
          <BaseButton v-if="interview.abilities?.no_show" variant="secondary" @click="reason = ''; dialogError = ''; dialog = 'no_show'">Não compareceu</BaseButton>
          <BaseButton v-if="interview.abilities?.cancel" variant="ghost" class="text-red-600" @click="reason = ''; dialogError = ''; dialog = 'cancel'">Cancelar</BaseButton>
        </div>
      </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
      <div class="space-y-6 lg:col-span-2">
        <section v-if="evaluating" class="rounded-2xl border border-brand-200 bg-white p-5 shadow-sm">
          <h2 class="mb-4 text-base font-semibold text-slate-900">{{ myEvaluation ? 'Corrigir meu parecer' : 'Meu parecer' }}</h2>
          <EvaluationForm :interview="interview" :recommendations="options.recommendations" @saved="updated" @cancel="evaluating = false" />
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-slate-900">Pareceres</h2>
            <p v-if="average" class="text-sm text-slate-600">Média geral: <strong class="text-slate-900">{{ average }}</strong> de 5</p>
          </div>
          <p v-if="interview.evaluations_hidden" class="mb-4 rounded-lg bg-slate-50 px-3 py-2 text-sm text-slate-600 ring-1 ring-slate-200">
            Os pareceres dos outros entrevistadores aparecem depois que você registrar o seu, para não influenciar a sua avaliação.
          </p>
          <div v-if="interview.evaluations?.length" class="space-y-4">
            <article v-for="e in interview.evaluations" :key="e.evaluator.id" class="rounded-xl border border-slate-200 p-4">
              <header class="flex flex-wrap items-center gap-2">
                <p class="font-medium text-slate-900">{{ e.evaluator.name }}<span v-if="e.is_mine" class="text-xs font-normal text-slate-500"> (você)</span></p>
                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="recColor[e.recommendation]">{{ e.recommendation_label }}</span>
                <span class="ml-auto text-sm text-slate-600">Média <strong>{{ Number(e.average_score).toFixed(1).replace('.', ',') }}</strong></span>
              </header>
              <ul class="mt-3 space-y-1.5">
                <li v-for="s in e.scores" :key="s.criterion" class="text-sm">
                  <div class="flex items-center gap-3">
                    <span class="w-44 shrink-0 truncate text-slate-700">{{ s.name }}</span>
                    <span class="h-2 flex-1 rounded-full bg-slate-100"><span class="block h-2 rounded-full bg-brand-600" :style="{ width: s.score * 20 + '%' }" /></span>
                    <span class="w-6 text-right font-medium text-slate-900">{{ s.score }}</span>
                  </div>
                  <p v-if="s.comment" class="ml-0 text-xs text-slate-500 sm:ml-[12.5rem]">{{ s.comment }}</p>
                </li>
              </ul>
              <p class="mt-3 whitespace-pre-line text-sm text-slate-700">{{ e.summary }}</p>
              <p v-if="e.observations" class="mt-2 whitespace-pre-line text-sm text-slate-500"><strong>Observações:</strong> {{ e.observations }}</p>
              <p class="mt-2 text-xs text-slate-400">Atualizado em {{ formatDateTime(e.updated_at) }}</p>
            </article>
          </div>
          <p v-else-if="!interview.evaluations_hidden" class="text-sm text-slate-500">
            {{ interview.status === 'scheduled' ? 'Os pareceres são registrados depois da entrevista.' : 'Nenhum parecer registrado.' }}
          </p>
        </section>
      </div>

      <div class="space-y-6">
        <section v-if="interview.instructions" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="mb-2 text-base font-semibold text-slate-900">Orientações</h2>
          <p class="whitespace-pre-line text-sm text-slate-700">{{ interview.instructions }}</p>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="mb-2 text-base font-semibold text-slate-900">Critérios avaliados</h2>
          <ul class="space-y-1.5 text-sm">
            <li v-for="c in interview.criteria" :key="c.id"><span class="font-medium text-slate-800">{{ c.name }}</span><span v-if="c.description" class="text-slate-500"> · {{ c.description }}</span></li>
          </ul>
        </section>
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h2 class="mb-3 text-base font-semibold text-slate-900">Histórico</h2>
          <ol class="space-y-3 border-l border-slate-200 pl-4">
            <li v-for="h in history" :key="h.id" class="relative">
              <span class="absolute -left-[21px] top-1.5 size-2.5 rounded-full bg-brand-500" />
              <p class="text-sm font-medium text-slate-900">{{ h.event_label }}</p>
              <p v-if="h.description && h.description !== h.event_label" class="text-sm text-slate-600">{{ h.description }}</p>
              <p v-if="h.reason" class="text-sm text-slate-600">Motivo: {{ h.reason }}</p>
              <p class="text-xs text-slate-500">{{ h.user ?? 'Sistema' }} · {{ formatDateTime(h.created_at) }}</p>
            </li>
          </ol>
        </section>
      </div>
    </div>

    <ScheduleInterviewModal :open="editing" :interview="interview" @close="editing = false" @saved="updated" />

    <BaseModal :open="!!dialog" :title="dialog === 'cancel' ? 'Cancelar entrevista' : 'Registrar ausência do candidato'" @close="dialog = null">
      <form id="iv-dialog" class="space-y-3" @submit.prevent="confirmDialog">
        <p class="text-sm text-slate-600">{{ dialog === 'cancel' ? 'Os entrevistadores serão avisados por e-mail. O histórico é mantido.' : 'A entrevista fica marcada como "não compareceu". Você pode agendar outra depois.' }}</p>
        <FormField :label="dialog === 'cancel' ? 'Motivo' : 'Observação'" for="iv-reason" :error="dialogError" :required="dialog === 'cancel'">
          <textarea id="iv-reason" v-model="reason" rows="3" maxlength="500" :class="inputClass(dialogError)" />
        </FormField>
      </form>
      <template #footer>
        <BaseButton variant="secondary" @click="dialog = null">Voltar</BaseButton>
        <BaseButton type="submit" form="iv-dialog" :variant="dialog === 'cancel' ? 'danger' : 'primary'" :loading="acting">{{ dialog === 'cancel' ? 'Cancelar entrevista' : 'Registrar ausência' }}</BaseButton>
      </template>
    </BaseModal>
  </template>
</template>
