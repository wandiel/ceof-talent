<script setup lang="ts">
import { computed } from 'vue'

/** Indicador visual da política de senha (a validação real ocorre no backend). */
const props = defineProps<{ password: string }>()

const rules = computed(() => [
  { label: 'Pelo menos 10 caracteres', ok: props.password.length >= 10 },
  { label: 'Letras maiúsculas e minúsculas', ok: /[a-z]/.test(props.password) && /[A-Z]/.test(props.password) },
  { label: 'Pelo menos um número', ok: /\d/.test(props.password) },
  { label: 'Pelo menos um símbolo (ex.: ! @ # $ %)', ok: /[^A-Za-z0-9]/.test(props.password) },
])
</script>

<template>
  <ul class="space-y-1 text-xs" aria-label="Requisitos da senha">
    <li v-for="rule in rules" :key="rule.label" class="flex items-center gap-2" :class="rule.ok ? 'text-emerald-700' : 'text-slate-500'">
      <span aria-hidden="true">{{ rule.ok ? '✓' : '○' }}</span>{{ rule.label }}
    </li>
  </ul>
</template>
