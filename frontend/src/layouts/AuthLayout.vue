<script setup lang="ts">
import { RouterView } from 'vue-router'
import BrandLogo from '@/components/BrandLogo.vue'
</script>

<template>
  <div class="grid min-h-screen lg:grid-cols-2">
    <div class="flex flex-col justify-center px-6 py-12 sm:px-12">
      <div class="mx-auto w-full max-w-sm">
        <BrandLogo size="lg" class="mb-10" />
        <RouterView />
      </div>
    </div>
    <div class="relative hidden overflow-hidden bg-brand-800 lg:block">
      <div class="absolute inset-0 bg-gradient-to-br from-brand-700 via-brand-800 to-brand-900" />
      <div class="relative flex h-full flex-col justify-end p-12 text-white">
        <h2 class="text-3xl font-semibold leading-tight">Banco de talentos e recrutamento em um só lugar.</h2>
        <p class="mt-4 max-w-md text-brand-100">Candidatos, vagas, processos seletivos, entrevistas e indicadores, com segurança e conformidade com a LGPD.</p>
      </div>
    </div>
  </div>
</template>
