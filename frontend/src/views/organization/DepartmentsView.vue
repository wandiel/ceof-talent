<script setup lang="ts">
import { reactive, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { departmentsApi, type DepartmentPayload } from '@/api/resources'
import type { Department } from '@/api/types'
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

const canManage = useAuthStore().can('organization.manage')
const toast = useToastStore()
const { params, items, meta, loading, load, goTo } = useListing<Department>(departmentsApi.list)

const open = ref(false)
const editing = ref<Department | null>(null)
const form = reactive<DepartmentPayload>({ name: '', code: '', description: '', is_active: true })
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const deleting = ref<Department | null>(null)
const deleteLoading = ref(false)

function openForm(dep: Department | null) {
  editing.value = dep
  errors.value = {}
  Object.assign(form, dep
    ? { name: dep.name, code: dep.code ?? '', description: dep.description ?? '', is_active: dep.is_active }
    : { name: '', code: '', description: '', is_active: true })
  open.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = { ...form, code: form.code || null, description: form.description || null }
  try {
    if (editing.value) await departmentsApi.update(editing.value.id, payload)
    else await departmentsApi.create(payload)
    toast.success(editing.value ? 'Departamento atualizado.' : 'Departamento criado.')
    open.value = false
    load()
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.fields
    if (!Object.keys(parsed.fields).length) toast.error(parsed.message)
  } finally {
    saving.value = false
  }
}

async function remove() {
  if (!deleting.value) return
  deleteLoading.value = true
  try {
    await departmentsApi.remove(deleting.value.id)
    toast.success('Departamento excluído.')
    load()
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    deleting.value = null
    deleteLoading.value = false
  }
}
</script>

<template>
  <PageHeader title="Departamentos" subtitle="Áreas da organização que solicitam vagas.">
    <template v-if="canManage" #actions><BaseButton @click="openForm(null)">+ Novo departamento</BaseButton></template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <ListToolbar v-model:search="params.search as string" v-model:status="params.status as string" placeholder="Buscar por nome ou código" />
    <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <SortHeader label="Nome" field="name" :sort="params.sort as string" @sort="params.sort = $event" />
            <SortHeader label="Código" field="code" :sort="params.sort as string" @sort="params.sort = $event" />
            <th scope="col" class="px-4 py-3">Cargos</th>
            <th scope="col" class="px-4 py-3">Usuários</th>
            <th scope="col" class="px-4 py-3">Situação</th>
            <th v-if="canManage" scope="col" class="px-4 py-3 text-right">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="dep in items" :key="dep.id" class="hover:bg-slate-50/60">
            <td class="px-4 py-3">
              <p class="font-medium text-slate-900">{{ dep.name }}</p>
              <p v-if="dep.description" class="line-clamp-1 text-xs text-slate-500">{{ dep.description }}</p>
            </td>
            <td class="px-4 py-3 text-slate-600">{{ dep.code ?? '—' }}</td>
            <td class="px-4 py-3 tabular-nums text-slate-600">{{ dep.positions_count ?? 0 }}</td>
            <td class="px-4 py-3 tabular-nums text-slate-600">{{ dep.users_count ?? 0 }}</td>
            <td class="px-4 py-3"><StatusBadge :active="dep.is_active" /></td>
            <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right">
              <BaseButton size="sm" variant="ghost" @click="openForm(dep)">Editar</BaseButton>
              <BaseButton size="sm" variant="ghost" class="text-red-600" @click="deleting = dep">Excluir</BaseButton>
            </td>
          </tr>
          <tr v-if="!loading && items.length === 0"><td colspan="6" class="px-4 py-12 text-center text-slate-500">Nenhum departamento encontrado.</td></tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :meta="meta" @page="goTo" />
  </div>

  <BaseModal :open="open" :title="editing ? 'Editar departamento' : 'Novo departamento'" @close="open = false">
    <form id="dep-form" class="grid gap-4" novalidate @submit.prevent="save">
      <FormField label="Nome" for="dep-name" :error="errors.name" required>
        <input id="dep-name" v-model.trim="form.name" :class="inputClass(errors.name)" maxlength="150" />
      </FormField>
      <FormField label="Código" for="dep-code" :error="errors.code" hint="Opcional. Ex.: TI, RH, FIN">
        <input id="dep-code" v-model.trim="form.code" :class="inputClass(errors.code)" maxlength="30" />
      </FormField>
      <FormField label="Descrição" for="dep-desc" :error="errors.description">
        <textarea id="dep-desc" v-model.trim="form.description" rows="3" :class="inputClass(errors.description)" maxlength="2000" />
      </FormField>
      <label class="flex items-center gap-2 text-sm text-slate-700">
        <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-brand-700" /> Departamento ativo
      </label>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="open = false">Cancelar</BaseButton>
      <BaseButton type="submit" form="dep-form" :loading="saving">Salvar</BaseButton>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="deleting !== null"
    title="Excluir departamento"
    :message="`Excluir o departamento ${deleting?.name}? Departamentos com cargos ou usuários vinculados não podem ser excluídos: desative-os.`"
    confirm-label="Excluir"
    danger
    :loading="deleteLoading"
    @confirm="remove"
    @cancel="deleting = null"
  />
</template>
