<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { parseApiError } from '@/api/http'
import { criteriaApi, type CriterionItem } from '@/api/interviews'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'

const toast = useToastStore()
const items = ref<CriterionItem[]>([])
const loading = ref(true)
const editing = ref<CriterionItem | null>(null)
const open = ref(false)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const removing = ref<CriterionItem | null>(null)
const form = reactive({ name: '', description: '', is_active: true })

async function load() {
  try {
    items.value = await criteriaApi.list()
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
  }
}
onMounted(load)

function edit(item: CriterionItem | null) {
  editing.value = item
  Object.assign(form, { name: item?.name ?? '', description: item?.description ?? '', is_active: item?.is_active ?? true })
  errors.value = {}
  open.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  const payload = { name: form.name.trim(), description: form.description.trim() || null, is_active: form.is_active }
  try {
    if (editing.value) await criteriaApi.update(editing.value.id, payload)
    else await criteriaApi.create(payload)
    toast.success(editing.value ? 'Critério atualizado.' : 'Critério criado.')
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
  if (!removing.value) return
  try {
    await criteriaApi.remove(removing.value.id)
    toast.success('Critério excluído.')
    removing.value = null
    load()
  } catch (e) {
    toast.error(parseApiError(e).message)
  }
}
</script>

<template>
  <PageHeader title="Critérios de avaliação" subtitle="Usados nos pareceres de entrevista (notas de 1 a 5). Entrevistas já agendadas guardam os critérios da época.">
    <template #actions><BaseButton @click="edit(null)">Novo critério</BaseButton></template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <ul class="divide-y divide-slate-100">
      <li v-for="c in items" :key="c.id" class="flex items-center gap-3 px-4 py-3">
        <div class="min-w-0 flex-1">
          <p class="font-medium text-slate-900">{{ c.name }}</p>
          <p v-if="c.description" class="text-sm text-slate-500">{{ c.description }}</p>
        </div>
        <StatusBadge :active="c.is_active" />
        <BaseButton variant="ghost" size="sm" @click="edit(c)">Editar</BaseButton>
        <BaseButton variant="ghost" size="sm" class="text-red-600" @click="removing = c">Excluir</BaseButton>
      </li>
      <li v-if="!loading && !items.length" class="px-4 py-12 text-center text-slate-500">Nenhum critério cadastrado.</li>
    </ul>
  </div>

  <BaseModal :open="open" :title="editing ? 'Editar critério' : 'Novo critério'" @close="open = false">
    <form id="criterion-form" class="space-y-4" @submit.prevent="save">
      <FormField label="Nome" for="cr-name" :error="errors.name" required>
        <input id="cr-name" v-model="form.name" type="text" maxlength="100" :class="inputClass(errors.name)" />
      </FormField>
      <FormField label="Descrição" for="cr-desc" :error="errors.description" hint="O que o entrevistador deve observar.">
        <textarea id="cr-desc" v-model="form.description" rows="2" maxlength="500" :class="inputClass(errors.description)" />
      </FormField>
      <label class="inline-flex items-center gap-2 text-sm text-slate-700"><input v-model="form.is_active" type="checkbox" class="size-4 rounded border-slate-300" /> Ativo (aparece em novos agendamentos)</label>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="open = false">Cancelar</BaseButton>
      <BaseButton type="submit" form="criterion-form" :loading="saving">Salvar</BaseButton>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="!!removing"
    title="Excluir critério"
    :message="`O critério “${removing?.name}” deixa de ser oferecido. Pareceres já registrados não mudam. Para só esconder, prefira desativar.`"
    confirm-label="Excluir"
    danger
    @confirm="remove"
    @cancel="removing = null"
  />
</template>
