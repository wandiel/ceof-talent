<script setup lang="ts">
import { ref } from 'vue'
import { candidatesApi, type Candidate, type CandidateDocument, type Option } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'
import { formatDateTime, formatFileSize } from '@/utils/format'

const props = defineProps<{ candidate: Candidate; types: Option[] }>()
const documents = defineModel<CandidateDocument[]>('documents', { required: true })
const emit = defineEmits<{ changed: [] }>()
const toast = useToastStore()

const ACCEPT = '.pdf,.doc,.docx,.odt,.jpg,.jpeg,.png'
const MAX_BYTES = 10 * 1024 * 1024

const type = ref('resume')
const file = ref<File | null>(null)
const input = ref<HTMLInputElement | null>(null)
const dragging = ref(false)
const uploading = ref(false)
const progress = ref(0)
const error = ref('')
const removing = ref<CandidateDocument | null>(null)
const removingLoading = ref(false)

function pick(selected: File | undefined | null) {
  error.value = ''
  if (!selected) return
  const extension = selected.name.split('.').pop()?.toLowerCase() ?? ''
  if (!ACCEPT.split(',').includes(`.${extension}`)) {
    error.value = 'Formato não permitido. Envie PDF, DOC, DOCX, ODT, JPG ou PNG.'
    return
  }
  if (selected.size > MAX_BYTES) {
    error.value = 'O arquivo deve ter no máximo 10 MB.'
    return
  }
  file.value = selected
}

function onDrop(event: DragEvent) {
  dragging.value = false
  pick(event.dataTransfer?.files?.[0])
}

async function upload() {
  if (!file.value) return
  uploading.value = true
  progress.value = 0
  error.value = ''
  try {
    const created = await candidatesApi.uploadDocument(props.candidate.id, type.value, file.value, (p) => (progress.value = p))
    documents.value = [created, ...documents.value]
    toast.success('Documento anexado.')
    file.value = null
    if (input.value) input.value.value = ''
    emit('changed')
  } catch (e) {
    const parsed = parseApiError(e)
    error.value = parsed.fields.file ?? parsed.fields.type ?? parsed.message
  } finally {
    uploading.value = false
  }
}

async function confirmRemove() {
  if (!removing.value) return
  removingLoading.value = true
  try {
    await candidatesApi.removeDocument(props.candidate.id, removing.value.id)
    documents.value = documents.value.filter((d) => d.id !== removing.value?.id)
    toast.success('Documento removido.')
    removing.value = null
    emit('changed')
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    removingLoading.value = false
  }
}

function icon(mime: string): string {
  if (mime === 'application/pdf') return 'PDF'
  if (mime.startsWith('image/')) return 'IMG'
  return 'DOC'
}
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-3">
    <section class="rounded-2xl border border-slate-200 bg-white shadow-sm lg:col-span-2">
      <header class="border-b border-slate-100 px-5 py-4">
        <h2 class="text-base font-semibold text-slate-900">Arquivos anexados</h2>
        <p class="text-sm text-slate-500">Guardados em área privada. Cada download fica registrado no histórico.</p>
      </header>
      <ul v-if="documents.length" class="divide-y divide-slate-100">
        <li v-for="d in documents" :key="d.id" class="flex items-center gap-4 px-5 py-3">
          <span class="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-100 text-[10px] font-bold text-slate-600">{{ icon(d.mime_type) }}</span>
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium text-slate-900" :title="d.original_name">{{ d.original_name }}</p>
            <p class="text-xs text-slate-500">{{ d.type_label }} · {{ formatFileSize(d.size) }} · {{ formatDateTime(d.created_at) }}{{ d.uploaded_by ? ` · ${d.uploaded_by}` : '' }}</p>
          </div>
          <a v-if="candidate.abilities.download_documents" :href="candidatesApi.documentUrl(candidate.id, d.id)" class="rounded-lg px-2.5 py-1.5 text-xs font-medium text-brand-700 hover:bg-brand-50" @click="emit('changed')">Baixar</a>
          <BaseButton v-if="candidate.abilities.update" size="sm" variant="ghost" class="text-red-600" @click="removing = d">Remover</BaseButton>
        </li>
      </ul>
      <p v-else class="px-5 py-10 text-center text-sm text-slate-500">Nenhum arquivo anexado.</p>
    </section>

    <section v-if="candidate.abilities.update" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 class="mb-3 text-base font-semibold text-slate-900">Anexar arquivo</h2>
      <form class="space-y-3" @submit.prevent="upload">
        <select v-model="type" :class="inputClass()" aria-label="Tipo de documento">
          <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
        </select>
        <label
          class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-4 py-8 text-center text-sm transition"
          :class="dragging ? 'border-brand-500 bg-brand-50' : error ? 'border-red-300' : 'border-slate-300 hover:border-brand-400'"
          @dragover.prevent="dragging = true"
          @dragleave.prevent="dragging = false"
          @drop.prevent="onDrop"
        >
          <input ref="input" type="file" :accept="ACCEPT" class="sr-only" data-testid="document-input" @change="pick(($event.target as HTMLInputElement).files?.[0])" />
          <span v-if="file" class="font-medium text-slate-800">{{ file.name }}<br /><span class="text-xs font-normal text-slate-500">{{ formatFileSize(file.size) }}</span></span>
          <span v-else class="text-slate-500"><span class="font-medium text-brand-700">Escolha um arquivo</span> ou arraste aqui<br /><span class="text-xs">PDF, DOC, DOCX, ODT, JPG ou PNG, até 10 MB</span></span>
        </label>
        <p v-if="error" class="text-xs text-red-600" role="alert">{{ error }}</p>
        <div v-if="uploading" class="h-1.5 overflow-hidden rounded-full bg-slate-100"><div class="h-full bg-brand-600 transition-all" :style="{ width: `${progress}%` }" /></div>
        <BaseButton type="submit" class="w-full" :disabled="!file" :loading="uploading">Enviar arquivo</BaseButton>
      </form>
    </section>

    <ConfirmDialog
      :open="removing !== null"
      title="Remover documento"
      :message="`O arquivo ${removing?.original_name ?? ''} será apagado definitivamente.`"
      confirm-label="Remover"
      danger
      :loading="removingLoading"
      @confirm="confirmRemove"
      @cancel="removing = null"
    />
  </div>
</template>
