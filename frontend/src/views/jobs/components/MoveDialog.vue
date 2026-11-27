<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Option } from '@/api/candidates'
import { canMoveTo, type ApplicationItem, type Stage } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'

const props = defineProps<{
  application: ApplicationItem | null
  initialStage: Stage | null
  stages: Option[]
  rejectionReasons: Option[]
  abilities: { move: boolean; decide: boolean }
  loading: boolean
  errors: Record<string, string>
}>()
const emit = defineEmits<{ confirm: [stage: Stage, reason: string | null, note: string | null]; cancel: [] }>()

const stage = ref<Stage | ''>('')
const reason = ref('')
const note = ref('')

watch(
  () => props.application,
  () => {
    stage.value = props.initialStage ?? ''
    reason.value = ''
    note.value = ''
  },
)

const available = computed(() => props.stages.filter((s) => props.application && canMoveTo(props.application.stage, s.value as Stage, props.abilities)))
const name = computed(() => props.application?.candidate?.social_name || props.application?.candidate?.full_name)

function submit() {
  if (!stage.value) return
  emit('confirm', stage.value, stage.value === 'rejected' ? reason.value || null : null, note.value.trim() || null)
}
</script>

<template>
  <BaseModal :open="!!application" :title="`Movimentar ${name ?? ''}`" @close="emit('cancel')">
    <form v-if="application" id="move-form" class="space-y-4" @submit.prevent="submit">
      <p class="text-sm text-slate-600">Etapa atual: <strong>{{ application.stage_label }}</strong></p>
      <FormField label="Nova etapa" for="move-stage" :error="errors.stage" required>
        <select id="move-stage" v-model="stage" :class="inputClass(errors.stage)">
          <option value="" disabled>Selecione</option>
          <option v-for="s in available" :key="s.value" :value="s.value">{{ s.label }}</option>
        </select>
      </FormField>
      <FormField v-if="stage === 'rejected'" label="Motivo da reprovação" for="move-reason" :error="errors.reason" required>
        <select id="move-reason" v-model="reason" :class="inputClass(errors.reason)">
          <option value="" disabled>Selecione</option>
          <option v-for="r in rejectionReasons" :key="r.value" :value="r.value">{{ r.label }}</option>
        </select>
      </FormField>
      <p v-if="stage === 'hired'" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 ring-1 ring-emerald-200">
        A contratação ocupa uma posição da vaga. Quando todas forem preenchidas, a vaga é encerrada automaticamente.
      </p>
      <FormField label="Observação" for="move-note" :error="errors.note" hint="Opcional. Fica registrada no histórico do candidato.">
        <textarea id="move-note" v-model="note" rows="3" maxlength="2000" :class="inputClass(errors.note)" />
      </FormField>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="emit('cancel')">Cancelar</BaseButton>
      <BaseButton type="submit" form="move-form" :variant="stage === 'rejected' ? 'danger' : 'primary'" :disabled="!stage || (stage === 'rejected' && !reason)" :loading="loading">
        {{ stage === 'rejected' ? 'Reprovar' : stage === 'hired' ? 'Confirmar contratação' : 'Mover' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
