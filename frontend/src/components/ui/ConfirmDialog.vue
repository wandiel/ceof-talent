<script setup lang="ts">
import BaseButton from './BaseButton.vue'
import BaseModal from './BaseModal.vue'

defineProps<{ open: boolean; title: string; message: string; confirmLabel?: string; danger?: boolean; loading?: boolean }>()
const emit = defineEmits<{ confirm: []; cancel: [] }>()
</script>

<template>
  <BaseModal :open="open" :title="title" @close="emit('cancel')">
    <p class="text-sm text-slate-600">{{ message }}</p>
    <template #footer>
      <BaseButton variant="secondary" @click="emit('cancel')">Cancelar</BaseButton>
      <BaseButton :variant="danger ? 'danger' : 'primary'" :loading="loading" @click="emit('confirm')">
        {{ confirmLabel ?? 'Confirmar' }}
      </BaseButton>
    </template>
  </BaseModal>
</template>
