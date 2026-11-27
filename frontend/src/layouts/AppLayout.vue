<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import BrandLogo from '@/components/BrandLogo.vue'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { navigation } from './navigation'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()
const route = useRoute()

const sidebarOpen = ref(false)
const userMenuOpen = ref(false)

const sections = computed(() =>
  navigation
    .map((section) => ({ ...section, items: section.items.filter((i) => !i.permission || auth.can(i.permission)) }))
    .filter((section) => section.items.length > 0),
)

const initials = computed(() =>
  (auth.user?.name ?? '?')
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]?.toUpperCase())
    .join(''),
)

watch(() => route.fullPath, () => {
  sidebarOpen.value = false
  userMenuOpen.value = false
})

function isActive(to: string) {
  return to === '/' ? route.path === '/' : route.path.startsWith(to)
}

async function logout() {
  await auth.logout()
  toast.info('Você saiu do sistema.')
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen bg-slate-50">
    <!-- Fundo escurecido do menu no celular -->
    <div v-if="sidebarOpen" class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden" @click="sidebarOpen = false" />

    <aside
      class="fixed inset-y-0 left-0 z-40 flex w-64 flex-col border-r border-slate-200 bg-white transition-transform lg:translate-x-0"
      :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
      aria-label="Menu principal"
    >
      <div class="flex h-16 items-center border-b border-slate-100 px-5"><BrandLogo /></div>
      <nav class="flex-1 space-y-6 overflow-y-auto px-3 py-5">
        <div v-for="(section, index) in sections" :key="index">
          <p v-if="section.title" class="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400">{{ section.title }}</p>
          <ul class="space-y-1">
            <li v-for="item in section.items" :key="item.to">
              <RouterLink
                :to="item.to"
                class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition"
                :class="isActive(item.to) ? 'bg-brand-50 text-brand-800' : 'text-slate-600 hover:bg-slate-50 hover:text-slate-900'"
              >
                <svg class="size-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="item.icon" /></svg>
                {{ item.label }}
              </RouterLink>
            </li>
          </ul>
        </div>
      </nav>
    </aside>

    <div class="lg:pl-64">
      <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-6">
        <button type="button" class="rounded-md p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Abrir menu" @click="sidebarOpen = true">
          <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
        </button>
        <div class="flex-1" />
        <div class="relative">
          <button type="button" class="flex items-center gap-3 rounded-lg p-1.5 hover:bg-slate-50" :aria-expanded="userMenuOpen" @click="userMenuOpen = !userMenuOpen">
            <span class="grid size-9 place-items-center rounded-full bg-brand-100 text-sm font-semibold text-brand-800">{{ initials }}</span>
            <span class="hidden text-left sm:block">
              <span class="block text-sm font-medium text-slate-900">{{ auth.user?.name }}</span>
              <span class="block text-xs text-slate-500">{{ auth.user?.roles.map((r) => r.label).join(', ') }}</span>
            </span>
          </button>
          <div v-if="userMenuOpen" class="absolute right-0 mt-2 w-56 rounded-xl border border-slate-200 bg-white py-1 shadow-lg" role="menu">
            <p class="truncate border-b border-slate-100 px-4 py-2 text-xs text-slate-500">{{ auth.user?.email }}</p>
            <RouterLink to="/minha-conta/senha" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50" role="menuitem">Alterar senha</RouterLink>
            <button type="button" class="block w-full px-4 py-2 text-left text-sm text-red-600 hover:bg-red-50" role="menuitem" @click="logout">Sair</button>
          </div>
        </div>
      </header>

      <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:py-8">
        <RouterView />
      </main>
    </div>
  </div>
</template>
