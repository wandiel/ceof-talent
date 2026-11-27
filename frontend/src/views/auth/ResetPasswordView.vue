<script setup lang="ts">
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { resetPassword } from '@/api/auth'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'
import PasswordRules from '@/components/PasswordRules.vue'
import { useToastStore } from '@/stores/toast'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const form = reactive({
  token: String(route.query.token ?? ''),
  email: String(route.query.email ?? ''),
  password: '',
  password_confirmation: '',
})
const errors = ref<Record<string, string>>({})
const loading = ref(false)

async function submit() {
  loading.value = true
  errors.value = {}
  try {
    toast.success(await resetPassword(form))
    router.replace({ name: 'login' })
  } catch (e) {
    errors.value = parseApiError(e).fields
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <h1 class="text-2xl font-semibold text-slate-900">Definir nova senha</h1>

  <p v-if="!form.token" class="mt-6 rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
    Link inválido. Solicite um novo em <RouterLink to="/esqueci-senha" class="font-medium underline">Esqueci minha senha</RouterLink>.
  </p>

  <form v-else class="mt-8 space-y-5" novalidate @submit.prevent="submit">
    <FormField label="E-mail" for="email" :error="errors.email">
      <input id="email" v-model.trim="form.email" type="email" autocomplete="username" :class="inputClass(errors.email)" />
    </FormField>
    <FormField label="Nova senha" for="password" :error="errors.password">
      <input id="password" v-model="form.password" type="password" autocomplete="new-password" :class="inputClass(errors.password)" />
    </FormField>
    <FormField label="Confirme a nova senha" for="password_confirmation">
      <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="inputClass()" />
    </FormField>
    <PasswordRules :password="form.password" />
    <BaseButton type="submit" class="w-full" :loading="loading">Salvar nova senha</BaseButton>
  </form>
</template>
