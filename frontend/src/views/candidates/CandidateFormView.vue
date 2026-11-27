<script setup lang="ts">
import MoneyInput from '@/components/ui/MoneyInput.vue'
import { useCepAutofill } from '@/composables/useCepAutofill'
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { candidatesApi, talentPoolApi, type CandidateOptions, type CandidatePayload, type DuplicateMatch } from '@/api/candidates'
import { parseApiError } from '@/api/http'
import BaseButton from '@/components/ui/BaseButton.vue'
import FormField from '@/components/ui/FormField.vue'
import PageHeader from '@/components/ui/PageHeader.vue'
import { inputClass } from '@/components/ui/inputClass'
import { useToastStore } from '@/stores/toast'
import { formatPhone, maskCep, maskCpf, maskPhone } from '@/utils/format'
import FormSection from './components/FormSection.vue'
import SkillsInput from './components/SkillsInput.vue'

const route = useRoute()
const router = useRouter()
const toast = useToastStore()

const id = computed(() => (route.params.id as string | undefined) ?? null)
const options = ref<CandidateOptions | null>(null)
const loading = ref(true)
const saving = ref(false)
const errors = ref<Record<string, string>>({})
const message = ref('')
const dirty = ref(false)

function emptyPayload(): CandidatePayload {
  return {
    full_name: '', social_name: null, email: null, phone: null, phone_secondary: null, cpf: null, birth_date: null,
    linkedin_url: null, portfolio_url: null, zip_code: null, neighborhood: null, city: null, state: null,
    professional_area: '', education_level: '', desired_position: null, summary: null, salary_expectation: null,
    availability: null, work_shifts: [], willing_to_relocate: false, source: 'internal', source_detail: null,
    educations: [], experiences: [], courses: [], skills: [],
  }
}

const form = reactive<CandidatePayload>(emptyPayload())
const cep = useCepAutofill(() => form.zip_code, form)

onMounted(async () => {
  try {
    options.value = await candidatesApi.options()
    if (id.value) {
      const c = await candidatesApi.get(id.value)
      cep.prime(c.zip_code)
      Object.assign(form, {
        ...emptyPayload(),
        ...Object.fromEntries(Object.keys(emptyPayload()).map((k) => [k, (c as unknown as Record<string, unknown>)[k]])),
        phone: c.phone ? maskPhone(c.phone) : null,
        phone_secondary: c.phone_secondary ? maskPhone(c.phone_secondary) : null,
        zip_code: c.zip_code ? maskCep(c.zip_code) : null,
        educations: c.educations.map(({ level, institution, course, status, start_year, end_year }) => ({ level, institution, course, status, start_year, end_year })),
        courses: c.courses.map(({ type, name, institution, year, workload_hours, credential }) => ({ type, name, institution, year, workload_hours, credential })),
        experiences: c.experiences.map((x) => ({ ...x })),
        skills: c.skills.map((s) => ({ ...s })),
      })
    }
  } catch (e) {
    toast.error(parseApiError(e).message)
  } finally {
    loading.value = false
    // Só considera alterações feitas depois do carregamento.
    setTimeout(() => (dirty.value = false))
  }
})

// Ao corrigir um campo, remove o erro dele (e das linhas da lista, se for uma lista).
const snapshot = ref<Record<string, string>>({})
watch(
  form,
  () => {
    const next = Object.fromEntries(Object.entries(form).map(([k, v]) => [k, JSON.stringify(v)]))
    const changed = Object.keys(next).filter((k) => next[k] !== snapshot.value[k])
    snapshot.value = next
    if (!changed.length || !Object.keys(errors.value).length) return
    errors.value = Object.fromEntries(Object.entries(errors.value).filter(([key]) => {
      const root = key.split('.')[0]
      // e-mail e telefone compartilham a regra "ao menos um contato".
      const related = root === 'email' || root === 'phone' ? ['email', 'phone'] : [root]
      return !related.some((r) => changed.includes(r))
    }))
    if (!Object.keys(errors.value).length) message.value = ''
  },
  { deep: true },
)

