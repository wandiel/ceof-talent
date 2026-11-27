<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { candidatesApi, talentPoolApi, tagColorClasses, type TagItem } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import FormField from '@/components/ui/FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import TagBadge from './components/TagBadge.vue'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()
const canManage = auth.can('talent_pool.manage')
const colors = Object.keys(tagColorClasses)
const colorNames: Record<string, string> = { slate: 'Cinza', blue: 'Azul', green: 'Verde', amber: 'Amarelo', red: 'Vermelho', purple: 'Roxo', pink: 'Rosa', teal: 'Turquesa' }

const tags = ref<TagItem[]>([])
const loading = ref(true)
const editing = ref<TagItem | null>(null)
const formOpen = ref(false)
const form = reactive({ name: '', color: 'blue' })
const errors = ref<Record<string, string>>({})
const saving = ref(false)
const removing = ref<TagItem | null>(null)
const removingLoading = ref(false)

async function load() {
  loading.value = true
  try {
    tags.value = await talentPoolApi.tags()
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
  }
}

function openForm(tag: TagItem | null) {
  editing.value = tag
  Object.assign(form, tag ? { name: tag.name, color: tag.color } : { name: '', color: 'blue' })
  errors.value = {}
  formOpen.value = true
}

async function save() {
  saving.value = true
  errors.value = {}
  try {
    if (editing.value) await talentPoolApi.updateTag(editing.value.id, { ...form })
    else await talentPoolApi.createTag({ ...form })
    candidatesApi.forgetOptions()
    toast.success(editing.value ? 'Etiqueta atualizada.' : 'Etiqueta criada.')
    formOpen.value = false
    load()
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = Object.keys(parsed.fields).length ? parsed.fields : { name: parsed.message }
  } finally {
    saving.value = false
  }
}

async function confirmRemove() {
  if (!removing.value) return
  removingLoading.value = true
  try {
    await talentPoolApi.removeTag(removing.value.id)
    candidatesApi.forgetOptions()
    toast.success('Etiqueta excluída.')
    removing.value = null
    load()
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    removingLoading.value = false
  }
}

onMounted(load)
</script>

<template>
  <PageHeader title="Etiquetas" subtitle="Marcadores compartilhados pela equipe para organizar o banco de talentos.">
    <template v-if="canManage" #actions>
      <BaseButton @click="openForm(null)">+ Nova etiqueta</BaseButton>
    </template>
  </PageHeader>

  <div class="rounded-2xl border border-slate-200 bg-white shadow-sm">
    <p v-if="loading" class="p-12 text-center text-slate-500">Carregando…</p>
    <p v-else-if="!tags.length" class="p-12 text-center text-slate-500">Nenhuma etiqueta criada. Exemplos: “Banco de reserva”, “Perfil liderança”, “Recontratar”.</p>
    <ul v-else class="divide-y divide-slate-100">
      <li v-for="t in tags" :key="t.id" class="flex items-center gap-4 px-4 py-3">
        <TagBadge :name="t.name" :color="t.color" />
        <button type="button" class="flex-1 text-left text-sm text-slate-500 hover:text-brand-700" @click="router.push({ name: 'candidates', query: { tags: [t.id] } })">
          {{ t.candidates_count }} candidato{{ t.candidates_count === 1 ? '' : 's' }}
        </button>
        <template v-if="canManage">
          <BaseButton size="sm" variant="ghost" @click="openForm(t)">Editar</BaseButton>
          <BaseButton size="sm" variant="ghost" class="text-red-600" @click="removing = t">Excluir</BaseButton>
        </template>
      </li>
    </ul>
  </div>

  <BaseModal :open="formOpen" :title="editing ? 'Editar etiqueta' : 'Nova etiqueta'" @close="formOpen = false">
    <form id="tag-form" class="space-y-4" novalidate @submit.prevent="save">
      <FormField label="Nome" for="tag-name" :error="errors.name" required>
        <input id="tag-name" v-model="form.name" type="text" maxlength="50" :class="inputClass(errors.name)" />
      </FormField>
      <fieldset>
        <legend class="mb-1 text-sm font-medium text-slate-700">Cor</legend>
        <div class="flex flex-wrap gap-2">
          <label v-for="c in colors" :key="c" class="cursor-pointer">
            <input v-model="form.color" type="radio" :value="c" class="peer sr-only" />
            <span class="inline-flex rounded-md px-2.5 py-1 text-xs font-medium ring-1 peer-checked:ring-2 peer-checked:ring-brand-600 peer-focus-visible:outline-2" :class="tagColorClasses[c]">{{ colorNames[c] }}</span>
          </label>
        </div>
      </fieldset>
      <p class="text-sm text-slate-500">Prévia: <TagBadge :name="form.name || 'Etiqueta'" :color="form.color" /></p>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="formOpen = false">Cancelar</BaseButton>
      <BaseButton type="submit" form="tag-form" :loading="saving">Salvar</BaseButton>
    </template>
  </BaseModal>

  <ConfirmDialog
    :open="removing !== null"
    title="Excluir etiqueta"
    :message="`A etiqueta ${removing?.name ?? ''} será retirada de ${removing?.candidates_count ?? 0} candidato(s). Os cadastros não são alterados.`"
    confirm-label="Excluir"
    danger
    :loading="removingLoading"
    @confirm="confirmRemove"
    @cancel="removing = null"
  />
</template>
