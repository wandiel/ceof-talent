<script setup lang="ts">
import logoUrl from '@/assets/logo-ceof.png'

withDefaults(defineProps<{ size?: 'md' | 'lg' }>(), { size: 'md' })
</script>

<template>
  <div class="flex items-center gap-3">
    <img :src="logoUrl" alt="Hospital de Olhos CEOF" :class="size === 'lg' ? 'h-14' : 'h-9'" class="w-auto shrink-0" />
    <div class="border-l border-slate-200 pl-3 leading-tight">
      <p class="font-semibold tracking-wide text-slate-900" :class="size === 'lg' ? 'text-base' : 'text-sm'">TALENT</p>
      <p class="text-xs text-slate-500">Banco de Talentos<br />e Recrutamento</p>
    </div>
  </div>
</template>
