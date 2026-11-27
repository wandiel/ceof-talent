<script setup lang="ts">
import { reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()
const route = useRoute()

const form = reactive({ email: '', password: '', remember: false })
const errors = ref<Record<string, string>>({})
const message = ref('')
const loading = ref(false)

async function submit() {
  loading.value = true
  errors.value = {}
  message.value = ''
  try {
    const user = await auth.login(form.email, form.password, form.remember)
    if (user.must_change_password) {
      router.replace({ name: 'change-password' })
      return
    }
    const redirect = typeof route.query.redirect === 'string' && route.query.redirect.startsWith('/') ? route.query.redirect : '/'
    router.replace(redirect)
  } catch (error) {
    const parsed = parseApiError(error)
    errors.value = parsed.fields
    message.value = parsed.fields.email ? '' : parsed.message
    form.password = ''
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <h1 class="text-2xl font-semibold text-slate-900">Entrar</h1>
  <p class="mt-1 text-sm text-slate-500">Acesso restrito à equipe autorizada.</p>

  <form class="mt-8 space-y-5" novalidate @submit.prevent="submit">
    <p v-if="message" class="rounded-lg bg-red-50 p-3 text-sm text-red-700" role="alert">{{ message }}</p>

    <FormField label="E-mail" for="email" :error="errors.email">
      <input id="email" v-model.trim="form.email" type="email" autocomplete="username" required :class="inputClass(errors.email)" />
    </FormField>

    <FormField label="Senha" for="password" :error="errors.password">
      <input id="password" v-model="form.password" type="password" autocomplete="current-password" required :class="inputClass(errors.password)" />
    </FormField>

    <div class="flex items-center justify-between text-sm">
      <label class="flex items-center gap-2 text-slate-600">
        <input v-model="form.remember" type="checkbox" class="size-4 rounded border-slate-300 text-brand-700" />
        Manter conectado
      </label>
      <RouterLink to="/esqueci-senha" class="font-medium text-brand-700 hover:text-brand-800">Esqueci minha senha</RouterLink>
    </div>

    <BaseButton type="submit" class="w-full" :loading="loading">Entrar</BaseButton>
  </form>
</template>
