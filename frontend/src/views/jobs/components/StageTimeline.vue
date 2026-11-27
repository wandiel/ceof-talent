<script setup lang="ts">
import { stageClasses, type StageHistoryEntry } from '@/api/recruitment'
import { formatDateTime } from '@/utils/format'

defineProps<{ entries: StageHistoryEntry[] }>()
</script>

<template>
  <ol class="space-y-3 border-l border-slate-200 pl-4">
    <li v-for="h in entries" :key="h.id" class="relative">
      <span class="absolute -left-[21px] top-1.5 size-2.5 rounded-full" :class="stageClasses[h.to_stage]" />
      <p class="text-sm text-slate-900">
        <template v-if="h.from_stage">{{ h.from_stage_label }} → </template><strong>{{ h.to_stage_label }}</strong>
        <span v-if="h.reason_label" class="text-red-700"> · {{ h.reason_label }}</span>
      </p>
      <p v-if="h.note" class="whitespace-pre-line text-sm text-slate-600">{{ h.note }}</p>
      <p class="text-xs text-slate-500">{{ h.user ?? 'Sistema' }} · {{ formatDateTime(h.created_at) }}</p>
    </li>
  </ol>
</template>
