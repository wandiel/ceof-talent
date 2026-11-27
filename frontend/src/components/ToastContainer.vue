<script setup lang="ts">
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const styles = {
  success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
  error: 'border-red-200 bg-red-50 text-red-800',
  info: 'border-brand-200 bg-brand-50 text-brand-800',
}
</script>

<template>
  <div class="pointer-events-none fixed right-4 top-4 z-[60] flex w-full max-w-sm flex-col gap-2" aria-live="polite">
    <TransitionGroup enter-active-class="transition duration-200" enter-from-class="translate-x-4 opacity-0" leave-active-class="transition duration-150" leave-to-class="opacity-0">
      <div v-for="t in toast.toasts" :key="t.id" class="pointer-events-auto flex items-start gap-3 rounded-xl border px-4 py-3 text-sm shadow-lg" :class="styles[t.type]" role="status">
        <p class="flex-1">{{ t.message }}</p>
        <button type="button" class="opacity-60 hover:opacity-100" aria-label="Fechar notificação" @click="toast.dismiss(t.id)">✕</button>
      </div>
    </TransitionGroup>
  </div>
</template>
