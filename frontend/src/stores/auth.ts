import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import * as authApi from '@/api/auth'
import type { User } from '@/api/types'

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loaded = ref(false)

  const isAuthenticated = computed(() => user.value !== null)
  const permissions = computed(() => new Set(user.value?.permissions ?? []))

  /** Verifica permissão para exibir/ocultar elementos. A regra real fica no backend. */
  function can(permission: string | string[]): boolean {
    const list = Array.isArray(permission) ? permission : [permission]
    return list.some((p) => permissions.value.has(p))
  }

  async function loadSession(): Promise<void> {
    try {
      user.value = await authApi.fetchMe()
    } catch {
      user.value = null
    } finally {
      loaded.value = true
    }
  }

  async function login(email: string, password: string, remember: boolean) {
    user.value = await authApi.login(email, password, remember)
    loaded.value = true
    return user.value
  }

  async function logout() {
    try {
      await authApi.logout()
    } finally {
      clear()
    }
  }

  function clear() {
    user.value = null
  }

  function passwordChanged() {
    if (user.value) user.value = { ...user.value, must_change_password: false }
  }

  return { user, loaded, isAuthenticated, can, loadSession, login, logout, clear, passwordChanged }
})
