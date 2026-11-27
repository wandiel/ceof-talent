<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { parseApiError } from '@/api/http'
import { jobsApi, type JobOptions, type JobPayload } from '@/api/recruitment'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'
import FormSection from '@/views/candidates/components/FormSection.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const id = computed(() => (route.params.id as string | undefined) ?? null)
const options = ref<JobOptions | null>(null)
const loading = ref(true)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const message = ref('')
const dirty = ref(false)
const code = ref('')

function emptyPayload(): JobPayload {
  return {
    title: '', department_id: null, unit_id: null, position_id: null, professional_area: '', employment_type: 'clt',
    reason: 'new_position', replaced_employee: null, positions_count: 1, work_schedule: null, salary_min: null, salary_max: null,
    show_salary: false, education_level_min: null, description: '', responsibilities: null, benefits: null, is_public: true,
    closes_on: null, requirements: [],
  }
}

const form = reactive<JobPayload>(emptyPayload())

onMounted(async () => {
  try {
    options.value = await jobsApi.options()
    if (id.value) {
      const job = await jobsApi.get(id.value)
      if (!job.abilities.update) {
        toast.error('Esta vaga não pode mais ser editada.')
        router.replace({ name: 'job-detail', params: { id: job.id } })
        return
      }
      code.value = job.code
      Object.assign(form, {
        ...Object.fromEntries(Object.keys(emptyPayload()).map((k) => [k, (job as unknown as Record<string, unknown>)[k]])),
        department_id: job.department.id,
        unit_id: job.unit?.id ?? null,
        position_id: job.position?.id ?? null,
        requirements: job.requirements.map((r) => ({ ...r })),
      })
    } else if (!options.value.can_choose_department) {
      form.department_id = options.value.my_department_id
    }
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
    setTimeout(() => (dirty.value = false))
  }
})

// Ao corrigir um campo, o erro dele some.
const snapshot = ref<Record<string, string>>({})
watch(
  form,
  () => {
    const next = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, JSON.stringify(v)]))
    const changed = Object.keys(next).filter((k) => next[k] !== snapshot.value[k])
    snapshot.value = next
    if (!changed.length || !Object.keys(errors.value).length) return
    errors.value = Object.fromEntries(Object.entries(errors.value).filter(([key]) => !changed.includes(key.split('.')[0]!)))
    if (!Object.keys(errors.value).length) message.value = ''
  },
  { deep: true },
)

const err = (key: string) => errors.value[key]
const today = new Date().toISOString().slice(0, 10)

function nullable<T>(value: T): T | null {
  return value === '' || value === undefined ? null : value
}

async function submit() {
  saving.value = true
  errors.value = {}
  message.value = ''
  const payload: JobPayload = {
    ...form,
    ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'string' ? nullable(v.trim()) : v])),
    title: form.title.trim(),
    description: form.description.trim(),
    replaced_employee: form.reason === 'replacement' ? nullable(form.replaced_employee?.trim() ?? null) : null,
    salary_min: nullable(form.salary_min),
    salary_max: nullable(form.salary_max),
    positions_count: Number(form.positions_count),
  } as JobPayload
  try {
    const saved = id.value ? await jobsApi.update(id.value, payload) : await jobsApi.create(payload)
    dirty.value = false
    toast.success(id.value ? 'Vaga atualizada.' : `Vaga ${saved.code} criada como rascunho. Envie para aprovação quando estiver pronta.`)
    router.push({ name: 'job-detail', params: { id: saved.id }, query: { aba: 'detalhes' } })
  } catch (e) {
    const parsed = parseApiError(e)
    errors.value = parsed.fields
    message.value = Object.keys(parsed.fields).length ? 'Revise os campos destacados.' : parsed.message
    requestAnimationFrame(() => document.querySelector('[role=alert]')?.scrollIntoView({ behavior: 'smooth', block: 'center' }))
  } finally {
    saving.value = false
  }
}

function cancel() {
  router.push(id.value ? { name: 'job-detail', params: { id: id.value } } : { name: 'jobs' })
}

