<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { fetchRoles } from '@/api/resources'
import type { RoleDefinition } from '@/api/types'
import PageHeader from '@/components/ui/PageHeader.vue'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const roles = ref<RoleDefinition[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    roles.value = await fetchRoles()
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
  }
})

/** Todas as permissões (do Administrador), agrupadas por módulo. */
const groups = computed(() => {
  const all = roles.value.find((r) => r.name === 'admin')?.permissions ?? []
  const map = new Map<string, { name: string; label: string }[]>()
  for (const p of all) map.set(p.group, [...(map.get(p.group) ?? []), p])
  return [...map.entries()]
})

function has(role: RoleDefinition, permission: string) {
  return role.permissions.some((p) => p.name === permission)
}
</script>

<template>
  <PageHeader title="Perfis de acesso" subtitle="Matriz de permissões de cada perfil. As permissões são verificadas pelo servidor em todas as operações." />

  <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    <article v-for="role in roles" :key="role.name" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="font-semibold text-slate-900">{{ role.label }}</h2>
      <p class="mt-1 text-sm text-slate-500">{{ role.description }}</p>
      <p class="mt-3 text-xs font-medium text-brand-700">{{ role.permissions.length }} permissões</p>
    </article>
  </div>

  <div v-if="!loading" class="mt-8 overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
    <table class="min-w-full text-sm">
      <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
        <tr>
          <th scope="col" class="px-4 py-3">Permissão</th>
          <th v-for="role in roles" :key="role.name" scope="col" class="px-3 py-3 text-center">{{ role.label }}</th>
        </tr>
      </thead>
      <tbody>
        <template v-for="[group, permissions] in groups" :key="group">
          <tr class="bg-slate-50/60"><td :colspan="roles.length + 1" class="px-4 py-2 text-xs font-semibold text-slate-600">{{ group }}</td></tr>
          <tr v-for="p in permissions" :key="p.name" class="border-t border-slate-100">
            <td class="px-4 py-2 text-slate-700">{{ p.label }}</td>
            <td v-for="role in roles" :key="role.name" class="px-3 py-2 text-center">
              <span v-if="has(role, p.name)" class="text-emerald-600" aria-label="Sim">✔</span>
              <span v-else class="text-slate-300" aria-label="Não">—</span>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>
