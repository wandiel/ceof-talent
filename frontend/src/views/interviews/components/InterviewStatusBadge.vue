<script setup lang="ts">
import { interviewStatusClasses, type InterviewStatus } from '@/api/interviews'

defineProps<{ status: InterviewStatus; label: string }>()
</script>

<template>
  <span class="inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium ring-1" :class="interviewStatusClasses[status]">{{ label }}</span>
</template>