onBeforeRouteLeave(() => {
  if (dirty.value && !saving.value) return window.confirm('Há alterações não salvas. Deseja sair mesmo assim?')
})
</script>

<template>
  <PageHeader :title="id ? `Editar vaga ${code}` : 'Nova vaga'" subtitle="Campos com * são obrigatórios. A vaga nasce como rascunho e precisa ser aprovada antes de receber candidatos." />

  <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">Carregando…</div>

  <form v-else-if="options" class="space-y-6 pb-24" novalidate @submit.prevent="submit" @input="dirty = true" @change="dirty = true">
    <p v-if="message" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200" role="alert">{{ message }}</p>

    <FormSection title="Identificação da vaga">
      <div class="grid gap-4 sm:grid-cols-6">
        <FormField class="sm:col-span-4" label="Título da vaga" for="j-title" :error="err('title')" required>
          <input id="j-title" v-model="form.title" type="text" maxlength="150" placeholder="Ex.: Técnico de Enfermagem" :class="inputClass(err('title'))" />
        </FormField>
        <FormField class="sm:col-span-2" label="Quantidade de posições" for="j-positions" :error="err('positions_count')" required>
          <input id="j-positions" v-model.number="form.positions_count" type="number" min="1" max="500" :class="inputClass(err('positions_count'))" />
        </FormField>
        <FormField class="sm:col-span-3" label="Departamento solicitante" for="j-dept" :error="err('department_id')" required>
          <select id="j-dept" v-model="form.department_id" :disabled="!options.can_choose_department" :class="inputClass(err('department_id'))">
            <option :value="null" disabled>Selecione</option>
            <option v-for="d in options.departments" :key="d.id" :value="d.id">{{ d.name }}</option>
          </select>
        </FormField>
        <FormField class="sm:col-span-3" label="Unidade" for="j-unit" :error="err('unit_id')">
          <select id="j-unit" v-model="form.unit_id" :class="inputClass(err('unit_id'))">
            <option :value="null">Não definida</option>
            <option v-for="u in options.units" :key="u.id" :value="u.id">{{ u.name }}</option>
          </select>
        </FormField>
        <FormField class="sm:col-span-3" label="Cargo" for="j-position" :error="err('position_id')" hint="Cargo do quadro, se já existir.">
          <select id="j-position" v-model="form.position_id" :class="inputClass(err('position_id'))">
            <option :value="null">Não vinculado</option>
            <option v-for="p in options.positions" :key="p.id" :value="p.id">{{ p.name }}</option>
          </select>
        </FormField>
        <FormField class="sm:col-span-3" label="Área profissional" for="j-area" :error="err('professional_area')" required>
          <select id="j-area" v-model="form.professional_area" :class="inputClass(err('professional_area'))">
            <option value="" disabled>Selecione</option>
            <option v-for="o in options.professional_areas" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
      </div>
    </FormSection>

    <FormSection title="Condições">
      <div class="grid gap-4 sm:grid-cols-6">
        <FormField class="sm:col-span-2" label="Tipo de contratação" for="j-type" :error="err('employment_type')" required>
          <select id="j-type" v-model="form.employment_type" :class="inputClass(err('employment_type'))">
            <option v-for="o in options.employment_types" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField class="sm:col-span-2" label="Motivo da vaga" for="j-reason" :error="err('reason')" required>
          <select id="j-reason" v-model="form.reason" :class="inputClass(err('reason'))">
            <option v-for="o in options.reasons" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField v-if="form.reason === 'replacement'" class="sm:col-span-2" label="Colaborador substituído" for="j-replaced" :error="err('replaced_employee')" required>
          <input id="j-replaced" v-model="form.replaced_employee" type="text" maxlength="150" :class="inputClass(err('replaced_employee'))" />
        </FormField>
        <FormField class="sm:col-span-3" label="Jornada / horário" for="j-schedule" :error="err('work_schedule')" hint="Ex.: 12x36 noturno, segunda a sexta 8h–17h.">
          <input id="j-schedule" v-model="form.work_schedule" type="text" maxlength="150" :class="inputClass(err('work_schedule'))" />
        </FormField>
        <FormField class="sm:col-span-3" label="Escolaridade mínima" for="j-edu" :error="err('education_level_min')">
          <select id="j-edu" v-model="form.education_level_min" :class="inputClass(err('education_level_min'))">
            <option :value="null">Não exigida</option>
            <option v-for="o in options.education_levels" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField class="sm:col-span-2" label="Salário de (R$)" for="j-smin" :error="err('salary_min')">
          <input id="j-smin" v-model="form.salary_min" type="number" min="0" step="0.01" :class="inputClass(err('salary_min'))" />
        </FormField>
        <FormField class="sm:col-span-2" label="Salário até (R$)" for="j-smax" :error="err('salary_max')">
          <input id="j-smax" v-model="form.salary_max" type="number" min="0" step="0.01" :class="inputClass(err('salary_max'))" />
        </FormField>
        <FormField class="sm:col-span-2" label="Inscrições até" for="j-closes" :error="err('closes_on')">
          <input id="j-closes" v-model="form.closes_on" type="date" :min="today" :class="inputClass(err('closes_on'))" />
        </FormField>
      </div>
      <div class="mt-4 flex flex-wrap gap-x-6 gap-y-2 text-sm text-slate-700">
        <label class="inline-flex items-center gap-2"><input v-model="form.show_salary" type="checkbox" class="size-4 rounded border-slate-300" /> Divulgar faixa salarial</label>
        <label class="inline-flex items-center gap-2"><input v-model="form.is_public" type="checkbox" class="size-4 rounded border-slate-300" /> Vaga pode ser divulgada externamente</label>
      </div>
    </FormSection>

    <FormSection title="Descrição" :invalid="!!err('description')">
      <div class="grid gap-4">
        <FormField label="Descrição da vaga" for="j-desc" :error="err('description')" required hint="Apresente a vaga, o setor e o perfil procurado (mínimo de 20 caracteres).">
          <textarea id="j-desc" v-model="form.description" rows="5" maxlength="10000" :class="inputClass(err('description'))" />
        </FormField>
        <FormField label="Principais atividades" for="j-resp" :error="err('responsibilities')">
          <textarea id="j-resp" v-model="form.responsibilities" rows="4" maxlength="10000" :class="inputClass(err('responsibilities'))" />
        </FormField>
        <FormField label="Benefícios" for="j-benefits" :error="err('benefits')">
          <textarea id="j-benefits" v-model="form.benefits" rows="3" maxlength="5000" :class="inputClass(err('benefits'))" />
        </FormField>
      </div>
    </FormSection>

    <FormSection title="Requisitos" description="Marque como obrigatório o que elimina o candidato; os demais são desejáveis." :invalid="Object.keys(errors).some((k) => k.startsWith('requirements'))">
      <ul class="space-y-2">
        <li v-for="(r, i) in form.requirements" :key="i" class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <input v-model="r.description" type="text" maxlength="255" :aria-label="`Requisito ${i + 1}`" placeholder="Ex.: COREN ativo" :class="inputClass(err(`requirements.${i}.description`))" />
          <label class="inline-flex shrink-0 items-center gap-2 text-sm text-slate-700">
            <input v-model="r.is_mandatory" type="checkbox" class="size-4 rounded border-slate-300" /> Obrigatório
          </label>
          <button type="button" class="shrink-0 rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="form.requirements.splice(i, 1)">Remover</button>
        </li>
      </ul>
      <BaseButton variant="secondary" size="sm" class="mt-3" @click="form.requirements.push({ description: '', is_mandatory: true })">+ Adicionar requisito</BaseButton>
    </FormSection>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:left-64">
      <div class="mx-auto flex max-w-5xl justify-end gap-2">
        <BaseButton variant="secondary" @click="cancel">Cancelar</BaseButton>
        <BaseButton type="submit" :loading="saving">{{ id ? 'Salvar alterações' : 'Criar rascunho' }}</BaseButton>
      </div>
    </div>
  </form>
</template>