// Aviso de possível duplicidade enquanto o cadastro é digitado (não impede salvar).
const duplicates = ref<DuplicateMatch[]>([])
let lastCheck = ''
async function checkDuplicates() {
  const payload = { full_name: form.full_name, email: form.email, phone: form.phone, phone_secondary: form.phone_secondary, cpf: form.cpf, birth_date: form.birth_date, ignore: id.value }
  const key = JSON.stringify(payload)
  if (key === lastCheck) return
  lastCheck = key
  try {
    duplicates.value = await talentPoolApi.checkDuplicates(payload)
  } catch {
    duplicates.value = []
  }
}

function err(key: string): string | undefined {
  return errors.value[key]
}

function sectionHasErrors(prefix: string): boolean {
  return Object.keys(errors.value).some((k) => k.startsWith(prefix))
}

function addEducation() {
  form.educations.push({ level: '', institution: '', course: null, status: 'completed', start_year: null, end_year: null })
}
function addExperience() {
  form.experiences.push({ company: '', job_title: '', start_date: '', end_date: null, is_current: false, description: null })
}
function addCourse() {
  form.courses.push({ type: 'course', name: '', institution: null, year: null, workload_hours: null, credential: null })
}

function nullable<T>(value: T): T | null {
  return value === '' || value === undefined ? null : value
}

function toPayload(): CandidatePayload {
  const toInt = (v: unknown) => (v === '' || v === null || v === undefined ? null : Number(v))
  return {
    ...form,
    ...Object.fromEntries(Object.entries(form).map(([k, v]) => [k, typeof v === 'string' ? nullable(v.trim()) : v])),
    full_name: form.full_name.trim(),
    salary_expectation: nullable(form.salary_expectation),
    educations: form.educations.map((e) => ({ ...e, course: nullable(e.course), start_year: toInt(e.start_year), end_year: toInt(e.end_year) })),
    experiences: form.experiences.map((x) => ({ ...x, end_date: x.is_current ? null : nullable(x.end_date), description: nullable(x.description) })),
    courses: form.courses.map((c) => ({ ...c, institution: nullable(c.institution), credential: nullable(c.credential), year: toInt(c.year), workload_hours: toInt(c.workload_hours) })),
  } as CandidatePayload
}

