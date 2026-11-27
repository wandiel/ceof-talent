<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { talentPoolApi, type DuplicateMatch } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import { useToastStore } from '@/stores/toast'
import { formatPhone } from '@/utils/format'

const props = defineProps<{ candidateId: string; canDismiss: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const toast = useToastStore()
const matches = ref<DuplicateMatch[]>([])
const busy = ref<string | null>(null)

onMounted(async () => {
  try {
    matches.value = await talentPoolApi.candidateDuplicates(props.candidateId)
  } catch {
    matches.value = []
  }
})

async function dismiss(match: DuplicateMatch) {
  busy.value = match.candidate.id
  try {
    toast.success(await talentPoolApi.dismiss(props.candidateId, match.candidate.id))
    matches.value = matches.value.filter((m) => m.candidate.id !== match.candidate.id)
    emit('changed')
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    busy.value = null
  }
}
</script>

<template>
  <div v-if="matches.length" class="mb-6 rounded-2xl border border-amber-300 bg-amber-50 p-4" role="status">
    <p class="font-medium text-amber-900">Possível cadastro duplicado</p>
    <p class="text-sm text-amber-800">Confira se é a mesma pessoa. Se for, mantenha o cadastro mais completo e inative ou exclua o outro.</p>
    <ul class="mt-3 space-y-2">
      <li v-for="m in matches" :key="m.candidate.id" class="flex flex-col gap-2 rounded-lg bg-white p-3 ring-1 ring-amber-200 sm:flex-row sm:items-center sm:justify-between">
        <div class="text-sm">
          <RouterLink :to="{ name: 'candidate-detail', params: { id: m.candidate.id } }" class="font-medium text-slate-900 hover:text-brand-700">{{ m.candidate.full_name }}</RouterLink>
          <span class="text-slate-500"> · {{ m.candidate.email || formatPhone(m.candidate.phone) || 'sem contato' }}</span>
          <p class="text-xs text-amber-800">{{ m.reasons.join(' · ') }}</p>
        </div>
        <BaseButton v-if="canDismiss" size="sm" variant="secondary" :loading="busy === m.candidate.id" @click="dismiss(m)">Não é a mesma pessoa</BaseButton>
      </li>
    </ul>
  </div>
</template>
