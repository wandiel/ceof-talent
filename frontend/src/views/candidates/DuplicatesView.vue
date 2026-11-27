<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { talentPoolApi, type CandidateListItem, type DuplicatePair } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import type { Paginated } from '@/api/types'
import BaseButton from '@/components/ui/BaseButton.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { formatDate, formatPhone } from '@/utils/format'

const auth = useAuthStore()
const toast = useToastStore()
const canDismiss = auth.can('candidates.manage')

const pairs = ref<DuplicatePair[]>([])
const meta = ref<Paginated<DuplicatePair>['meta']>({ current_page: 1, last_page: 1, per_page: 20, total: 0, from: null, to: null })
const loading = ref(true)
const busy = ref<string | null>(null)

async function load(page = 1) {
  loading.value = true
  try {
    const result = await talentPoolApi.pairs(page)
    pairs.value = result.data
    meta.value = result.meta
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
  }
}

async function dismiss(pair: DuplicatePair) {
  busy.value = pair.a.id + pair.b.id
  try {
    toast.success(await talentPoolApi.dismiss(pair.a.id, pair.b.id))
    await load(meta.value.current_page)
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    busy.value = null
  }
}

function contact(c: CandidateListItem) {
  return [c.email, formatPhone(c.phone)].filter(Boolean).join(' · ') || 'sem contato'
}

onMounted(() => load())
</script>

<template>
  <PageHeader title="Possíveis duplicidades" subtitle="Cadastros com o mesmo telefone ou nomes muito parecidos. Revise e decida o que fazer com cada par." />

  <div class="mb-4 rounded-xl bg-brand-50 px-4 py-3 text-sm text-brand-900 ring-1 ring-brand-200">
    Se for a mesma pessoa, abra os dois cadastros, complete o mais atual e inative ou exclua o outro. Se forem pessoas diferentes, use
    <strong>Não é a mesma pessoa</strong> e o par não volta a aparecer.
  </div>

  <div v-if="loading && !pairs.length" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">Carregando…</div>
  <div v-else-if="!pairs.length" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">Nenhuma duplicidade pendente. 👍</div>

  <ul v-else class="space-y-4" :class="{ 'opacity-60': loading }">
    <li v-for="pair in pairs" :key="pair.a.id + pair.b.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
      <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-1">
          <span v-for="r in pair.reasons" :key="r" class="rounded-md bg-amber-50 px-2 py-0.5 text-xs font-medium text-amber-800 ring-1 ring-amber-200">{{ r }}</span>
        </div>
        <BaseButton v-if="canDismiss" size="sm" variant="secondary" :loading="busy === pair.a.id + pair.b.id" @click="dismiss(pair)">Não é a mesma pessoa</BaseButton>
      </div>
      <div class="grid gap-3 sm:grid-cols-2">
        <RouterLink
          v-for="c in [pair.a, pair.b]"
          :key="c.id"
          :to="{ name: 'candidate-detail', params: { id: c.id } }"
          target="_blank"
          class="rounded-xl border border-slate-200 p-3 transition hover:border-brand-300 hover:bg-brand-50/40"
        >
          <div class="flex items-center gap-2">
            <p class="font-medium text-slate-900">{{ c.full_name }}</p>
            <StatusBadge v-if="c.status !== 'active'" :active="false" />
          </div>
          <p class="text-sm text-slate-600">{{ contact(c) }}</p>
          <p class="text-xs text-slate-500">
            {{ c.desired_position || c.professional_area_label }} · {{ c.city || 'cidade não informada' }} · cadastrado em {{ formatDate(c.created_at) }}
            <template v-if="c.documents_count"> · {{ c.documents_count }} anexo(s)</template>
          </p>
        </RouterLink>
      </div>
    </li>
  </ul>
  <div v-if="meta.last_page > 1" class="mt-4 rounded-2xl border border-slate-200 bg-white"><PaginationBar :meta="meta" @page="load" /></div>
</template>
