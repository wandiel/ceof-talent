import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: { name: 'system-status' } },
    {
      path: '/status',
      name: 'system-status',
      component: () => import('@/views/SystemStatusView.vue'),
      meta: { title: 'Status do sistema' },
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: () => import('@/views/NotFoundView.vue'),
      meta: { title: 'Página não encontrada' },
    },
  ],
})

router.afterEach((to) => {
  const title = to.meta.title as string | undefined
  document.title = title ? `${title} · CEOF TALENT` : 'CEOF TALENT'
})

export default router
