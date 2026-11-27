<script setup lang="ts">
import { tagColorClasses } from '@/api/candidates'

defineProps<{ name: string; color: string }>()
</script>

<template>
  <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-medium ring-1" :class="tagColorClasses[color] ?? tagColorClasses.slate">{{ name }}</span>
</template>
