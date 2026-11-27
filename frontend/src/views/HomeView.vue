<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { navigation } from '@/layouts/navigation'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()

const greeting = computed(() => {
  const hour = new Date().getHours()
  return hour < 12 ? 'Bom dia' : hour < 18 ? 'Boa tarde' : 'Boa noite'
})

const shortcuts = computed(() =>
  navigation.flatMap((s) => s.items).filter((i) => i.to !== '/' && (!i.permission || auth.can(i.permission))),
)

const lastLogin = computed(() => (auth.user?.last_login_at ? new Date(auth.user.last_login_at).toLocaleString('pt-BR') : '—'))
</script>

<template>
  <section class="rounded-2xl bg-gradient-to-r from-brand-700 to-brand-800 p-6 text-white shadow-sm sm:p-8">
    <p class="text-sm text-brand-100">{{ greeting }},</p>
    <h1 class="mt-1 text-2xl font-semibold sm:text-3xl">{{ auth.user?.name }}</h1>
    <p class="mt-3 text-sm text-brand-100">
      Perfil: <strong class="text-white">{{ auth.user?.roles.map((r) => r.label).join(', ') }}</strong>
      <span v-if="auth.user?.department"> · {{ auth.user.department.name }}</span>
      <span v-if="auth.user?.unit"> · {{ auth.user.unit.name }}</span>
    </p>
    <p class="mt-1 text-xs text-brand-200">Último acesso registrado: {{ lastLogin }}</p>
  </section>

  <section class="mt-8">
    <h2 class="text-sm font-semibold uppercase tracking-wider text-slate-500">Acesso rápido</h2>
    <div class="mt-4 grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      <RouterLink
        v-for="item in shortcuts"
        :key="item.to"
        :to="item.to"
        class="group flex items-center gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-brand-200 hover:shadow"
      >
        <span class="grid size-11 place-items-center rounded-xl bg-brand-50 text-brand-700 group-hover:bg-brand-100">
          <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" /></svg>
        </span>
        <span class="font-medium text-slate-800">{{ item.label }}</span>
      </RouterLink>
    </div>
  </section>
</template>
