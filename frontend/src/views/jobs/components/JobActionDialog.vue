<script setup lang="ts">
import { ref, watch } from 'vue'
import type { JobAction } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import BaseModal from '@/components/ui/BaseModal.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'

const props = defineProps<{ action: { action: JobAction; label: string; needs_reason: boolean } | null; loading: boolean; error?: string }>()
const emit = defineEmits<{ confirm: [reason: string]; cancel: [] }>()

const reason = ref('')
watch(() => props.action, () => (reason.value = ''))

const help: Record<JobAction, string> = {
  submit: 'A vaga vai para o gestor do departamento (ou para o RH) aprovar. Enquanto aguarda, não pode ser editada pelo solicitante.',
  approve: 'Com a aprovação, o RH pode publicar a vaga e começar a inscrever candidatos.',
  reject: 'A vaga volta para o solicitante ajustar e reenviar. Explique o que precisa mudar.',
  publish: 'A vaga fica publicada e o processo seletivo pode avançar.',
  suspend: 'O processo fica pausado. Os candidatos continuam no quadro e a vaga pode ser publicada de novo.',
  close: 'A vaga é encerrada e o quadro fica congelado. O RH pode reabri-la se ainda houver posições.',
  reopen: 'A vaga volta a ficar publicada e o quadro pode ser movimentado.',
  cancel: 'A vaga é cancelada definitivamente. O histórico é mantido.',
}
</script>

<template>
  <BaseModal :open="!!action" :title="action?.label ?? ''" @close="emit('cancel')">
    <form v-if="action" id="job-action-form" class="space-y-4" @submit.prevent="emit('confirm', reason)">
      <p class="text-sm text-slate-600">{{ help[action.action] }}</p>
      <FormField v-if="action.needs_reason" label="Motivo" for="job-action-reason" :error="error" required>
        <textarea id="job-action-reason" v-model="reason" rows="3" maxlength="500" :class="inputClass(error)" />
      </FormField>
      <p v-else-if="error" class="text-sm text-red-600" role="alert">{{ error }}</p>
    </form>
    <template #footer>
      <BaseButton variant="secondary" @click="emit('cancel')">Voltar</BaseButton>
      <BaseButton type="submit" form="job-action-form" :variant="action?.action === 'cancel' || action?.action === 'reject' ? 'danger' : 'primary'" :loading="loading">
        {{ action?.label }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
