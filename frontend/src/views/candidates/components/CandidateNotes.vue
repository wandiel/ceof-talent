<script setup lang="ts">
import { ref } from 'vue'
import { candidatesApi, type Candidate, type CandidateNote } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import { inputClass } from '@/components/ui/inputClass'
import { formatDateTime } from '@/utils/format'

const props = defineProps<{ candidate: Candidate }>()
const notes = defineModel<CandidateNote[]>('notes', { required: true })
const emit = defineEmits<{ changed: [] }>()

const body = ref('')
const error = ref('')
const saving = ref(false)

async function submit() {
  saving.value = true
  error.value = ''
  try {
    notes.value = [await candidatesApi.addNote(props.candidate.id, body.value), ...notes.value]
    body.value = ''
    emit('changed')
  } catch (e) {
    const parsed = parseApiError(e)
    error.value = parsed.fields.body ?? parsed.message
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="max-w-3xl space-y-4">
    <form v-if="candidate.abilities.update" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" @submit.prevent="submit">
      <label for="note-body" class="mb-1 block text-sm font-medium text-slate-700">Nova anotação</label>
      <textarea id="note-body" v-model="body" rows="3" maxlength="3000" placeholder="Registre impressões da triagem, contatos realizados, combinados…" :class="inputClass(error)" />
      <p v-if="error" class="mt-1 text-xs text-red-600" role="alert">{{ error }}</p>
      <div class="mt-3 flex items-center justify-between gap-3">
        <p class="text-xs text-slate-500">Anotações são internas e não podem ser editadas depois de registradas.</p>
        <BaseButton type="submit" :loading="saving" :disabled="body.trim().length < 2">Registrar</BaseButton>
      </div>
    </form>

    <ul v-if="notes.length" class="space-y-3">
      <li v-for="n in notes" :key="n.id" class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <p class="whitespace-pre-line text-sm text-slate-800">{{ n.body }}</p>
        <p class="mt-2 text-xs text-slate-500">{{ n.author ?? 'Usuário removido' }} · {{ formatDateTime(n.created_at) }}</p>
      </li>
    </ul>
    <p v-else class="rounded-2xl border border-dashed border-slate-300 p-8 text-center text-sm text-slate-500">Nenhuma anotação registrada.</p>
  </div>
</template>
