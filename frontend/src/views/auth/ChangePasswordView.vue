<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { changePassword } from '@/api/auth'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { inputClass } from '@/components/ui/inputClass'
import PasswordRules from '@/components/PasswordRules.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()

const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const errors = ref<Record<string, string>>({})
const loading = ref(false)

async function submit() {
  loading.value = true
  errors.value = {}
  try {
    const wasForced = auth.user?.must_change_password
    toast.success(await changePassword(form))
    auth.passwordChanged()
    Object.assign(form, { current_password: '', password: '', password_confirmation: '' })
    if (wasForced) router.replace('/')
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.fields
    if (!Object.keys(parsed.fields).length) toast.error(parsed.message)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <PageHeader title="Alterar senha" subtitle="Por segurança, as outras sessões abertas com a senha antiga serão encerradas." />

  <div v-if="auth.user?.must_change_password" class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800" role="alert">
    Sua senha atual é provisória. Defina uma nova senha para continuar usando o sistema.
  </div>

  <form class="max-w-lg space-y-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm" novalidate @submit.prevent="submit">
    <FormField label="Senha atual" for="current_password" :error="errors.current_password" required>
      <input id="current_password" v-model="form.current_password" type="password" autocomplete="current-password" :class="inputClass(errors.current_password)" />
    </FormField>
    <FormField label="Nova senha" for="password" :error="errors.password" required>
      <input id="password" v-model="form.password" type="password" autocomplete="new-password" :class="inputClass(errors.password)" />
    </FormField>
    <FormField label="Confirme a nova senha" for="password_confirmation" required>
      <input id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" :class="inputClass()" />
    </FormField>
    <PasswordRules :password="form.password" />
    <div class="flex justify-end"><BaseButton type="submit" :loading="loading">Salvar nova senha</BaseButton></div>
  </form>
</template>
