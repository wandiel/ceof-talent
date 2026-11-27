<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{ label: string; field: string; sort: string }>()
const emit = defineEmits<{ sort: [string] }>()

const direction = computed(() => (props.sort === props.field ? 'asc' : props.sort === `-${props.field}` ? 'desc' : null))

function toggle() {
  emit('sort', direction.value === 'asc' ? `-${props.field}` : props.field)
}
</script>

<template>
  <th scope="col" class="px-4 py-3" :aria-sort="direction === 'asc' ? 'ascending' : direction === 'desc' ? 'descending' : 'none'">
    <button type="button" class="inline-flex items-center gap-1 font-semibold hover:text-slate-900" @click="toggle">
      {{ label }}
      <span class="text-[10px]" aria-hidden="true">{{ direction === 'asc' ? '▲' : direction === 'desc' ? '▼' : '↕' }}</span>
    </button>
  </th>
</template>