async function submit() {
  saving.value = true
  errors.value = {}
  message.value = ''
  try {
    const saved = id.value ? await candidatesApi.update(id.value, toPayload()) : await candidatesApi.create(toPayload())
    dirty.value = false
    toast.success(id.value ? 'Cadastro atualizado.' : 'Candidato cadastrado. Agora você pode anexar o currículo.')
    router.push({ name: 'candidate-detail', params: { id: saved.id }, query: id.value ? {} : { aba: 'documentos' } })
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
  router.push(id.value ? { name: 'candidate-detail', params: { id: id.value } } : { name: 'candidates' })
}

onBeforeRouteLeave(() => {
  if (dirty.value && !saving.value) {
    return window.confirm('Há alterações não salvas. Deseja sair mesmo assim?')
  }
})

const currentYear = new Date().getFullYear()
const maxMonth = `${currentYear}-${String(new Date().getMonth() + 1).padStart(2, '0')}`
</script>

<template>
  <PageHeader :title="id ? 'Editar candidato' : 'Novo candidato'" subtitle="Campos com * são obrigatórios. Informe ao menos um contato: e-mail ou telefone." />

  <div v-if="loading" class="rounded-2xl border border-slate-200 bg-white p-12 text-center text-slate-500">Carregando…</div>

  <form v-else-if="options" class="space-y-6 pb-24" novalidate @submit.prevent="submit" @input="dirty = true" @change="dirty = true">
    <p v-if="message" class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-200" role="alert">{{ message }}</p>

    <div v-if="duplicates.length" class="rounded-2xl border border-amber-300 bg-amber-50 p-4" role="status" data-testid="duplicate-warning">
      <p class="font-medium text-amber-900">Já pode existir um cadastro desta pessoa</p>
      <p class="text-sm text-amber-800">Confira antes de continuar. Os links abrem em outra aba.</p>
      <ul class="mt-2 space-y-1 text-sm">
        <li v-for="d in duplicates" :key="d.candidate.id">
          <a :href="router.resolve({ name: 'candidate-detail', params: { id: d.candidate.id } }).href" target="_blank" rel="noopener" class="font-medium text-amber-900 underline">{{ d.candidate.full_name }}</a>
          <span class="text-amber-800"> · {{ d.candidate.email || formatPhone(d.candidate.phone) || 'sem contato' }} · {{ d.reasons.join(', ') }}</span>
        </li>
      </ul>
    </div>

    <FormSection title="Dados pessoais" description="Coletamos apenas o necessário para o processo seletivo (LGPD).">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Nome completo" for="c-name" :error="err('full_name')" required class="sm:col-span-2">
          <input id="c-name" @blur="checkDuplicates" v-model="form.full_name" type="text" maxlength="150" autocomplete="off" :class="inputClass(err('full_name'))" />
        </FormField>
        <FormField label="Nome social" for="c-social" :error="err('social_name')" hint="Como a pessoa prefere ser chamada.">
          <input id="c-social" v-model="form.social_name" type="text" maxlength="150" :class="inputClass(err('social_name'))" />
        </FormField>
        <FormField label="Data de nascimento" for="c-birth" :error="err('birth_date')">
          <input id="c-birth" @blur="checkDuplicates" v-model="form.birth_date" type="date" :class="inputClass(err('birth_date'))" />
        </FormField>
        <FormField label="CPF" for="c-cpf" :error="err('cpf')" hint="Opcional. Guardado criptografado; usado para evitar cadastros duplicados.">
          <input id="c-cpf" @blur="checkDuplicates" :value="form.cpf ?? ''" type="text" inputmode="numeric" placeholder="000.000.000-00" :class="inputClass(err('cpf'))" @input="form.cpf = maskCpf(($event.target as HTMLInputElement).value)" />
        </FormField>
      </div>
    </FormSection>

    <FormSection title="Contato">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="E-mail" for="c-email" :error="err('email')">
          <input id="c-email" @blur="checkDuplicates" v-model="form.email" type="email" maxlength="191" :class="inputClass(err('email'))" />
        </FormField>
        <FormField label="Telefone / WhatsApp" for="c-phone" :error="err('phone')">
          <input id="c-phone" @blur="checkDuplicates" :value="form.phone ?? ''" type="tel" inputmode="numeric" placeholder="(92) 99999-9999" :class="inputClass(err('phone'))" @input="form.phone = maskPhone(($event.target as HTMLInputElement).value)" />
        </FormField>
        <FormField label="Telefone secundário" for="c-phone2" :error="err('phone_secondary')">
          <input id="c-phone2" @blur="checkDuplicates" :value="form.phone_secondary ?? ''" type="tel" inputmode="numeric" :class="inputClass(err('phone_secondary'))" @input="form.phone_secondary = maskPhone(($event.target as HTMLInputElement).value)" />
        </FormField>
        <FormField label="LinkedIn" for="c-linkedin" :error="err('linkedin_url')">
          <input id="c-linkedin" v-model="form.linkedin_url" type="url" placeholder="https://www.linkedin.com/in/…" :class="inputClass(err('linkedin_url'))" />
        </FormField>
        <FormField label="Portfólio ou site" for="c-portfolio" :error="err('portfolio_url')" class="sm:col-span-2">
          <input id="c-portfolio" v-model="form.portfolio_url" type="url" placeholder="https://…" :class="inputClass(err('portfolio_url'))" />
        </FormField>
      </div>
    </FormSection>

    <FormSection title="Localização">
      <div class="grid gap-4 sm:grid-cols-6">
        <FormField label="CEP" for="c-cep" :error="err('zip_code')" class="sm:col-span-2">
          <input id="c-cep" :value="form.zip_code ?? ''" type="text" inputmode="numeric" placeholder="00000-000" :class="inputClass(err('zip_code'))" @input="form.zip_code = maskCep(($event.target as HTMLInputElement).value)" />
          <p v-if="cep.message.value && !err('zip_code')" class="mt-1 text-xs" :class="cep.status.value === 'not_found' ? 'text-amber-700' : 'text-slate-500'" data-testid="cep-status">{{ cep.message.value }}</p>
        </FormField>
        <FormField label="Bairro" for="c-neigh" :error="err('neighborhood')" class="sm:col-span-4">
          <input id="c-neigh" v-model="form.neighborhood" type="text" maxlength="120" :class="inputClass(err('neighborhood'))" />
        </FormField>
        <FormField label="Cidade" for="c-city" :error="err('city')" class="sm:col-span-4">
          <input id="c-city" v-model="form.city" type="text" maxlength="120" :class="inputClass(err('city'))" />
        </FormField>
        <FormField label="UF" for="c-state" :error="err('state')" class="sm:col-span-2">
          <select id="c-state" v-model="form.state" :class="inputClass(err('state'))">
            <option :value="null">—</option>
            <option v-for="uf in options.states" :key="uf" :value="uf">{{ uf }}</option>
          </select>
        </FormField>
      </div>
    </FormSection>

    <FormSection title="Perfil profissional">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Área profissional" for="c-area" :error="err('professional_area')" required>
          <select id="c-area" v-model="form.professional_area" :class="inputClass(err('professional_area'))">
            <option value="" disabled>Selecione</option>
            <option v-for="o in options.professional_areas" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Escolaridade" for="c-edu" :error="err('education_level')" required>
          <select id="c-edu" v-model="form.education_level" :class="inputClass(err('education_level'))">
            <option value="" disabled>Selecione</option>
            <option v-for="o in options.education_levels" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Cargo pretendido" for="c-position" :error="err('desired_position')">
          <input id="c-position" v-model="form.desired_position" type="text" maxlength="150" :class="inputClass(err('desired_position'))" />
        </FormField>
        <FormField label="Pretensão salarial (R$)" for="c-salary" :error="err('salary_expectation')">
          <MoneyInput id="c-salary" v-model="form.salary_expectation" :input-class="inputClass(err('salary_expectation'))" />
        </FormField>
        <FormField label="Disponibilidade para início" for="c-avail" :error="err('availability')">
          <select id="c-avail" v-model="form.availability" :class="inputClass(err('availability'))">
            <option :value="null">Não informada</option>
            <option v-for="o in options.availabilities" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <fieldset>
          <legend class="mb-1 block text-sm font-medium text-slate-700">Turnos disponíveis</legend>
          <div class="flex flex-wrap gap-2">
            <label v-for="o in options.work_shifts" :key="o.value" class="inline-flex cursor-pointer items-center gap-2 rounded-lg px-3 py-1.5 text-sm ring-1" :class="form.work_shifts.includes(o.value) ? 'bg-brand-50 text-brand-800 ring-brand-300' : 'text-slate-600 ring-slate-300'">
              <input v-model="form.work_shifts" type="checkbox" :value="o.value" class="sr-only" />
              {{ o.label }}
            </label>
          </div>
        </fieldset>
        <label class="inline-flex items-center gap-2 text-sm text-slate-700 sm:col-span-2">
          <input v-model="form.willing_to_relocate" type="checkbox" class="size-4 rounded border-slate-300 text-brand-700" />
          Aceita mudar de cidade
        </label>
        <FormField label="Resumo profissional" for="c-summary" :error="err('summary')" class="sm:col-span-2">
          <textarea id="c-summary" v-model="form.summary" rows="4" maxlength="5000" :class="inputClass(err('summary'))" />
        </FormField>
      </div>
    </FormSection>

    <FormSection title="Formação acadêmica" :invalid="sectionHasErrors('educations')">
      <div v-for="(e, i) in form.educations" :key="i" class="relative mb-4 grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-6">
        <FormField label="Nível" :for="`edu-level-${i}`" :error="err(`educations.${i}.level`)" required class="sm:col-span-2">
          <select :id="`edu-level-${i}`" v-model="e.level" :class="inputClass(err(`educations.${i}.level`))">
            <option value="" disabled>Selecione</option>
            <option v-for="o in options.education_levels" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Instituição" :for="`edu-inst-${i}`" :error="err(`educations.${i}.institution`)" required class="sm:col-span-4">
          <input :id="`edu-inst-${i}`" v-model="e.institution" type="text" maxlength="150" :class="inputClass(err(`educations.${i}.institution`))" />
        </FormField>
        <FormField label="Curso" :for="`edu-course-${i}`" :error="err(`educations.${i}.course`)" class="sm:col-span-3">
          <input :id="`edu-course-${i}`" v-model="e.course" type="text" maxlength="150" :class="inputClass(err(`educations.${i}.course`))" />
        </FormField>
        <FormField label="Situação" :for="`edu-status-${i}`" :error="err(`educations.${i}.status`)" required class="sm:col-span-1">
          <select :id="`edu-status-${i}`" v-model="e.status" :class="inputClass(err(`educations.${i}.status`))">
            <option v-for="o in options.education_statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Início" :for="`edu-start-${i}`" :error="err(`educations.${i}.start_year`)" class="sm:col-span-1">
          <input :id="`edu-start-${i}`" v-model="e.start_year" type="number" min="1950" :max="currentYear" placeholder="Ano" :class="inputClass(err(`educations.${i}.start_year`))" />
        </FormField>
        <FormField label="Conclusão" :for="`edu-end-${i}`" :error="err(`educations.${i}.end_year`)" class="sm:col-span-1">
          <input :id="`edu-end-${i}`" v-model="e.end_year" type="number" min="1950" :max="currentYear + 10" placeholder="Ano" :class="inputClass(err(`educations.${i}.end_year`))" />
        </FormField>
        <button type="button" class="absolute right-2 top-2 rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="form.educations.splice(i, 1)">Remover</button>
      </div>
      <BaseButton variant="secondary" size="sm" @click="addEducation">+ Adicionar formação</BaseButton>
    </FormSection>

    <FormSection title="Experiência profissional" :invalid="sectionHasErrors('experiences')">
      <div v-for="(x, i) in form.experiences" :key="i" class="relative mb-4 grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-6">
        <FormField label="Empresa" :for="`exp-company-${i}`" :error="err(`experiences.${i}.company`)" required class="sm:col-span-3">
          <input :id="`exp-company-${i}`" v-model="x.company" type="text" maxlength="150" :class="inputClass(err(`experiences.${i}.company`))" />
        </FormField>
        <FormField label="Cargo" :for="`exp-title-${i}`" :error="err(`experiences.${i}.job_title`)" required class="sm:col-span-3">
          <input :id="`exp-title-${i}`" v-model="x.job_title" type="text" maxlength="150" :class="inputClass(err(`experiences.${i}.job_title`))" />
        </FormField>
        <FormField label="Entrada" :for="`exp-start-${i}`" :error="err(`experiences.${i}.start_date`)" required class="sm:col-span-2">
          <input :id="`exp-start-${i}`" v-model="x.start_date" type="month" :max="maxMonth" :class="inputClass(err(`experiences.${i}.start_date`))" />
        </FormField>
        <FormField label="Saída" :for="`exp-end-${i}`" :error="err(`experiences.${i}.end_date`)" class="sm:col-span-2">
          <input :id="`exp-end-${i}`" v-model="x.end_date" type="month" :max="maxMonth" :disabled="x.is_current" :class="inputClass(err(`experiences.${i}.end_date`))" />
        </FormField>
        <label class="inline-flex items-center gap-2 self-end pb-2 text-sm text-slate-700 sm:col-span-2">
          <input v-model="x.is_current" type="checkbox" class="size-4 rounded border-slate-300 text-brand-700" />
          Emprego atual
        </label>
        <FormField label="Principais atividades" :for="`exp-desc-${i}`" :error="err(`experiences.${i}.description`)" class="sm:col-span-6">
          <textarea :id="`exp-desc-${i}`" v-model="x.description" rows="2" maxlength="3000" :class="inputClass(err(`experiences.${i}.description`))" />
        </FormField>
        <button type="button" class="absolute right-2 top-2 rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="form.experiences.splice(i, 1)">Remover</button>
      </div>
      <BaseButton variant="secondary" size="sm" @click="addExperience">+ Adicionar experiência</BaseButton>
    </FormSection>

    <FormSection title="Cursos e certificações" :invalid="sectionHasErrors('courses')">
      <div v-for="(c, i) in form.courses" :key="i" class="relative mb-4 grid gap-3 rounded-xl border border-slate-200 p-4 sm:grid-cols-6">
        <FormField label="Tipo" :for="`course-type-${i}`" :error="err(`courses.${i}.type`)" required class="sm:col-span-2">
          <select :id="`course-type-${i}`" v-model="c.type" :class="inputClass(err(`courses.${i}.type`))">
            <option v-for="o in options.course_types" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Nome" :for="`course-name-${i}`" :error="err(`courses.${i}.name`)" required class="sm:col-span-4">
          <input :id="`course-name-${i}`" v-model="c.name" type="text" maxlength="150" :class="inputClass(err(`courses.${i}.name`))" />
        </FormField>
        <FormField label="Instituição" :for="`course-inst-${i}`" :error="err(`courses.${i}.institution`)" class="sm:col-span-3">
          <input :id="`course-inst-${i}`" v-model="c.institution" type="text" maxlength="150" :class="inputClass(err(`courses.${i}.institution`))" />
        </FormField>
        <FormField label="Ano" :for="`course-year-${i}`" :error="err(`courses.${i}.year`)" class="sm:col-span-1">
          <input :id="`course-year-${i}`" v-model="c.year" type="number" min="1950" :max="currentYear + 5" :class="inputClass(err(`courses.${i}.year`))" />
        </FormField>
        <FormField label="Horas" :for="`course-hours-${i}`" :error="err(`courses.${i}.workload_hours`)" class="sm:col-span-1">
          <input :id="`course-hours-${i}`" v-model="c.workload_hours" type="number" min="1" :class="inputClass(err(`courses.${i}.workload_hours`))" />
        </FormField>
        <FormField label="Registro" :for="`course-cred-${i}`" :error="err(`courses.${i}.credential`)" class="sm:col-span-1">
          <input :id="`course-cred-${i}`" v-model="c.credential" type="text" maxlength="120" :class="inputClass(err(`courses.${i}.credential`))" />
        </FormField>
        <button type="button" class="absolute right-2 top-2 rounded-md px-2 py-1 text-xs text-red-600 hover:bg-red-50" @click="form.courses.splice(i, 1)">Remover</button>
      </div>
      <BaseButton variant="secondary" size="sm" @click="addCourse">+ Adicionar curso ou certificação</BaseButton>
    </FormSection>

    <FormSection title="Competências" description="Digite e tecle Enter. Sugestões aparecem a partir das competências já cadastradas." :invalid="sectionHasErrors('skills')">
      <SkillsInput v-model="form.skills" :levels="options.skill_levels" :errors="errors" />
    </FormSection>

    <FormSection title="Origem do cadastro">
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField label="Origem" for="c-source" :error="err('source')" required>
          <select id="c-source" v-model="form.source" :class="inputClass(err('source'))">
            <option v-for="o in options.sources" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
        </FormField>
        <FormField label="Detalhe da origem" for="c-source-detail" :error="err('source_detail')" hint="Ex.: nome de quem indicou ou site da vaga.">
          <input id="c-source-detail" v-model="form.source_detail" type="text" maxlength="150" :class="inputClass(err('source_detail'))" />
        </FormField>
      </div>
    </FormSection>

    <div class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 px-4 py-3 backdrop-blur lg:left-64">
      <div class="mx-auto flex max-w-5xl justify-end gap-2">
        <BaseButton variant="secondary" @click="cancel">Cancelar</BaseButton>
        <BaseButton type="submit" :loading="saving">{{ id ? 'Salvar alterações' : 'Cadastrar candidato' }}</BaseButton>
      </div>
    </div>
  </form>
</template>
