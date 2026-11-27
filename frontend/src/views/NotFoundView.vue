<script setup lang="ts">
import { RouterLink } from 'vue-router'
import BrandLogo from '@/components/BrandLogo.vue'
</script>

<template>
  <main class="grid min-h-screen place-items-center p-6">
    <div class="text-center">
      <div class="mb-8 flex justify-center"><BrandLogo /></div>
      <p class="text-sm font-semibold text-brand-700">404</p>
      <h1 class="mt-2 text-2xl font-semibold text-slate-900">Página não encontrada</h1>
      <RouterLink to="/" class="mt-6 inline-block rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">
        Voltar ao início
      </RouterLink>
    </div>
  </main>
</template>
