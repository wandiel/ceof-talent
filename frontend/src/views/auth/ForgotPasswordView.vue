<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { forgotPassword } from '@/api/auth'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'

const email = ref('')
const error = ref('')
const done = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  error.value = ''
  try {
    done.value = await forgotPassword(email.value)
  } catch (e) {
    error.value = parseApiError(e).message
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <h1 class="text-2xl font-semibold text-slate-900">Recuperar senha</h1>
  <p class="mt-1 text-sm text-slate-500">Informe seu e-mail de acesso para receber o link de redefinição.</p>

  <div v-if="done" class="mt-8 rounded-lg bg-emerald-50 p-4 text-sm text-emerald-800" role="status">{{ done }}</div>

  <form v-else class="mt-8 space-y-5" novalidate @submit.prevent="submit">
    <FormField label="E-mail" for="email" :error="error">
      <input id="email" v-model.trim="email" type="email" autocomplete="username" required :class="inputClass(error)" />
    </FormField>
    <BaseButton type="submit" class="w-full" :loading="loading">Enviar link</BaseButton>
  </form>

  <p class="mt-6 text-center text-sm"><RouterLink to="/entrar" class="font-medium text-brand-700 hover:text-brand-800">Voltar para o login</RouterLink></p>
</template>
