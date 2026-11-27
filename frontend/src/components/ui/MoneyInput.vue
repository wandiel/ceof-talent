<script setup lang="ts">
import { ref, watch } from 'vue'
import { parseMoney } from '@/utils/format'

/**
 * Campo de valor em reais no formato brasileiro: aceita "1.500", "1500",
 * "1.500,50" ou "1500,5" e mostra "1.500,00" ao sair do campo.
 * O valor enviado ao formulário é um número (ou null quando vazio).
 */
const model = defineModel<number | string | null>({ default: null })
defineProps<{ id?: string; inputClass?: string; placeholder?: string }>()

const fmt = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

const display = (v: number | string | null | undefined) => {
  if (v === null || v === undefined || v === '') return ''
  const n = typeof v === 'number' ? v : Number(v)
  return Number.isFinite(n) ? fmt.format(n) : ''
}

const text = ref(display(model.value))
let editing = false

watch(model, (v) => {
  if (!editing) text.value = display(v)
})

function onInput(e: Event) {
  editing = true
  text.value = (e.target as HTMLInputElement).value
  model.value = parseMoney(text.value)
}

function onBlur() {
  editing = false
  text.value = display(model.value)
}
</script>

<template>
  <div class="relative">
    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-slate-500">R$</span>
    <input
      :id="id"
      :value="text"
      type="text"
      inputmode="decimal"
      autocomplete="off"
      :placeholder="placeholder ?? '0,00'"
      :class="[inputClass, 'pl-10']"
      @input="onInput"
      @blur="onBlur"
    />
  </div>
</template>
