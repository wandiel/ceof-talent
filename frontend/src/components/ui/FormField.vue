<script setup lang="ts">
defineProps<{
  label: string
  for?: string
  error?: string
  hint?: string
  required?: boolean
}>()
</script>

<template>
  <div>
    <label :for="$props.for" class="mb-1 block text-sm font-medium text-slate-700">
      {{ label }}<span v-if="required" class="text-red-600" aria-hidden="true"> *</span>
    </label>
    <slot />
    <p v-if="error" class="mt-1 text-xs text-red-600" role="alert">{{ error }}</p>
    <p v-else-if="hint" class="mt-1 text-xs text-slate-500">{{ hint }}</p>
  </div>
</template>
