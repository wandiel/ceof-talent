<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <div class="mx-auto max-w-md py-16 text-center">
    <p class="text-sm font-semibold text-brand-700">403</p>
    <h1 class="mt-2 text-2xl font-semibold text-slate-900">Acesso negado</h1>
    <p class="mt-2 text-sm text-slate-500">Seu perfil não tem permissão para acessar esta área. Se precisar, solicite ao administrador.</p>
    <RouterLink to="/" class="mt-6 inline-block rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800">Ir para o início</RouterLink>
  </div>
</template>
