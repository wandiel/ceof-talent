<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { candidatesApi, type HistoryEntry } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import { formatDateTime } from '@/utils/format'

const props = defineProps<{ candidateId: string }>()

const entries = ref<HistoryEntry[]>([])
const page = ref(1)
const lastPage = ref(1)
const includeViews = ref(false)
const loading = ref(false)
const error = ref('')

async function load(reset = true) {
  loading.value = true
  error.value = ''
  try {
    if (reset) page.value = 1
    const result = await candidatesApi.history(props.candidateId, page.value, includeViews.value)
    entries.value = reset ? result.data : [...entries.value, ...result.data]
    lastPage.value = result.meta.last_page
  } catch (e) {
    error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}

function more() {
  page.value++
  load(false)
}

onMounted(() => load())
watch(includeViews, () => load())

const dot: Record<string, string> = {
  'candidate.created': 'bg-emerald-500',
  'candidate.deleted': 'bg-red-500',
  'candidate.status_changed': 'bg-amber-500',
  'candidate.viewed': 'bg-slate-300',
  'candidate.document_downloaded': 'bg-slate-400',
}
</script>

<template>
  <section class="max-w-3xl rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
    <header class="mb-4 flex flex-wrap items-center justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold text-slate-900">Histórico</h2>
        <p class="text-sm text-slate-500">Quem alterou, anexou, baixou ou comentou, e quando.</p>
      </div>
      <label class="inline-flex items-center gap-2 text-sm text-slate-600">
        <input v-model="includeViews" type="checkbox" class="size-4 rounded border-slate-300 text-brand-700" />
        Mostrar visualizações da ficha
      </label>
    </header>

    <p v-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
    <ol v-else-if="entries.length" class="space-y-4 border-l border-slate-200 pl-4">
      <li v-for="e in entries" :key="e.id" class="relative">
        <span class="absolute -left-[21px] top-1.5 size-2.5 rounded-full" :class="dot[e.event] ?? 'bg-brand-500'" />
        <p class="text-sm font-medium text-slate-900">{{ e.event_label }}</p>
        <p v-if="e.description" class="text-sm text-slate-600">{{ e.description }}</p>
        <p class="text-xs text-slate-500">{{ e.user ?? 'Sistema' }} · {{ formatDateTime(e.created_at) }}</p>
      </li>
    </ol>
    <p v-else-if="!loading" class="text-sm text-slate-500">Nenhum registro.</p>

    <BaseButton v-if="page < lastPage" variant="secondary" size="sm" class="mt-4" :loading="loading" @click="more">Carregar mais</BaseButton>
  </section>
</template>
