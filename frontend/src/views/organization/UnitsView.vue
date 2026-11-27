<script setup lang="ts">
import { reactive, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { unitsApi, type UnitPayload } from '@/api/resources'
import type { Unit } from '@/api/types'
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

const STATES = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO']

const canManage = useAuthStore().can('organization.manage')
const toast = useToastStore()
const { params, items, meta, loading, load, goTo } = useListing<Unit>(unitsApi.list)

const open = ref(false)
const editing = ref<Unit | null>(null)
const form = reactive<UnitPayload>({ name: '', code: '', city: '', state: '', is_active: true })
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const deleting = ref<Unit | null>(null)
const deleteLoading = ref(false)

function openForm(unit: Unit | null) {
  editing.value = unit
  errors.value = {}
  Object.assign(form, unit
    ? { name: unit.name, code: unit.code ?? '', city: unit.city ?? '', state: unit.state ?? '', is_active: unit.is_active }
    : { name: '', code: '', city: '', state: '', is_active: true })
  open.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = { ...form, code: form.code || null, city: form.city || null, state: form.state || null }
  try {
    if (editing.value) await unitsApi.update(editing.value.id, payload)
    else await unitsApi.create(payload)
    toast.success(editing.value ? 'Unidade atualizada.' : 'Unidade criada.')
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
    await unitsApi.remove(deleting.value.id)
    toast.success('Unidade excluída.')
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
  <PageHeader title="Unidades" subtitle="Sedes, filiais e polos da organização.">
    <template v-if="canManage" #actions><BaseButton @click="openForm(null)">+ Nova unidade</BaseButton></template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <ListToolbar v-model:search="params.search as string" v-model:status="params.status as string" placeholder="Buscar por nome, código ou cidade" />
    <div class="overflow-x-auto" :class="{ 'opacity-60': loading }">
      <table class="min-w-full text-sm">
        <thead class="bg-slate-50 text-left text-xs uppercase tracking-wider text-slate-500">
          <tr>
            <SortHeader label="Nome" field="name" :sort="params.sort as string" @sort="params.sort = $event" />
            <SortHeader label="Código" field="code" :sort="params.sort as string" @sort="params.sort = $event" />
            <SortHeader label="Cidade/UF" field="city" :sort="params.sort as string" @sort="params.sort = $event" />
            <th scope="col" class="px-4 py-3">Usuários</th>
            <th scope="col" class="px-4 py-3">Situação</th>
            <th v-if="canManage" scope="col" class="px-4 py-3 text-right">Ações</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <tr v-for="unit in items" :key="unit.id" class="hover:bg-slate-50/60">
            <td class="px-4 py-3 font-medium text-slate-900">{{ unit.name }}</td>
            <td class="px-4 py-3 text-slate-600">{{ unit.code ?? '—' }}</td>
            <td class="px-4 py-3 text-slate-600">{{ [unit.city, unit.state].filter(Boolean).join('/') || '—' }}</td>
            <td class="px-4 py-3 tabular-nums text-slate-600">{{ unit.users_count ?? 0 }}</td>
            <td class="px-4 py-3"><StatusBadge :active="unit.is_active" /></td>
            <td v-if="canManage" class="whitespace-nowrap px-4 py-3 text-right">
              <BaseButton size="sm" variant="ghost" @click="openForm(unit)">Editar</BaseButton>
              <BaseButton size="sm" variant="ghost" class="text-red-600" @click="deleting = unit">Excluir</BaseButton>
            </td>
          </tr>
          <tr v-if="!loading && items.length === 0"><td colspan="6" class="px-4 py-12 text-center text-slate-500">Nenhuma unidade encontrada.</td></tr>
        </tbody>
      </table>
    </div>
    <PaginationBar :meta="meta" @page="goTo" />
  </div>

  <BaseModal :open="open" :title="editing ? 'Editar unidade' : 'Nova unidade'" @close="open = false">
    <form id="unit-form" class="grid gap-4 sm:grid-cols-2" novalidate @submit.prevent="save">
      <FormField class="sm:col-span-2" label="Nome" for="unit-name" :error="errors.name" required>
        <input id="unit-name" v-model.trim="form.name" :class="inputClass(errors.name)" maxlength="150" />
      </FormField>
      <FormField label="Código" for="unit-code" :error="errors.code" hint="Opcional. Ex.: MAO-01">
        <input id="unit-code" v-model.trim="form.code" :class="inputClass(errors.code)" maxlength="30" />
      </FormField>
      <FormField label="UF" for="unit-state" :error="errors.state">
        <select id="unit-state" v-model="form.state" :class="inputClass(errors.state)">
          <option value="">—</option>
          <option v-for="uf in STATES" :key="uf" :value="uf">{{ uf }}</option>
        </select>
      </FormField>
      <FormField class="sm:col-span-2" label="Cidade" for="unit-city" :error="errors.city">
        <input id="unit-city" v-model.trim="form.city" :class="inputClass(errors.city)" maxlength="120" />
      </FormField>
      <label class="flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
        <input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300 text-brand-700" /> Unidade ativa
      </label>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="open = false">Cancelar</BaseButton>
      <BaseButton type="submit" form="unit-form" :loading="saving">Salvar</BaseButton>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="deleting !== null"
    title="Excluir unidade"
    :message="`Excluir a unidade ${deleting?.name}? Unidades com usuários vinculados não podem ser excluídas: desative-as.`"
    confirm-label="Excluir"
    danger
    :loading="deleteLoading"
    @confirm="remove"
    @cancel="deleting = null"
  />
</template>
