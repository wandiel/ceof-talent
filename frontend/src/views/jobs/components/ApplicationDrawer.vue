<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { parseApiError } from '@/api/http'
import { applicationsApi, type ApplicationItem } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import { formatDate, formatPhone } from '@/utils/format'
import StageTimeline from './StageTimeline.vue'

const props = defineProps<{ applicationId: string | null; canMove: boolean; refreshKey: number }>()
const emit = defineEmits<{ close: []; move: [application: ApplicationItem] }>()

const application = ref<ApplicationItem | null>(null)
const loading = ref(false)
const error = ref('')

async function load() {
  if (!props.applicationId) return
  loading.value = true
  error.value = ''
  try {
    application.value = await applicationsApi.get(props.applicationId)
  } catch (e) {
    error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}

function onKey(event: KeyboardEvent) {
  if (event.key === 'Escape') emit('close')
}

watch(
  () => [props.applicationId, props.refreshKey],
  () => {
    if (props.applicationId) {
      load()
      document.addEventListener('keydown', onKey)
    } else {
      application.value = null
      document.removeEventListener('keydown', onKey)
    }
  },
  { immediate: true },
)
onBeforeUnmount(() => document.removeEventListener('keydown', onKey))
</script>

<template>
  <Teleport to="body">
    <div v-if="applicationId" class="fixed inset-0 z-40 bg-slate-900/30" @mousedown.self="emit('close')">
      <aside role="dialog" aria-modal="true" aria-label="Inscrição" class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-xl">
        <header class="flex items-start justify-between gap-3 border-b border-slate-100 px-5 py-4">
          <div v-if="application?.candidate">
            <h2 class="text-base font-semibold text-slate-900">{{ application.candidate.social_name || application.candidate.full_name }}</h2>
            <p class="text-sm text-slate-500">{{ application.candidate.desired_position || 'Cargo pretendido não informado' }}</p>
          </div>
          <p v-else class="text-sm text-slate-500">{{ loading ? 'Carregando…' : error }}</p>
          <button type="button" class="rounded-md p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600" aria-label="Fechar" @click="emit('close')">
            <svg class="size-5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M6.28 5.22a.75.75 0 0 0-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 1 0 1.06 1.06L10 11.06l3.72 3.72a.75.75 0 1 0 1.06-1.06L11.06 10l3.72-3.72a.75.75 0 0 0-1.06-1.06L10 8.94 6.28 5.22Z" /></svg>
          </button>
        </header>

        <div v-if="application" class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
          <dl class="grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-xs text-slate-500">Etapa atual</dt><dd class="font-medium text-slate-900">{{ application.stage_label }}</dd></div>
            <div><dt class="text-xs text-slate-500">Desde</dt><dd class="text-slate-800">{{ formatDate(application.stage_changed_at) }}</dd></div>
            <div v-if="application.rejection_reason_label" class="col-span-2"><dt class="text-xs text-slate-500">Motivo da reprovação</dt><dd class="text-red-700">{{ application.rejection_reason_label }}</dd></div>
            <div><dt class="text-xs text-slate-500">E-mail</dt><dd class="truncate text-slate-800">{{ application.candidate?.email || '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Telefone</dt><dd class="text-slate-800">{{ formatPhone(application.candidate?.phone) || '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Inscrito em</dt><dd class="text-slate-800">{{ formatDate(application.created_at) }}</dd></div>
            <div><dt class="text-xs text-slate-500">Inscrito por</dt><dd class="text-slate-800">{{ application.creator || '—' }}</dd></div>
          </dl>

          <div class="flex flex-wrap gap-2">
            <BaseButton v-if="canMove" size="sm" @click="emit('move', application)">Movimentar</BaseButton>
            <RouterLink v-if="application.candidate" :to="{ name: 'candidate-detail', params: { id: application.candidate.id } }" class="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-medium text-brand-700 ring-1 ring-brand-200 hover:bg-brand-50">
              Abrir ficha do candidato
            </RouterLink>
          </div>

          <section>
            <h3 class="mb-3 text-sm font-semibold text-slate-900">Histórico de movimentações</h3>
            <StageTimeline :entries="application.history ?? []" />
          </section>
        </div>
      </aside>
    </div>
  </Teleport>
</template>
