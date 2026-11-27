<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { candidatesApi, talentPoolApi, type TagItem } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import { useToastStore } from '@/stores/toast'
import TagBadge from './TagBadge.vue'

const props = defineProps<{ candidateId: string; editable: boolean }>()
const tags = defineModel<TagItem[]>({ required: true })
const emit = defineEmits<{ changed: [] }>()
const toast = useToastStore()

const open = ref(false)
const all = ref<TagItem[]>([])
const saving = ref(false)
const root = ref<HTMLElement | null>(null)

async function toggleMenu() {
  open.value = !open.value
  if (open.value) {
    try {
      all.value = (await candidatesApi.options()).tags
    } catch (e) {
      toast.error(parseApiError(e).message)
    }
  }
}

async function toggle(tag: TagItem) {
  const ids = tags.value.map((t) => t.id)
  const next = ids.includes(tag.id) ? ids.filter((id) => id !== tag.id) : [...ids, tag.id]
  saving.value = true
  try {
    tags.value = await talentPoolApi.setTags(props.candidateId, next)
    emit('changed')
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    saving.value = false
  }
}

function onClick(event: MouseEvent) {
  if (open.value && root.value && !root.value.contains(event.target as Node)) open.value = false
}
document.addEventListener('mousedown', onClick)
onBeforeUnmount(() => document.removeEventListener('mousedown', onClick))
</script>

<template>
  <div ref="root" class="relative flex flex-wrap items-center gap-1.5">
    <TagBadge v-for="t in tags" :key="t.id" :name="t.name" :color="t.color" />
    <button v-if="editable" type="button" class="rounded-md px-2 py-0.5 text-xs text-slate-500 ring-1 ring-dashed ring-slate-300 hover:text-brand-700" :aria-expanded="open" @click="toggleMenu">
      {{ tags.length ? 'Editar etiquetas' : '+ Etiqueta' }}
    </button>
    <div v-if="open" class="absolute left-0 top-full z-30 mt-1 w-64 rounded-xl border border-slate-200 bg-white p-2 shadow-lg" role="menu">
      <p v-if="!all.length" class="px-2 py-3 text-sm text-slate-500">Nenhuma etiqueta criada ainda.</p>
      <label v-for="t in all" :key="t.id" class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-slate-50" role="menuitemcheckbox" :aria-checked="tags.some((x) => x.id === t.id)">
        <input type="checkbox" class="size-4 rounded border-slate-300" :checked="tags.some((x) => x.id === t.id)" :disabled="saving" @change="toggle(t)" />
        <TagBadge :name="t.name" :color="t.color" />
      </label>
      <RouterLink :to="{ name: 'tags' }" class="mt-1 block border-t border-slate-100 px-2 pt-2 text-xs text-brand-700 hover:underline">Gerenciar etiquetas</RouterLink>
    </div>
  </div>
</template>
