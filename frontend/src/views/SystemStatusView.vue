<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { fetchHealth, type HealthResponse } from '@/api/health'

/**
 * Tela de status da infraestrutura (Fase 2). Consulta /api/health, que
 * verifica de verdade PostgreSQL, Redis, cache e armazenamento privado.
 * Será substituída pelo Dashboard na Fase 8 e mantida como página técnica.
 */
const labels: Record<string, string> = {
  database: 'Banco de dados (PostgreSQL)',
  redis: 'Redis (filas e sessões)',
  cache: 'Cache da aplicação',
  storage: 'Armazenamento privado de documentos',
}

const health = ref<HealthResponse | null>(null)
const error = ref<string | null>(null)
const loading = ref(false)
let timer: number | undefined

async function load() {
  loading.value = true
  try {
    health.value = await fetchHealth()
    error.value = null
  } catch {
    health.value = null
    error.value = 'Não foi possível contatar a API. Verifique se os containers estão em execução.'
  } finally {
    loading.value = false
  }
}

const healthy = computed(() => health.value?.status === 'ok')
const checkedAt = computed(() =>
  health.value ? new Date(health.value.time).toLocaleString('pt-BR') : '—',
)

onMounted(() => {
  load()
  timer = window.setInterval(load, 15000)
})
onBeforeUnmount(() => window.clearInterval(timer))
</script>

<template>
  <PageHeader title="Status do sistema" subtitle="Verificação em tempo real dos componentes de infraestrutura.">
    <template #actions>
      <BaseButton variant="secondary" :loading="loading" @click="load">Verificar agora</BaseButton>
    </template>
  </PageHeader>

  <section class="max-w-3xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
    <div class="flex items-start justify-between gap-4">
      <p class="text-sm text-slate-500">
        Ambiente: <strong class="font-medium text-slate-700">{{ health?.environment ?? '—' }}</strong>
        · Última verificação: {{ checkedAt }}
      </p>
      <span
        v-if="health"
        class="rounded-full px-3 py-1 text-xs font-semibold"
        :class="healthy ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200' : 'bg-red-50 text-red-700 ring-1 ring-red-200'"
      >
        {{ healthy ? 'Operacional' : 'Com falhas' }}
      </span>
    </div>

    <p v-if="error" class="mt-6 rounded-lg bg-red-50 p-4 text-sm text-red-700" role="alert">{{ error }}</p>

    <ul v-else-if="health" class="mt-6 divide-y divide-slate-100">
      <li v-for="(check, key) in health.checks" :key="key" class="flex items-center justify-between py-3">
        <div class="flex items-center gap-3">
          <span class="size-2.5 rounded-full" :class="check.status === 'ok' ? 'bg-emerald-500' : 'bg-red-500'" />
          <span class="text-sm font-medium text-slate-800">{{ labels[key] ?? key }}</span>
        </div>
        <span class="text-xs tabular-nums text-slate-500">
          {{ check.status === 'ok' ? 'OK' : 'Falha' }} · {{ check.duration_ms }} ms
        </span>
      </li>
    </ul>

    <div v-else class="mt-6 space-y-3" aria-busy="true">
      <div v-for="n in 4" :key="n" class="h-10 animate-pulse rounded-lg bg-slate-100" />
    </div>
  </section>
</template>
