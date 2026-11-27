<script setup lang="ts">
import { ref } from 'vue'
import { talentPoolApi } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ candidateId: string; size?: 'sm' | 'md' }>()
const favorite = defineModel<boolean>({ default: false })
const toast = useToastStore()
const busy = ref(false)

async function toggle() {
  if (busy.value) return
  busy.value = true
  const next = !favorite.value
  favorite.value = next
  try {
    favorite.value = await talentPoolApi.favorite(props.candidateId, next)
  } catch (e) {
    favorite.value = !next
    toast.error(parseApiError(e).message)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <button
    type="button"
    class="rounded-md p-1 transition hover:bg-amber-50"
    :class="favorite ? 'text-amber-500' : 'text-slate-300 hover:text-amber-400'"
    :aria-pressed="favorite"
    :aria-label="favorite ? 'Remover dos meus favoritos' : 'Adicionar aos meus favoritos'"
    :title="favorite ? 'Remover dos meus favoritos' : 'Adicionar aos meus favoritos'"
    @click.stop="toggle"
  >
    <svg :class="size === 'md' ? 'size-6' : 'size-5'" viewBox="0 0 24 24" :fill="favorite ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.5" aria-hidden="true">
      <path stroke-linejoin="round" d="M11.48 3.5a.56.56 0 0 1 1.04 0l2.13 5.11a.56.56 0 0 0 .48.35l5.52.44c.5.04.7.66.32.99l-4.2 3.6a.56.56 0 0 0-.18.56l1.28 5.39a.56.56 0 0 1-.84.61l-4.73-2.89a.56.56 0 0 0-.59 0l-4.72 2.89a.56.56 0 0 1-.84-.61l1.28-5.39a.56.56 0 0 0-.18-.56l-4.2-3.6a.56.56 0 0 1 .32-.99l5.52-.44a.56.56 0 0 0 .47-.35l2.13-5.11Z" />
    </svg>
  </button>
</template>
