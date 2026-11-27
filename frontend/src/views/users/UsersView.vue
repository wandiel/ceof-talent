<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { departmentsApi, fetchRoles, usersApi } from '@/api/resources'
import type { Department, RoleDefinition, User } from '@/api/types'
import PasswordRules from '@/components/PasswordRules.vue'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import ListToolbar from '@/components/ui/ListToolbar.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SortHeader from '@/components/ui/SortHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useListing } from '@/composables/useListing'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import UserFormModal from './UserFormModal.vue'

const auth = useAuthStore()
const toast = useToastStore()
const canManage = auth.can('users.manage')

const { params, items, meta, loading, load, goTo } = useListing<User>(usersApi.list, { role: '', department_id: '' })
const roles = ref<RoleDefinition[]>([])
const departments = ref<Department[]>([])

const formOpen = ref(false)
const editing = ref<User | null>(null)
const statusTarget = ref<User | null>(null)
const statusLoading = ref(false)
const passwordTarget = ref<User | null>(null)
const tempPassword = ref('')
const passwordError = ref('')
const passwordLoading = ref(false)

onMounted(async () => {
  try {
    ;[roles.value, departments.value] = await Promise.all([fetchRoles(), departmentsApi.options({ status: '' })])
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
})

function openCreate() {
  editing.value = null
  formOpen.value = true
}

function openEdit(user: User) {
  editing.value = user
  formOpen.value = true
}

function onSaved() {
  formOpen.value = false
  load()
}

async function confirmStatus() {
  if (!statusTarget.value) return
  statusLoading.value = true
  try {
    const updated = await usersApi.setStatus(statusTarget.value.id, !statusTarget.value.is_active)
    toast.success(updated.is_active ? 'Usuário ativado.' : 'Usuário desativado e sessões encerradas.')
    statusTarget.value = null
    load()
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    statusLoading.value = false
  }
}

async function submitPassword() {
  if (!passwordTarget.value) return
  passwordLoading.value = true
  passwordError.value = ''
  try {
    toast.success(await usersApi.setTemporaryPassword(passwordTarget.value.id, tempPassword.value))
    passwordTarget.value = null
    tempPassword.value = ''
  } catch (e) {
    const parsed = parseApiError(e)
    passwordError.value = parsed.fields.password ?? parsed.message
  } finally {
    passwordLoading.value = false
  }
}

function formatDate(value: string | null) {
  return value ? new Date(value).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : 'Nunca'
}
</script>

<template>
  <PageHeader title="Usuários" subtitle="Equipe com acesso ao sistema e seus perfis de acesso.">
    <template v-if="canManage" #actions>
      <BaseButton @click="openCreate">+ Novo usuário</BaseButton>
    </template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <ListToolbar v-model:search="params.search as string" v-model:status="params.status as string" placeholder="Buscar por nome ou e-mail">
      <select v-model="params.role" :class="inputClass()" class="sm:w-48" aria-label="Perfil">
        <option value="">Todos os perfis</option>
        <option v-for="r in roles" :key="r.name" :value="r.name">{{ r.label }}</option>
      </select>
      <select v-model="params.department_id" :class="inputClass()" class="sm:w-48" aria-label="Departamento">
        <option value="">Todos os departamentos</option>
        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
    </ListToolbar>

    <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <SortHeader label="Nome" field="name" :sort="params.sort as string" @sort="params.sort = $event" />
            <th scope="col" class="px-4 py-3">Perfis</th>
            <th scope="col" class="px-4 py-3">Departamento</th>
            <th scope="col" class="px-4 py-3">Situação</th>
            <SortHeader label="Último acesso" field="last_login_at" :sort="params.sort as string" @sort="params.sort = $event" />
            <th v-if="canManage" scope="col" class="px-4 py-3 text-right">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="user in items" :key="user.id" class="hover:bg-slate-50/60">
            <td class="px-4 py-3">
              <p class="font-medium text-slate-900">{{ user.name }}</p>
              <p class="text-xs text-slate-500">{{ user.email }}</p>
              <p v-if="user.must_change_password" class="mt-1 text-xs text-amber-700">Senha provisória pendente</p>
            </td>
            <td class="px-4 py-3">
              <span v-for="r in user.roles" :key="r.name" class="mb-1 mr-1 inline-block rounded-md bg-brand-50 px-2 py-0.5 text-xs font-medium text-brand-800">{{ r.label }}</span>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ user.department?.name ?? '—' }}</td>
            <td class="px-4 py-3"><StatusBadge :active="user.is_active" /></td>
            <td class="px-4 py-3 text-slate-600">{{ formatDate(user.last_login_at) }}</td>
            <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right">
              <BaseButton size="sm" variant="ghost" @click="openEdit(user)">Editar</BaseButton>
              <template v-if="user.id !== auth.user?.id">
                <BaseButton size="sm" variant="ghost" @click="passwordTarget = user">Senha</BaseButton>
                <BaseButton size="sm" variant="ghost" :class="user.is_active ? 'text-red-600' : 'text-emerald-700'" @click="statusTarget = user">
                  {{ user.is_active ? 'Desativar' : 'Ativar' }}
                </BaseButton>
              </template>
            </td>
          </tr>
          <tr v-if="!loading && items.length === 0">
            <td :colspan="canManage ? 6 : 5" class="px-4 py-12 text-center text-slate-500">Nenhum usuário encontrado com os filtros atuais.</td>
          </tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :meta="meta" @page="goTo" />
  </div>

  <UserFormModal :open="formOpen" :user="editing" :roles="roles" @close="formOpen = false" @saved="onSaved" />

  <ConfirmDialog
    :open="statusTarget !== null"
    :title="statusTarget?.is_active ? 'Desativar usuário' : 'Ativar usuário'"
    :message="statusTarget?.is_active
      ? `${statusTarget?.name} perderá o acesso imediatamente e as sessões abertas serão encerradas. O histórico é preservado.`
      : `${statusTarget?.name} voltará a ter acesso ao sistema.`"
    :confirm-label="statusTarget?.is_active ? 'Desativar' : 'Ativar'"
    :danger="statusTarget?.is_active"
    :loading="statusLoading"
    @confirm="confirmStatus"
    @cancel="statusTarget = null"
  />

  <BaseModal :open="passwordTarget !== null" title="Definir senha provisória" @close="passwordTarget = null">
    <form id="temp-pass-form" class="space-y-4" novalidate @submit.prevent="submitPassword">
      <p class="text-sm text-slate-600">{{ passwordTarget?.name }} deverá trocar esta senha no próximo acesso. As sessões abertas serão encerradas.</p>
      <FormField label="Senha provisória" for="temp-pass" :error="passwordError" required>
        <input id="temp-pass" v-model="tempPassword" type="text" autocomplete="off" :class="inputClass(passwordError)" />
      </FormField>
      <PasswordRules :password="tempPassword" />
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="passwordTarget = null">Cancelar</BaseButton>
      <BaseButton type="submit" form="temp-pass-form" :loading="passwordLoading">Definir senha</BaseButton>
    </template>
  </BaseModal>
</template>
