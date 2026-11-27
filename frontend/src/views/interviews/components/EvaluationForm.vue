<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import type { Option } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import { interviewsApi, type Interview } from '@/api/interviews'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'

const props = defineProps<{ interview: Interview; recommendations: Option[] }>()
const emit = defineEmits<{ saved: [interview: Interview]; cancel: [] }>()
const toast = useToastStore()

const labels = ['', 'Insuficiente', 'Abaixo do esperado', 'Adequado', 'Bom', 'Excelente']
const form = reactive({ recommendation: '', summary: '', observations: '', scores: {} as Record<string, { score: number; comment: string }> })
const errors = ref<Record<string, string>>({})
const saving = ref(false)

watch(
  () => props.interview,
  (i) => {
    const mine = i.evaluations?.find((e) => e.is_mine)
    form.recommendation = mine?.recommendation ?? ''
    form.summary = mine?.summary ?? ''
    form.observations = mine?.observations ?? ''
    form.scores = Object.fromEntries((i.criteria ?? []).map((c) => {
      const s = mine?.scores.find((x) => x.criterion === c.id)
      return [c.id, { score: s?.score ?? 0, comment: s?.comment ?? '' }]
    }))
  },
  { immediate: true },
)

async function submit() {
  const missing = (props.interview.criteria ?? []).filter((c) => !form.scores[c.id]?.score)
  if (missing.length) {
    errors.value = { scores: `Dê uma nota para: ${missing.map((c) => c.name).join(', ')}.` }
    return
  }
  saving.value = true
  errors.value = {}
  try {
    const saved = await interviewsApi.evaluate(props.interview.id, {
      recommendation: form.recommendation,
      summary: form.summary.trim(),
      observations: form.observations.trim() || null,
      scores: Object.entries(form.scores).map(([criterion, v]) => ({ criterion, score: v.score, comment: v.comment.trim() || null })),
    })
    toast.success('Parecer registrado.')
    emit('saved', saved)
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.fields
    if (!Object.keys(parsed.fields).length) toast.error(parsed.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <form class="space-y-5" novalidate @submit.prevent="submit">
    <fieldset>
      <legend class="mb-2 text-sm font-medium text-slate-700">Notas por critério <span class="text-red-600">*</span> <span class="font-normal text-slate-500">(1 = insuficiente, 5 = excelente)</span></legend>
      <div class="space-y-3">
        <div v-for="c in interview.criteria" :key="c.id" class="rounded-lg border border-slate-200 p-3">
          <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <p class="text-sm font-medium text-slate-900">{{ c.name }}</p>
              <p v-if="c.description" class="text-xs text-slate-500">{{ c.description }}</p>
            </div>
            <div class="flex gap-1" role="radiogroup" :aria-label="`Nota para ${c.name}`">
              <button
                v-for="n in 5"
                :key="n"
                type="button"
                role="radio"
                :aria-checked="form.scores[c.id]!.score === n"
                :title="labels[n]"
                class="size-9 rounded-lg text-sm font-semibold ring-1 transition"
                :class="form.scores[c.id]!.score === n ? 'bg-brand-700 text-white ring-brand-700' : form.scores[c.id]!.score > n ? 'bg-brand-50 text-brand-800 ring-brand-200' : 'bg-white text-slate-600 ring-slate-300 hover:bg-slate-50'"
                @click="form.scores[c.id]!.score = n"
              >
                {{ n }}
              </button>
            </div>
          </div>
          <input v-model="form.scores[c.id]!.comment" type="text" maxlength="500" :aria-label="`Comentário sobre ${c.name}`" placeholder="Comentário (opcional)" :class="inputClass()" class="mt-2" />
        </div>
      </div>
      <p v-if="errors.scores" class="mt-1 text-xs text-red-600" role="alert">{{ errors.scores }}</p>
    </fieldset>

    <FormField label="Recomendação" for="ev-rec" :error="errors.recommendation" required>
      <select id="ev-rec" v-model="form.recommendation" :class="inputClass(errors.recommendation)">
        <option value="" disabled>Selecione</option>
        <option v-for="o in recommendations" :key="o.value" :value="o.value">{{ o.label }}</option>
      </select>
    </FormField>
    <FormField label="Parecer" for="ev-summary" :error="errors.summary" required hint="Pontos fortes, pontos de atenção e conclusão.">
      <textarea id="ev-summary" v-model="form.summary" rows="5" maxlength="5000" :class="inputClass(errors.summary)" />
    </FormField>
    <FormField label="Observações" for="ev-obs" :error="errors.observations" hint="Opcional. Ex.: disponibilidade, pretensão, próximos passos.">
      <textarea id="ev-obs" v-model="form.observations" rows="3" maxlength="5000" :class="inputClass(errors.observations)" />
    </FormField>
    <div class="flex justify-end gap-2">
      <BaseButton variant="secondary" @click="emit('cancel')">Cancelar</BaseButton>
      <BaseButton type="submit" :loading="saving">Salvar parecer</BaseButton>
    </div>
  </form>
</template>
