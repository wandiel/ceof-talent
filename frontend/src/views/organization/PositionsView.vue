<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { departmentsApi, positionsApi, type PositionPayload } from '@/api/resources'
import type { Department, Position } from '@/api/types'
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
const { params, items, meta, loading, load, goTo } = useListing<Position>(positionsApi.list, { sort: 'title', department_id: '' })
const departments = ref<Department[]>([])

const open = ref(false)
const editing = ref<Position | null>(null)
const form = reactive<PositionPayload>({ title: '', department_id: null, cbo_code: '', description: '', is_active: true })
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const deleting = ref<Position | null>(null)
const deleteLoading = ref(false)

onMounted(async () => {
  try {
    departments.value = await departmentsApi.options({ status: '' })
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
})

function openForm(position: Position | null) {
  editing.value = position
  errors.value = {}
  Object.assign(form, position
    ? { title: position.title, department_id: position.department?.id ?? null, cbo_code: position.cbo_code ?? '', description: position.description ?? '', is_active: position.is_active }
    : { title: '', department_id: null, cbo_code: '', description: '', is_active: true })
  open.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = { ...form, cbo_code: form.cbo_code || null, description: form.description || null }
  try {
    if (editing.value) await positionsApi.update(editing.value.id, payload)
    else await positionsApi.create(payload)
    toast.success(editing.value ? 'Cargo atualizado.' : 'Cargo criado.')
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
    await positionsApi.remove(deleting.value.id)
    toast.success('Cargo excluído.')
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
  <PageHeader title="Cargos" subtitle="Cargos da organização, usados na abertura de vagas.">
    <template v-if="canManage" #actions><BaseButton @click="openForm(null)">+ Novo cargo</BaseButton></template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <ListToolbar v-model:search="params.search as string" v-model:status="params.status as string" placeholder="Buscar por título ou CBO">
      <select v-model="params.department_id" :class="inputClass()" class="sm:w-52" aria-label="Departamento">
        <option value="">Todos os departamentos</option>
        <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
      </select>
    </ListToolbar>
    <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <SortHeader label="Título" field="title" :sort="params.sort as string" @sort="params.sort = $event" />
            <th scope="col" class="px-4 py-3">Departamento</th>
            <th scope="col" class="px-4 py-3">CBO</th>
            <th scope="col" class="px-4 py-3">Situação</th>
            <th v-if="canManage" scope="col" class="px-4 py-3 text-right">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="position in items" :key="position.id" class="hover:bg-slate-50/60">
            <td class="px-4 py-3 font-medium text-slate-900">{{ position.title }}</td>
            <td class="px-4 py-3 text-slate-600">{{ position.department?.name ?? '—' }}</td>
            <td class="px-4 py-3 tabular-nums text-slate-600">{{ position.cbo_code ?? '—' }}</td>
            <td class="px-4 py-3"><StatusBadge :active="position.is_active" /></td>
            <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right">
              <BaseButton size="sm" variant="ghost" @click="openForm(position)">Editar</BaseButton>
              <BaseButton size="sm" variant="ghost" class="text-red-600" @click="deleting = position">Excluir</BaseButton>
            </td>
          </tr>
          <tr v-if="!loading && items.length === 0"><td colspan="5" class="px-4 py-12 text-center text-slate-500">Nenhum cargo encontrado.</td></tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :meta="meta" @page="goTo" />
  </div>

  <BaseModal :open="open" :title="editing ? 'Editar cargo' : 'Novo cargo'" @close="open = false">
    <form id="pos-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
      <FormField class="sm:col-span-2" label="Título" for="pos-title" :error="errors.title" required>
        <input id="pos-title" v-model.trim="form.title" :class="inputClass(errors.title)" maxlength="150" />
      </FormField>
      <FormField label="Departamento" for="pos-dep" :error="errors.department_id">
        <select id="pos-dep" v-model="form.department_id" :class="inputClass(errors.department_id)">
          <option :value="null">Sem departamento</option>
          <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
      </FormField>
      <FormField label="Código CBO" for="pos-cbo" :error="errors.cbo_code" hint="Opcional. Formato 0000-00">
        <input id="pos-cbo" v-model.trim="form.cbo_code" :class="inputClass(errors.cbo_code)" maxlength="7" inputmode="numeric" />
      </FormField>
      <FormField class="sm:col-span-2" label="Descrição" for="pos-desc" :error="errors.description">
        <textarea id="pos-desc" v-model.trim="form.description" rows="3" :class="inputClass(errors.description)" maxlength="2000" />
      </FormField>
      <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
        <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-brand-700" /> Cargo ativo
      </label>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="open = false">Cancelar</BaseButton>
      <BaseButton type="submit" form="pos-form" :loading="saving">Salvar</BaseButton>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="deleting !== null"
    title="Excluir cargo"
    :message="`Excluir o cargo ${deleting?.title}?`"
    confirm-label="Excluir"
    danger
    :loading="deleteLoading"
    @confirm="remove"
    @cancel="deleting = null"
  />
</template>
