import { createRouter, createWebHistory, type RouteLocationNormalized } from 'vue-router'
import { onPasswordChangeRequired, onUnauthorized } from '@/api/http'
import AppLayout from '@/layouts/AppLayout.vue'
import AuthLayout from '@/layouts/AuthLayout.vue'
import { useAuthStore } from '@/stores/auth'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    /** Rota exige usuário autenticado. */
    auth?: boolean
    /** Rota só para visitantes (ex.: login). */
    guest?: boolean
    /** Permissão necessária para acessar a rota. */
    permission?: string
  }
}

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: AuthLayout,
      meta: { guest: true },
      children: [
        { path: 'entrar', name: 'login', component: () => import('@/views/auth/LoginView.vue'), meta: { title: 'Entrar', guest: true } },
        { path: 'esqueci-senha', name: 'forgot-password', component: () => import('@/views/auth/ForgotPasswordView.vue'), meta: { title: 'Recuperar senha', guest: true } },
        { path: 'redefinir-senha', name: 'reset-password', component: () => import('@/views/auth/ResetPasswordView.vue'), meta: { title: 'Redefinir senha', guest: true } },
      ],
    },
    {
      path: '/',
      component: AppLayout,
      meta: { auth: true },
      children: [
        { path: '', name: 'home', component: () => import('@/views/HomeView.vue'), meta: { title: 'Início' } },
        { path: 'minha-conta/senha', name: 'change-password', component: () => import('@/views/auth/ChangePasswordView.vue'), meta: { title: 'Alterar senha' } },
        { path: 'usuarios', name: 'users', component: () => import('@/views/users/UsersView.vue'), meta: { title: 'Usuários', permission: 'users.view' } },
        { path: 'perfis', name: 'roles', component: () => import('@/views/users/RolesView.vue'), meta: { title: 'Perfis de acesso', permission: 'roles.view' } },
        { path: 'unidades', name: 'units', component: () => import('@/views/organization/UnitsView.vue'), meta: { title: 'Unidades', permission: 'organization.view' } },
        { path: 'departamentos', name: 'departments', component: () => import('@/views/organization/DepartmentsView.vue'), meta: { title: 'Departamentos', permission: 'organization.view' } },
        { path: 'cargos', name: 'positions', component: () => import('@/views/organization/PositionsView.vue'), meta: { title: 'Cargos', permission: 'organization.view' } },
        { path: 'status', name: 'system-status', component: () => import('@/views/SystemStatusView.vue'), meta: { title: 'Status do sistema' } },
        { path: 'acesso-negado', name: 'forbidden', component: () => import('@/views/ForbiddenView.vue'), meta: { title: 'Acesso negado' } },
      ],
    },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('@/views/NotFoundView.vue'), meta: { title: 'Página não encontrada' } },
  ],
})

function requires(to: RouteLocationNormalized, key: 'auth' | 'guest') {
  return to.matched.some((r) => r.meta[key])
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (!auth.loaded) await auth.loadSession()

  if (requires(to, 'auth') && !auth.isAuthenticated) {
    return { name: 'login', query: to.fullPath !== '/' ? { redirect: to.fullPath } : {} }
  }

  if (requires(to, 'guest') && auth.isAuthenticated) {
    return { name: 'home' }
  }

  // Senha provisória: só a tela de troca de senha fica disponível.
  if (auth.user?.must_change_password && requires(to, 'auth') && to.name !== 'change-password') {
    return { name: 'change-password' }
  }

  const permission = to.meta.permission
  if (permission && !auth.can(permission)) {
    return { name: 'forbidden' }
  }
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} · CEOF TALENT` : 'CEOF TALENT'
})

// Sessão expirada ou encerrada no servidor: volta para o login.
onUnauthorized(() => {
  const auth = useAuthStore()
  if (!auth.isAuthenticated) return
  auth.clear()
  router.push({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
})

onPasswordChangeRequired(() => {
  router.push({ name: 'change-password' })
})

export default router
