<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { parseApiError } from '@/api/http'
import { interviewsApi, splitLocal, toIso, type Interview, type InterviewOptions } from '@/api/interviews'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'

/** Agenda uma entrevista (applicationId) ou remarca/edita uma existente (interview). */
const props = defineProps<{
  open: boolean
  applicationId?: string | null
  candidateName?: string
  interview?: Interview | null
  canMoveStage?: boolean
}>()
const emit = defineEmits<{ close: []; saved: [interview: Interview] }>()
const toast = useToastStore()

const options = ref<InterviewOptions | null>(null)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const conflicts = ref<string[]>([])
const search = ref('')

const form = reactive({
  type: 'hr', mode: 'in_person', date: '', time: '09:00', duration_minutes: 60, location: '', meeting_url: '', instructions: '',
  interviewers: [] as string[], criteria: [] as string[], move_to_stage: true,
})

const editing = computed(() => !!props.interview)
const title = computed(() => (editing.value ? 'Remarcar ou alterar entrevista' : `Agendar entrevista${props.candidateName ? ' · ' + props.candidateName : ''}`))
const today = new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)

watch(
  () => props.open,
  async (open) => {
    if (!open) return
    errors.value = {}
    conflicts.value = []
    search.value = ''
    try {
      options.value = await interviewsApi.options()
    } catch (e) {
      toast.error(parseApiError(e).message)
      return
    }
    const i = props.interview
    if (i) {
      const { date, time } = splitLocal(i.scheduled_at)
      Object.assign(form, {
        type: i.type, mode: i.mode, date, time, duration_minutes: i.duration_minutes, location: i.location ?? '', meeting_url: i.meeting_url ?? '',
        instructions: i.instructions ?? '', interviewers: (i.interviewers ?? []).map((u) => u.id), criteria: (i.criteria ?? []).map((c) => c.id), move_to_stage: false,
      })
    } else {
      const tomorrow = new Date(Date.now() + 86400000 - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 10)
      Object.assign(form, { type: 'hr', mode: 'in_person', date: tomorrow, time: '09:00', duration_minutes: 60, location: '', meeting_url: '', instructions: '', interviewers: [], criteria: options.value.criteria.map((c) => c.id), move_to_stage: true })
    }
  },
)

const filteredInterviewers = computed(() => {
  const term = search.value.trim().toLowerCase()
  return (options.value?.interviewers ?? []).filter((u) => !term || u.name.toLowerCase().includes(term) || (u.job_title ?? '').toLowerCase().includes(term))
})

// Critérios de uma entrevista já marcada que foram desativados continuam aparecendo.
const criteriaOptions = computed(() => {
  const list = [...(options.value?.criteria ?? [])]
  for (const c of props.interview?.criteria ?? []) if (!list.some((x) => x.id === c.id)) list.push(c)
  return list
})

