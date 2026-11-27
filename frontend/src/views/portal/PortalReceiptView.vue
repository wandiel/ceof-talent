<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { receiptStore } from '@/api/portal'

const receipt = receiptStore.load()
const when = receipt ? new Date(receipt.received_at).toLocaleString('pt-BR', { dateStyle: 'long', timeStyle: 'short' }) : ''
</script>

<template>
  <div class="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
    <div class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-50 text-emerald-700">
      <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5" /></svg>
    </div>
    <template v-if="receipt">
      <h1 class="mt-4 text-2xl font-semibold text-slate-900">Recebemos o seu currículo</h1>
      <p class="mt-2 text-slate-600">
        <template v-if="receipt.job">Sua candidatura para <strong>{{ receipt.job.title }}</strong> foi registrada.</template>
        <template v-else>O seu currículo foi registrado no nosso banco de talentos.</template>
      </p>
      <dl class="mx-auto mt-6 max-w-xs rounded-xl bg-slate-50 p-4 text-left text-sm">
        <dt class="text-slate-500">Protocolo</dt>
        <dd class="font-mono text-lg font-semibold text-slate-900" data-testid="protocol">{{ receipt.protocol }}</dd>
        <dt class="mt-2 text-slate-500">Recebido em</dt>
        <dd class="text-slate-900">{{ when }}</dd>
      </dl>
      <p class="mt-6 text-sm text-slate-600">Enviamos uma confirmação para <strong>{{ receipt.email }}</strong>. Guarde o número do protocolo. Se o seu perfil seguir no processo, a equipe de RH entrará em contato.</p>
    </template>
    <template v-else>
      <h1 class="mt-4 text-2xl font-semibold text-slate-900">Envio concluído</h1>
      <p class="mt-2 text-slate-600">Se você enviou o currículo agora, a confirmação chegará no seu e-mail.</p>
    </template>
    <RouterLink to="/trabalhe-conosco" class="mt-6 inline-block font-medium text-brand-700 hover:underline">Ver outras vagas</RouterLink>
  </div>
</template>
