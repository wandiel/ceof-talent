<script setup lang="ts">
import { jobStatusClasses, type JobStatus } from '@/api/recruitment'

defineProps<{ status: JobStatus; label: string }>()
</script>

<template>
  <span class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1" :class="jobStatusClasses[status]">{{ label }}</span>
</template>