async function submit(force = false) {
  if (!form.date || !form.time) {
    errors.value = { scheduled_at: 'Informe a data e o horário.' }
    return
  }
  saving.value = true
  errors.value = {}
  const payload = {
    type: form.type,
    mode: form.mode,
    scheduled_at: toIso(form.date, form.time),
    duration_minutes: Number(form.duration_minutes),
    location: form.location.trim() || null,
    meeting_url: form.meeting_url.trim() || null,
    instructions: form.instructions.trim() || null,
    interviewers: form.interviewers,
    criteria: form.criteria,
    force,
    ...(editing.value ? {} : { move_to_stage: form.move_to_stage }),
  }
  try {
    const saved = editing.value ? await interviewsApi.update(props.interview!.id, payload) : await interviewsApi.schedule(props.applicationId!, payload)
    conflicts.value = []
    toast.success(editing.value ? 'Entrevista atualizada. Os entrevistadores foram avisados por e-mail.' : 'Entrevista agendada. Os entrevistadores foram avisados por e-mail.')
    emit('saved', saved)
    emit('close')
  } catch (e) {
    const parsed = parseApiError(e)
    const raw = (e as { response?: { data?: { errors?: Record<string, string[]> } } }).response?.data?.errors
    if (raw?.conflicts) {
      conflicts.value = raw.conflicts
    } else {
      errors.value = parsed.fields
      if (!Object.keys(parsed.fields).length) toast.error(parsed.message)
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <BaseModal :open="open" :title="title" size="lg" @close="emit('close')">
    <form v-if="options" id="schedule-form" class="space-y-4" novalidate @submit.prevent="submit(false)">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Tipo" for="iv-type" :error="errors.type" required>
          <select id="iv-type" v-model="form.type" :class="inputClass(errors.type)">
            <option v-for="o in options.types" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Formato" for="iv-mode" :error="errors.mode" required>
          <select id="iv-mode" v-model="form.mode" :class="inputClass(errors.mode)">
            <option v-for="o in options.modes" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
      </div>
      <div class="grid gap-4 sm:grid-cols-3">
        <FormField label="Data" for="iv-date" :error="errors.scheduled_at" required>
          <input id="iv-date" v-model="form.date" type="date" :min="editing ? undefined : today" :class="inputClass(errors.scheduled_at)" />
        </FormField>
        <FormField label="Horário" for="iv-time" required>
          <input id="iv-time" v-model="form.time" type="time" step="300" :class="inputClass(errors.scheduled_at)" />
        </FormField>
        <FormField label="Duração" for="iv-duration" :error="errors.duration_minutes" required>
          <select id="iv-duration" v-model.number="form.duration_minutes" :class="inputClass(errors.duration_minutes)">
            <option v-for="m in [15, 20, 30, 45, 60, 90, 120, 180, 240]" :key="m" :value="m">{{ m < 60 ? `${m} min` : `${m / 60} h`.replace('.5', ',5') }}</option>
          </select>
        </FormField>
      </div>
      <FormField v-if="form.mode === 'in_person'" label="Local" for="iv-location" :error="errors.location" required hint="Ex.: Sala 2 do RH, 3º andar.">
        <input id="iv-location" v-model="form.location" type="text" maxlength="255" :class="inputClass(errors.location)" />
      </FormField>
      <FormField v-else-if="form.mode === 'video'" label="Link da videochamada" for="iv-url" :error="errors.meeting_url" hint="Google Meet, Teams, Zoom…">
        <input id="iv-url" v-model="form.meeting_url" type="url" maxlength="500" placeholder="https://" :class="inputClass(errors.meeting_url)" />
      </FormField>

      <fieldset>
        <legend class="mb-1 text-sm font-medium text-slate-700">Entrevistadores <span class="text-red-600">*</span></legend>
        <input v-model="search" type="search" placeholder="Filtrar por nome ou cargo" aria-label="Filtrar entrevistadores" :class="inputClass()" class="mb-2" />
        <div class="max-h-40 overflow-y-auto rounded-lg border p-1" :class="errors.interviewers ? 'border-red-400' : 'border-slate-200'">
          <label v-for="u in filteredInterviewers" :key="u.id" class="flex cursor-pointer items-center gap-2 rounded px-2 py-1.5 text-sm hover:bg-slate-50">
            <input v-model="form.interviewers" type="checkbox" :value="u.id" class="size-4 rounded border-slate-300" />
            <span class="text-slate-800">{{ u.name }}</span>
            <span v-if="u.job_title" class="text-xs text-slate-500">· {{ u.job_title }}</span>
          </label>
          <p v-if="!filteredInterviewers.length" class="px-2 py-2 text-sm text-slate-500">Nenhum usuário encontrado.</p>
        </div>
        <p v-if="errors.interviewers" class="mt-1 text-xs text-red-600" role="alert">{{ errors.interviewers }}</p>
      </fieldset>

      <fieldset>
        <legend class="mb-1 text-sm font-medium text-slate-700">Critérios de avaliação</legend>
        <p v-if="editing && interview?.evaluations_count" class="text-xs text-slate-500">Já há parecer registrado: os critérios não podem mais mudar.</p>
        <div class="flex flex-wrap gap-2">
          <label v-for="c in criteriaOptions" :key="c.id" class="inline-flex cursor-pointer items-center gap-1.5 rounded-full px-3 py-1 text-sm ring-1" :class="form.criteria.includes(c.id) ? 'bg-brand-50 text-brand-800 ring-brand-200' : 'bg-white text-slate-600 ring-slate-200'" :title="c.description ?? ''">
            <input v-model="form.criteria" type="checkbox" :value="c.id" class="sr-only" :disabled="editing && !!interview?.evaluations_count" />
            {{ c.name }}
          </label>
        </div>
        <p v-if="errors.criteria" class="mt-1 text-xs text-red-600" role="alert">{{ errors.criteria }}</p>
      </fieldset>

      <FormField label="Orientações aos entrevistadores" for="iv-instructions" :error="errors.instructions" hint="Opcional. Ex.: pontos a investigar, documentos a pedir.">
        <textarea id="iv-instructions" v-model="form.instructions" rows="3" maxlength="5000" :class="inputClass(errors.instructions)" />
      </FormField>

      <label v-if="!editing && canMoveStage" class="flex items-center gap-2 text-sm text-slate-700">
        <input v-model="form.move_to_stage" type="checkbox" class="size-4 rounded border-slate-300" />
        Mover o candidato para a etapa Entrevista (se ainda estiver antes dela)
      </label>

      <div v-if="conflicts.length" class="rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-900 ring-1 ring-amber-200" role="alert">
        <p class="font-medium">Conflito de horário:</p>
        <ul class="mt-1 list-disc pl-5">
          <li v-for="c in conflicts" :key="c">{{ c }}</li>
        </ul>
        <p class="mt-2">Escolha outro horário ou confirme para agendar mesmo assim.</p>
      </div>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="emit('close')">Cancelar</BaseButton>
      <BaseButton v-if="conflicts.length" variant="secondary" :loading="saving" @click="submit(true)">Agendar mesmo assim</BaseButton>
      <BaseButton type="submit" form="schedule-form" :loading="saving">{{ editing ? 'Salvar' : 'Agendar' }}</BaseButton>
    </template>
  </BaseModal>
</template>
