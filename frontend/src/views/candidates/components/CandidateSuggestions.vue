<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { intelligenceApi, type SuggestionItem } from '@/api/interviews'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useToastStore } from '@/stores/toast'

/** Sugestões da leitura automática do currículo. Nada muda no cadastro sem que alguém aceite. */
const props = defineProps<{ candidateId: string }>()
const emit = defineEmits<{ changed: [] }>()
const toast = useToastStore()

const items = ref<SuggestionItem[]>([])
const reading = ref(false)
const canReview = ref(false)
const busy = ref<string | null>(null)
const showReviewed = ref(false)
let timer: number | undefined
let polls = 0

async function load() {
  try {
    const result = await intelligenceApi.suggestions(props.candidateId)
    items.value = result.data
    reading.value = result.meta.reading
    canReview.value = result.meta.can_review
    // Enquanto o currículo é lido em segundo plano, confere de novo a cada 3 segundos (até 1 minuto).
    window.clearTimeout(timer)
    if (reading.value && polls++ < 20) timer = window.setTimeout(load, 3000)
  } catch {
    items.value = []
  }
}
onMounted(load)
onBeforeUnmount(() => window.clearTimeout(timer))

const pending = computed(() => items.value.filter((s) => s.status === 'pending'))
const reviewed = computed(() => items.value.filter((s) => s.status !== 'pending'))

async function review(s: SuggestionItem, action: 'accept' | 'dismiss') {
  busy.value = s.id
  try {
    Object.assign(s, await intelligenceApi.review(props.candidateId, s.id, action))
    toast.success(action === 'accept' ? `${s.type_label} aplicada ao cadastro.` : 'Sugestão descartada.')
    if (action === 'accept') emit('changed')
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    busy.value = null
  }
}

async function acceptAllSkills() {
  for (const s of pending.value.filter((x) => x.type === 'skill')) await review(s, 'accept')
}

const percent = (n: number) => `${Math.round(n * 100)}%`
</script>

<template>
  <section v-if="pending.length || reading || reviewed.length" class="mb-6 rounded-2xl border border-violet-200 bg-violet-50/40 p-5 shadow-sm">
    <header class="mb-3 flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="flex items-center gap-2 text-base font-semibold text-slate-900">
          <svg class="size-5 text-violet-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z" /></svg>
          Sugestões da leitura do currículo
        </h2>
        <p class="text-sm text-slate-600">Geradas automaticamente. Revise antes de aplicar: nada muda no cadastro sem a sua confirmação.</p>
      </div>
      <BaseButton v-if="canReview && pending.filter((s) => s.type === 'skill').length > 1" size="sm" variant="secondary" @click="acceptAllSkills">Aceitar todas as competências</BaseButton>
    </header>

    <p v-if="reading" class="mb-3 text-sm text-sky-700">Lendo o currículo anexado…</p>

    <ul v-if="pending.length" class="space-y-2">
      <li v-for="s in pending" :key="s.id" class="flex flex-col gap-2 rounded-lg bg-white p-3 ring-1 ring-violet-100 sm:flex-row sm:items-center">
        <div class="min-w-0 flex-1">
          <p class="text-sm">
            <span class="text-slate-500">{{ s.type_label }}:</span>{{ " " }}
            <strong class="text-slate-900">{{ s.value_label }}</strong>
            <span class="ml-2 rounded bg-violet-100 px-1.5 text-xs text-violet-800" title="Grau de confiança da sugestão">{{ percent(s.confidence) }}</span>
          </p>
          <p v-if="s.evidence" class="truncate text-xs text-slate-500" :title="s.evidence">“{{ s.evidence }}”</p>
        </div>
        <div v-if="canReview" class="flex shrink-0 gap-2">
          <BaseButton size="sm" :loading="busy === s.id" @click="review(s, 'accept')">{{ s.type === 'skill' ? 'Adicionar' : 'Usar esta área' }}</BaseButton>
          <BaseButton size="sm" variant="ghost" :disabled="busy === s.id" @click="review(s, 'dismiss')">Descartar</BaseButton>
        </div>
      </li>
    </ul>
    <p v-else-if="!reading" class="text-sm text-slate-600">Todas as sugestões já foram revisadas.</p>

    <button v-if="reviewed.length" type="button" class="mt-3 text-xs text-brand-700 hover:underline" @click="showReviewed = !showReviewed">
      {{ showReviewed ? 'Ocultar' : 'Ver' }} sugestões revisadas ({{ reviewed.length }})
    </button>
    <ul v-if="showReviewed" class="mt-2 space-y-1 text-xs text-slate-600">
      <li v-for="s in reviewed" :key="s.id">{{ s.type_label }} “{{ s.value_label }}” · {{ s.status_label.toLowerCase() }} por {{ s.reviewed_by ?? '—' }}</li>
    </ul>
  </section>
</template>
